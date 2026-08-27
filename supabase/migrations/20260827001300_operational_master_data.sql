-- A3 Tracker V2 - M2.3E operational master data.
-- Adds account-owned catalog records, an operational people directory, and
-- operator snapshots for controlled Daily counter submissions.

alter table public.manufacturers
  add column account_id uuid references public.accounts(id) on delete restrict;

alter table public.machine_models
  add column account_id uuid references public.accounts(id) on delete restrict,
  add column description text;

drop index public.manufacturers_code_normalized_key;
create unique index manufacturers_shared_code_normalized_key
  on public.manufacturers (lower(btrim(code))) where account_id is null;
create unique index manufacturers_account_code_normalized_key
  on public.manufacturers (account_id, lower(btrim(code))) where account_id is not null;
create index manufacturers_account_active_idx on public.manufacturers (account_id, is_active);
drop index public.machine_models_manufacturer_model_code_normalized_key;
create unique index machine_models_shared_code_normalized_key
  on public.machine_models (manufacturer_id, lower(btrim(model_code))) where account_id is null;
create unique index machine_models_account_code_normalized_key
  on public.machine_models (account_id, manufacturer_id, lower(btrim(model_code))) where account_id is not null;
create index machine_models_account_active_idx on public.machine_models (account_id, is_active);

create or replace function public.validate_scoped_machine_catalog()
returns trigger
language plpgsql
set search_path = ''
as $$
declare
  manufacturer_account_id uuid;
begin
  select manufacturer.account_id into manufacturer_account_id
  from public.manufacturers manufacturer
  where manufacturer.id = new.manufacturer_id;

  if not found then
    raise exception 'manufacturer not found' using errcode = '23503';
  end if;

  if new.account_id is null and manufacturer_account_id is not null then
    raise exception 'shared models must use a shared manufacturer' using errcode = '23514';
  end if;

  if new.account_id is not null
      and manufacturer_account_id is not null
      and manufacturer_account_id <> new.account_id then
    raise exception 'workspace model and manufacturer must belong to the same account'
      using errcode = '23514';
  end if;

  return new;
end;
$$;

create trigger machine_models_validate_scope
before insert or update of manufacturer_id, account_id on public.machine_models
for each row execute function public.validate_scoped_machine_catalog();

create or replace function public.validate_machine_model_assignment()
returns trigger
language plpgsql
set search_path = ''
as $$
declare
  model_account_id uuid;
  model_is_active boolean;
begin
  if tg_op = 'UPDATE' and new.machine_model_id = old.machine_model_id then
    return new;
  end if;

  select model.account_id, model.is_active
  into model_account_id, model_is_active
  from public.machine_models model
  where model.id = new.machine_model_id;

  if not found or not model_is_active then
    raise exception 'active machine model not found' using errcode = '23503';
  end if;

  if model_account_id is not null and model_account_id <> new.account_id then
    raise exception 'machine model belongs to another account' using errcode = '23503';
  end if;

  return new;
end;
$$;

create trigger machines_validate_model_assignment
before insert or update of machine_model_id, account_id on public.machines
for each row execute function public.validate_machine_model_assignment();

create or replace function public.validate_component_catalog_scope()
returns trigger
language plpgsql
set search_path = ''
as $$
declare
  referenced_account_id uuid;
begin
  if tg_table_name = 'components' then
    if new.manufacturer_id is null then return new; end if;
    select manufacturer.account_id into referenced_account_id
    from public.manufacturers manufacturer where manufacturer.id = new.manufacturer_id;
  else
    select model.account_id into referenced_account_id
    from public.machine_models model where model.id = new.machine_model_id;
  end if;

  if not found then raise exception 'catalog reference not found' using errcode = '23503'; end if;
  if new.account_id is null and referenced_account_id is not null then
    raise exception 'shared records may only reference shared catalog records' using errcode = '23514';
  end if;
  if new.account_id is not null and referenced_account_id is not null and referenced_account_id <> new.account_id then
    raise exception 'catalog reference belongs to another account' using errcode = '23514';
  end if;
  return new;
end;
$$;

create trigger components_validate_manufacturer_scope
before insert or update of account_id, manufacturer_id on public.components
for each row execute function public.validate_component_catalog_scope();
create trigger machine_model_components_validate_model_scope
before insert or update of account_id, machine_model_id on public.machine_model_components
for each row execute function public.validate_component_catalog_scope();

