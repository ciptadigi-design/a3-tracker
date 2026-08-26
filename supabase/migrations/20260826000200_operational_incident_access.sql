-- A3 Tracker V2 - Product Milestone M2.2
-- Tenant RLS and controlled operational incident lifecycle APIs.

alter table public.operational_incidents enable row level security;

create policy operational_incidents_select_account_members
on public.operational_incidents
for select
to authenticated
using (public.is_account_member(account_id));

create or replace function public.create_operational_incident(
  target_account_id uuid,
  target_branch_id uuid,
  target_occurred_at timestamptz,
  target_category public.operational_incident_category,
  target_incident_type public.operational_incident_type,
  target_description text,
  target_client_request_id uuid,
  target_machine_id uuid default null,
  target_invoice_number text default null,
  target_customer_name text default null,
  target_product_name text default null,
  target_qty_affected integer default null,
  target_responsible_user_id uuid default null,
  target_responsible_name text default null,
  target_material_loss numeric default 0,
  target_service_loss numeric default 0,
  target_cause text default null,
  target_prevention text default null,
  target_customer_resolution text default null
)
returns public.operational_incidents
language plpgsql
security definer
set search_path = ''
as $$
declare
  actor_id uuid := (select auth.uid());
  existing_incident public.operational_incidents%rowtype;
  result_incident public.operational_incidents%rowtype;
  normalized_description text := nullif(btrim(target_description), '');
  normalized_invoice text := nullif(btrim(target_invoice_number), '');
  normalized_customer text := nullif(btrim(target_customer_name), '');
  normalized_product text := nullif(btrim(target_product_name), '');
  normalized_responsible_name text := nullif(btrim(target_responsible_name), '');
  normalized_cause text := nullif(btrim(target_cause), '');
  normalized_prevention text := nullif(btrim(target_prevention), '');
  normalized_customer_resolution text := nullif(btrim(target_customer_resolution), '');
begin
  if actor_id is null then
    raise exception 'authentication required' using errcode = '42501';
  end if;

  if not public.has_account_role(
    target_account_id,
    array['owner', 'admin', 'technician', 'operator']::public.account_role[]
  ) then
    raise exception 'active account membership required' using errcode = '42501';
  end if;

  if target_client_request_id is null then
    raise exception 'client request id is required' using errcode = '22023';
  end if;

  if target_occurred_at is null or target_occurred_at > statement_timestamp() + interval '5 minutes' then
    raise exception 'valid incident date and time are required' using errcode = '22007';
  end if;

  if normalized_description is null then
    raise exception 'incident description is required' using errcode = '22023';
  end if;

  if target_qty_affected is not null and target_qty_affected <= 0 then
    raise exception 'affected quantity must be greater than zero' using errcode = '22003';
  end if;

  if coalesce(target_material_loss, 0) < 0 or coalesce(target_service_loss, 0) < 0 then
    raise exception 'loss values cannot be negative' using errcode = '22003';
  end if;

  perform 1
  from public.branches as branch
  where branch.id = target_branch_id
    and branch.account_id = target_account_id
    and branch.is_active;

  if not found then
    raise exception 'active branch not found in this account' using errcode = 'P0002';
  end if;

  if target_machine_id is not null then
    perform 1
    from public.machines as machine
    where machine.id = target_machine_id
      and machine.account_id = target_account_id
      and machine.branch_id = target_branch_id;

    if not found then
      raise exception 'machine not found in this account and branch' using errcode = 'P0002';
    end if;
  end if;

  if target_responsible_user_id is not null then
    if not exists (
      select 1
      from public.account_memberships as membership
      where membership.account_id = target_account_id
        and membership.user_id = target_responsible_user_id
        and membership.status in ('invited', 'active')
    ) then
      raise exception 'responsible user is not a current account member' using errcode = '23503';
    end if;

    if normalized_responsible_name is null then
      select profile.display_name
      into normalized_responsible_name
      from public.profiles as profile
      where profile.user_id = target_responsible_user_id;
    end if;
  end if;

  select incident.*
  into existing_incident
  from public.operational_incidents as incident
  where incident.account_id = target_account_id
    and incident.client_request_id = target_client_request_id;

  if found then
    if existing_incident.branch_id = target_branch_id
      and existing_incident.machine_id is not distinct from target_machine_id
      and existing_incident.occurred_at = target_occurred_at
      and existing_incident.category = target_category
      and existing_incident.incident_type = target_incident_type
      and existing_incident.description = normalized_description
      and existing_incident.invoice_number is not distinct from normalized_invoice
      and existing_incident.customer_name_snapshot is not distinct from normalized_customer
      and existing_incident.product_name_snapshot is not distinct from normalized_product
      and existing_incident.qty_affected is not distinct from target_qty_affected
      and existing_incident.responsible_user_id is not distinct from target_responsible_user_id
      and existing_incident.responsible_name_snapshot is not distinct from normalized_responsible_name
      and existing_incident.material_loss = coalesce(target_material_loss, 0)
      and existing_incident.service_loss = coalesce(target_service_loss, 0)
      and existing_incident.cause is not distinct from normalized_cause
      and existing_incident.prevention is not distinct from normalized_prevention
      and existing_incident.customer_resolution is not distinct from normalized_customer_resolution then
      return existing_incident;
    end if;

    raise exception 'client request id was already used for a different incident'
      using errcode = '23505';
  end if;

  insert into public.operational_incidents (
    account_id,
    branch_id,
    machine_id,
    occurred_at,
    invoice_number,
    customer_name_snapshot,
    product_name_snapshot,
    category,
    incident_type,
    qty_affected,
    responsible_user_id,
    responsible_name_snapshot,
    material_loss,
    service_loss,
    penalty_multiplier,
    description,
    cause,
    prevention,
    customer_resolution,
    client_request_id,
    created_by,
    updated_by
  ) values (
    target_account_id,
    target_branch_id,
    target_machine_id,
    target_occurred_at,
    normalized_invoice,
    normalized_customer,
    normalized_product,
    target_category,
    target_incident_type,
    target_qty_affected,
    target_responsible_user_id,
    normalized_responsible_name,
    coalesce(target_material_loss, 0),
    coalesce(target_service_loss, 0),
    1,
    normalized_description,
    normalized_cause,
    normalized_prevention,
    normalized_customer_resolution,
    target_client_request_id,
    actor_id,
    actor_id
  )
  returning * into result_incident;

  return result_incident;
