-- M2.7A Settings foundation: workspace administration and operational policy.

create table public.account_operational_permissions (
  account_id uuid primary key references public.accounts(id) on delete cascade,
  operator_can_initialize_component boolean not null default false,
  operator_can_replace_component boolean not null default true,
  operator_can_create_purchase boolean not null default false,
  operator_can_receive_goods boolean not null default false,
  operator_can_adjust_inventory boolean not null default false,
  operator_can_transfer_inventory boolean not null default false,
  operator_can_log_errors boolean not null default true,
  created_at timestamptz not null default statement_timestamp(),
  created_by uuid references auth.users(id) on delete set null,
  updated_at timestamptz not null default statement_timestamp(),
  updated_by uuid references auth.users(id) on delete set null
);

comment on table public.account_operational_permissions is
  'Account-level Operator policy. Defaults reproduce the accepted pre-Settings authorization contract.';

insert into public.account_operational_permissions (account_id)
select account.id from public.accounts account
on conflict (account_id) do nothing;

create or replace function public.ensure_account_operational_permissions()
returns trigger language plpgsql security definer set search_path='' as $$
begin
  insert into public.account_operational_permissions(account_id,created_by,updated_by)
  values(new.id,new.created_by,new.created_by) on conflict(account_id) do nothing;
  return new;
end $$;
revoke all on function public.ensure_account_operational_permissions() from public,anon,authenticated,service_role;
create trigger accounts_ensure_operational_permissions
after insert on public.accounts for each row execute function public.ensure_account_operational_permissions();

create table public.settings_change_events (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  client_request_id uuid not null,
  action text not null,
  target_type text not null,
  target_id text,
  request_payload jsonb not null,
  before_state jsonb,
  after_state jsonb,
  actor_id uuid not null references auth.users(id) on delete restrict,
  actor_name_snapshot text not null,
  created_at timestamptz not null default statement_timestamp(),
  completed_at timestamptz,
  constraint settings_change_events_request_key unique(account_id,client_request_id),
  constraint settings_change_events_action_not_blank check(btrim(action)<>''),
  constraint settings_change_events_target_not_blank check(btrim(target_type)<>'')
);
create index settings_change_events_account_created_idx on public.settings_change_events(account_id,created_at desc);

create or replace function public.settings_actor_name(actor uuid)
returns text language sql stable security definer set search_path='' as $$
  select coalesce(nullif(btrim(profile.display_name),''),'User') from public.profiles profile where profile.user_id=actor
$$;
revoke all on function public.settings_actor_name(uuid) from public,anon,authenticated,service_role;

create or replace function public.claim_settings_change(
  target_account_id uuid,target_client_request_id uuid,target_action text,target_type text,target_payload jsonb
) returns boolean language plpgsql security definer set search_path='' as $$
declare actor uuid:=auth.uid(); inserted integer; existing public.settings_change_events%rowtype;
begin
  if actor is null then raise exception 'authentication required' using errcode='42501'; end if;
  if target_client_request_id is null then raise exception 'client request id is required' using errcode='22023'; end if;
  insert into public.settings_change_events(account_id,client_request_id,action,target_type,request_payload,actor_id,actor_name_snapshot)
  values(target_account_id,target_client_request_id,target_action,target_type,target_payload,actor,public.settings_actor_name(actor))
  on conflict(account_id,client_request_id) do nothing;
  get diagnostics inserted=row_count;
  if inserted=1 then return true; end if;
  select * into existing from public.settings_change_events
  where account_id=target_account_id and client_request_id=target_client_request_id;
  if existing.action<>target_action or existing.target_type<>target_type or existing.request_payload<>target_payload then
    raise exception 'client request id was already used with a different Settings mutation' using errcode='23505';
  end if;
  return false;
end $$;
revoke all on function public.claim_settings_change(uuid,uuid,text,text,jsonb) from public,anon,authenticated,service_role;

create or replace function public.finish_settings_change(
  target_account_id uuid,target_client_request_id uuid,result_target_id text,target_before jsonb,target_after jsonb
) returns void language sql security definer set search_path='' as $$
  update public.settings_change_events set target_id=result_target_id,before_state=target_before,
    after_state=target_after,completed_at=statement_timestamp()
  where account_id=target_account_id and client_request_id=target_client_request_id