create table public.operational_people (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  name text not null,
  linked_user_id uuid references auth.users(id) on delete set null,
  code text,
  is_active boolean not null default true,
  notes text,
  created_at timestamptz not null default statement_timestamp(),
  created_by uuid default auth.uid() references auth.users(id) on delete set null,
  updated_at timestamptz not null default statement_timestamp(),
  updated_by uuid default auth.uid() references auth.users(id) on delete set null,
  archived_at timestamptz,
  archived_by uuid references auth.users(id) on delete set null,
  constraint operational_people_id_account_key unique (id, account_id),
  constraint operational_people_name_not_blank check (btrim(name) <> ''),
  constraint operational_people_code_not_blank check (code is null or btrim(code) <> ''),
  constraint operational_people_archive_fields_consistent check (
    (is_active and archived_at is null and archived_by is null)
    or (not is_active and archived_at is not null)
  )
);

create unique index operational_people_account_name_normalized_key
  on public.operational_people (account_id, lower(btrim(name)));
create unique index operational_people_account_code_normalized_key
  on public.operational_people (account_id, lower(btrim(code)))
  where code is not null;
create index operational_people_account_active_idx
  on public.operational_people (account_id, is_active, name);

create trigger operational_people_set_audit_fields
before update on public.operational_people
for each row execute function public.set_archivable_catalog_audit_fields();

create or replace function public.validate_operational_person_link()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  if new.linked_user_id is not null and not exists (
    select 1 from public.account_memberships membership
    where membership.account_id = new.account_id
      and membership.user_id = new.linked_user_id
  ) then
    raise exception 'linked user must belong to the same account' using errcode = '23503';
  end if;
  return new;
end;
$$;

create trigger operational_people_validate_link
before insert or update of account_id, linked_user_id on public.operational_people
for each row execute function public.validate_operational_person_link();

alter table public.counter_readings
  add column operator_person_id uuid,
  add column operator_name_snapshot text,
  add constraint counter_readings_operator_account_fkey
    foreign key (operator_person_id, account_id)
    references public.operational_people(id, account_id) on delete restrict,
  add constraint counter_readings_operator_snapshot_consistent check (
    (operator_person_id is null and operator_name_snapshot is null)
    or (operator_person_id is not null and nullif(btrim(operator_name_snapshot), '') is not null)
  );

drop view public.machine_counter_history;
create view public.machine_counter_history
with (security_invoker = true)
as
select
  reading.id as reading_id,
  reading.account_id,
  reading.machine_id,
  reading.counter_type_id,
  counter_type.code as counter_type_code,
  counter_type.name as counter_type_name,
  counter_type.unit,
  reading.reading_value,
  previous.reading_value as previous_value,
  case when previous.id is null then null else reading.reading_value - previous.reading_value end as usage,
  reading.observed_at,
  reading.shift_code,
  reading.operator_person_id,
  reading.operator_name_snapshot,
  reading.entered_by,
  reading.created_by,
  reading.source,
  reading.status,
  reading.correction_reason,
  reading.notes,
  reading.client_request_id,
  reading.previous_reading_id,
  reading.corrects_reading_id,
  reading.created_at
from public.counter_readings reading
join public.counter_types counter_type on counter_type.id = reading.counter_type_id
left join public.counter_readings previous on previous.id = reading.previous_reading_id;

comment on view public.machine_counter_history is
  'Tenant-filtered counter history with immutable operator snapshots and database-derived usage.';

create or replace function public.record_machine_counter(
  target_account_id uuid,
  target_machine_id uuid,
  target_reading_value numeric,
  target_observed_at timestamptz,
  target_client_request_id uuid,
  target_operator_person_id uuid,
  target_shift_code text default null,
  target_notes text default null,
  target_counter_type_code text default 'total_impressions'
)
returns public.counter_readings
language plpgsql
security definer
set search_path = ''
as $$
declare
  actor_id uuid := (select auth.uid());
  counter_type_record public.counter_types%rowtype;
  previous_reading public.counter_readings%rowtype;
  existing_reading public.counter_readings%rowtype;
  result_reading public.counter_readings%rowtype;
  operator_record public.operational_people%rowtype;
  normalized_shift text := nullif(upper(btrim(target_shift_code)), '');
  normalized_notes text := nullif(btrim(target_notes), '');
