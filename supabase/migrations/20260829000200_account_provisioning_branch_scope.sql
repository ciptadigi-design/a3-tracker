-- M2.7B: platform privilege separation, user identities, branch assignments,
-- governance separation, and reusable branch/PIC authorization.

create type public.platform_role as enum ('superuser');

create table public.platform_user_privileges (
  user_id uuid primary key references auth.users(id) on delete restrict,
  role public.platform_role not null,
  is_active boolean not null default true,
  granted_at timestamptz not null default statement_timestamp(),
  granted_by uuid references auth.users(id) on delete restrict,
  revoked_at timestamptz,
  revoked_by uuid references auth.users(id) on delete restrict,
  notes text,
  constraint platform_user_privileges_state_check check (
    (is_active and revoked_at is null and revoked_by is null)
    or (not is_active and revoked_at is not null)
  )
);

alter table public.profiles
  add column username text,
  add column username_normalized text;

create or replace function public.normalize_username(value text)
returns text language sql immutable set search_path='' as $$
  select lower(btrim(value))
$$;

create or replace function public.validate_profile_username()
returns trigger language plpgsql set search_path='' as $$
begin
  if new.username is null then
    new.username_normalized := null;
    return new;
  end if;
  new.username := public.normalize_username(new.username);
  new.username_normalized := new.username;
  if new.username !~ '^[a-z0-9._-]{3,32}$' then
    raise exception 'username must be 3 to 32 ASCII letters, numbers, dots, underscores, or hyphens'
      using errcode='22023';
  end if;
  if new.username in ('admin','root','system','support','api','auth','null') then
    raise exception 'username is reserved' using errcode='22023';
  end if;
  return new;
end $$;

create trigger profiles_validate_username
before insert or update of username on public.profiles
for each row execute function public.validate_profile_username();

create unique index profiles_username_normalized_key
  on public.profiles(username_normalized) where username_normalized is not null;

create table public.member_provisioning_requests (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  client_request_id uuid not null,
  email_normalized text not null,
  username_normalized text not null,
  display_name text not null,
  role public.account_role not null,
  branch_ids uuid[] not null,
  auth_user_id uuid references auth.users(id) on delete restrict,
  status text not null default 'pending_auth',
  requested_by uuid not null references auth.users(id) on delete restrict,
  created_at timestamptz not null default statement_timestamp(),
  completed_at timestamptz,
  last_error_code text,
  constraint member_provisioning_requests_account_request_key unique(account_id,client_request_id),
  constraint member_provisioning_requests_status_check check(status in ('pending_auth','completed','failed_retryable')),
  constraint member_provisioning_requests_email_check check(email_normalized=lower(btrim(email_normalized)) and position('@' in email_normalized)>1),
  constraint member_provisioning_requests_branches_check check(cardinality(branch_ids)>0)
);
create unique index member_provisioning_requests_pending_username_key on public.member_provisioning_requests(username_normalized)
  where status in ('pending_auth','failed_retryable');
create unique index member_provisioning_requests_pending_account_email_key on public.member_provisioning_requests(account_id,email_normalized)
  where status in ('pending_auth','failed_retryable');

create table public.account_membership_branches (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  membership_id uuid not null,
  branch_id uuid not null,
  is_active boolean not null default true,
  assigned_at timestamptz not null default statement_timestamp(),
  assigned_by uuid references auth.users(id) on delete set null,
  updated_at timestamptz not null default statement_timestamp(),
  updated_by uuid references auth.users(id) on delete set null,
  constraint account_membership_branches_membership_fkey foreign key (membership_id,account_id)
    references public.account_memberships(id,account_id) on delete cascade,
  constraint account_membership_branches_branch_fkey foreign key (branch_id,account_id)
    references public.branches(id,account_id) on delete restrict,
  constraint account_membership_branches_key unique(membership_id,branch_id)
);
create index account_membership_branches_scope_idx
  on public.account_membership_branches(account_id,branch_id,membership_id) where is_active;

create table public.operational_person_branches (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  operational_person_id uuid not null,
  branch_id uuid not null,
  is_active boolean not null default true,
  assigned_at timestamptz not null default statement_timestamp(),
  assigned_by uuid references auth.users(id) on delete set null,
  updated_at timestamptz not null default statement_timestamp(),
  updated_by uuid references auth.users(id) on delete set null,
  constraint operational_person_branches_person_fkey foreign key (operational_person_id,account_id)
    references public.operational_people(id,account_id) on delete cascade,
  constraint operational_person_branches_branch_fkey foreign key (branch_id,account_id)
    references public.branches(id,account_id) on delete restrict,
  constraint operational_person_branches_key unique(operational_person_id,branch_id)
);
create index operational_person_branches_scope_idx
  on public.operational_person_branches(account_id,branch_id,operational_person_id) where is_active;

alter table public.operational_incidents add column responsible_person_id uuid;
alter table public.operational_incidents add constraint operational_incidents_responsible_person_fkey
  foreign key(responsible_person_id,account_id) references public.operational_people(id,account_id) on delete restrict;

alter table public.component_replacement_events add column performed_by_person_id uuid;
alter table public.component_replacement_events add constraint component_replacement_events_person_fkey
  foreign key(performed_by_person_id,account_id) references public.operational_people(id,account_id) on delete restrict;

create or replace function public.is_platform_superuser()
returns boolean language sql stable security definer set search_path='' as $$
  select exists(select 1 from public.platform_user_privileges privilege
    where privilege.user_id=auth.uid() and privilege.role='superuser' and privilege.is_active)
$$;

create or replace function public.is_account_member(target_account_id uuid)
returns boolean language sql stable security definer set search_path='' as $$
  select public.is_platform_superuser() or exists(
    select 1 from public.account_memberships membership
    join public.accounts account on account.id=membership.account_id and account.status='active'
    where membership.account_id=target_account_id and membership.user_id=auth.uid()
      and membership.status='active')
$$;

create or replace function public.has_account_role(target_account_id uuid,allowed_roles public.account_role[])
returns boolean language sql stable security definer set search_path='' as $$
  select public.is_platform_superuser() or exists(
    select 1 from public.account_memberships membership
    join public.accounts account on account.id=membership.account_id and account.status='active'
    where membership.account_id=target_account_id and membership.user_id=auth.uid()
      and membership.status='active' and membership.role=any(allowed_roles)
  ) or (
    current_setting('a3.operational_capability_override',true) like target_account_id::text||':%'
    and public.has_operational_capability(target_account_id,split_part(current_setting('a3.operational_capability_override',true),':',2))
  )
