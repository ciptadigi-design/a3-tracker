-- Pre-Settings Component Assignment Contract
-- Model profiles provision persistent machine slots. Machine overrides always win.

create type public.machine_component_source as enum ('model_profile','machine_specific');
create type public.machine_component_assignment_status as enum ('configured','retired');

create table public.machine_component_assignments (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  branch_id uuid not null,
  machine_id uuid not null,
  component_id uuid not null references public.components(id) on delete restrict,
  slot_code text not null,
  display_label text,
  display_order integer not null default 0,
  tracking_method public.component_tracking_method not null,
  baseline_expected_clicks bigint,
  healthy_threshold_percent numeric(5,2) not null default 30,
  watch_threshold_percent numeric(5,2) not null default 15,
  warning_threshold_percent numeric(5,2) not null default 5,
  critical_threshold_percent numeric(5,2) not null default 0,
  source_type public.machine_component_source not null,
  source_profile_id uuid references public.machine_model_components(id) on delete restrict,
  status public.machine_component_assignment_status not null default 'configured',
  notes text,
  creation_request_id uuid,
  created_by uuid default auth.uid() references auth.users(id) on delete set null,
  created_at timestamptz not null default statement_timestamp(),
  updated_by uuid default auth.uid() references auth.users(id) on delete set null,
  updated_at timestamptz not null default statement_timestamp(),
  retired_by uuid references auth.users(id) on delete set null,
  retired_at timestamptz,
  constraint machine_component_assignments_machine_scope_fkey
    foreign key (machine_id, account_id, branch_id)
    references public.machines(id, account_id, branch_id) on delete restrict,
  constraint machine_component_assignments_slot_not_blank check (btrim(slot_code) <> ''),
  constraint machine_component_assignments_expected_positive check (baseline_expected_clicks is null or baseline_expected_clicks > 0),
  constraint machine_component_assignments_source_consistent check (
    (source_type = 'model_profile' and source_profile_id is not null)
    or (source_type = 'machine_specific' and source_profile_id is null)
  ),
  constraint machine_component_assignments_status_consistent check (
    (status = 'configured' and retired_at is null and retired_by is null)
    or (status = 'retired' and retired_at is not null)
  ),
  constraint machine_component_assignments_thresholds_valid check (
    healthy_threshold_percent <= 100 and healthy_threshold_percent > watch_threshold_percent
    and watch_threshold_percent > warning_threshold_percent
    and warning_threshold_percent > critical_threshold_percent and critical_threshold_percent >= 0
  )
);

create unique index machine_component_assignments_configured_slot_key
  on public.machine_component_assignments (machine_id, lower(btrim(slot_code))) where status = 'configured';
create unique index machine_component_assignments_profile_machine_key
  on public.machine_component_assignments (machine_id, source_profile_id) where source_profile_id is not null;
create unique index machine_component_assignments_request_key
  on public.machine_component_assignments (account_id, creation_request_id) where creation_request_id is not null;
create index machine_component_assignments_scope_idx
  on public.machine_component_assignments (account_id, branch_id, machine_id, status);

create table public.machine_component_profile_exclusions (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  machine_id uuid not null references public.machines(id) on delete restrict,
  model_component_profile_id uuid not null references public.machine_model_components(id) on delete restrict,
  machine_model_id uuid not null references public.machine_models(id) on delete restrict,
  slot_code text not null,
  reason text not null,
  excluded_by uuid not null references auth.users(id) on delete restrict,
  excluded_at timestamptz not null default statement_timestamp(),
  cleared_by uuid references auth.users(id) on delete set null,
  cleared_at timestamptz,
  client_request_id uuid not null,
  constraint machine_component_profile_exclusions_reason_not_blank check (btrim(reason) <> ''),
  constraint machine_component_profile_exclusions_slot_not_blank check (btrim(slot_code) <> ''),
  constraint machine_component_profile_exclusions_clear_consistent check ((cleared_at is null) = (cleared_by is null))
);
create unique index machine_component_profile_exclusions_active_key
  on public.machine_component_profile_exclusions (machine_id, lower(btrim(slot_code))) where cleared_at is null;
create unique index machine_component_profile_exclusions_request_key
  on public.machine_component_profile_exclusions (account_id, client_request_id);
create index machine_component_profile_exclusions_scope_idx
  on public.machine_component_profile_exclusions (account_id, machine_id, cleared_at);

create table public.component_configuration_requests (
  account_id uuid not null references public.accounts(id) on delete restrict,
  client_request_id uuid not null,
  operation text not null,
  payload text not null,
  result_id uuid,
  created_at timestamptz not null default statement_timestamp(),
  primary key(account_id,client_request_id)
);

create function public.claim_component_configuration_request(target_account_id uuid,target_client_request_id uuid,target_operation text,target_payload text)
returns boolean language plpgsql set search_path='' as $$
declare existing public.component_configuration_requests%rowtype;
begin
  insert into public.component_configuration_requests(account_id,client_request_id,operation,payload)
  values(target_account_id,target_client_request_id,target_operation,target_payload) on conflict do nothing;
  if found then return true; end if;
  select * into existing from public.component_configuration_requests where account_id=target_account_id and client_request_id=target_client_request_id;
  if existing.operation<>target_operation or existing.payload<>target_payload then
    raise exception 'client request id was already used with different configuration data' using errcode='23505';
  end if;
  return false;
