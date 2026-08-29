-- M2.7D: explicit Superuser bootstrap, direct-active member provisioning,
-- managed Auth identity changes, and authenticated My Account identity changes.

alter table public.member_provisioning_requests
  add column operation text not null default 'invite';
alter table public.member_provisioning_requests
  add constraint member_provisioning_requests_operation_check
  check (operation in ('invite','direct_create','activate'));

create table public.identity_change_events (
  id uuid primary key default gen_random_uuid(),
  client_request_id uuid not null,
  actor_id uuid not null references auth.users(id) on delete restrict,
  target_user_id uuid not null references auth.users(id) on delete restrict,
  action text not null,
  request_payload jsonb not null,
  before_state jsonb,
  after_state jsonb,
  created_at timestamptz not null default statement_timestamp(),
  completed_at timestamptz not null default statement_timestamp(),
  constraint identity_change_events_actor_request_key unique(actor_id,client_request_id),
  constraint identity_change_events_action_check check(action in ('profile.update','email.update','password.update'))
);
create index identity_change_events_target_created_idx
  on public.identity_change_events(target_user_id,created_at desc);

alter table public.identity_change_events enable row level security;
create policy identity_change_events_select_own_or_platform on public.identity_change_events
  for select to authenticated using(target_user_id=auth.uid() or public.is_platform_superuser());
revoke all on table public.identity_change_events from public,anon,authenticated,service_role;
grant select on table public.identity_change_events to authenticated,service_role;
grant all on table public.identity_change_events to service_role;

create or replace function public.prepare_direct_member_provisioning(
  target_account_id uuid,target_user_id uuid,target_email text,target_display_name text,target_username text,
  target_role public.account_role,target_branch_ids uuid[],target_operation text,target_client_request_id uuid
) returns public.member_provisioning_requests
language plpgsql security definer set search_path='' as $$
declare
  normalized_email text:=lower(btrim(target_email));
  normalized_username text:=public.normalize_username(target_username);
  normalized_branches uuid[]:=array(select distinct id from unnest(coalesce(target_branch_ids,array[]::uuid[])) branch(id) order by id);
  existing public.member_provisioning_requests%rowtype;
begin
  if not public.is_platform_superuser() then raise exception 'Platform Superuser required' using errcode='42501'; end if;
  if target_operation not in ('direct_create','activate') then raise exception 'unsupported provisioning operation' using errcode='22023'; end if;
  if target_client_request_id is null or normalized_email !~ '^[^[:space:]@]+@[^[:space:]@]+$' then
    raise exception 'valid email and request id required' using errcode='22023'; end if;
  if normalized_username is null or normalized_username !~ '^[a-z0-9._-]{3,32}$'
    or normalized_username in ('admin','root','system','support','api','auth','null') then
    raise exception 'invalid or reserved username' using errcode='22023'; end if;
  if target_role not in ('admin','technician','operator') then
    raise exception 'direct provisioning supports scoped operational roles' using errcode='22023'; end if;
  if nullif(btrim(target_display_name),'') is null or length(btrim(target_display_name))>120 then
    raise exception 'display name is required' using errcode='22023'; end if;
  if cardinality(normalized_branches)=0 or exists(
    select 1 from unnest(normalized_branches) requested(id)
    left join public.branches branch on branch.id=requested.id and branch.account_id=target_account_id and branch.is_active
    where branch.id is null
  ) then raise exception 'at least one valid branch is required' using errcode='23503'; end if;

  perform 1 from public.accounts where id=target_account_id and status='active';
  if not found then raise exception 'active account not found' using errcode='P0002'; end if;

  select * into existing from public.member_provisioning_requests
  where account_id=target_account_id and client_request_id=target_client_request_id;
  if found then
    if existing.operation=target_operation and existing.email_normalized=normalized_email
      and existing.username_normalized=normalized_username and existing.display_name=btrim(target_display_name)
      and existing.role=target_role and existing.branch_ids=normalized_branches
      and (target_operation='direct_create' or existing.auth_user_id is not distinct from target_user_id) then return existing; end if;
    raise exception 'client request id was already used with different provisioning data' using errcode='23505';
  end if;

  if target_operation='direct_create' then
    if target_user_id is not null then raise exception 'new account must not name an existing user' using errcode='22023'; end if;
    if exists(select 1 from auth.users where lower(email)=normalized_email) then
      raise exception 'email is already in use' using errcode='23505'; end if;
  else
    if target_user_id is null or not exists(
      select 1 from public.account_memberships membership join auth.users auth_user on auth_user.id=membership.user_id
      where membership.account_id=target_account_id and membership.user_id=target_user_id
        and membership.status='invited' and lower(auth_user.email)=normalized_email
    ) then raise exception 'invited member identity mismatch' using errcode='23503'; end if;
  end if;

  if exists(select 1 from public.profiles profile
    where profile.username_normalized=normalized_username and profile.user_id is distinct from target_user_id) then
    raise exception 'username is already in use' using errcode='23505'; end if;

  insert into public.member_provisioning_requests(
    account_id,client_request_id,email_normalized,username_normalized,display_name,role,branch_ids,
    auth_user_id,status,requested_by,operation
  ) values(
    target_account_id,target_client_request_id,normalized_email,normalized_username,btrim(target_display_name),target_role,
    normalized_branches,target_user_id,'pending_auth',auth.uid(),target_operation
  ) returning * into existing;
  return existing;