$$;

create or replace function public.can_manage_account_governance(target_account_id uuid)
returns boolean language sql stable security definer set search_path='' as $$
  select public.is_platform_superuser() or exists(
    select 1 from public.account_memberships membership
    where membership.account_id=target_account_id and membership.user_id=auth.uid()
      and membership.status='active' and membership.role='owner')
$$;

create or replace function public.can_access_branch(target_account_id uuid,target_branch_id uuid)
returns boolean language sql stable security definer set search_path='' as $$
  select target_account_id is not null and target_branch_id is not null and exists(
    select 1 from public.branches branch
    join public.accounts account on account.id=branch.account_id
    where branch.id=target_branch_id and branch.account_id=target_account_id
      and account.status='active' and (
        public.is_platform_superuser() or exists(
          select 1 from public.account_memberships membership
          where membership.account_id=target_account_id and membership.user_id=auth.uid()
            and membership.status='active' and (
              membership.role='owner' or exists(
                select 1 from public.account_membership_branches assignment
                where assignment.account_id=target_account_id and assignment.membership_id=membership.id
                  and assignment.branch_id=target_branch_id and assignment.is_active
              )
            )
        )
      )
  )
$$;

create or replace function public.can_access_operational_scope(target_account_id uuid,target_branch_id uuid)
returns boolean language sql stable security definer set search_path='' as $$
  select case when target_branch_id is null then public.is_account_member(target_account_id)
    else public.can_access_branch(target_account_id,target_branch_id) end
$$;

create or replace function public.is_operational_person_valid_for_branch(
  target_account_id uuid,target_person_id uuid,target_branch_id uuid
) returns boolean language sql stable security definer set search_path='' as $$
  select exists(select 1 from public.operational_people person
    join public.operational_person_branches assignment
      on assignment.operational_person_id=person.id and assignment.account_id=person.account_id
    join public.branches branch on branch.id=assignment.branch_id and branch.account_id=assignment.account_id
    where person.id=target_person_id and person.account_id=target_account_id and person.is_active
      and assignment.branch_id=target_branch_id and assignment.is_active and branch.is_active)
$$;

alter table public.platform_user_privileges enable row level security;
alter table public.member_provisioning_requests enable row level security;
alter table public.account_membership_branches enable row level security;
alter table public.operational_person_branches enable row level security;
create policy platform_privileges_select_own on public.platform_user_privileges for select to authenticated using(user_id=auth.uid());
create policy member_provisioning_requests_select_governance on public.member_provisioning_requests for select to authenticated
  using(public.can_manage_account_governance(account_id));
create policy membership_branches_select_scoped on public.account_membership_branches for select to authenticated
  using(public.can_manage_account_governance(account_id) or public.can_access_branch(account_id,branch_id));
create policy person_branches_select_scoped on public.operational_person_branches for select to authenticated
  using(public.can_manage_account_governance(account_id) or public.can_access_branch(account_id,branch_id));

drop policy if exists operational_people_select_members on public.operational_people;
drop policy if exists operational_people_insert_owner_admin on public.operational_people;
drop policy if exists operational_people_update_owner_admin on public.operational_people;
drop policy if exists operational_people_delete_owner_admin on public.operational_people;
create policy operational_people_select_branch_scope on public.operational_people for select to authenticated using(
  public.can_manage_account_governance(operational_people.account_id) or exists(select 1 from public.operational_person_branches assignment
    where assignment.operational_person_id=operational_people.id and assignment.account_id=operational_people.account_id and assignment.is_active
      and public.can_access_branch(operational_people.account_id,assignment.branch_id)));
create policy operational_people_insert_governance on public.operational_people for insert to authenticated
  with check(public.can_manage_account_governance(account_id));
create policy operational_people_update_governance on public.operational_people for update to authenticated
  using(public.can_manage_account_governance(account_id)) with check(public.can_manage_account_governance(account_id));
create policy operational_people_delete_governance on public.operational_people for delete to authenticated
  using(public.can_manage_account_governance(account_id));

drop policy if exists branches_select_active_members on public.branches;
drop policy if exists branches_insert_owner_admin on public.branches;
drop policy if exists branches_update_owner_admin on public.branches;
create policy branches_select_accessible on public.branches for select to authenticated using(public.can_access_branch(account_id,id));
create policy branches_insert_governance on public.branches for insert to authenticated with check(public.can_manage_account_governance(account_id));
create policy branches_update_governance on public.branches for update to authenticated
  using(public.can_manage_account_governance(account_id)) with check(public.can_manage_account_governance(account_id));

drop policy if exists machines_select_account_members on public.machines;
drop policy if exists machines_insert_owner_admin on public.machines;
drop policy if exists machines_update_owner_admin on public.machines;
create policy machines_select_branch_access on public.machines for select to authenticated using(public.can_access_branch(account_id,branch_id));
create policy machines_insert_branch_admin on public.machines for insert to authenticated with check(
  public.has_account_role(account_id,array['owner','admin']::public.account_role[]) and public.can_access_branch(account_id,branch_id));
create policy machines_update_branch_admin on public.machines for update to authenticated using(
  public.has_account_role(account_id,array['owner','admin']::public.account_role[]) and public.can_access_branch(account_id,branch_id)) with check(
  public.has_account_role(account_id,array['owner','admin']::public.account_role[]) and public.can_access_branch(account_id,branch_id));

-- Restrictive branch policies compose with every existing operational policy.
-- NULL branch_id denotes an explicitly account-global master/location.
do $$ declare relation record; begin
  for relation in
    select c.relname from pg_catalog.pg_class c
    join pg_catalog.pg_namespace n on n.oid=c.relnamespace
    where n.nspname='public' and c.relkind in ('r','p')
      and c.relrowsecurity and c.relname not in ('branches','machines')
      and exists(select 1 from pg_catalog.pg_attribute a where a.attrelid=c.oid and a.attname='account_id' and not a.attisdropped)
      and exists(select 1 from pg_catalog.pg_attribute a where a.attrelid=c.oid and a.attname='branch_id' and not a.attisdropped)
  loop
    execute format('create policy %I on public.%I as restrictive for all to authenticated using (public.can_access_operational_scope(account_id,branch_id)) with check (public.can_access_operational_scope(account_id,branch_id))',
      'm27b_branch_scope_'||relation.relname,relation.relname);
  end loop;
end $$;