end $$;
revoke all on function public.claim_component_configuration_request(uuid,uuid,text,text) from public,anon,authenticated,service_role;

alter table public.machine_component_lifecycles
  add column machine_component_assignment_id uuid references public.machine_component_assignments(id) on delete restrict;
create index machine_component_lifecycles_assignment_idx
  on public.machine_component_lifecycles (machine_component_assignment_id);

create function public.sync_machine_component_assignments_internal(target_account_id uuid, target_machine_id uuid default null)
returns integer language plpgsql set search_path = '' as $$
declare changed integer := 0; affected integer;
begin
  -- One transaction-level account lock serializes profile changes, exclusions and machine sync.
  perform pg_advisory_xact_lock(hashtextextended(target_account_id::text || ':component-assignment', 0));

  insert into public.machine_component_assignments(
    account_id,branch_id,machine_id,component_id,slot_code,display_order,tracking_method,
    baseline_expected_clicks,healthy_threshold_percent,watch_threshold_percent,
    warning_threshold_percent,critical_threshold_percent,source_type,source_profile_id,created_by
  )
  select machine.account_id,machine.branch_id,machine.id,profile.component_id,profile.slot_code,
    profile.display_order,profile.tracking_method,profile.baseline_expected_clicks,
    profile.healthy_threshold_percent,profile.watch_threshold_percent,
    profile.warning_threshold_percent,profile.critical_threshold_percent,
    'model_profile'::public.machine_component_source,profile.id,auth.uid()
  from public.machines machine
  join lateral (
    select effective.* from (
      select distinct on (lower(btrim(candidate.slot_code))) candidate.*
      from public.machine_model_components candidate
      where candidate.machine_model_id=machine.machine_model_id
        and (candidate.account_id is null or candidate.account_id=machine.account_id)
      order by lower(btrim(candidate.slot_code)),(candidate.account_id=machine.account_id) desc,candidate.created_at desc,candidate.id desc
      ) effective where effective.is_active
        and lower(btrim(effective.slot_code)) <> 'test_component'
  ) profile on true
  where machine.account_id=target_account_id and machine.is_active
    and (target_machine_id is null or machine.id=target_machine_id)
    and not exists (select 1 from public.machine_component_assignments override_assignment
      where override_assignment.machine_id=machine.id and override_assignment.status='configured'
        and lower(btrim(override_assignment.slot_code))=lower(btrim(profile.slot_code))
        and override_assignment.source_profile_id is distinct from profile.id)
    and not exists (select 1 from public.machine_component_profile_exclusions exclusion
      where exclusion.machine_id=machine.id and lower(btrim(exclusion.slot_code))=lower(btrim(profile.slot_code)) and exclusion.cleared_at is null)
  on conflict (machine_id,source_profile_id) where source_profile_id is not null do update set
    status='configured',retired_at=null,retired_by=null,component_id=excluded.component_id,
    slot_code=excluded.slot_code,display_order=excluded.display_order,tracking_method=excluded.tracking_method,
    baseline_expected_clicks=excluded.baseline_expected_clicks,
    healthy_threshold_percent=excluded.healthy_threshold_percent,
    watch_threshold_percent=excluded.watch_threshold_percent,
    warning_threshold_percent=excluded.warning_threshold_percent,
    critical_threshold_percent=excluded.critical_threshold_percent,
    updated_at=statement_timestamp(),updated_by=auth.uid();
  get diagnostics affected = row_count; changed := changed + affected;

  -- Archived/overridden inherited slots without any lifecycle evidence are stale configuration only.
  update public.machine_component_assignments assignment set
    status='retired',retired_at=statement_timestamp(),retired_by=auth.uid(),updated_at=statement_timestamp(),updated_by=auth.uid()
  where assignment.account_id=target_account_id and assignment.source_type='model_profile' and assignment.status='configured'
    and (target_machine_id is null or assignment.machine_id=target_machine_id)
    and (
      not exists (
        select 1 from public.machines machine
        join lateral (
          select effective.* from (
            select distinct on (lower(btrim(candidate.slot_code))) candidate.*
            from public.machine_model_components candidate
            where candidate.machine_model_id=machine.machine_model_id
              and (candidate.account_id is null or candidate.account_id=machine.account_id)
            order by lower(btrim(candidate.slot_code)),(candidate.account_id=machine.account_id) desc,candidate.created_at desc,candidate.id desc
          ) effective where effective.is_active
        ) profile on profile.id=assignment.source_profile_id
        where machine.id=assignment.machine_id
      )
      or exists (select 1 from public.machine_component_profile_exclusions exclusion
        where exclusion.machine_id=assignment.machine_id and lower(btrim(exclusion.slot_code))=lower(btrim(assignment.slot_code)) and exclusion.cleared_at is null)
    )
    and not exists (select 1 from public.machine_component_lifecycles lifecycle
      where lifecycle.machine_id=assignment.machine_id and lower(btrim(lifecycle.slot_code))=lower(btrim(assignment.slot_code)));
  get diagnostics affected = row_count; changed := changed + affected;
  return changed;
