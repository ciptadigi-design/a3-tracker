-- A3 Tracker V2 - Product Patch M2.2.1
-- Tenant-safe review, revision history, and deliberate solve workflow.

alter table public.operational_incident_revisions enable row level security;

create policy operational_incident_revisions_select_account_members
on public.operational_incident_revisions
for select
to authenticated
using (public.is_account_member(account_id));

create or replace function public.update_operational_incident(
  target_incident_id uuid,
  target_base_updated_at timestamptz,
  target_occurred_at timestamptz,
  target_category public.operational_incident_category,
  target_incident_type public.operational_incident_type,
  target_description text,
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
  target_customer_resolution text default null,
  target_change_reason text default null
)
returns public.operational_incidents
language plpgsql
security definer
set search_path = ''
as $$
declare
  actor_id uuid := (select auth.uid());
  old_incident public.operational_incidents%rowtype;
  new_incident public.operational_incidents%rowtype;
  normalized_description text := nullif(btrim(target_description), '');
  normalized_invoice text := nullif(btrim(target_invoice_number), '');
  normalized_customer text := nullif(btrim(target_customer_name), '');
  normalized_product text := nullif(btrim(target_product_name), '');
  normalized_responsible_name text := nullif(btrim(target_responsible_name), '');
  normalized_cause text := nullif(btrim(target_cause), '');
  normalized_prevention text := nullif(btrim(target_prevention), '');
  normalized_customer_resolution text := nullif(btrim(target_customer_resolution), '');
  normalized_change_reason text := nullif(btrim(target_change_reason), '');
  changed_fields text[] := array[]::text[];
  old_snapshot jsonb;
  new_snapshot jsonb;