end $$;

create or replace function public.finalize_direct_member_provisioning(
  target_account_id uuid,target_user_id uuid,target_email text,target_display_name text,target_username text,
  target_role public.account_role,target_branch_ids uuid[],target_operation text,target_client_request_id uuid
) returns public.account_memberships
language plpgsql security definer set search_path='' as $$
declare
  normalized_email text:=lower(btrim(target_email));
  normalized_username text:=public.normalize_username(target_username);
  normalized_branches uuid[]:=array(select distinct id from unnest(coalesce(target_branch_ids,array[]::uuid[])) branch(id) order by id);
  request public.member_provisioning_requests%rowtype;
  prior public.account_memberships%rowtype;
  result public.account_memberships%rowtype;
  claimed boolean;
  action_name text:=case when target_operation='activate' then 'member.activate' else 'member.provision_active' end;
  payload jsonb:=jsonb_build_object('user_id',target_user_id,'email',normalized_email,'display_name',btrim(target_display_name),
    'username',normalized_username,'role',target_role,'branch_ids',normalized_branches);
begin
  if not public.is_platform_superuser() then raise exception 'Platform Superuser required' using errcode='42501'; end if;
  select * into request from public.member_provisioning_requests
  where account_id=target_account_id and client_request_id=target_client_request_id for update;
  if not found or request.operation<>target_operation or request.email_normalized<>normalized_email
    or request.username_normalized<>normalized_username or request.display_name<>btrim(target_display_name)
    or request.role<>target_role or request.branch_ids<>normalized_branches
    or (target_operation='activate' and request.auth_user_id is distinct from target_user_id) then
    raise exception 'direct member provisioning was not prepared' using errcode='42501'; end if;
  if not exists(select 1 from auth.users where id=target_user_id and lower(email)=normalized_email) then
    raise exception 'Auth identity mismatch' using errcode='23503'; end if;

  perform 1 from public.accounts where id=target_account_id and status='active' for update;
  select * into prior from public.account_memberships
  where account_id=target_account_id and user_id=target_user_id for update;
  if request.status='completed' then
    if found then return prior; end if;
    raise exception 'completed provisioning has no membership' using errcode='23503';
  end if;
  if target_operation='direct_create' and found then raise exception 'user is already a member' using errcode='23505'; end if;
  if target_operation='activate' and (not found or prior.status<>'invited') then
    raise exception 'member is not awaiting activation' using errcode='22023'; end if;

  update public.member_provisioning_requests set auth_user_id=target_user_id
  where id=request.id and auth_user_id is null;
  claimed:=public.claim_settings_change(target_account_id,target_client_request_id,action_name,'membership',payload);
  if not claimed then raise exception 'member provisioning retry is incomplete; retry with the same request' using errcode='40001'; end if;

  update public.profiles set display_name=btrim(target_display_name),username=target_username where user_id=target_user_id;
  result:=public.manage_account_membership(target_account_id,target_user_id,target_role,'active');
  update public.account_membership_branches assignment
    set is_active=false,updated_at=statement_timestamp(),updated_by=auth.uid()
    where assignment.account_id=target_account_id and assignment.membership_id=result.id and assignment.is_active
      and not(assignment.branch_id=any(normalized_branches));
  insert into public.account_membership_branches(account_id,membership_id,branch_id,assigned_by,updated_by)
    select target_account_id,result.id,id,auth.uid(),auth.uid() from unnest(normalized_branches) branch(id)
    on conflict(membership_id,branch_id) do update
      set is_active=true,updated_at=statement_timestamp(),updated_by=auth.uid();
  if (select count(*) from public.account_membership_branches where membership_id=result.id and is_active)<>cardinality(normalized_branches) then
    raise exception 'branch assignment is invalid' using errcode='23503'; end if;

  perform public.finish_settings_change(target_account_id,target_client_request_id,result.id::text,to_jsonb(prior),
    jsonb_build_object('membership',to_jsonb(result),'branch_ids',normalized_branches,'username',normalized_username));
  update public.member_provisioning_requests
    set auth_user_id=target_user_id,status='completed',completed_at=statement_timestamp(),last_error_code=null
    where id=request.id;
  return result;