end $$;
revoke all on function public.sync_machine_component_assignments_internal(uuid,uuid) from public,anon,authenticated,service_role;

-- Deterministic reconciliation: configure effective active slots and every slot with lifecycle history.
do $$ declare account_record record; begin
  for account_record in select id from public.accounts loop
    perform public.sync_machine_component_assignments_internal(account_record.id,null);
  end loop;
end $$;

insert into public.machine_component_assignments(
  account_id,branch_id,machine_id,component_id,slot_code,display_order,tracking_method,
  baseline_expected_clicks,healthy_threshold_percent,watch_threshold_percent,
  warning_threshold_percent,critical_threshold_percent,source_type,source_profile_id,status,created_by
)
select distinct on (lifecycle.machine_id,lower(btrim(lifecycle.slot_code)))
  lifecycle.account_id,lifecycle.branch_id,lifecycle.machine_id,lifecycle.component_id,lifecycle.slot_code,
  profile.display_order,profile.tracking_method,profile.baseline_expected_clicks,
  profile.healthy_threshold_percent,profile.watch_threshold_percent,profile.warning_threshold_percent,
  profile.critical_threshold_percent,'model_profile',profile.id,'configured',lifecycle.created_by
from public.machine_component_lifecycles lifecycle
join public.machine_model_components profile on profile.id=lifecycle.model_component_profile_id
where not exists (select 1 from public.machine_component_assignments assignment
  where assignment.machine_id=lifecycle.machine_id and lower(btrim(assignment.slot_code))=lower(btrim(lifecycle.slot_code)))
order by lifecycle.machine_id,lower(btrim(lifecycle.slot_code)),(lifecycle.status='active') desc,lifecycle.created_at desc;

update public.machine_component_lifecycles lifecycle set machine_component_assignment_id=assignment.id
from public.machine_component_assignments assignment
where assignment.machine_id=lifecycle.machine_id
  and lower(btrim(assignment.slot_code))=lower(btrim(lifecycle.slot_code));
alter table public.machine_component_lifecycles alter column machine_component_assignment_id set not null;

create function public.resolve_machine_component_assignment_for_lifecycle()
returns trigger language plpgsql set search_path='' as $$
begin
  if new.machine_component_assignment_id is null then
    select assignment.id into new.machine_component_assignment_id
    from public.machine_component_assignments assignment
    where assignment.machine_id=new.machine_id
      and lower(btrim(assignment.slot_code))=lower(btrim(new.slot_code))
    order by (assignment.status='configured') desc,assignment.created_at desc,assignment.id desc limit 1;
  end if;
  if new.machine_component_assignment_id is null then
    raise exception 'configured machine component assignment not found for lifecycle slot' using errcode='23514';
  end if;
  return new;
end $$;
create trigger aa_machine_component_lifecycles_resolve_assignment
before insert on public.machine_component_lifecycles for each row
execute function public.resolve_machine_component_assignment_for_lifecycle();
revoke all on function public.resolve_machine_component_assignment_for_lifecycle() from public,anon,authenticated,service_role;

create function public.validate_machine_component_assignment()
returns trigger language plpgsql set search_path='' as $$
declare machine_record public.machines%rowtype; profile_record public.machine_model_components%rowtype; component_record public.components%rowtype;
begin
  select * into machine_record from public.machines where id=new.machine_id;
  if not found or machine_record.account_id<>new.account_id or machine_record.branch_id<>new.branch_id then
    raise exception 'machine assignment scope mismatch' using errcode='23514'; end if;
  select * into component_record from public.components where id=new.component_id;
  if not found or not component_record.is_active or (component_record.account_id is not null and component_record.account_id<>new.account_id) then
    raise exception 'active component is not assignable in this account' using errcode='23514'; end if;
  if new.source_profile_id is not null then
    select * into profile_record from public.machine_model_components where id=new.source_profile_id;
    if not found or profile_record.machine_model_id<>machine_record.machine_model_id
      or profile_record.component_id<>new.component_id
      or lower(btrim(profile_record.slot_code))<>lower(btrim(new.slot_code))
      or (profile_record.account_id is not null and profile_record.account_id<>new.account_id) then
      raise exception 'source profile does not match machine assignment' using errcode='23514'; end if;
  end if;
  if tg_op='UPDATE' then
    if new.account_id<>old.account_id or new.branch_id<>old.branch_id or new.machine_id<>old.machine_id
      or new.source_type<>old.source_type or new.source_profile_id is distinct from old.source_profile_id then
      raise exception 'machine component assignment identity is immutable' using errcode='42501'; end if;
    new.updated_at:=statement_timestamp(); new.updated_by:=coalesce(auth.uid(),new.updated_by,old.updated_by);
    if old.status='configured' and new.status='retired' then new.retired_at:=statement_timestamp(); new.retired_by:=coalesce(auth.uid(),new.retired_by);
    elsif old.status='retired' and new.status='configured' then new.retired_at:=null; new.retired_by:=null;
    else new.retired_at:=old.retired_at; new.retired_by:=old.retired_by; end if;
  end if;
  return new;