$$;
revoke all on function public.finish_settings_change(uuid,uuid,text,jsonb,jsonb) from public,anon,authenticated,service_role;

create or replace function public.has_operational_capability(target_account_id uuid,target_capability text)
returns boolean language sql stable security definer set search_path='' as $$
  with actor as (
    select membership.role from public.account_memberships membership
    where membership.account_id=target_account_id and membership.user_id=auth.uid() and membership.status='active'
  ), policy as (
    select * from public.account_operational_permissions where account_id=target_account_id
  )
  select coalesce((select case
    when actor.role in ('owner','admin') then true
    when actor.role='technician' then target_capability in ('replace_component','log_errors')
    when actor.role='operator' then case target_capability
      when 'initialize_component' then policy.operator_can_initialize_component
      when 'replace_component' then policy.operator_can_replace_component
      when 'create_purchase' then policy.operator_can_create_purchase
      when 'receive_goods' then policy.operator_can_receive_goods
      when 'adjust_inventory' then policy.operator_can_adjust_inventory
      when 'transfer_inventory' then policy.operator_can_transfer_inventory
      when 'log_errors' then policy.operator_can_log_errors
      else false end
    else false end from actor cross join policy),false)
$$;
revoke all on function public.has_operational_capability(uuid,text) from public,anon,authenticated,service_role;
grant execute on function public.has_operational_capability(uuid,text) to authenticated;

-- A wrapper may authorize one legacy operational RPC for the current transaction.
create or replace function public.has_account_role(target_account_id uuid,allowed_roles public.account_role[])
returns boolean language sql stable security definer set search_path='' as $$
  select exists(
    select 1 from public.account_memberships membership
    where membership.account_id=target_account_id and membership.user_id=auth.uid()
      and membership.status='active' and membership.role=any(allowed_roles)
  ) or (
    current_setting('a3.operational_capability_override',true) like target_account_id::text||':%'
    and public.has_operational_capability(target_account_id,split_part(current_setting('a3.operational_capability_override',true),':',2))
  )
$$;

create or replace function public.manage_workspace_settings(
  target_account_id uuid,target_name text,target_default_timezone text,target_client_request_id uuid
) returns public.accounts language plpgsql security definer set search_path='' as $$
declare actor uuid:=auth.uid(); prior public.accounts%rowtype; result public.accounts%rowtype;
  payload jsonb:=jsonb_build_object('name',btrim(target_name),'default_timezone',target_default_timezone); claimed boolean;
begin
  if actor is null or not public.has_account_role(target_account_id,array['owner','admin']::public.account_role[]) then
    raise exception 'owner or admin role required' using errcode='42501'; end if;
  if nullif(btrim(target_name),'') is null or length(btrim(target_name))>120 then raise exception 'workspace name must contain 1 to 120 characters' using errcode='22023'; end if;
  if not public.is_valid_timezone(target_default_timezone) then raise exception 'valid IANA timezone required' using errcode='22023'; end if;
  perform 1 from public.accounts where id=target_account_id and status='active' for update;
  if not found then raise exception 'active account not found' using errcode='P0002'; end if;
  claimed:=public.claim_settings_change(target_account_id,target_client_request_id,'workspace.update','account',payload);
  if not claimed then select * into result from public.accounts where id=target_account_id; return result; end if;
  select * into prior from public.accounts where id=target_account_id;
  update public.accounts set name=btrim(target_name),default_timezone=target_default_timezone,updated_by=actor
  where id=target_account_id returning * into result;
  perform public.finish_settings_change(target_account_id,target_client_request_id,target_account_id::text,to_jsonb(prior),to_jsonb(result));
  return result;
end $$;

create or replace function public.manage_settings_branch(
  target_account_id uuid,target_branch_id uuid,target_action text,target_code text,target_name text,
  target_address text,target_timezone text,target_notes text,target_client_request_id uuid
) returns public.branches language plpgsql security definer set search_path='' as $$
declare actor uuid:=auth.uid(); prior public.branches%rowtype; result public.branches%rowtype; claimed boolean; existing_target text;
  payload jsonb:=jsonb_build_object('branch_id',target_branch_id,'action',target_action,'code',upper(btrim(target_code)),
    'name',btrim(target_name),'address',nullif(btrim(target_address),''),'timezone',nullif(target_timezone,''),'notes',nullif(btrim(target_notes),''));