end;
$$;

create or replace function public.resolve_operational_incident(target_incident_id uuid)
returns public.operational_incidents
language plpgsql
security definer
set search_path = ''
as $$
declare
  actor_id uuid := (select auth.uid());
  target_incident public.operational_incidents%rowtype;
begin
  if actor_id is null then
    raise exception 'authentication required' using errcode = '42501';
  end if;

  select incident.*
  into target_incident
  from public.operational_incidents as incident
  where incident.id = target_incident_id
  for update;

  if not found then
    raise exception 'operational incident not found' using errcode = 'P0002';
  end if;

  if not public.has_account_role(
    target_incident.account_id,
    array['owner', 'admin', 'technician']::public.account_role[]
  ) then
    raise exception 'owner, admin, or technician role required to resolve incidents'
      using errcode = '42501';
  end if;

  if target_incident.status = 'resolved' then
    return target_incident;
  end if;

  if target_incident.status <> 'open' then
    raise exception 'only an open incident can be resolved' using errcode = '22023';
  end if;

  update public.operational_incidents
  set status = 'resolved', updated_by = actor_id
  where id = target_incident.id
  returning * into target_incident;

  return target_incident;
end;
$$;

create or replace function public.void_operational_incident(
  target_incident_id uuid,
  target_void_reason text
)
returns public.operational_incidents
language plpgsql
security definer
set search_path = ''
as $$
declare
  actor_id uuid := (select auth.uid());
  normalized_reason text := nullif(btrim(target_void_reason), '');
  target_incident public.operational_incidents%rowtype;
begin
  if actor_id is null then
    raise exception 'authentication required' using errcode = '42501';
  end if;

  if normalized_reason is null then
    raise exception 'void reason is required' using errcode = '22023';
  end if;

  select incident.*
  into target_incident
  from public.operational_incidents as incident
  where incident.id = target_incident_id
  for update;

  if not found then
    raise exception 'operational incident not found' using errcode = 'P0002';
  end if;

  if not public.has_account_role(
    target_incident.account_id,
    array['owner', 'admin']::public.account_role[]
  ) then
    raise exception 'owner or admin role required to void incidents'
      using errcode = '42501';
  end if;

  if target_incident.status = 'voided' then
    if target_incident.void_reason = normalized_reason then
      return target_incident;
    end if;
    raise exception 'incident is already voided' using errcode = '22023';
  end if;

  update public.operational_incidents
  set status = 'voided', void_reason = normalized_reason, updated_by = actor_id
  where id = target_incident.id
  returning * into target_incident;

  return target_incident;
end;
$$;

revoke all on type public.operational_incident_category from public;
revoke all on type public.operational_incident_type from public;
revoke all on type public.operational_incident_status from public;
grant usage on type public.operational_incident_category to authenticated, service_role;
grant usage on type public.operational_incident_type to authenticated, service_role;
grant usage on type public.operational_incident_status to authenticated, service_role;

revoke all on table public.operational_incidents from public, anon, authenticated, service_role;
grant select on table public.operational_incidents to authenticated;
grant select, insert, update on table public.operational_incidents to service_role;

revoke all on function public.protect_operational_incident_history()
  from public, anon, authenticated, service_role;
revoke all on function public.create_operational_incident(
  uuid, uuid, timestamptz, public.operational_incident_category,
  public.operational_incident_type, text, uuid, uuid, text, text, text,
  integer, uuid, text, numeric, numeric, text, text, text
) from public, anon, authenticated, service_role;
revoke all on function public.resolve_operational_incident(uuid)
  from public, anon, authenticated, service_role;
revoke all on function public.void_operational_incident(uuid, text)
  from public, anon, authenticated, service_role;

grant execute on function public.create_operational_incident(
  uuid, uuid, timestamptz, public.operational_incident_category,
  public.operational_incident_type, text, uuid, uuid, text, text, text,
  integer, uuid, text, numeric, numeric, text, text, text
) to authenticated;
grant execute on function public.resolve_operational_incident(uuid) to authenticated;
grant execute on function public.void_operational_incident(uuid, text) to authenticated;