end $$;
create trigger machine_component_assignments_validate before insert or update on public.machine_component_assignments
for each row execute function public.validate_machine_component_assignment();
revoke all on function public.validate_machine_component_assignment() from public,anon,authenticated,service_role;

create function public.guard_component_profile_identity_and_catalog_archive()
returns trigger language plpgsql set search_path='' as $$
begin
  if tg_table_name='machine_model_components' then
    if tg_op='UPDATE'
      and (lower(btrim(new.slot_code))<>lower(btrim(old.slot_code)) or new.component_id<>old.component_id)
      and (exists(select 1 from public.machine_component_assignments where source_profile_id=old.id)
        or exists(select 1 from public.machine_component_lifecycles where model_component_profile_id=old.id)) then
      raise exception 'profile slot code is immutable after machine provisioning' using errcode='42501';
    end if;
  elsif tg_table_name='components' then
    if tg_op='UPDATE' and old.is_active and not new.is_active
      and exists(select 1 from public.machine_model_components where component_id=old.id and is_active) then
      raise exception 'archive active model profiles before archiving this component' using errcode='23514';
    end if;
  end if;
  return new;
end $$;
create trigger machine_model_components_guard_identity before update on public.machine_model_components
for each row execute function public.guard_component_profile_identity_and_catalog_archive();
create trigger components_guard_archive before update on public.components
for each row execute function public.guard_component_profile_identity_and_catalog_archive();
revoke all on function public.guard_component_profile_identity_and_catalog_archive() from public,anon,authenticated,service_role;

create function public.sync_machine_component_assignments_trigger()
returns trigger language plpgsql security definer set search_path='' as $$
declare account_record record;
begin
  if tg_table_name='machines' then
    perform public.sync_machine_component_assignments_internal(new.account_id,new.id);
  else
    for account_record in select distinct machine.account_id from public.machines machine
      where machine.machine_model_id=new.machine_model_id and machine.is_active loop
      perform public.sync_machine_component_assignments_internal(account_record.account_id,null);
    end loop;
  end if;
  return new;
end $$;
create trigger machines_sync_component_assignments after insert or update of machine_model_id,is_active on public.machines
for each row execute function public.sync_machine_component_assignments_trigger();
create trigger model_profiles_sync_component_assignments after insert or update of is_active,component_id,baseline_expected_clicks,tracking_method,display_order,healthy_threshold_percent,watch_threshold_percent,warning_threshold_percent,critical_threshold_percent on public.machine_model_components
for each row execute function public.sync_machine_component_assignments_trigger();
revoke all on function public.sync_machine_component_assignments_trigger() from public,anon,authenticated,service_role;

create function public.manage_machine_component_profile(target_account_id uuid,target_profile_id uuid,target_action text,target_client_request_id uuid)
returns public.machine_model_components language plpgsql security definer set search_path='' as $$
declare actor uuid:=auth.uid(); result public.machine_model_components%rowtype;
begin
  if actor is null or not public.has_account_role(target_account_id,array['owner','admin']::public.account_role[]) then raise exception 'owner or admin role required' using errcode='42501'; end if;
  if target_client_request_id is null then raise exception 'client request id is required' using errcode='22023'; end if;
  perform pg_advisory_xact_lock(hashtextextended(target_account_id::text||':component-assignment',0));
  if not public.claim_component_configuration_request(target_account_id,target_client_request_id,'profile_'||target_action,target_profile_id::text) then
    select * into result from public.machine_model_components where id=target_profile_id and (account_id is null or account_id=target_account_id); return result;
  end if;
  select * into result from public.machine_model_components where id=target_profile_id and (account_id is null or account_id=target_account_id) for update;
  if not found then raise exception 'model profile not found for this workspace' using errcode='P0002'; end if;
  if target_action='restore' and not exists(select 1 from public.components component where component.id=result.component_id and component.is_active and (component.account_id is null or component.account_id=target_account_id)) then
    raise exception 'restore the Component Catalog entry before restoring this profile' using errcode='23514';
  end if;
  if target_action='archive' and result.account_id is null then
    insert into public.machine_model_components(account_id,machine_model_id,component_id,slot_code,display_order,tracking_method,
      baseline_expected_clicks,adaptive_enabled,healthy_threshold_percent,watch_threshold_percent,warning_threshold_percent,
      critical_threshold_percent,notes,is_active,created_by)
    values(target_account_id,result.machine_model_id,result.component_id,result.slot_code,result.display_order,result.tracking_method,
      result.baseline_expected_clicks,result.adaptive_enabled,result.healthy_threshold_percent,result.watch_threshold_percent,
      result.warning_threshold_percent,result.critical_threshold_percent,result.notes,false,actor) returning * into result;
  elsif target_action='archive' then update public.machine_model_components set is_active=false where id=result.id and is_active;
  elsif target_action='restore' then update public.machine_model_components set is_active=true where id=result.id and not is_active;
  else raise exception 'action must be archive or restore' using errcode='22023'; end if;
  select * into result from public.machine_model_components where id=result.id; return result;