create or replace function public.manage_account_membership(
  target_account_id uuid,target_user_id uuid,target_role public.account_role,target_status public.membership_status
) returns public.account_memberships language plpgsql security definer set search_path='' as $$
declare actor_id uuid:=auth.uid(); current_membership public.account_memberships%rowtype; result public.account_memberships%rowtype;
begin
  if actor_id is null then
    raise exception 'authentication required' using errcode='42501';
  end if;
  if not public.can_manage_account_governance(target_account_id) then
    raise exception 'workspace owner required' using errcode='42501'; end if;
  perform 1 from public.accounts where id=target_account_id for update;
  if not found then raise exception 'account not found' using errcode='P0002'; end if;
  if target_user_id=actor_id and not public.is_platform_superuser() then raise exception 'users cannot directly change their own membership' using errcode='42501'; end if;
  if not exists(select 1 from auth.users where id=target_user_id) then raise exception 'target Auth user does not exist' using errcode='23503'; end if;
  select * into current_membership from public.account_memberships where account_id=target_account_id and user_id=target_user_id for update;
  insert into public.account_memberships(account_id,user_id,role,status,invited_at,accepted_at,created_by,updated_by)
  values(target_account_id,target_user_id,target_role,target_status,statement_timestamp(),
    case when target_status='active' then statement_timestamp() end,actor_id,actor_id)
  on conflict(account_id,user_id) do update set role=excluded.role,status=excluded.status,
    accepted_at=case when excluded.status='active' then coalesce(public.account_memberships.accepted_at,statement_timestamp()) else public.account_memberships.accepted_at end,
    updated_by=actor_id returning * into result;
  return result;
end $$;

drop function public.get_settings_members(uuid);
create function public.get_settings_members(target_account_id uuid)
returns table(membership_id uuid,user_id uuid,display_name text,username text,email text,role public.account_role,
  status public.membership_status,created_at timestamptz,accepted_at timestamptz,branch_ids uuid[],branch_names text[])
language sql stable security definer set search_path='' as $$
  select membership.id,membership.user_id,profile.display_name,profile.username,auth_user.email,membership.role,membership.status,
    membership.created_at,membership.accepted_at,
    coalesce(array_agg(branch.id order by branch.name) filter(where assignment.is_active),array[]::uuid[]),
    coalesce(array_agg(branch.name order by branch.name) filter(where assignment.is_active),array[]::text[])
  from public.account_memberships membership
  join public.profiles profile on profile.user_id=membership.user_id
  join auth.users auth_user on auth_user.id=membership.user_id
  left join public.account_membership_branches assignment on assignment.membership_id=membership.id and assignment.account_id=membership.account_id
  left join public.branches branch on branch.id=assignment.branch_id and branch.account_id=assignment.account_id
  where membership.account_id=target_account_id and public.can_manage_account_governance(target_account_id)
  group by membership.id,profile.user_id,auth_user.id
  order by (membership.role='owner') desc,profile.display_name
$$;

create or replace function public.manage_settings_membership(
  target_account_id uuid,target_user_id uuid,target_role public.account_role,target_status public.membership_status,
  target_branch_ids uuid[],target_username text,target_display_name text,target_client_request_id uuid
) returns public.account_memberships language plpgsql security definer set search_path='' as $$
declare prior public.account_memberships%rowtype; result public.account_memberships%rowtype; claimed boolean; membership_id uuid;
  normalized_branches uuid[]:=coalesce(target_branch_ids,array[]::uuid[]);
  payload jsonb:=jsonb_build_object('user_id',target_user_id,'role',target_role,'status',target_status,'branch_ids',normalized_branches,
    'username',public.normalize_username(target_username),'display_name',btrim(target_display_name));
begin
  if not public.can_manage_account_governance(target_account_id) then raise exception 'workspace owner required' using errcode='42501'; end if;
  if target_status not in ('invited','active','suspended') then raise exception 'unsupported membership status' using errcode='22023'; end if;
  if target_role<>'owner' and target_status in ('invited','active') and cardinality(normalized_branches)=0 then
    raise exception 'active or invited scoped members require at least one branch' using errcode='22023'; end if;
  if target_role='owner' then normalized_branches:=array[]::uuid[]; end if;
  if exists(select 1 from unnest(normalized_branches) requested(id) left join public.branches branch
    on branch.id=requested.id and branch.account_id=target_account_id and branch.is_active where branch.id is null)
    then raise exception 'branch assignment is invalid' using errcode='23503'; end if;
  perform 1 from public.accounts where id=target_account_id for update;
  select * into prior from public.account_memberships where account_id=target_account_id and user_id=target_user_id;
  if not found then raise exception 'membership not found' using errcode='P0002'; end if;
  claimed:=public.claim_settings_change(target_account_id,target_client_request_id,'membership.update','membership',payload);
  if not claimed then return prior; end if;
  update public.profiles set username=target_username,display_name=btrim(target_display_name) where user_id=target_user_id;
  result:=public.manage_account_membership(target_account_id,target_user_id,target_role,target_status);
  membership_id:=result.id;
  update public.account_membership_branches assignment set is_active=false,updated_at=statement_timestamp(),updated_by=auth.uid()
    where assignment.account_id=target_account_id and assignment.membership_id=result.id and assignment.is_active
      and not(assignment.branch_id=any(normalized_branches));
  insert into public.account_membership_branches(account_id,membership_id,branch_id,assigned_by,updated_by)
    select target_account_id,result.id,id,auth.uid(),auth.uid() from unnest(normalized_branches) branch(id)
    on conflict(membership_id,branch_id) do update set is_active=true,updated_at=statement_timestamp(),updated_by=auth.uid();
  perform public.finish_settings_change(target_account_id,target_client_request_id,result.id::text,to_jsonb(prior),
    jsonb_build_object('membership',to_jsonb(result),'branch_ids',normalized_branches,'username',public.normalize_username(target_username)));
  return result;
end $$;

create or replace function public.finalize_member_provisioning(
  target_account_id uuid,target_user_id uuid,target_email text,target_display_name text,target_username text,
  target_role public.account_role,target_branch_ids uuid[],target_client_request_id uuid
) returns public.account_memberships language plpgsql security definer set search_path='' as $$
declare result public.account_memberships%rowtype; existing public.account_memberships%rowtype; claimed boolean;
  payload jsonb:=jsonb_build_object('email',lower(btrim(target_email)),'display_name',btrim(target_display_name),
    'username',public.normalize_username(target_username),'role',target_role,'branch_ids',coalesce(target_branch_ids,array[]::uuid[]));