begin
  if actor_id is null then raise exception 'authentication required' using errcode = '42501'; end if;
  if not public.has_account_role(target_account_id, array['owner','admin','technician','operator']::public.account_role[])
    then raise exception 'active account membership required' using errcode = '42501'; end if;
  if target_reading_value is null or target_reading_value < 0
    then raise exception 'counter value must be zero or greater' using errcode = '22003'; end if;
  if target_observed_at is null or target_observed_at > statement_timestamp() + interval '5 minutes'
    then raise exception 'observed date and time are invalid' using errcode = '22007'; end if;
  if normalized_shift is not null and normalized_shift not in ('S1','S2')
    then raise exception 'shift must be S1, S2, or blank' using errcode = '22023'; end if;

  select person.* into operator_record from public.operational_people person
  where person.id = target_operator_person_id and person.account_id = target_account_id and person.is_active;
  if not found then raise exception 'active PIC / Operator not found in this account' using errcode = 'P0002'; end if;

  perform 1 from public.machines machine
  where machine.id = target_machine_id and machine.account_id = target_account_id and machine.is_active for update;
  if not found then raise exception 'active machine not found in this account' using errcode = 'P0002'; end if;

  select counter_type.* into counter_type_record from public.counter_types counter_type
  where lower(btrim(counter_type.code)) = lower(btrim(target_counter_type_code)) and counter_type.is_active;
  if not found then raise exception 'active counter type not found' using errcode = 'P0002'; end if;
  if round(target_reading_value, counter_type_record.decimal_scale) <> target_reading_value
    then raise exception 'counter value has too many decimal places' using errcode = '22003'; end if;

  select reading.* into existing_reading from public.counter_readings reading
  where reading.account_id = target_account_id and reading.client_request_id = target_client_request_id;
  if found then
    if existing_reading.machine_id = target_machine_id
      and existing_reading.counter_type_id = counter_type_record.id
      and existing_reading.reading_value = target_reading_value
      and existing_reading.observed_at = target_observed_at
      and existing_reading.operator_person_id = target_operator_person_id
      and existing_reading.shift_code is not distinct from normalized_shift
      and existing_reading.notes is not distinct from normalized_notes then return existing_reading; end if;
    raise exception 'client request id was already used for a different counter submission' using errcode = '23505';
  end if;

  select reading.* into previous_reading from public.counter_readings reading
  where reading.account_id = target_account_id and reading.machine_id = target_machine_id
    and reading.counter_type_id = counter_type_record.id and reading.status = 'effective'
  order by reading.observed_at desc, reading.created_at desc, reading.id desc limit 1;
  if previous_reading.id is not null and target_observed_at < previous_reading.observed_at
    then raise exception 'counter reading is older than the latest effective reading' using errcode = '22007'; end if;
  if counter_type_record.is_monotonic and previous_reading.id is not null
      and target_reading_value < previous_reading.reading_value
    then raise exception 'counter regression: new reading must be at least the previous reading' using errcode = '22003'; end if;

  insert into public.counter_readings (
    account_id, machine_id, counter_type_id, reading_value, observed_at, shift_code,
    operator_person_id, operator_name_snapshot, entered_by, source,
    previous_reading_id, notes, client_request_id, created_by
  ) values (
    target_account_id, target_machine_id, counter_type_record.id, target_reading_value,
    target_observed_at, normalized_shift, operator_record.id, operator_record.name,
    actor_id, 'manual', previous_reading.id, normalized_notes, target_client_request_id, actor_id
  ) returning * into result_reading;
  return result_reading;
end;
$$;

-- Corrections preserve the operational operator while keeping the correcting
-- authenticated actor in entered_by/created_by.
create or replace function public.correct_machine_counter(
  target_reading_id uuid,
  target_correction_reason text,
  target_replacement_value numeric default null,
  target_client_request_id uuid default null,
  target_replacement_notes text default null
)
returns public.counter_readings
language plpgsql
security definer
set search_path = ''
as $$
declare
  actor_id uuid := (select auth.uid()); target_reading public.counter_readings%rowtype;
  previous_reading public.counter_readings%rowtype; existing_replacement public.counter_readings%rowtype;
  result_reading public.counter_readings%rowtype; counter_type_record public.counter_types%rowtype;
  latest_effective_id uuid; normalized_reason text := nullif(btrim(target_correction_reason), '');
  normalized_notes text := nullif(btrim(target_replacement_notes), '');