end $$;

create function public.save_machine_model_component_profile(
  target_account_id uuid,target_machine_model_id uuid,target_profile_id uuid,target_component_id uuid,target_slot_code text,
  target_display_order integer,target_tracking_method public.component_tracking_method,target_baseline_expected_clicks bigint,
  target_adaptive_enabled boolean,target_healthy_threshold numeric,target_watch_threshold numeric,
  target_warning_threshold numeric,target_critical_threshold numeric,target_notes text,target_client_request_id uuid)
returns public.machine_model_components language plpgsql security definer set search_path='' as $$
declare actor uuid:=auth.uid(); result public.machine_model_components%rowtype; source public.machine_model_components%rowtype;
  normalized_slot text:=upper(btrim(target_slot_code));
  payload text:=jsonb_build_object('model',target_machine_model_id,'profile',target_profile_id,'component',target_component_id,'slot',normalized_slot,
    'order',target_display_order,'tracking',target_tracking_method,'baseline',target_baseline_expected_clicks,'adaptive',target_adaptive_enabled,
    'healthy',target_healthy_threshold,'watch',target_watch_threshold,'warning',target_warning_threshold,'critical',target_critical_threshold,
    'notes',nullif(btrim(target_notes),''))::text;
begin
  if actor is null or not public.has_account_role(target_account_id,array['owner','admin']::public.account_role[]) then raise exception 'owner or admin role required' using errcode='42501'; end if;
  if target_client_request_id is null or normalized_slot='' then raise exception 'client request id and slot code are required' using errcode='22023'; end if;
  if not exists(select 1 from public.machine_models model where model.id=target_machine_model_id and (model.account_id is null or model.account_id=target_account_id)) then
    raise exception 'machine model not found for this workspace' using errcode='P0002';
  end if;
  if not exists(select 1 from public.components component where component.id=target_component_id and component.is_active and (component.account_id is null or component.account_id=target_account_id)) then
    raise exception 'active Component Catalog entry not found for this workspace' using errcode='23514';
  end if;
  perform pg_advisory_xact_lock(hashtextextended(target_account_id::text||':component-assignment',0));
  if not public.claim_component_configuration_request(target_account_id,target_client_request_id,'save_profile',payload) then
    select profile.* into result from public.component_configuration_requests request join public.machine_model_components profile on profile.id=request.result_id
    where request.account_id=target_account_id and request.client_request_id=target_client_request_id; return result;
  end if;
  if target_profile_id is not null then
    select * into source from public.machine_model_components where id=target_profile_id and (account_id is null or account_id=target_account_id) for update;
    if not found then raise exception 'model profile not found for this workspace' using errcode='P0002'; end if;
    if source.machine_model_id<>target_machine_model_id then raise exception 'model profile identity cannot move to another machine model' using errcode='42501'; end if;
  end if;
  if target_profile_id is not null and source.account_id=target_account_id then
    update public.machine_model_components set component_id=target_component_id,slot_code=normalized_slot,display_order=target_display_order,
      tracking_method=target_tracking_method,baseline_expected_clicks=target_baseline_expected_clicks,adaptive_enabled=target_adaptive_enabled,
      healthy_threshold_percent=target_healthy_threshold,watch_threshold_percent=target_watch_threshold,
      warning_threshold_percent=target_warning_threshold,critical_threshold_percent=target_critical_threshold,notes=nullif(btrim(target_notes),'')
    where id=source.id returning * into result;
  else
    insert into public.machine_model_components(account_id,machine_model_id,component_id,slot_code,display_order,tracking_method,
      baseline_expected_clicks,adaptive_enabled,healthy_threshold_percent,watch_threshold_percent,warning_threshold_percent,
      critical_threshold_percent,notes,created_by)
    values(target_account_id,target_machine_model_id,target_component_id,normalized_slot,target_display_order,target_tracking_method,
      target_baseline_expected_clicks,target_adaptive_enabled,target_healthy_threshold,target_watch_threshold,target_warning_threshold,
      target_critical_threshold,nullif(btrim(target_notes),''),actor) returning * into result;
  end if;
  update public.component_configuration_requests set result_id=result.id where account_id=target_account_id and client_request_id=target_client_request_id;
  return result;
end $$;