begin
  if actor is null or not public.has_account_role(target_account_id,array['owner','admin']::public.account_role[]) then raise exception 'owner or admin role required' using errcode='42501'; end if;
  if target_action not in ('create','update','archive','restore') then raise exception 'invalid branch action' using errcode='22023'; end if;
  if target_action in ('create','update') and (nullif(btrim(target_code),'') is null or length(btrim(target_code))>32 or nullif(btrim(target_name),'') is null or length(btrim(target_name))>120) then
    raise exception 'branch code and name are required and too long' using errcode='22023'; end if;
  if nullif(target_timezone,'') is not null and not public.is_valid_timezone(target_timezone) then raise exception 'valid IANA timezone required' using errcode='22023'; end if;
  perform 1 from public.accounts where id=target_account_id and status='active' for update;
  if not found then raise exception 'active account not found' using errcode='P0002'; end if;
  claimed:=public.claim_settings_change(target_account_id,target_client_request_id,'branch.'||target_action,'branch',payload);
  if not claimed then
    select target_id into existing_target from public.settings_change_events where account_id=target_account_id and client_request_id=target_client_request_id;
    select * into result from public.branches where account_id=target_account_id and id=existing_target::uuid; return result;
  end if;
  if target_action='create' then
    insert into public.branches(account_id,code,name,address,timezone,notes,created_by,updated_by)
    values(target_account_id,upper(btrim(target_code)),btrim(target_name),nullif(btrim(target_address),''),nullif(target_timezone,''),nullif(btrim(target_notes),''),actor,actor)
    returning * into result;
  else
    select * into prior from public.branches where id=target_branch_id and account_id=target_account_id for update;
    if not found then raise exception 'branch not found' using errcode='P0002'; end if;
    if target_action='update' then update public.branches set code=upper(btrim(target_code)),name=btrim(target_name),address=nullif(btrim(target_address),''),timezone=nullif(target_timezone,''),notes=nullif(btrim(target_notes),''),updated_by=actor where id=prior.id returning * into result;
    elsif target_action='archive' then update public.branches set is_active=false,updated_by=actor where id=prior.id returning * into result;
    else update public.branches set is_active=true,updated_by=actor where id=prior.id returning * into result; end if;
  end if;
  perform public.finish_settings_change(target_account_id,target_client_request_id,result.id::text,to_jsonb(prior),to_jsonb(result)); return result;
end $$;

create or replace function public.get_settings_members(target_account_id uuid)
returns table(membership_id uuid,user_id uuid,display_name text,email text,role public.account_role,status public.membership_status,created_at timestamptz,accepted_at timestamptz)
language sql stable security definer set search_path='' as $$
  select membership.id,membership.user_id,profile.display_name,auth_user.email,membership.role,membership.status,membership.created_at,membership.accepted_at
  from public.account_memberships membership join public.profiles profile on profile.user_id=membership.user_id
  join auth.users auth_user on auth_user.id=membership.user_id
  where membership.account_id=target_account_id
    and public.has_account_role(target_account_id,array['owner','admin']::public.account_role[])
  order by (membership.role='owner') desc,profile.display_name
$$;

create or replace function public.manage_settings_membership(
  target_account_id uuid,target_user_id uuid,target_role public.account_role,target_status public.membership_status,target_client_request_id uuid
) returns public.account_memberships language plpgsql security definer set search_path='' as $$
declare prior public.account_memberships%rowtype; result public.account_memberships%rowtype; claimed boolean;
  payload jsonb:=jsonb_build_object('user_id',target_user_id,'role',target_role,'status',target_status);
begin
  if target_status not in ('active','suspended') then raise exception 'Settings supports active or suspended memberships only' using errcode='22023'; end if;
  perform 1 from public.accounts where id=target_account_id for update;
  if not found then raise exception 'account not found' using errcode='P0002'; end if;
  select * into prior from public.account_memberships where account_id=target_account_id and user_id=target_user_id;
  if not found then raise exception 'member onboarding is not available in Settings' using errcode='P0002'; end if;
  claimed:=public.claim_settings_change(target_account_id,target_client_request_id,'membership.update','membership',payload);
  if not claimed then select * into result from public.account_memberships where account_id=target_account_id and user_id=target_user_id; return result; end if;
  result:=public.manage_account_membership(target_account_id,target_user_id,target_role,target_status);
  perform public.finish_settings_change(target_account_id,target_client_request_id,result.id::text,to_jsonb(prior),to_jsonb(result)); return result;