begin
  if actor_id is null then raise exception 'authentication required' using errcode = '42501'; end if;
  select reading.* into target_reading from public.counter_readings reading where reading.id = target_reading_id;
  if not found then raise exception 'counter reading not found' using errcode = 'P0002'; end if;
  if not public.has_account_role(target_reading.account_id, array['owner','admin']::public.account_role[])
    then raise exception 'owner or admin role required for counter correction' using errcode = '42501'; end if;
  if normalized_reason is null then raise exception 'correction reason is required' using errcode = '22023'; end if;
  if target_client_request_id is not null then
    select reading.* into existing_replacement from public.counter_readings reading
    where reading.account_id = target_reading.account_id and reading.client_request_id = target_client_request_id;
    if found then
      if existing_replacement.corrects_reading_id = target_reading_id and existing_replacement.reading_value = target_replacement_value
        then return existing_replacement; end if;
      raise exception 'client request id was already used for a different correction' using errcode = '23505';
    end if;
  end if;
  perform 1 from public.machines machine where machine.id = target_reading.machine_id and machine.account_id = target_reading.account_id for update;
  select reading.* into target_reading from public.counter_readings reading where reading.id = target_reading_id for update;
  if target_reading.status = 'voided' and target_replacement_value is null and target_reading.correction_reason = normalized_reason then return target_reading; end if;
  if target_reading.status <> 'effective' then raise exception 'only an effective reading can be corrected' using errcode = '22023'; end if;
  select reading.id into latest_effective_id from public.counter_readings reading
  where reading.account_id = target_reading.account_id and reading.machine_id = target_reading.machine_id
    and reading.counter_type_id = target_reading.counter_type_id and reading.status = 'effective'
  order by reading.observed_at desc, reading.created_at desc, reading.id desc limit 1;
  if latest_effective_id <> target_reading.id then raise exception 'only the latest effective reading can be corrected' using errcode = '22023'; end if;
  if target_replacement_value is null then
    update public.counter_readings set status='voided', correction_reason=normalized_reason where id=target_reading.id returning * into result_reading;
    return result_reading;
  end if;
  if target_client_request_id is null then raise exception 'client request id is required for a replacement reading' using errcode = '22023'; end if;
  if target_replacement_value < 0 then raise exception 'counter value must be zero or greater' using errcode = '22003'; end if;
  select counter_type.* into counter_type_record from public.counter_types counter_type where counter_type.id=target_reading.counter_type_id;
  if round(target_replacement_value,counter_type_record.decimal_scale)<>target_replacement_value
    then raise exception 'counter value has too many decimal places' using errcode='22003'; end if;
  if target_reading.previous_reading_id is not null then select reading.* into previous_reading from public.counter_readings reading where reading.id=target_reading.previous_reading_id; end if;
  if counter_type_record.is_monotonic and previous_reading.id is not null and target_replacement_value<previous_reading.reading_value
    then raise exception 'counter regression: replacement reading must be at least the previous reading' using errcode='22003'; end if;
  update public.counter_readings set status='superseded', correction_reason=normalized_reason where id=target_reading.id;
  insert into public.counter_readings (
    account_id,machine_id,counter_type_id,reading_value,observed_at,shift_code,
    operator_person_id,operator_name_snapshot,entered_by,source,previous_reading_id,
    corrects_reading_id,notes,client_request_id,created_by
  ) values (
    target_reading.account_id,target_reading.machine_id,target_reading.counter_type_id,target_replacement_value,
    target_reading.observed_at,target_reading.shift_code,target_reading.operator_person_id,target_reading.operator_name_snapshot,
    actor_id,'correction',target_reading.previous_reading_id,target_reading.id,
    coalesce(normalized_notes,target_reading.notes),target_client_request_id,actor_id
  ) returning * into result_reading;
  return result_reading;
end;
$$;

-- Replace catalog policies with shared + workspace-aware access.
drop policy manufacturers_select_active_authenticated on public.manufacturers;
drop policy machine_models_select_active_authenticated on public.machine_models;