create function public.manage_component_catalog_status(target_account_id uuid,target_component_id uuid,target_action text,target_client_request_id uuid)
returns public.components language plpgsql security definer set search_path='' as $$
declare actor uuid:=auth.uid(); result public.components%rowtype;
begin
  if actor is null or not public.has_account_role(target_account_id,array['owner','admin']::public.account_role[]) then raise exception 'owner or admin role required' using errcode='42501'; end if;
  if target_client_request_id is null then raise exception 'client request id is required' using errcode='22023'; end if;
  if not public.claim_component_configuration_request(target_account_id,target_client_request_id,'catalog_'||target_action,target_component_id::text) then
    select * into result from public.components where id=target_component_id and account_id=target_account_id; return result;
  end if;
  select * into result from public.components where id=target_component_id and account_id=target_account_id for update;
  if not found then raise exception 'workspace component not found' using errcode='P0002'; end if;
  if target_action='archive' and exists(select 1 from public.machine_model_components where component_id=result.id and is_active) then
    raise exception 'archive active model profiles before archiving this component' using errcode='23514';
  elsif target_action='archive' then update public.components set is_active=false where id=result.id and is_active;
  elsif target_action='restore' then update public.components set is_active=true where id=result.id and not is_active;
  else raise exception 'action must be archive or restore' using errcode='22023'; end if;
  if target_action='restore' then perform public.provision_canonical_inventory_items_for_account(target_account_id); end if;
  select * into result from public.components where id=result.id; return result;
end $$;

create function public.add_machine_component_assignment(
  target_account_id uuid,target_machine_id uuid,target_component_id uuid,target_slot_code text,
  target_tracking_method public.component_tracking_method,target_baseline_expected_clicks bigint,
  target_notes text,target_client_request_id uuid)
returns public.machine_component_assignments language plpgsql security definer set search_path='' as $$
declare actor uuid:=auth.uid(); machine_record public.machines%rowtype; existing public.machine_component_assignments%rowtype; normalized_slot text:=upper(btrim(target_slot_code));
begin
  if actor is null or not public.has_account_role(target_account_id,array['owner','admin']::public.account_role[]) then raise exception 'owner or admin role required' using errcode='42501'; end if;
  if target_client_request_id is null or normalized_slot='' then raise exception 'client request id and slot code are required' using errcode='22023'; end if;
  perform pg_advisory_xact_lock(hashtextextended(target_account_id::text||':component-assignment',0));
  select * into existing from public.machine_component_assignments where account_id=target_account_id and creation_request_id=target_client_request_id;
  if found then
    if existing.machine_id=target_machine_id and existing.component_id=target_component_id and lower(btrim(existing.slot_code))=lower(normalized_slot)
      and existing.tracking_method=target_tracking_method and existing.baseline_expected_clicks is not distinct from target_baseline_expected_clicks and existing.notes is not distinct from nullif(btrim(target_notes),'') then return existing; end if;
    raise exception 'client request id was already used with different assignment data' using errcode='23505';
  end if;
  select * into machine_record from public.machines where id=target_machine_id and account_id=target_account_id and is_active for update;
  if not found then raise exception 'active machine not found' using errcode='P0002'; end if;
  insert into public.machine_component_assignments(account_id,branch_id,machine_id,component_id,slot_code,tracking_method,
    baseline_expected_clicks,source_type,notes,creation_request_id,created_by)
  values(target_account_id,machine_record.branch_id,machine_record.id,target_component_id,normalized_slot,target_tracking_method,
    target_baseline_expected_clicks,'machine_specific',nullif(btrim(target_notes),''),target_client_request_id,actor) returning * into existing;
  return existing;
end $$;

create function public.remove_machine_component_assignment(target_account_id uuid,target_assignment_id uuid,target_reason text,target_client_request_id uuid)
returns public.machine_component_assignments language plpgsql security definer set search_path='' as $$
declare actor uuid:=auth.uid(); assignment public.machine_component_assignments%rowtype; existing_exclusion public.machine_component_profile_exclusions%rowtype;
begin
  if actor is null or not public.has_account_role(target_account_id,array['owner','admin']::public.account_role[]) then raise exception 'owner or admin role required' using errcode='42501'; end if;
  if target_client_request_id is null or nullif(btrim(target_reason),'') is null then raise exception 'client request id and reason are required' using errcode='22023'; end if;
  perform pg_advisory_xact_lock(hashtextextended(target_account_id::text||':component-assignment',0));
  if not public.claim_component_configuration_request(target_account_id,target_client_request_id,'remove_assignment',target_assignment_id::text||':'||btrim(target_reason)) then
    select * into assignment from public.machine_component_assignments where id=target_assignment_id and account_id=target_account_id;
    if not found then raise exception 'machine component assignment not found' using errcode='P0002'; end if;
    return assignment;
  end if;
  select * into assignment from public.machine_component_assignments where id=target_assignment_id and account_id=target_account_id for update;
  if not found then raise exception 'machine component assignment not found' using errcode='P0002'; end if;
  if exists(select 1 from public.machine_component_lifecycles where machine_component_assignment_id=assignment.id) then
    raise exception 'component has lifecycle or replacement history and cannot be removed from the machine' using errcode='23514'; end if;
  if assignment.source_profile_id is not null then
    select * into existing_exclusion from public.machine_component_profile_exclusions where account_id=target_account_id and client_request_id=target_client_request_id;
    if found and (existing_exclusion.machine_id<>assignment.machine_id or lower(btrim(existing_exclusion.slot_code))<>lower(btrim(assignment.slot_code)) or existing_exclusion.reason<>btrim(target_reason)) then
      raise exception 'client request id was already used with different exclusion data' using errcode='23505'; end if;
    if not found then insert into public.machine_component_profile_exclusions(account_id,machine_id,model_component_profile_id,machine_model_id,slot_code,reason,excluded_by,client_request_id)
      select target_account_id,assignment.machine_id,assignment.source_profile_id,profile.machine_model_id,assignment.slot_code,btrim(target_reason),actor,target_client_request_id
      from public.machine_model_components profile where profile.id=assignment.source_profile_id
      on conflict (machine_id,(lower(btrim(slot_code)))) where cleared_at is null do nothing; end if;
  end if;
  update public.machine_component_assignments set status='retired' where id=assignment.id and status='configured' returning * into assignment;
  if assignment.id is null then select * into assignment from public.machine_component_assignments where id=target_assignment_id; end if;
  return assignment;