begin
  if not public.can_manage_account_governance(target_account_id) then raise exception 'workspace owner required' using errcode='42501'; end if;
  if target_role='owner' then raise exception 'invite a scoped role; owner promotion is a separate governance action' using errcode='22023'; end if;
  if cardinality(coalesce(target_branch_ids,array[]::uuid[]))=0 then raise exception 'at least one branch is required' using errcode='22023'; end if;
  if not exists(select 1 from auth.users where id=target_user_id and lower(email)=lower(btrim(target_email))) then raise exception 'Auth identity mismatch' using errcode='23503'; end if;
  if not exists(select 1 from public.member_provisioning_requests request where request.account_id=target_account_id
    and request.client_request_id=target_client_request_id and request.email_normalized=lower(btrim(target_email))
    and request.username_normalized=public.normalize_username(target_username) and request.display_name=btrim(target_display_name)
    and request.role=target_role and request.branch_ids=target_branch_ids and request.status in ('pending_auth','failed_retryable','completed'))
    then raise exception 'member provisioning was not prepared' using errcode='42501'; end if;
  perform 1 from public.accounts where id=target_account_id and status='active' for update;
  select * into existing from public.account_memberships where account_id=target_account_id and user_id=target_user_id for update;
  if found then
    if exists(select 1 from public.settings_change_events where account_id=target_account_id and client_request_id=target_client_request_id
      and action='member.provision' and request_payload=payload and completed_at is not null) then return existing; end if;
    raise exception 'user is already a member of this workspace' using errcode='23505';
  end if;
  claimed:=public.claim_settings_change(target_account_id,target_client_request_id,'member.provision','membership',payload);
  if not claimed then raise exception 'member provisioning retry is incomplete; retry with the same request' using errcode='40001'; end if;
  update public.profiles set display_name=btrim(target_display_name),username=target_username where user_id=target_user_id;
  insert into public.account_memberships(account_id,user_id,role,status,created_by,updated_by)
    values(target_account_id,target_user_id,target_role,'invited',auth.uid(),auth.uid()) returning * into result;
  insert into public.account_membership_branches(account_id,membership_id,branch_id,assigned_by,updated_by)
    select target_account_id,result.id,branch.id,auth.uid(),auth.uid() from unnest(target_branch_ids) requested(id)
    join public.branches branch on branch.id=requested.id and branch.account_id=target_account_id and branch.is_active;
  if (select count(*) from public.account_membership_branches where membership_id=result.id and is_active)<>cardinality(target_branch_ids) then
    raise exception 'branch assignment is invalid' using errcode='23503'; end if;
  perform public.finish_settings_change(target_account_id,target_client_request_id,result.id::text,null,
    jsonb_build_object('membership',to_jsonb(result),'branch_ids',target_branch_ids,'username',public.normalize_username(target_username)));
  update public.member_provisioning_requests set auth_user_id=target_user_id,status='completed',completed_at=statement_timestamp(),last_error_code=null
    where account_id=target_account_id and client_request_id=target_client_request_id;
  return result;
end $$;

create or replace function public.prepare_member_provisioning(
  target_account_id uuid,target_email text,target_display_name text,target_username text,
  target_role public.account_role,target_branch_ids uuid[],target_client_request_id uuid
) returns public.member_provisioning_requests language plpgsql security definer set search_path='' as $$
declare normalized_email text:=lower(btrim(target_email)); normalized_username text:=public.normalize_username(target_username);
  normalized_branches uuid[]:=coalesce(target_branch_ids,array[]::uuid[]); existing public.member_provisioning_requests%rowtype;
begin
  if not public.can_manage_account_governance(target_account_id) then raise exception 'workspace owner required' using errcode='42501'; end if;
  if target_client_request_id is null or normalized_email !~ '^[^[:space:]@]+@[^[:space:]@]+$' then raise exception 'valid email and request id required' using errcode='22023'; end if;
  if normalized_username is null or normalized_username !~ '^[a-z0-9._-]{3,32}$'
    or normalized_username in ('admin','root','system','support','api','auth','null') then raise exception 'invalid or reserved username' using errcode='22023'; end if;
  if target_role='owner' then raise exception 'owner invitation is not supported in this flow' using errcode='22023'; end if;
  if nullif(btrim(target_display_name),'') is null or length(btrim(target_display_name))>120 then raise exception 'display name is required' using errcode='22023'; end if;
  if cardinality(normalized_branches)=0 or exists(select 1 from unnest(normalized_branches) requested(id)
    left join public.branches branch on branch.id=requested.id and branch.account_id=target_account_id and branch.is_active where branch.id is null)
    then raise exception 'at least one valid branch is required' using errcode='23503'; end if;
  if exists(select 1 from public.profiles profile join auth.users auth_user on auth_user.id=profile.user_id
    where profile.username_normalized=normalized_username and lower(auth_user.email)<>normalized_email)
    then raise exception 'username is already in use' using errcode='23505'; end if;
  select * into existing from public.member_provisioning_requests where account_id=target_account_id and client_request_id=target_client_request_id;
  if found then
    if existing.email_normalized=normalized_email and existing.username_normalized=normalized_username
      and existing.display_name=btrim(target_display_name) and existing.role=target_role and existing.branch_ids=normalized_branches then return existing; end if;
    raise exception 'client request id was already used with different provisioning data' using errcode='23505';
  end if;
  insert into public.member_provisioning_requests(account_id,client_request_id,email_normalized,username_normalized,display_name,role,branch_ids,requested_by)
  values(target_account_id,target_client_request_id,normalized_email,normalized_username,btrim(target_display_name),target_role,normalized_branches,auth.uid())
  returning * into existing; return existing;
end $$;

create or replace function public.manage_operational_person_branches(
  target_account_id uuid,target_person_id uuid,target_branch_ids uuid[],target_client_request_id uuid
) returns public.operational_people language plpgsql security definer set search_path='' as $$
declare person public.operational_people%rowtype; claimed boolean; normalized uuid[]:=coalesce(target_branch_ids,array[]::uuid[]);
  payload jsonb:=jsonb_build_object('person_id',target_person_id,'branch_ids',normalized);