end $$;

create or replace function public.manage_operational_permissions(
  target_account_id uuid,target_operator_can_initialize_component boolean,target_operator_can_replace_component boolean,
  target_operator_can_create_purchase boolean,target_operator_can_receive_goods boolean,target_operator_can_adjust_inventory boolean,
  target_operator_can_transfer_inventory boolean,target_operator_can_log_errors boolean,target_client_request_id uuid
) returns public.account_operational_permissions language plpgsql security definer set search_path='' as $$
declare actor uuid:=auth.uid(); prior public.account_operational_permissions%rowtype; result public.account_operational_permissions%rowtype; claimed boolean;
  payload jsonb:=jsonb_build_object('operator_can_initialize_component',target_operator_can_initialize_component,'operator_can_replace_component',target_operator_can_replace_component,'operator_can_create_purchase',target_operator_can_create_purchase,'operator_can_receive_goods',target_operator_can_receive_goods,'operator_can_adjust_inventory',target_operator_can_adjust_inventory,'operator_can_transfer_inventory',target_operator_can_transfer_inventory,'operator_can_log_errors',target_operator_can_log_errors);
begin
  if actor is null or not public.has_account_role(target_account_id,array['owner','admin']::public.account_role[]) then raise exception 'owner or admin role required' using errcode='42501'; end if;
  perform 1 from public.accounts where id=target_account_id and status='active' for update;
  if not found then raise exception 'active account not found' using errcode='P0002'; end if;
  select * into prior from public.account_operational_permissions where account_id=target_account_id for update;
  claimed:=public.claim_settings_change(target_account_id,target_client_request_id,'permissions.update','operational_policy',payload);
  if not claimed then select * into result from public.account_operational_permissions where account_id=target_account_id; return result; end if;
  update public.account_operational_permissions set operator_can_initialize_component=target_operator_can_initialize_component,
    operator_can_replace_component=target_operator_can_replace_component,operator_can_create_purchase=target_operator_can_create_purchase,
    operator_can_receive_goods=target_operator_can_receive_goods,operator_can_adjust_inventory=target_operator_can_adjust_inventory,
    operator_can_transfer_inventory=target_operator_can_transfer_inventory,operator_can_log_errors=target_operator_can_log_errors,
    updated_at=statement_timestamp(),updated_by=actor where account_id=target_account_id returning * into result;
  perform public.finish_settings_change(target_account_id,target_client_request_id,target_account_id::text,to_jsonb(prior),to_jsonb(result)); return result;
end $$;

create or replace function public.manage_advanced_economics_setting(target_account_id uuid,target_enabled boolean,target_client_request_id uuid)
returns boolean language plpgsql security definer set search_path='' as $$
declare prior boolean; claimed boolean;
begin
  select machine_economics_advanced_enabled into prior from public.accounts where id=target_account_id for update;
  claimed:=public.claim_settings_change(target_account_id,target_client_request_id,'advanced.machine_economics','account',jsonb_build_object('enabled',target_enabled));
  if not claimed then return (select machine_economics_advanced_enabled from public.accounts where id=target_account_id); end if;
  perform public.set_machine_economics_advanced_enabled(target_account_id,target_enabled);
  perform public.finish_settings_change(target_account_id,target_client_request_id,target_account_id::text,jsonb_build_object('enabled',prior),jsonb_build_object('enabled',target_enabled)); return target_enabled;
end $$;

alter table public.account_operational_permissions enable row level security;
alter table public.settings_change_events enable row level security;
create policy account_operational_permissions_select_members on public.account_operational_permissions for select to authenticated using(public.is_account_member(account_id));
create policy settings_change_events_select_admins on public.settings_change_events for select to authenticated using(public.has_account_role(account_id,array['owner','admin']::public.account_role[]));
revoke all on table public.account_operational_permissions,public.settings_change_events from public,anon,authenticated,service_role;
grant select on table public.account_operational_permissions,public.settings_change_events to authenticated,service_role;