create policy manufacturers_select_scoped_members on public.manufacturers for select to authenticated
using ((account_id is null and is_active) or (account_id is not null and public.is_account_member(account_id)));
create policy manufacturers_insert_owner_admin on public.manufacturers for insert to authenticated
with check (account_id is not null and public.has_account_role(account_id,array['owner','admin']::public.account_role[]));
create policy manufacturers_update_owner_admin on public.manufacturers for update to authenticated
using (account_id is not null and public.has_account_role(account_id,array['owner','admin']::public.account_role[]))
with check (account_id is not null and public.has_account_role(account_id,array['owner','admin']::public.account_role[]));
create policy manufacturers_delete_owner_admin on public.manufacturers for delete to authenticated
using (account_id is not null and public.has_account_role(account_id,array['owner','admin']::public.account_role[]));

create policy machine_models_select_scoped_members on public.machine_models for select to authenticated
using ((account_id is null and is_active) or (account_id is not null and public.is_account_member(account_id)));
create policy machine_models_insert_owner_admin on public.machine_models for insert to authenticated
with check (account_id is not null and public.has_account_role(account_id,array['owner','admin']::public.account_role[]));
create policy machine_models_update_owner_admin on public.machine_models for update to authenticated
using (account_id is not null and public.has_account_role(account_id,array['owner','admin']::public.account_role[]))
with check (account_id is not null and public.has_account_role(account_id,array['owner','admin']::public.account_role[]));
create policy machine_models_delete_owner_admin on public.machine_models for delete to authenticated
using (account_id is not null and public.has_account_role(account_id,array['owner','admin']::public.account_role[]));

alter table public.operational_people enable row level security;
create policy operational_people_select_members on public.operational_people for select to authenticated
using (public.is_account_member(account_id));
create policy operational_people_insert_owner_admin on public.operational_people for insert to authenticated
with check (public.has_account_role(account_id,array['owner','admin']::public.account_role[]));
create policy operational_people_update_owner_admin on public.operational_people for update to authenticated
using (public.has_account_role(account_id,array['owner','admin']::public.account_role[]))
with check (public.has_account_role(account_id,array['owner','admin']::public.account_role[]));
create policy operational_people_delete_owner_admin on public.operational_people for delete to authenticated
using (public.has_account_role(account_id,array['owner','admin']::public.account_role[]));

grant insert (account_id,code,name,website,notes,is_active) on public.manufacturers to authenticated;
grant update (code,name,website,notes,is_active) on public.manufacturers to authenticated;
grant delete on public.manufacturers to authenticated;
grant insert (account_id,manufacturer_id,model_code,name,machine_category,color_capability,description,notes,is_active) on public.machine_models to authenticated;
grant update (manufacturer_id,model_code,name,machine_category,color_capability,description,notes,is_active) on public.machine_models to authenticated;
grant delete on public.machine_models to authenticated;

revoke all on table public.operational_people from public,anon,authenticated,service_role;
grant select on public.operational_people to authenticated;
grant insert (account_id,name,linked_user_id,code,is_active,notes) on public.operational_people to authenticated;
grant update (name,linked_user_id,code,is_active,notes) on public.operational_people to authenticated;
grant delete on public.operational_people to authenticated;
grant select,insert,update,delete on public.operational_people to service_role;
grant delete on public.manufacturers,public.machine_models to service_role;

revoke all on function public.validate_scoped_machine_catalog() from public,anon,authenticated,service_role;
revoke all on function public.validate_machine_model_assignment() from public,anon,authenticated,service_role;
revoke all on function public.validate_operational_person_link() from public,anon,authenticated,service_role;
revoke all on function public.validate_component_catalog_scope() from public,anon,authenticated,service_role;
revoke all on function public.record_machine_counter(uuid,uuid,numeric,timestamptz,uuid,uuid,text,text,text)
  from public,anon,authenticated,service_role;
grant execute on function public.record_machine_counter(uuid,uuid,numeric,timestamptz,uuid,uuid,text,text,text)
  to authenticated;

revoke all on table public.machine_counter_history from public,anon,authenticated,service_role;
grant select on public.machine_counter_history to authenticated,service_role;

comment on column public.manufacturers.account_id is 'NULL for protected shared platform records; populated for workspace-owned records.';
comment on column public.machine_models.account_id is 'NULL for protected shared platform records; populated for workspace-owned records.';
comment on table public.operational_people is 'Account-scoped operational people/PIC directory reusable by operational workflows.';