begin
  if not public.can_manage_account_governance(target_account_id) then raise exception 'workspace owner required' using errcode='42501'; end if;
  select * into person from public.operational_people where id=target_person_id and account_id=target_account_id for update;
  if not found then raise exception 'operational person not found' using errcode='P0002'; end if;
  if exists(select 1 from unnest(normalized) requested(id) left join public.branches branch
    on branch.id=requested.id and branch.account_id=target_account_id and branch.is_active where branch.id is null)
    then raise exception 'branch assignment is invalid' using errcode='23503'; end if;
  claimed:=public.claim_settings_change(target_account_id,target_client_request_id,'operational_person.branches','operational_person',payload);
  if not claimed then return person; end if;
  update public.operational_person_branches set is_active=false,updated_at=statement_timestamp(),updated_by=auth.uid()
    where operational_person_id=target_person_id and is_active and not(branch_id=any(normalized));
  insert into public.operational_person_branches(account_id,operational_person_id,branch_id,assigned_by,updated_by)
    select target_account_id,target_person_id,id,auth.uid(),auth.uid() from unnest(normalized) branch(id)
    on conflict(operational_person_id,branch_id) do update set is_active=true,updated_at=statement_timestamp(),updated_by=auth.uid();
  perform public.finish_settings_change(target_account_id,target_client_request_id,target_person_id::text,null,payload);
  return person;
end $$;

-- Governance functions introduced by M2.7A are Owner/platform-only.
create or replace function public.has_operational_capability(target_account_id uuid,target_capability text)
returns boolean language sql stable security definer set search_path='' as $$
  with actor as (select membership.role from public.account_memberships membership
    where membership.account_id=target_account_id and membership.user_id=auth.uid() and membership.status='active'),
  policy as (select * from public.account_operational_permissions where account_id=target_account_id)
  select public.is_platform_superuser() or coalesce((select case
    when actor.role in ('owner','admin') then true
    when actor.role='technician' then target_capability in ('replace_component','log_errors')
    when actor.role='operator' then case target_capability
      when 'initialize_component' then policy.operator_can_initialize_component when 'replace_component' then policy.operator_can_replace_component
      when 'create_purchase' then policy.operator_can_create_purchase when 'receive_goods' then policy.operator_can_receive_goods
      when 'adjust_inventory' then policy.operator_can_adjust_inventory when 'transfer_inventory' then policy.operator_can_transfer_inventory
      when 'log_errors' then policy.operator_can_log_errors else false end else false end from actor cross join policy),false)
$$;

-- Active member transition happens after the invited user completes Auth setup and
-- first establishes a session. It intentionally does not reveal invite state anonymously.
create or replace function public.accept_current_memberships()
returns integer language plpgsql security definer set search_path='' as $$
declare changed integer;
begin
  if auth.uid() is null then raise exception 'authentication required' using errcode='42501'; end if;
  update public.account_memberships set status='active',accepted_at=coalesce(accepted_at,statement_timestamp()),updated_by=auth.uid()
    where user_id=auth.uid() and status='invited'
      and (role='owner' or exists(select 1 from public.account_membership_branches assignment
        where assignment.membership_id=public.account_memberships.id and assignment.is_active));
  get diagnostics changed=row_count; return changed;
end $$;

-- Direct governance grants are removed; all mutations use audited RPCs.
revoke all on table public.platform_user_privileges,public.member_provisioning_requests,public.account_membership_branches,public.operational_person_branches from public,anon,authenticated,service_role;
grant select on table public.platform_user_privileges,public.member_provisioning_requests,public.account_membership_branches,public.operational_person_branches to authenticated,service_role;
grant all on table public.platform_user_privileges,public.member_provisioning_requests,public.account_membership_branches,public.operational_person_branches to service_role;

revoke all on function public.normalize_username(text),public.is_platform_superuser(),public.can_manage_account_governance(uuid),
  public.can_access_branch(uuid,uuid),public.can_access_operational_scope(uuid,uuid),public.is_operational_person_valid_for_branch(uuid,uuid,uuid),
  public.get_settings_members(uuid),public.manage_settings_membership(uuid,uuid,public.account_role,public.membership_status,uuid[],text,text,uuid),
  public.prepare_member_provisioning(uuid,text,text,text,public.account_role,uuid[],uuid),
  public.finalize_member_provisioning(uuid,uuid,text,text,text,public.account_role,uuid[],uuid),
  public.manage_operational_person_branches(uuid,uuid,uuid[],uuid),public.accept_current_memberships()
  from public,anon,authenticated,service_role;
grant execute on function public.normalize_username(text),public.is_platform_superuser(),public.can_manage_account_governance(uuid),
  public.can_access_branch(uuid,uuid),public.can_access_operational_scope(uuid,uuid),public.is_operational_person_valid_for_branch(uuid,uuid,uuid),
  public.get_settings_members(uuid),public.manage_settings_membership(uuid,uuid,public.account_role,public.membership_status,uuid[],text,text,uuid),
  public.prepare_member_provisioning(uuid,text,text,text,public.account_role,uuid[],uuid),
  public.finalize_member_provisioning(uuid,uuid,text,text,text,public.account_role,uuid[],uuid),
  public.manage_operational_person_branches(uuid,uuid,uuid[],uuid),public.accept_current_memberships()
  to authenticated;
grant execute on function public.is_platform_superuser(),public.can_manage_account_governance(uuid),public.can_access_branch(uuid,uuid),
  public.can_access_operational_scope(uuid,uuid),public.is_operational_person_valid_for_branch(uuid,uuid,uuid) to service_role;

revoke execute on function public.manage_settings_membership(uuid,uuid,public.account_role,public.membership_status,uuid) from authenticated,service_role;

drop policy if exists settings_change_events_select_admins on public.settings_change_events;
create policy settings_change_events_select_governance on public.settings_change_events for select to authenticated
  using(public.can_manage_account_governance(account_id));

-- No automatic scoped-role or PIC backfill: absence of an explicit assignment is denial.
comment on table public.account_membership_branches is 'Explicit many-to-many branch scope for Admin, Technician, and Operator. Owners are implicit all-branch.';
comment on table public.operational_person_branches is 'Explicit many-to-many future-use eligibility. Removing an assignment never rewrites historical snapshots.';
comment on table public.platform_user_privileges is 'Platform authorization independent of tenant membership. Rows are provisioned only by controlled platform administration.';

-- Existing Settings implementations remain the single mutation implementations;
-- governance wrappers narrow their callable contract to Owner/platform.
alter function public.manage_workspace_settings(uuid,text,text,uuid) rename to manage_workspace_settings_m27b_base;
alter function public.manage_settings_branch(uuid,uuid,text,text,text,text,text,text,uuid) rename to manage_settings_branch_m27b_base;
alter function public.manage_operational_permissions(uuid,boolean,boolean,boolean,boolean,boolean,boolean,boolean,uuid) rename to manage_operational_permissions_m27b_base;
alter function public.manage_advanced_economics_setting(uuid,boolean,uuid) rename to manage_advanced_economics_setting_m27b_base;

