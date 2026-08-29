-- M2.10C: represent Operator and PIC Terlibat as distinct canonical people.
-- Existing incident snapshots remain untouched; these nullable fields apply to
-- new entries and future reviewed edits only.

alter table public.operational_incidents
  add column operator_person_id uuid,
  add column operator_name_snapshot text,
  add constraint operational_incidents_operator_person_fkey
    foreign key (operator_person_id, account_id)
    references public.operational_people(id, account_id)
    on delete restrict,
  add constraint operational_incidents_operator_name_not_blank
    check (operator_name_snapshot is null or btrim(operator_name_snapshot) <> '');

comment on column public.operational_incidents.operator_person_id is
  'Canonical Operational Person who performed/submitted the operational activity. Nullable for immutable historical rows.';
comment on column public.operational_incidents.operator_name_snapshot is
  'Immutable display-name snapshot captured with operator_person_id.';

create or replace function public.apply_incident_operator_person()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  configured text := current_setting('a3.incident_operator_person_id', true);
  person public.operational_people%rowtype;
begin
  if configured is null then
    return new;
  end if;

  if configured = '' then
    new.operator_person_id := null;
    new.operator_name_snapshot := null;
    return new;
  end if;

  select * into strict person
  from public.operational_people
  where id = configured::uuid
    and account_id = new.account_id;

  new.operator_person_id := person.id;
  new.operator_name_snapshot := person.name;
  return new;
end;
$$;

create trigger m210c_incident_apply_operator_person
before insert or update on public.operational_incidents
for each row execute function public.apply_incident_operator_person();

-- Extend the existing review guard only for the two new audited content fields.
create or replace function public.protect_operational_incident_history()
returns trigger
language plpgsql
set search_path = ''
as $$
declare
  actor_id uuid := (select auth.uid());
  authorized_edit_id text := current_setting('a3tracker.operational_incident_edit_id', true);
  editable_content_changed boolean;
begin
  if (to_jsonb(new) - array[
        'occurred_at', 'invoice_number', 'customer_name_snapshot',
        'product_name_snapshot', 'category', 'incident_type', 'machine_id',
        'qty_affected', 'operator_person_id', 'operator_name_snapshot',
        'responsible_user_id', 'responsible_name_snapshot', 'responsible_person_id',
        'material_loss', 'service_loss', 'description', 'cause', 'prevention',
        'customer_resolution', 'assessed_loss', 'status', 'updated_by',
        'updated_at', 'resolved_by', 'resolved_at', 'resolution_note',
        'voided_by', 'voided_at', 'void_reason'
      ]::text[])
      is distinct from
     (to_jsonb(old) - array[
        'occurred_at', 'invoice_number', 'customer_name_snapshot',
        'product_name_snapshot', 'category', 'incident_type', 'machine_id',
        'qty_affected', 'operator_person_id', 'operator_name_snapshot',
        'responsible_user_id', 'responsible_name_snapshot', 'responsible_person_id',
        'material_loss', 'service_loss', 'description', 'cause', 'prevention',
        'customer_resolution', 'assessed_loss', 'status', 'updated_by',
        'updated_at', 'resolved_by', 'resolved_at', 'resolution_note',
        'voided_by', 'voided_at', 'void_reason'
      ]::text[]) then
    raise exception 'protected operational incident fields are immutable'
      using errcode = '42501';
  end if;

  if old.status = 'voided' then
    raise exception 'voided operational incidents are immutable'
      using errcode = '42501';
  end if;

  editable_content_changed := (to_jsonb(new) - array[
      'id', 'account_id', 'branch_id', 'penalty_multiplier', 'assessed_loss',
      'client_request_id', 'created_by', 'created_at', 'status', 'updated_by',
      'updated_at', 'resolved_by', 'resolved_at', 'resolution_note',
      'voided_by', 'voided_at', 'void_reason'
    ]::text[])
    is distinct from
    (to_jsonb(old) - array[
      'id', 'account_id', 'branch_id', 'penalty_multiplier', 'assessed_loss',
      'client_request_id', 'created_by', 'created_at', 'status', 'updated_by',
      'updated_at', 'resolved_by', 'resolved_at', 'resolution_note',
      'voided_by', 'voided_at', 'void_reason'
    ]::text[]);

  if editable_content_changed then
    if old.status <> 'open' or new.status <> 'open' then
      raise exception 'only open operational incidents can be edited' using errcode = '42501';
    end if;
    if authorized_edit_id is distinct from old.id::text then
      raise exception 'posted operational incident content requires the review API' using errcode = '42501';
    end if;
    if new.resolved_by is distinct from old.resolved_by
      or new.resolved_at is distinct from old.resolved_at
      or new.resolution_note is distinct from old.resolution_note
      or new.voided_by is distinct from old.voided_by
      or new.voided_at is distinct from old.voided_at
      or new.void_reason is distinct from old.void_reason then
      raise exception 'lifecycle metadata cannot be changed during incident review' using errcode = '42501';
    end if;
  elsif new.status is distinct from old.status then
    if old.status = 'open' and new.status = 'resolved' then
      new.resolved_by := coalesce(actor_id, new.resolved_by);
      new.resolved_at := statement_timestamp();
      new.resolution_note := nullif(btrim(new.resolution_note), '');
      new.voided_by := null; new.voided_at := null; new.void_reason := null;
    elsif old.status in ('open', 'resolved') and new.status = 'voided' then
      if nullif(btrim(new.void_reason), '') is null then
        raise exception 'void reason is required' using errcode = '22023';
      end if;
      new.voided_by := coalesce(actor_id, new.voided_by);
      new.voided_at := statement_timestamp();
      new.void_reason := btrim(new.void_reason);
    else
      raise exception 'invalid operational incident lifecycle transition' using errcode = '42501';
    end if;
  else
    raise exception 'operational incident update requires an edit or lifecycle transition' using errcode = '42501';
  end if;

  new.updated_by := coalesce(actor_id, new.updated_by, old.updated_by);
  new.updated_at := statement_timestamp();
  return new;