revoke all on function public.manage_workspace_settings(uuid,text,text,uuid),public.manage_settings_branch(uuid,uuid,text,text,text,text,text,text,uuid),
  public.get_settings_members(uuid),public.manage_settings_membership(uuid,uuid,public.account_role,public.membership_status,uuid),
  public.manage_operational_permissions(uuid,boolean,boolean,boolean,boolean,boolean,boolean,boolean,uuid),
  public.manage_advanced_economics_setting(uuid,boolean,uuid) from public,anon,authenticated,service_role;
grant execute on function public.manage_workspace_settings(uuid,text,text,uuid),public.manage_settings_branch(uuid,uuid,text,text,text,text,text,text,uuid),
  public.get_settings_members(uuid),public.manage_settings_membership(uuid,uuid,public.account_role,public.membership_status,uuid),
  public.manage_operational_permissions(uuid,boolean,boolean,boolean,boolean,boolean,boolean,boolean,uuid),
  public.manage_advanced_economics_setting(uuid,boolean,uuid) to authenticated,service_role;

-- Preserve the existing operational implementations behind capability-aware wrappers.
alter function public.initialize_machine_component_lifecycle(uuid,uuid,uuid,numeric,timestamptz,uuid,text) rename to initialize_machine_component_lifecycle_m27a_base;
alter function public.replace_machine_component(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,public.component_removal_condition,boolean,uuid,text,text,uuid,public.component_replacement_inventory_source,uuid,uuid,numeric,text) rename to replace_machine_component_m27a_base;
alter function public.create_operational_incident(uuid,uuid,timestamptz,public.operational_incident_category,public.operational_incident_type,text,uuid,uuid,text,text,text,integer,uuid,text,numeric,numeric,text,text,text) rename to create_operational_incident_m27a_base;
alter function public.adjust_inventory_stock_costed(uuid,uuid,uuid,numeric,timestamptz,uuid,text,text,uuid,numeric) rename to adjust_inventory_stock_costed_m27a_base;
alter function public.transfer_inventory_stock(uuid,uuid,uuid,uuid,numeric,timestamptz,uuid,text,uuid) rename to transfer_inventory_stock_m27a_base;
alter function public.create_inventory_purchase_auto(uuid,uuid,date,text,text,text,jsonb,uuid) rename to create_inventory_purchase_auto_m27a_base;
alter function public.receive_inventory_purchase(uuid,uuid,uuid,timestamptz,uuid,text,jsonb,uuid) rename to receive_inventory_purchase_m27a_base;

create or replace function public.set_operational_override(target_account_id uuid,target_capability text)
returns void language plpgsql security definer set search_path='' as $$
begin
  if not public.has_operational_capability(target_account_id,target_capability) then raise exception 'operational capability is disabled for this role' using errcode='42501'; end if;
  perform set_config('a3.operational_capability_override',target_account_id::text||':'||target_capability,true);
end $$;
revoke all on function public.set_operational_override(uuid,text) from public,anon,authenticated,service_role;

create function public.initialize_machine_component_lifecycle(target_account_id uuid,target_machine_id uuid,target_model_component_profile_id uuid,target_installed_counter numeric default null,target_installed_at timestamptz default null,target_client_request_id uuid default null,target_notes text default null)
returns public.machine_component_lifecycles language plpgsql security definer set search_path='' as $$
declare result public.machine_component_lifecycles%rowtype;
begin
  perform public.set_operational_override(target_account_id,'initialize_component');
  result:=public.initialize_machine_component_lifecycle_m27a_base(target_account_id,target_machine_id,target_model_component_profile_id,target_installed_counter,target_installed_at,target_client_request_id,target_notes);
  perform set_config('a3.operational_capability_override','',true); return result;