create function public.manage_workspace_settings(target_account_id uuid,target_name text,target_default_timezone text,target_client_request_id uuid)
returns public.accounts language plpgsql security definer set search_path='' as $$ begin
  if not public.can_manage_account_governance(target_account_id) then raise exception 'workspace owner required' using errcode='42501'; end if;
  return public.manage_workspace_settings_m27b_base(target_account_id,target_name,target_default_timezone,target_client_request_id);
end $$;
create function public.manage_settings_branch(target_account_id uuid,target_branch_id uuid,target_action text,target_code text,target_name text,
  target_address text,target_timezone text,target_notes text,target_client_request_id uuid)
returns public.branches language plpgsql security definer set search_path='' as $$ begin
  if not public.can_manage_account_governance(target_account_id) then raise exception 'workspace owner required' using errcode='42501'; end if;
  return public.manage_settings_branch_m27b_base(target_account_id,target_branch_id,target_action,target_code,target_name,target_address,target_timezone,target_notes,target_client_request_id);
end $$;
create function public.manage_operational_permissions(target_account_id uuid,target_operator_can_initialize_component boolean,
  target_operator_can_replace_component boolean,target_operator_can_create_purchase boolean,target_operator_can_receive_goods boolean,
  target_operator_can_adjust_inventory boolean,target_operator_can_transfer_inventory boolean,target_operator_can_log_errors boolean,target_client_request_id uuid)
returns public.account_operational_permissions language plpgsql security definer set search_path='' as $$ begin
  if not public.can_manage_account_governance(target_account_id) then raise exception 'workspace owner required' using errcode='42501'; end if;
  return public.manage_operational_permissions_m27b_base(target_account_id,target_operator_can_initialize_component,target_operator_can_replace_component,
    target_operator_can_create_purchase,target_operator_can_receive_goods,target_operator_can_adjust_inventory,target_operator_can_transfer_inventory,
    target_operator_can_log_errors,target_client_request_id);
end $$;
create function public.manage_advanced_economics_setting(target_account_id uuid,target_enabled boolean,target_client_request_id uuid)
returns boolean language plpgsql security definer set search_path='' as $$ begin
  if not public.can_manage_account_governance(target_account_id) then raise exception 'workspace owner required' using errcode='42501'; end if;
  return public.manage_advanced_economics_setting_m27b_base(target_account_id,target_enabled,target_client_request_id);
end $$;

revoke all on function public.manage_workspace_settings_m27b_base(uuid,text,text,uuid),
  public.manage_settings_branch_m27b_base(uuid,uuid,text,text,text,text,text,text,uuid),
  public.manage_operational_permissions_m27b_base(uuid,boolean,boolean,boolean,boolean,boolean,boolean,boolean,uuid),
  public.manage_advanced_economics_setting_m27b_base(uuid,boolean,uuid) from public,anon,authenticated,service_role;
revoke all on function public.manage_workspace_settings(uuid,text,text,uuid),
  public.manage_settings_branch(uuid,uuid,text,text,text,text,text,text,uuid),
  public.manage_operational_permissions(uuid,boolean,boolean,boolean,boolean,boolean,boolean,boolean,uuid),
  public.manage_advanced_economics_setting(uuid,boolean,uuid) from public,anon,authenticated,service_role;
grant execute on function public.manage_workspace_settings(uuid,text,text,uuid),
  public.manage_settings_branch(uuid,uuid,text,text,text,text,text,text,uuid),
  public.manage_operational_permissions(uuid,boolean,boolean,boolean,boolean,boolean,boolean,boolean,uuid),
  public.manage_advanced_economics_setting(uuid,boolean,uuid) to authenticated;

-- A final trigger-level guard protects writes performed inside SECURITY DEFINER
-- operational RPCs, including the race where access is revoked mid-request.
create or replace function public.enforce_branch_mutation_scope()
returns trigger language plpgsql security definer set search_path='' as $$
begin
  if new.branch_id is not null and not exists(
    select 1 from public.branches branch where branch.id=new.branch_id and branch.account_id=new.account_id
  ) then
    raise exception 'branch does not belong to account' using errcode='23503';
  end if;
  if auth.uid() is not null and not public.can_access_operational_scope(new.account_id,new.branch_id) then
    raise exception 'branch access required' using errcode='42501';
  end if;
  return new;
end $$;
do $$ declare relation record; begin
  for relation in select c.relname from pg_catalog.pg_class c join pg_catalog.pg_namespace n on n.oid=c.relnamespace
    where n.nspname='public' and c.relkind in ('r','p') and c.relname not in ('branches')
      and exists(select 1 from pg_catalog.pg_attribute a where a.attrelid=c.oid and a.attname='account_id' and not a.attisdropped)
      and exists(select 1 from pg_catalog.pg_attribute a where a.attrelid=c.oid and a.attname='branch_id' and not a.attisdropped)
  loop execute format('create trigger %I before insert or update on public.%I for each row execute function public.enforce_branch_mutation_scope()',
    'm27b_enforce_branch_'||relation.relname,relation.relname); end loop;
end $$;

create or replace function public.validate_branch_pic_assignment()
returns trigger language plpgsql security definer set search_path='' as $$
declare resolved_branch uuid;
begin
  if tg_table_name='counter_readings' then
    select branch_id into resolved_branch from public.machines where id=new.machine_id and account_id=new.account_id;
    if new.operator_person_id is not null and not public.is_operational_person_valid_for_branch(new.account_id,new.operator_person_id,resolved_branch)
      then raise exception 'PIC / Operator is not assigned to the machine Branch' using errcode='23514'; end if;
  elsif tg_table_name='inventory_movements' then
    select branch_id into resolved_branch from public.inventory_locations where id=new.location_id and account_id=new.account_id;
    if resolved_branch is not null and new.operational_person_id is not null and not public.is_operational_person_valid_for_branch(new.account_id,new.operational_person_id,resolved_branch)
      then raise exception 'PIC / Operator is not assigned to the inventory Branch' using errcode='23514'; end if;
  elsif tg_table_name='inventory_receipts' then
    select branch_id into resolved_branch from public.inventory_locations where id=new.location_id and account_id=new.account_id;
    if resolved_branch is not null and not public.is_operational_person_valid_for_branch(new.account_id,new.operational_person_id,resolved_branch)
      then raise exception 'PIC / Operator is not assigned to the receiving Branch' using errcode='23514'; end if;
  elsif tg_table_name='machine_operating_costs' and new.operational_person_id is not null
    and not public.is_operational_person_valid_for_branch(new.account_id,new.operational_person_id,new.branch_id)
    then raise exception 'PIC / Operator is not assigned to the machine Branch' using errcode='23514';
  end if;
  return new;