end $$;

create or replace function public.manage_my_profile(
  target_display_name text,target_username text,target_client_request_id uuid
) returns public.profiles
language plpgsql security definer set search_path='' as $$
declare actor uuid:=auth.uid(); prior public.profiles%rowtype; result public.profiles%rowtype;
  payload jsonb:=jsonb_build_object('display_name',btrim(target_display_name),'username',public.normalize_username(target_username));
  existing public.identity_change_events%rowtype;
begin
  if actor is null then raise exception 'authentication required' using errcode='42501'; end if;
  if target_client_request_id is null or nullif(btrim(target_display_name),'') is null then
    raise exception 'display name and request id are required' using errcode='22023'; end if;
  select * into prior from public.profiles where user_id=actor for update;
  if not found then raise exception 'profile not found' using errcode='P0002'; end if;
  select * into existing from public.identity_change_events
    where actor_id=actor and client_request_id=target_client_request_id;
  if found then
    if existing.action='profile.update' and existing.request_payload=payload then return prior; end if;
    raise exception 'client request id was already used with a different identity mutation' using errcode='23505';
  end if;
  update public.profiles set display_name=btrim(target_display_name),username=target_username
    where user_id=actor returning * into result;
  insert into public.identity_change_events(client_request_id,actor_id,target_user_id,action,request_payload,before_state,after_state)
    values(target_client_request_id,actor,actor,'profile.update',payload,
      jsonb_build_object('display_name',prior.display_name,'username',prior.username),
      jsonb_build_object('display_name',result.display_name,'username',result.username));
  return result;
end $$;

create or replace function public.prepare_managed_auth_change(
  target_account_id uuid,target_user_id uuid,target_action text,target_email text,target_client_request_id uuid
) returns jsonb
language plpgsql security definer set search_path='' as $$
declare normalized_email text:=case when target_email is null then null else lower(btrim(target_email)) end;
  payload jsonb:=jsonb_strip_nulls(jsonb_build_object('user_id',target_user_id,'email',normalized_email));
  claimed boolean;
begin
  if not public.is_platform_superuser() then raise exception 'Platform Superuser required' using errcode='42501'; end if;
  if target_action not in ('member.email','member.password_reset') then raise exception 'unsupported managed Auth change' using errcode='22023'; end if;
  if not exists(select 1 from public.account_memberships where account_id=target_account_id and user_id=target_user_id) then
    raise exception 'membership not found' using errcode='P0002'; end if;
  if target_action='member.email' then
    if normalized_email is null or normalized_email !~ '^[^[:space:]@]+@[^[:space:]@]+$' then
      raise exception 'valid email required' using errcode='22023'; end if;
    if exists(select 1 from auth.users where lower(email)=normalized_email and id<>target_user_id) then
      raise exception 'email is already in use' using errcode='23505'; end if;
  end if;
  claimed:=public.claim_settings_change(target_account_id,target_client_request_id,target_action,'auth_identity',payload);
  return jsonb_build_object('claimed',claimed,'user_id',target_user_id,'email',normalized_email);
end $$;

create or replace function public.finish_managed_auth_change(
  target_account_id uuid,target_user_id uuid,target_action text,target_email text,target_client_request_id uuid
) returns jsonb
language plpgsql security definer set search_path='' as $$
declare normalized_email text:=case when target_email is null then null else lower(btrim(target_email)) end;
  current_email text; event public.settings_change_events%rowtype;
begin
  if not public.is_platform_superuser() then raise exception 'Platform Superuser required' using errcode='42501'; end if;
  select * into event from public.settings_change_events
    where account_id=target_account_id and client_request_id=target_client_request_id
      and action=target_action and target_type='auth_identity' for update;
  if not found then raise exception 'managed Auth change was not prepared' using errcode='42501'; end if;
  if event.completed_at is not null then return coalesce(event.after_state,'{}'::jsonb); end if;
  select lower(email) into current_email from auth.users where id=target_user_id;
  if not found then raise exception 'Auth identity not found' using errcode='P0002'; end if;
  if target_action='member.email' and current_email<>normalized_email then
    raise exception 'Auth email update is incomplete' using errcode='40001'; end if;
  perform public.finish_settings_change(target_account_id,target_client_request_id,target_user_id::text,null,
    case when target_action='member.email' then jsonb_build_object('email',current_email)
      else jsonb_build_object('credential_replaced',true) end);
  return case when target_action='member.email' then jsonb_build_object('email',current_email)
    else jsonb_build_object('credential_replaced',true) end;