end;
$$;

create function public.create_operational_incident_v2(
  target_account_id uuid, target_branch_id uuid, target_occurred_at timestamptz,
  target_category public.operational_incident_category,
  target_incident_type public.operational_incident_type, target_description text,
  target_client_request_id uuid, target_machine_id uuid default null,
  target_invoice_number text default null, target_customer_name text default null,
  target_product_name text default null, target_qty_affected integer default null,
  target_operator_person_id uuid default null,
  target_responsible_person_id uuid default null,
  target_material_loss numeric default 0, target_service_loss numeric default 0,
  target_cause text default null, target_prevention text default null,
  target_customer_resolution text default null
)
returns public.operational_incidents
language plpgsql security definer set search_path = ''
as $$
declare result public.operational_incidents%rowtype;
begin
  if target_operator_person_id is not null and not public.is_operational_person_valid_for_branch(
    target_account_id, target_operator_person_id, target_branch_id
  ) then
    raise exception 'Operator is not active and assigned to this Branch' using errcode = '23514';
  end if;
  perform set_config('a3.incident_operator_person_id', coalesce(target_operator_person_id::text, ''), true);
  result := public.create_operational_incident(
    target_account_id, target_branch_id, target_occurred_at, target_category,
    target_incident_type, target_description, target_client_request_id,
    target_machine_id, target_invoice_number, target_customer_name,
    target_product_name, target_qty_affected, target_responsible_person_id,
    null, target_material_loss, target_service_loss, target_cause,
    target_prevention, target_customer_resolution
  );
  perform set_config('a3.incident_operator_person_id', '', true);
  return result;
end;
$$;

create function public.update_operational_incident_v2(
  target_incident_id uuid, target_base_updated_at timestamptz,
  target_occurred_at timestamptz, target_category public.operational_incident_category,
  target_incident_type public.operational_incident_type, target_description text,
  target_machine_id uuid default null, target_invoice_number text default null,
  target_customer_name text default null, target_product_name text default null,
  target_qty_affected integer default null, target_operator_person_id uuid default null,
  target_responsible_person_id uuid default null, target_material_loss numeric default 0,
  target_service_loss numeric default 0, target_cause text default null,
  target_prevention text default null, target_customer_resolution text default null,
  target_change_reason text default null
)
returns public.operational_incidents
language plpgsql security definer set search_path = ''
as $$
declare
  actor_id uuid := (select auth.uid());
  old_incident public.operational_incidents%rowtype;
  result public.operational_incidents%rowtype;
  operator_changed boolean;
  caught_message text;