end $$;
create trigger counter_readings_validate_branch_pic before insert or update of machine_id,operator_person_id on public.counter_readings
  for each row execute function public.validate_branch_pic_assignment();
create trigger inventory_movements_validate_branch_pic before insert or update of location_id,operational_person_id on public.inventory_movements
  for each row execute function public.validate_branch_pic_assignment();
create trigger inventory_receipts_validate_branch_pic before insert or update of location_id,operational_person_id on public.inventory_receipts
  for each row execute function public.validate_branch_pic_assignment();
create trigger machine_operating_costs_validate_branch_pic before insert or update of branch_id,operational_person_id on public.machine_operating_costs
  for each row execute function public.validate_branch_pic_assignment();

-- Report RPCs may aggregate with elevated privileges. Scoped members must name
-- an accessible Branch; only Owner/platform may request a workspace-wide report.
create or replace function public.resolve_operational_report_scope(
  target_account_id uuid,target_branch_id uuid,target_machine_id uuid,target_period_start date,target_period_end date
) returns table(resolved_timezone text,period_start_at timestamptz,period_end_at timestamptz)
language plpgsql stable security definer set search_path='' as $$
declare account_record public.accounts%rowtype; branch_record public.branches%rowtype; machine_record public.machines%rowtype; v_timezone text;
begin
  if auth.uid() is null or not public.is_account_member(target_account_id) then raise exception 'active account membership required' using errcode='42501'; end if;
  if target_branch_id is null and not public.can_manage_account_governance(target_account_id) then raise exception 'Branch is required for scoped reports' using errcode='42501'; end if;
  if target_branch_id is not null and not public.can_access_branch(target_account_id,target_branch_id) then raise exception 'Branch access required' using errcode='42501'; end if;
  if target_period_start is null or target_period_end is null or target_period_end<target_period_start then raise exception 'valid report period is required' using errcode='22007'; end if;
  select * into account_record from public.accounts where id=target_account_id and status='active';
  if not found then raise exception 'active account not found' using errcode='P0002'; end if;
  if target_branch_id is not null then select * into branch_record from public.branches where id=target_branch_id and account_id=target_account_id and is_active;
    if not found then raise exception 'active branch not found in account' using errcode='P0002'; end if; end if;
  if target_machine_id is not null then select * into machine_record from public.machines where id=target_machine_id and account_id=target_account_id and is_active;
    if not found or not public.can_access_branch(target_account_id,machine_record.branch_id) then raise exception 'active accessible machine not found' using errcode='P0002'; end if;
    if target_branch_id is not null and machine_record.branch_id<>target_branch_id then raise exception 'machine is outside selected branch' using errcode='22023'; end if; end if;
  v_timezone:=coalesce(machine_record.timezone,branch_record.timezone,account_record.default_timezone);
  return query select v_timezone,target_period_start::timestamp at time zone v_timezone,(target_period_end+1)::timestamp at time zone v_timezone;
end $$;

revoke all on function public.enforce_branch_mutation_scope(),public.validate_branch_pic_assignment() from public,anon,authenticated,service_role;

-- Errors keeps its legacy account-member column for historical rows while new
-- and edited responsibility uses the normalized Operational People identity.
create or replace function public.apply_incident_responsible_person()
returns trigger language plpgsql security definer set search_path='' as $$
declare configured text:=nullif(current_setting('a3.incident_responsible_person_id',true),'');
begin
  if configured is not null then new.responsible_person_id:=configured::uuid;
  elsif current_setting('a3.incident_responsible_person_id',true)='' then new.responsible_person_id:=null; end if;
  return new;
end $$;
create trigger m27b_incident_apply_responsible_person before insert or update on public.operational_incidents
  for each row execute function public.apply_incident_responsible_person();

alter function public.create_operational_incident(uuid,uuid,timestamptz,public.operational_incident_category,public.operational_incident_type,text,uuid,uuid,text,text,text,integer,uuid,text,numeric,numeric,text,text,text)
  rename to create_operational_incident_m27b_base;
create function public.create_operational_incident(target_account_id uuid,target_branch_id uuid,target_occurred_at timestamptz,
  target_category public.operational_incident_category,target_incident_type public.operational_incident_type,target_description text,
  target_client_request_id uuid,target_machine_id uuid default null,target_invoice_number text default null,target_customer_name text default null,
  target_product_name text default null,target_qty_affected integer default null,target_responsible_user_id uuid default null,
  target_responsible_name text default null,target_material_loss numeric default 0,target_service_loss numeric default 0,target_cause text default null,
  target_prevention text default null,target_customer_resolution text default null)
returns public.operational_incidents language plpgsql security definer set search_path='' as $$
declare person public.operational_people%rowtype; result public.operational_incidents%rowtype;
begin
  if not public.can_access_branch(target_account_id,target_branch_id) then raise exception 'Branch access required' using errcode='42501'; end if;
  if target_responsible_user_id is not null then
    select * into person from public.operational_people where id=target_responsible_user_id and account_id=target_account_id and is_active;
    if not found or not public.is_operational_person_valid_for_branch(target_account_id,person.id,target_branch_id) then
      raise exception 'PIC is not active and assigned to this Branch' using errcode='23514'; end if;
  end if;
  perform set_config('a3.incident_responsible_person_id',coalesce(person.id::text,''),true);
  result:=public.create_operational_incident_m27b_base(target_account_id,target_branch_id,target_occurred_at,target_category,target_incident_type,
    target_description,target_client_request_id,target_machine_id,target_invoice_number,target_customer_name,target_product_name,target_qty_affected,
    null,coalesce(person.name,target_responsible_name),target_material_loss,target_service_loss,target_cause,target_prevention,target_customer_resolution);
  perform set_config('a3.incident_responsible_person_id','',true); return result;
end $$;

alter function public.update_operational_incident(uuid,timestamptz,timestamptz,public.operational_incident_category,public.operational_incident_type,text,uuid,text,text,text,integer,uuid,text,numeric,numeric,text,text,text,text)
  rename to update_operational_incident_m27b_base;
create function public.update_operational_incident(target_incident_id uuid,target_base_updated_at timestamptz,target_occurred_at timestamptz,
  target_category public.operational_incident_category,target_incident_type public.operational_incident_type,target_description text,
  target_machine_id uuid default null,target_invoice_number text default null,target_customer_name text default null,target_product_name text default null,
  target_qty_affected integer default null,target_responsible_user_id uuid default null,target_responsible_name text default null,
  target_material_loss numeric default 0,target_service_loss numeric default 0,target_cause text default null,target_prevention text default null,
  target_customer_resolution text default null,target_change_reason text default null)