end $$;

create or replace function public.record_identity_auth_change(
  target_actor_id uuid,target_user_id uuid,target_action text,target_client_request_id uuid,
  target_request_payload jsonb,target_before jsonb,target_after jsonb
) returns public.identity_change_events
language plpgsql security definer set search_path='' as $$
declare existing public.identity_change_events%rowtype;
begin
  if target_action not in ('email.update','password.update') then raise exception 'unsupported identity action' using errcode='22023'; end if;
  if not exists(select 1 from auth.users where id=target_actor_id) or not exists(select 1 from auth.users where id=target_user_id) then
    raise exception 'Auth identity not found' using errcode='P0002'; end if;
  insert into public.identity_change_events(client_request_id,actor_id,target_user_id,action,request_payload,before_state,after_state)
    values(target_client_request_id,target_actor_id,target_user_id,target_action,target_request_payload,target_before,target_after)
    on conflict(actor_id,client_request_id) do nothing returning * into existing;
  if found then return existing; end if;
  select * into existing from public.identity_change_events
    where actor_id=target_actor_id and client_request_id=target_client_request_id;
  if existing.target_user_id<>target_user_id or existing.action<>target_action or existing.request_payload<>target_request_payload then
    raise exception 'client request id was already used with a different identity mutation' using errcode='23505'; end if;
  return existing;
end $$;

create or replace function public.bootstrap_platform_superuser(
  target_user_id uuid,target_operator_id uuid,target_notes text default null
) returns public.platform_user_privileges
language plpgsql security definer set search_path='' as $$
declare result public.platform_user_privileges%rowtype;
begin
  if not exists(select 1 from auth.users where id=target_user_id) then raise exception 'target Auth user not found' using errcode='P0002'; end if;
  if target_operator_id is not null and not exists(select 1 from auth.users where id=target_operator_id) then
    raise exception 'operator Auth user not found' using errcode='P0002'; end if;
  insert into public.platform_user_privileges(user_id,role,is_active,granted_at,granted_by,revoked_at,revoked_by,notes)
    values(target_user_id,'superuser',true,statement_timestamp(),target_operator_id,null,null,nullif(btrim(target_notes),''))
  on conflict(user_id) do update set role='superuser',is_active=true,
    granted_at=case when public.platform_user_privileges.is_active then public.platform_user_privileges.granted_at else statement_timestamp() end,
    granted_by=case when public.platform_user_privileges.is_active then public.platform_user_privileges.granted_by else excluded.granted_by end,
    revoked_at=null,revoked_by=null,notes=coalesce(excluded.notes,public.platform_user_privileges.notes)
  returning * into result;
  return result;
end $$;

revoke all on function public.prepare_direct_member_provisioning(uuid,uuid,text,text,text,public.account_role,uuid[],text,uuid),
  public.finalize_direct_member_provisioning(uuid,uuid,text,text,text,public.account_role,uuid[],text,uuid),
  public.manage_my_profile(text,text,uuid),public.prepare_managed_auth_change(uuid,uuid,text,text,uuid),
  public.finish_managed_auth_change(uuid,uuid,text,text,uuid),
  public.record_identity_auth_change(uuid,uuid,text,uuid,jsonb,jsonb,jsonb),
  public.bootstrap_platform_superuser(uuid,uuid,text)
  from public,anon,authenticated,service_role;
grant execute on function public.prepare_direct_member_provisioning(uuid,uuid,text,text,text,public.account_role,uuid[],text,uuid),
  public.finalize_direct_member_provisioning(uuid,uuid,text,text,text,public.account_role,uuid[],text,uuid),
  public.manage_my_profile(text,text,uuid),public.prepare_managed_auth_change(uuid,uuid,text,text,uuid),
  public.finish_managed_auth_change(uuid,uuid,text,text,uuid)
  to authenticated;
grant execute on function public.record_identity_auth_change(uuid,uuid,text,uuid,jsonb,jsonb,jsonb),
  public.bootstrap_platform_superuser(uuid,uuid,text)
  to service_role;

comment on function public.bootstrap_platform_superuser(uuid,uuid,text) is
  'Operator-controlled, service-role-only, repeat-safe bootstrap using an explicit immutable Auth user UUID.';
comment on table public.identity_change_events is
  'Safe identity audit evidence. Passwords, hashes, tokens, and session credentials are never stored.';