begin
  select * into old_incident from public.operational_incidents where id = target_incident_id;
  if not found then raise exception 'incident not found' using errcode = 'P0002'; end if;
  if target_operator_person_id is not null and not public.is_operational_person_valid_for_branch(
    old_incident.account_id, target_operator_person_id, old_incident.branch_id
  ) then
    raise exception 'Operator is not active and assigned to this Branch' using errcode = '23514';
  end if;
  operator_changed := old_incident.operator_person_id is distinct from target_operator_person_id;
  perform set_config('a3.incident_operator_person_id', coalesce(target_operator_person_id::text, ''), true);

  begin
    result := public.update_operational_incident(
      target_incident_id, target_base_updated_at, target_occurred_at, target_category,
      target_incident_type, target_description, target_machine_id,
      target_invoice_number, target_customer_name, target_product_name,
      target_qty_affected, target_responsible_person_id, null,
      target_material_loss, target_service_loss, target_cause,
      target_prevention, target_customer_resolution, target_change_reason
    );
  exception when sqlstate '22023' then
    get stacked diagnostics caught_message = message_text;
    if not operator_changed or caught_message <> 'no incident changes supplied' then raise; end if;
    if actor_id is null or old_incident.status <> 'open'
      or old_incident.updated_at is distinct from target_base_updated_at
      or not public.has_account_role(old_incident.account_id, array['owner','admin']::public.account_role[]) then
      raise;
    end if;
    perform set_config('a3tracker.operational_incident_edit_id', old_incident.id::text, true);
    update public.operational_incidents set updated_by = actor_id
    where id = old_incident.id returning * into result;
    insert into public.operational_incident_revisions(
      account_id, incident_id, changed_by, change_reason,
      old_values, new_values, changed_fields
    ) values (
      old_incident.account_id, old_incident.id, actor_id,
      nullif(btrim(target_change_reason), ''),
      jsonb_build_object('operator_person_id', old_incident.operator_person_id,
        'operator_name_snapshot', old_incident.operator_name_snapshot),
      jsonb_build_object('operator_person_id', result.operator_person_id,
        'operator_name_snapshot', result.operator_name_snapshot),
      array['operator_person_id','operator_name_snapshot']::text[]
    );
  end;

  if operator_changed and exists (
    select 1 from public.operational_incident_revisions
    where incident_id = result.id and changed_by = actor_id
      and changed_at = statement_timestamp()
      and not ('operator_person_id' = any(changed_fields))
  ) then
    update public.operational_incident_revisions
    set old_values = old_values || jsonb_build_object(
          'operator_person_id', old_incident.operator_person_id,
          'operator_name_snapshot', old_incident.operator_name_snapshot),
        new_values = new_values || jsonb_build_object(
          'operator_person_id', result.operator_person_id,
          'operator_name_snapshot', result.operator_name_snapshot),
        changed_fields = changed_fields || array['operator_person_id','operator_name_snapshot']::text[]
    where id = (
      select id from public.operational_incident_revisions
      where incident_id = result.id and changed_by = actor_id
        and changed_at = statement_timestamp()
      order by id desc limit 1
    );
  end if;

  perform set_config('a3.incident_operator_person_id', '', true);
  return result;
end;
$$;

revoke all on function public.apply_incident_operator_person(),
  public.create_operational_incident_v2(uuid,uuid,timestamptz,public.operational_incident_category,public.operational_incident_type,text,uuid,uuid,text,text,text,integer,uuid,uuid,numeric,numeric,text,text,text),
  public.update_operational_incident_v2(uuid,timestamptz,timestamptz,public.operational_incident_category,public.operational_incident_type,text,uuid,text,text,text,integer,uuid,uuid,numeric,numeric,text,text,text,text)
  from public, anon, authenticated, service_role;
grant execute on function
  public.create_operational_incident_v2(uuid,uuid,timestamptz,public.operational_incident_category,public.operational_incident_type,text,uuid,uuid,text,text,text,integer,uuid,uuid,numeric,numeric,text,text,text),
  public.update_operational_incident_v2(uuid,timestamptz,timestamptz,public.operational_incident_category,public.operational_incident_type,text,uuid,text,text,text,integer,uuid,uuid,numeric,numeric,text,text,text,text)
  to authenticated;