end $$;
create function public.replace_machine_component(target_account_id uuid,target_machine_id uuid,target_lifecycle_id uuid,target_replacement_counter numeric,target_replaced_at timestamptz,target_replacement_reason public.component_replacement_reason,target_condition_at_removal public.component_removal_condition,target_include_in_adaptive_learning boolean,target_performed_by_user_id uuid,target_performed_by_name_snapshot text,target_notes text,target_client_request_id uuid,target_inventory_source public.component_replacement_inventory_source,target_inventory_item_id uuid,target_inventory_location_id uuid,target_inventory_quantity numeric,target_external_inventory_reason text)
returns public.component_replacement_events language plpgsql security definer set search_path='' as $$
declare result public.component_replacement_events%rowtype;
begin
  -- The base implementation retains machine_record and every for update lock.
  perform public.set_operational_override(target_account_id,'replace_component');
  result:=public.replace_machine_component_m27a_base(target_account_id,target_machine_id,target_lifecycle_id,target_replacement_counter,target_replaced_at,target_replacement_reason,target_condition_at_removal,target_include_in_adaptive_learning,target_performed_by_user_id,target_performed_by_name_snapshot,target_notes,target_client_request_id,target_inventory_source,target_inventory_item_id,target_inventory_location_id,target_inventory_quantity,target_external_inventory_reason);
  perform set_config('a3.operational_capability_override','',true); return result;
end $$;
create function public.create_operational_incident(target_account_id uuid,target_branch_id uuid,target_occurred_at timestamptz,target_category public.operational_incident_category,target_incident_type public.operational_incident_type,target_description text,target_client_request_id uuid,target_machine_id uuid default null,target_invoice_number text default null,target_customer_name text default null,target_product_name text default null,target_qty_affected integer default null,target_responsible_user_id uuid default null,target_responsible_name text default null,target_material_loss numeric default 0,target_service_loss numeric default 0,target_cause text default null,target_prevention text default null,target_customer_resolution text default null)
returns public.operational_incidents language plpgsql security definer set search_path='' as $$
declare result public.operational_incidents%rowtype;
begin
  perform public.set_operational_override(target_account_id,'log_errors');
  result:=public.create_operational_incident_m27a_base(target_account_id,target_branch_id,target_occurred_at,target_category,target_incident_type,target_description,target_client_request_id,target_machine_id,target_invoice_number,target_customer_name,target_product_name,target_qty_affected,target_responsible_user_id,target_responsible_name,target_material_loss,target_service_loss,target_cause,target_prevention,target_customer_resolution);
  perform set_config('a3.operational_capability_override','',true); return result;
end $$;
create function public.adjust_inventory_stock_costed(target_account_id uuid,target_inventory_item_id uuid,target_location_id uuid,target_quantity numeric,target_occurred_at timestamptz,target_operational_person_id uuid,target_reason text,target_notes text,target_client_request_id uuid,target_unit_cost numeric default null)
returns public.inventory_movements language plpgsql security definer set search_path='' as $$
declare result public.inventory_movements%rowtype;
begin
  perform public.set_operational_override(target_account_id,'adjust_inventory');
  result:=public.adjust_inventory_stock_costed_m27a_base(target_account_id,target_inventory_item_id,target_location_id,target_quantity,target_occurred_at,target_operational_person_id,target_reason,target_notes,target_client_request_id,target_unit_cost);
  perform set_config('a3.operational_capability_override','',true); return result;
end $$;
create function public.transfer_inventory_stock(target_account_id uuid,target_inventory_item_id uuid,target_source_location_id uuid,target_destination_location_id uuid,target_quantity numeric,target_occurred_at timestamptz,target_operational_person_id uuid,target_notes text,target_client_request_id uuid)
returns table(transfer_id uuid,transfer_out_id uuid,transfer_in_id uuid) language plpgsql security definer set search_path='' as $$
begin
  -- The base implementation retains every for update lock.
  perform public.set_operational_override(target_account_id,'transfer_inventory');
  return query select * from public.transfer_inventory_stock_m27a_base(target_account_id,target_inventory_item_id,target_source_location_id,target_destination_location_id,target_quantity,target_occurred_at,target_operational_person_id,target_notes,target_client_request_id);
  perform set_config('a3.operational_capability_override','',true);
end $$;
create function public.create_inventory_purchase_auto(target_account_id uuid,target_supplier_id uuid,target_purchase_date date,target_external_reference text,target_currency_code text,target_notes text,target_lines jsonb,target_client_request_id uuid)
returns public.inventory_purchases language plpgsql security definer set search_path='' as $$
declare result public.inventory_purchases%rowtype;
begin
  -- The base implementation retains pg_advisory_xact_lock serialization.
  perform public.set_operational_override(target_account_id,'create_purchase');
  result:=public.create_inventory_purchase_auto_m27a_base(target_account_id,target_supplier_id,target_purchase_date,target_external_reference,target_currency_code,target_notes,target_lines,target_client_request_id);
  perform set_config('a3.operational_capability_override','',true); return result;