returns public.operational_incidents language plpgsql security definer set search_path='' as $$
declare incident public.operational_incidents%rowtype; person public.operational_people%rowtype; result public.operational_incidents%rowtype;
begin
  select * into incident from public.operational_incidents where id=target_incident_id;
  if not found or not public.can_access_branch(incident.account_id,incident.branch_id) then raise exception 'accessible incident not found' using errcode='P0002'; end if;
  if target_responsible_user_id is not null then select * into person from public.operational_people
    where id=target_responsible_user_id and account_id=incident.account_id and is_active;
    if not found or not public.is_operational_person_valid_for_branch(incident.account_id,person.id,incident.branch_id) then
      raise exception 'PIC is not active and assigned to this Branch' using errcode='23514'; end if; end if;
  perform set_config('a3.incident_responsible_person_id',coalesce(person.id::text,''),true);
  result:=public.update_operational_incident_m27b_base(target_incident_id,target_base_updated_at,target_occurred_at,target_category,
    target_incident_type,target_description,target_machine_id,target_invoice_number,target_customer_name,target_product_name,target_qty_affected,
    null,coalesce(person.name,target_responsible_name),target_material_loss,target_service_loss,target_cause,target_prevention,
    target_customer_resolution,target_change_reason);
  perform set_config('a3.incident_responsible_person_id','',true); return result;
end $$;

revoke all on function public.apply_incident_responsible_person(),
  public.create_operational_incident_m27b_base(uuid,uuid,timestamptz,public.operational_incident_category,public.operational_incident_type,text,uuid,uuid,text,text,text,integer,uuid,text,numeric,numeric,text,text,text),
  public.update_operational_incident_m27b_base(uuid,timestamptz,timestamptz,public.operational_incident_category,public.operational_incident_type,text,uuid,text,text,text,integer,uuid,text,numeric,numeric,text,text,text,text)
  from public,anon,authenticated,service_role;
revoke all on function public.create_operational_incident(uuid,uuid,timestamptz,public.operational_incident_category,public.operational_incident_type,text,uuid,uuid,text,text,text,integer,uuid,text,numeric,numeric,text,text,text),
  public.update_operational_incident(uuid,timestamptz,timestamptz,public.operational_incident_category,public.operational_incident_type,text,uuid,text,text,text,integer,uuid,text,numeric,numeric,text,text,text,text)
  from public,anon,authenticated,service_role;
grant execute on function public.create_operational_incident(uuid,uuid,timestamptz,public.operational_incident_category,public.operational_incident_type,text,uuid,uuid,text,text,text,integer,uuid,text,numeric,numeric,text,text,text),
  public.update_operational_incident(uuid,timestamptz,timestamptz,public.operational_incident_category,public.operational_incident_type,text,uuid,text,text,text,integer,uuid,text,numeric,numeric,text,text,text,text)
  to authenticated;

create or replace function public.apply_replacement_performer_person()
returns trigger language plpgsql security definer set search_path='' as $$
declare configured text:=nullif(current_setting('a3.replacement_performer_person_id',true),'');
begin
  if configured is not null then new.performed_by_person_id:=configured::uuid; end if;
  return new;
end $$;
create trigger m27b_replacement_apply_performer before insert on public.component_replacement_events
  for each row execute function public.apply_replacement_performer_person();

alter function public.replace_machine_component(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,public.component_removal_condition,boolean,uuid,text,text,uuid,public.component_replacement_inventory_source,uuid,uuid,numeric,text)
  rename to replace_machine_component_m27b_base;
create function public.replace_machine_component(target_account_id uuid,target_machine_id uuid,target_lifecycle_id uuid,
  target_replacement_counter numeric,target_replaced_at timestamptz,target_replacement_reason public.component_replacement_reason,
  target_condition_at_removal public.component_removal_condition,target_include_in_adaptive_learning boolean,target_performed_by_user_id uuid,
  target_performed_by_name_snapshot text,target_notes text,target_client_request_id uuid,target_inventory_source public.component_replacement_inventory_source,
  target_inventory_item_id uuid,target_inventory_location_id uuid,target_inventory_quantity numeric,target_external_inventory_reason text)
returns public.component_replacement_events language plpgsql security definer set search_path='' as $$
declare machine public.machines%rowtype; person public.operational_people%rowtype; result public.component_replacement_events%rowtype;
begin
  select * into machine from public.machines where id=target_machine_id and account_id=target_account_id and is_active;
  if not found or not public.can_access_branch(target_account_id,machine.branch_id) then raise exception 'accessible machine not found' using errcode='P0002'; end if;
  if target_performed_by_user_id is not null then select * into person from public.operational_people
    where id=target_performed_by_user_id and account_id=target_account_id and is_active;
    if not found or not public.is_operational_person_valid_for_branch(target_account_id,person.id,machine.branch_id) then
      raise exception 'PIC is not active and assigned to the machine Branch' using errcode='23514'; end if;
  end if;
  perform set_config('a3.replacement_performer_person_id',coalesce(person.id::text,''),true);
  result:=public.replace_machine_component_m27b_base(target_account_id,target_machine_id,target_lifecycle_id,target_replacement_counter,
    target_replaced_at,target_replacement_reason,target_condition_at_removal,target_include_in_adaptive_learning,target_performed_by_user_id,
    target_performed_by_name_snapshot,target_notes,target_client_request_id,target_inventory_source,target_inventory_item_id,
    target_inventory_location_id,target_inventory_quantity,target_external_inventory_reason);
  perform set_config('a3.replacement_performer_person_id','',true); return result;
end $$;
revoke all on function public.apply_replacement_performer_person(),
  public.replace_machine_component_m27b_base(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,public.component_removal_condition,boolean,uuid,text,text,uuid,public.component_replacement_inventory_source,uuid,uuid,numeric,text)
  from public,anon,authenticated,service_role;
revoke all on function public.replace_machine_component(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,public.component_removal_condition,boolean,uuid,text,text,uuid,public.component_replacement_inventory_source,uuid,uuid,numeric,text)
  from public,anon,authenticated,service_role;
grant execute on function public.replace_machine_component(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,public.component_removal_condition,boolean,uuid,text,text,uuid,public.component_replacement_inventory_source,uuid,uuid,numeric,text)
  to authenticated;