end $$;

create function public.clear_machine_component_exclusion(target_account_id uuid,target_machine_id uuid,target_profile_id uuid,target_client_request_id uuid)
returns public.machine_component_assignments language plpgsql security definer set search_path='' as $$
declare actor uuid:=auth.uid(); result public.machine_component_assignments%rowtype; profile_record public.machine_model_components%rowtype;
begin
  if actor is null or not public.has_account_role(target_account_id,array['owner','admin']::public.account_role[]) then raise exception 'owner or admin role required' using errcode='42501'; end if;
  if target_client_request_id is null then raise exception 'client request id is required' using errcode='22023'; end if;
  perform pg_advisory_xact_lock(hashtextextended(target_account_id::text||':component-assignment',0));
  if not public.claim_component_configuration_request(target_account_id,target_client_request_id,'clear_exclusion',target_machine_id::text||':'||target_profile_id::text) then
    select * into result from public.machine_component_assignments where machine_id=target_machine_id and source_profile_id=target_profile_id; return result;
  end if;
  select * into profile_record from public.machine_model_components where id=target_profile_id and (account_id is null or account_id=target_account_id);
  if not found then raise exception 'model profile not found for this workspace' using errcode='P0002'; end if;
  update public.machine_component_profile_exclusions set cleared_at=statement_timestamp(),cleared_by=actor
  where account_id=target_account_id and machine_id=target_machine_id
    and machine_model_id=profile_record.machine_model_id and lower(btrim(slot_code))=lower(btrim(profile_record.slot_code)) and cleared_at is null;
  perform public.sync_machine_component_assignments_internal(target_account_id,target_machine_id);
  select * into result from public.machine_component_assignments where machine_id=target_machine_id
    and lower(btrim(slot_code))=lower(btrim(profile_record.slot_code)) and status='configured';
  if not found then raise exception 'active profile cannot be restored to this machine' using errcode='23514'; end if;
  return result;
end $$;

create function public.sync_machine_component_assignments(target_account_id uuid,target_machine_id uuid default null)
returns integer language plpgsql security definer set search_path='' as $$
begin
  if auth.uid() is null or not public.has_account_role(target_account_id,array['owner','admin']::public.account_role[]) then raise exception 'owner or admin role required' using errcode='42501'; end if;
  return public.sync_machine_component_assignments_internal(target_account_id,target_machine_id);
end $$;

-- Assignment-based read projection. Absence of a lifecycle is UNKNOWN configuration, not a fabricated lifecycle row.
create view public.machine_component_configuration with (security_invoker=true) as
select assignment.id as assignment_id,assignment.account_id,assignment.branch_id,assignment.machine_id,
  machine.machine_code,machine.display_name as machine_name,assignment.source_type,assignment.source_profile_id as model_component_profile_id,
  assignment.component_id,component.code as component_code,component.name as component_name,assignment.slot_code,
  assignment.display_label,assignment.display_order,assignment.tracking_method,assignment.status as assignment_status,
  lifecycle.id as lifecycle_id,coalesce(lifecycle.status::text,'unknown') as lifecycle_status,lifecycle.installation_source,
  lifecycle.installed_counter,lifecycle.installed_at,lifecycle.baseline_expected_clicks_snapshot,lifecycle.expected_at_install,
  assignment.baseline_expected_clicks as current_profile_baseline,lifecycle.adaptive_expected_snapshot,
  coalesce(lifecycle.adaptive_expected_snapshot,lifecycle.expected_at_install,assignment.baseline_expected_clicks) as effective_expected,
  case when lifecycle.id is null then 'Not initialized' when lifecycle.adaptive_expected_snapshot is null then 'Baseline only' else 'Adaptive snapshot' end as expected_source,
  latest.reading_value as latest_effective_counter,latest.observed_at as latest_counter_observed_at,
  case when lifecycle.status='active' and latest.reading_value is not null then latest.reading_value-lifecycle.installed_counter end as current_usage,
  case when lifecycle.status='active' and latest.reading_value is not null then coalesce(lifecycle.adaptive_expected_snapshot,lifecycle.expected_at_install)-(latest.reading_value-lifecycle.installed_counter) end as remaining_clicks,
  case when lifecycle.status='active' and latest.reading_value is not null then round((coalesce(lifecycle.adaptive_expected_snapshot,lifecycle.expected_at_install)-(latest.reading_value-lifecycle.installed_counter))/coalesce(lifecycle.adaptive_expected_snapshot,lifecycle.expected_at_install)*100,2) end as remaining_percent,
  case when lifecycle.status='active' and latest.reading_value is not null then lifecycle.installed_counter+coalesce(lifecycle.adaptive_expected_snapshot,lifecycle.expected_at_install) end as estimated_replacement_counter,
  case when lifecycle.id is null or lifecycle.status<>'active' or latest.reading_value is null then 'unknown'
    when (coalesce(lifecycle.adaptive_expected_snapshot,lifecycle.expected_at_install)-(latest.reading_value-lifecycle.installed_counter))/coalesce(lifecycle.adaptive_expected_snapshot,lifecycle.expected_at_install)*100>assignment.healthy_threshold_percent then 'healthy'
    when (coalesce(lifecycle.adaptive_expected_snapshot,lifecycle.expected_at_install)-(latest.reading_value-lifecycle.installed_counter))/coalesce(lifecycle.adaptive_expected_snapshot,lifecycle.expected_at_install)*100>assignment.watch_threshold_percent then 'watch'
    when (coalesce(lifecycle.adaptive_expected_snapshot,lifecycle.expected_at_install)-(latest.reading_value-lifecycle.installed_counter))/coalesce(lifecycle.adaptive_expected_snapshot,lifecycle.expected_at_install)*100>assignment.warning_threshold_percent then 'warning'
    when (coalesce(lifecycle.adaptive_expected_snapshot,lifecycle.expected_at_install)-(latest.reading_value-lifecycle.installed_counter))/coalesce(lifecycle.adaptive_expected_snapshot,lifecycle.expected_at_install)*100>assignment.critical_threshold_percent then 'critical' else 'overdue' end as health_status,
  assignment.notes,assignment.created_at,assignment.updated_at