end $$;
create function public.receive_inventory_purchase(target_account_id uuid,target_purchase_id uuid,target_location_id uuid,target_received_at timestamptz,target_operational_person_id uuid,target_notes text,target_lines jsonb,target_client_request_id uuid)
returns public.inventory_receipts language plpgsql security definer set search_path='' as $$
declare result public.inventory_receipts%rowtype;
begin
  -- The base implementation retains every for update lock.
  perform public.set_operational_override(target_account_id,'receive_goods');
  result:=public.receive_inventory_purchase_m27a_base(target_account_id,target_purchase_id,target_location_id,target_received_at,target_operational_person_id,target_notes,target_lines,target_client_request_id);
  perform set_config('a3.operational_capability_override','',true); return result;
end $$;

revoke all on function public.initialize_machine_component_lifecycle_m27a_base(uuid,uuid,uuid,numeric,timestamptz,uuid,text),public.replace_machine_component_m27a_base(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,public.component_removal_condition,boolean,uuid,text,text,uuid,public.component_replacement_inventory_source,uuid,uuid,numeric,text),public.create_operational_incident_m27a_base(uuid,uuid,timestamptz,public.operational_incident_category,public.operational_incident_type,text,uuid,uuid,text,text,text,integer,uuid,text,numeric,numeric,text,text,text),public.adjust_inventory_stock_costed_m27a_base(uuid,uuid,uuid,numeric,timestamptz,uuid,text,text,uuid,numeric),public.transfer_inventory_stock_m27a_base(uuid,uuid,uuid,uuid,numeric,timestamptz,uuid,text,uuid),public.create_inventory_purchase_auto_m27a_base(uuid,uuid,date,text,text,text,jsonb,uuid),public.receive_inventory_purchase_m27a_base(uuid,uuid,uuid,timestamptz,uuid,text,jsonb,uuid) from public,anon,authenticated,service_role;
revoke all on function public.initialize_machine_component_lifecycle(uuid,uuid,uuid,numeric,timestamptz,uuid,text),public.replace_machine_component(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,public.component_removal_condition,boolean,uuid,text,text,uuid,public.component_replacement_inventory_source,uuid,uuid,numeric,text),public.create_operational_incident(uuid,uuid,timestamptz,public.operational_incident_category,public.operational_incident_type,text,uuid,uuid,text,text,text,integer,uuid,text,numeric,numeric,text,text,text),public.adjust_inventory_stock_costed(uuid,uuid,uuid,numeric,timestamptz,uuid,text,text,uuid,numeric),public.transfer_inventory_stock(uuid,uuid,uuid,uuid,numeric,timestamptz,uuid,text,uuid),public.create_inventory_purchase_auto(uuid,uuid,date,text,text,text,jsonb,uuid),public.receive_inventory_purchase(uuid,uuid,uuid,timestamptz,uuid,text,jsonb,uuid) from public,anon,authenticated,service_role;
grant execute on function public.initialize_machine_component_lifecycle(uuid,uuid,uuid,numeric,timestamptz,uuid,text),public.replace_machine_component(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,public.component_removal_condition,boolean,uuid,text,text,uuid,public.component_replacement_inventory_source,uuid,uuid,numeric,text),public.create_operational_incident(uuid,uuid,timestamptz,public.operational_incident_category,public.operational_incident_type,text,uuid,uuid,text,text,text,integer,uuid,text,numeric,numeric,text,text,text),public.adjust_inventory_stock_costed(uuid,uuid,uuid,numeric,timestamptz,uuid,text,text,uuid,numeric),public.transfer_inventory_stock(uuid,uuid,uuid,uuid,numeric,timestamptz,uuid,text,uuid),public.create_inventory_purchase_auto(uuid,uuid,date,text,text,text,jsonb,uuid),public.receive_inventory_purchase(uuid,uuid,uuid,timestamptz,uuid,text,jsonb,uuid) to authenticated,service_role;