begin
  if actor_id is null then
    raise exception 'authentication required' using errcode = '42501';
  end if;

  select incident.*
  into old_incident
  from public.operational_incidents as incident
  where incident.id = target_incident_id
  for update;

  if not found then
    raise exception 'operational incident not found' using errcode = 'P0002';
  end if;

  if not public.has_account_role(
    old_incident.account_id,
    array['owner', 'admin']::public.account_role[]
  ) then
    raise exception 'owner or admin role required to edit incidents'
      using errcode = '42501';
  end if;

  if old_incident.status <> 'open' then
    raise exception 'only an open incident can be edited' using errcode = '42501';
  end if;

  if target_base_updated_at is null
    or old_incident.updated_at is distinct from target_base_updated_at then
    raise exception 'incident changed since this draft was created'
      using errcode = '40001';
  end if;

  if target_occurred_at is null
    or target_occurred_at > statement_timestamp() + interval '5 minutes' then
    raise exception 'valid incident date and time are required' using errcode = '22007';
  end if;

  if normalized_description is null then
    raise exception 'incident description is required' using errcode = '22023';
  end if;

  if target_qty_affected is not null and target_qty_affected <= 0 then
    raise exception 'affected quantity must be greater than zero' using errcode = '22003';
  end if;

  if coalesce(target_material_loss, 0) < 0
    or coalesce(target_service_loss, 0) < 0 then
    raise exception 'loss values cannot be negative' using errcode = '22003';
  end if;

  if target_machine_id is not null and not exists (
    select 1
    from public.machines as machine
    where machine.id = target_machine_id
      and machine.account_id = old_incident.account_id
      and machine.branch_id = old_incident.branch_id
  ) then
    raise exception 'machine not found in this account and branch' using errcode = 'P0002';
  end if;

  if target_responsible_user_id is not null
    and target_responsible_user_id is distinct from old_incident.responsible_user_id then
    if not exists (
      select 1
      from public.account_memberships as membership
      where membership.account_id = old_incident.account_id
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

  if old_incident.occurred_at is distinct from target_occurred_at then changed_fields := array_append(changed_fields, 'occurred_at'); end if;
  if old_incident.invoice_number is distinct from normalized_invoice then changed_fields := array_append(changed_fields, 'invoice_number'); end if;
  if old_incident.customer_name_snapshot is distinct from normalized_customer then changed_fields := array_append(changed_fields, 'customer_name_snapshot'); end if;
  if old_incident.product_name_snapshot is distinct from normalized_product then changed_fields := array_append(changed_fields, 'product_name_snapshot'); end if;
  if old_incident.category is distinct from target_category then changed_fields := array_append(changed_fields, 'category'); end if;
  if old_incident.incident_type is distinct from target_incident_type then changed_fields := array_append(changed_fields, 'incident_type'); end if;
  if old_incident.machine_id is distinct from target_machine_id then changed_fields := array_append(changed_fields, 'machine_id'); end if;
  if old_incident.qty_affected is distinct from target_qty_affected then changed_fields := array_append(changed_fields, 'qty_affected'); end if;
  if old_incident.responsible_user_id is distinct from target_responsible_user_id then changed_fields := array_append(changed_fields, 'responsible_user_id'); end if;
  if old_incident.responsible_name_snapshot is distinct from normalized_responsible_name then changed_fields := array_append(changed_fields, 'responsible_name_snapshot'); end if;
  if old_incident.material_loss is distinct from coalesce(target_material_loss, 0) then changed_fields := array_append(changed_fields, 'material_loss'); end if;
  if old_incident.service_loss is distinct from coalesce(target_service_loss, 0) then changed_fields := array_append(changed_fields, 'service_loss'); end if;
  if old_incident.description is distinct from normalized_description then changed_fields := array_append(changed_fields, 'description'); end if;
  if old_incident.cause is distinct from normalized_cause then changed_fields := array_append(changed_fields, 'cause'); end if;
  if old_incident.prevention is distinct from normalized_prevention then changed_fields := array_append(changed_fields, 'prevention'); end if;
  if old_incident.customer_resolution is distinct from normalized_customer_resolution then changed_fields := array_append(changed_fields, 'customer_resolution'); end if;

  if cardinality(changed_fields) = 0 then
    raise exception 'no incident changes supplied' using errcode = '22023';
  end if;

  old_snapshot := jsonb_build_object(
    'occurred_at', old_incident.occurred_at,
    'invoice_number', old_incident.invoice_number,
    'customer_name_snapshot', old_incident.customer_name_snapshot,
    'product_name_snapshot', old_incident.product_name_snapshot,
    'category', old_incident.category,
    'incident_type', old_incident.incident_type,
    'machine_id', old_incident.machine_id,
    'qty_affected', old_incident.qty_affected,
    'responsible_user_id', old_incident.responsible_user_id,
    'responsible_name_snapshot', old_incident.responsible_name_snapshot,
    'material_loss', old_incident.material_loss,
    'service_loss', old_incident.service_loss,
    'assessed_loss', old_incident.assessed_loss,
    'description', old_incident.description,
    'cause', old_incident.cause,
    'prevention', old_incident.prevention,
    'customer_resolution', old_incident.customer_resolution
  );

  perform set_config(
    'a3tracker.operational_incident_edit_id',
    old_incident.id::text,
    true
  );

  update public.operational_incidents
  set occurred_at = target_occurred_at,
      invoice_number = normalized_invoice,
      customer_name_snapshot = normalized_customer,
      product_name_snapshot = normalized_product,
      category = target_category,
      incident_type = target_incident_type,
      machine_id = target_machine_id,
      qty_affected = target_qty_affected,
      responsible_user_id = target_responsible_user_id,
      responsible_name_snapshot = normalized_responsible_name,
      material_loss = coalesce(target_material_loss, 0),
      service_loss = coalesce(target_service_loss, 0),
      description = normalized_description,
      cause = normalized_cause,
      prevention = normalized_prevention,
      customer_resolution = normalized_customer_resolution,
      updated_by = actor_id
  where id = old_incident.id
  returning * into new_incident;

  new_snapshot := jsonb_build_object(
    'occurred_at', new_incident.occurred_at,
    'invoice_number', new_incident.invoice_number,
    'customer_name_snapshot', new_incident.customer_name_snapshot,
    'product_name_snapshot', new_incident.product_name_snapshot,
    'category', new_incident.category,
    'incident_type', new_incident.incident_type,
    'machine_id', new_incident.machine_id,
    'qty_affected', new_incident.qty_affected,
    'responsible_user_id', new_incident.responsible_user_id,
    'responsible_name_snapshot', new_incident.responsible_name_snapshot,
    'material_loss', new_incident.material_loss,
    'service_loss', new_incident.service_loss,
    'assessed_loss', new_incident.assessed_loss,
    'description', new_incident.description,
    'cause', new_incident.cause,
    'prevention', new_incident.prevention,
    'customer_resolution', new_incident.customer_resolution
  );

  insert into public.operational_incident_revisions (
    account_id, incident_id, changed_by, change_reason,
    old_values, new_values, changed_fields
  ) values (
    old_incident.account_id, old_incident.id, actor_id, normalized_change_reason,
    old_snapshot, new_snapshot, changed_fields
  );

  return new_incident;
end;
$$;

create or replace function public.solve_operational_incident(
  target_incident_id uuid,
  target_resolution_note text default null
)
returns public.operational_incidents
language plpgsql
security definer
set search_path = ''
as $$
declare
  actor_id uuid := (select auth.uid());
  normalized_note text := nullif(btrim(target_resolution_note), '');
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
    raise exception 'owner, admin, or technician role required to solve incidents'
      using errcode = '42501';
  end if;

  if target_incident.status <> 'open' then
    raise exception 'only an open incident can be solved' using errcode = '42501';
  end if;

  update public.operational_incidents
  set status = 'resolved',
      resolution_note = normalized_note,
      updated_by = actor_id
  where id = target_incident.id
  returning * into target_incident;

  return target_incident;
end;
$$;

grant select on table public.operational_incident_revisions to authenticated;
grant select, insert on table public.operational_incident_revisions to service_role;

revoke all on function public.update_operational_incident(
  uuid, timestamptz, timestamptz, public.operational_incident_category,
  public.operational_incident_type, text, uuid, text, text, text, integer,
  uuid, text, numeric, numeric, text, text, text, text
) from public, anon, authenticated, service_role;

revoke all on function public.solve_operational_incident(uuid, text)
  from public, anon, authenticated, service_role;

grant execute on function public.update_operational_incident(
  uuid, timestamptz, timestamptz, public.operational_incident_category,
  public.operational_incident_type, text, uuid, text, text, text, integer,
  uuid, text, numeric, numeric, text, text, text, text
) to authenticated;

grant execute on function public.solve_operational_incident(uuid, text)
  to authenticated;