from public.machine_component_assignments assignment
join public.machines machine on machine.id=assignment.machine_id
join public.components component on component.id=assignment.component_id
left join lateral (select row.* from public.machine_component_lifecycles row where row.machine_component_assignment_id=assignment.id and row.status in ('unknown','active') order by (row.status='active') desc,row.created_at desc limit 1) lifecycle on true
left join lateral (select reading.reading_value,reading.observed_at from public.counter_readings reading join public.counter_types counter_type on counter_type.id=reading.counter_type_id where reading.account_id=assignment.account_id and reading.machine_id=assignment.machine_id and reading.status='effective' and lower(btrim(counter_type.code))='total_impressions' order by reading.observed_at desc,reading.created_at desc,reading.id desc limit 1) latest on true
where assignment.status='configured';

alter table public.machine_component_assignments enable row level security;
alter table public.machine_component_profile_exclusions enable row level security;
alter table public.component_configuration_requests enable row level security;
create policy machine_component_assignments_select_members on public.machine_component_assignments for select to authenticated using(public.is_account_member(account_id));
create policy machine_component_exclusions_select_members on public.machine_component_profile_exclusions for select to authenticated using(public.is_account_member(account_id));
revoke all on table public.machine_component_assignments,public.machine_component_profile_exclusions,public.machine_component_configuration from public,anon,authenticated,service_role;
revoke all on table public.component_configuration_requests from public,anon,authenticated,service_role;
grant select on table public.machine_component_assignments,public.machine_component_profile_exclusions,public.machine_component_configuration to authenticated,service_role;
grant select,insert,update,delete on table public.machine_component_assignments,public.machine_component_profile_exclusions to service_role;
grant select,insert,update,delete on table public.component_configuration_requests to service_role;

-- RPCs provide idempotent state changes for the application. Existing RLS-protected
-- column grants remain for backwards compatibility with accepted operational clients.

do $$ declare signature regprocedure; begin
  foreach signature in array array[
    'public.manage_machine_component_profile(uuid,uuid,text,uuid)'::regprocedure,
    'public.save_machine_model_component_profile(uuid,uuid,uuid,uuid,text,integer,public.component_tracking_method,bigint,boolean,numeric,numeric,numeric,numeric,text,uuid)'::regprocedure,
    'public.manage_component_catalog_status(uuid,uuid,text,uuid)'::regprocedure,
    'public.add_machine_component_assignment(uuid,uuid,uuid,text,public.component_tracking_method,bigint,text,uuid)'::regprocedure,
    'public.remove_machine_component_assignment(uuid,uuid,text,uuid)'::regprocedure,
    'public.clear_machine_component_exclusion(uuid,uuid,uuid,uuid)'::regprocedure,
    'public.sync_machine_component_assignments(uuid,uuid)'::regprocedure
  ] loop execute format('revoke all on function %s from public,anon,service_role',signature); execute format('grant execute on function %s to authenticated',signature); end loop;
end $$;

comment on table public.machine_component_assignments is 'Persistent physical-machine component slots. Model profiles provision them but never remain live truth.';
comment on table public.machine_component_profile_exclusions is 'Durable machine override preventing an inherited profile slot from being re-provisioned until explicitly cleared.';
comment on view public.machine_component_configuration is 'Configured machine slots with optional current lifecycle evidence; missing lifecycle is an explicit UNKNOWN state.';
