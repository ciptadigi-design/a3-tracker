-- A3 Tracker V2 - Product Milestone M2.1
-- Append-only cumulative machine counter readings.

create type public.counter_reading_status as enum (
  'effective',
  'voided',
  'superseded'
);

-- Required for tenant-safe composite references from counter_readings.
create unique index machines_id_account_id_key
  on public.machines (id, account_id);

create table public.counter_readings (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  machine_id uuid not null,
  counter_type_id uuid not null references public.counter_types(id) on delete restrict,
  reading_value numeric(20, 4) not null,
  observed_at timestamptz not null,
  shift_code text,
  entered_by uuid not null references auth.users(id) on delete restrict,
  source text not null default 'manual',
  previous_reading_id uuid,
  corrects_reading_id uuid,
  status public.counter_reading_status not null default 'effective',
  correction_reason text,
  notes text,
  client_request_id uuid not null,
  created_at timestamptz not null default statement_timestamp(),
  created_by uuid not null default auth.uid() references auth.users(id) on delete restrict,
  constraint counter_readings_machine_account_fkey
    foreign key (machine_id, account_id)
    references public.machines(id, account_id)
    on delete restrict,
  constraint counter_readings_value_nonnegative check (reading_value >= 0),
  constraint counter_readings_shift_code_valid check (
    shift_code is null or shift_code in ('S1', 'S2')
  ),
  constraint counter_readings_source_not_blank check (btrim(source) <> ''),
  constraint counter_readings_correction_reason_consistent check (
    (status = 'effective' and correction_reason is null)
    or (
      status in ('voided', 'superseded')
      and nullif(btrim(correction_reason), '') is not null
    )
  ),
  constraint counter_readings_correction_source_consistent check (
    corrects_reading_id is null or source = 'correction'
  )
);

create unique index counter_readings_id_tenant_stream_key
  on public.counter_readings (id, account_id, machine_id, counter_type_id);

alter table public.counter_readings
  add constraint counter_readings_previous_same_stream_fkey
  foreign key (previous_reading_id, account_id, machine_id, counter_type_id)
  references public.counter_readings(id, account_id, machine_id, counter_type_id)
  on delete restrict;

alter table public.counter_readings
  add constraint counter_readings_correction_same_stream_fkey
  foreign key (corrects_reading_id, account_id, machine_id, counter_type_id)
  references public.counter_readings(id, account_id, machine_id, counter_type_id)
  on delete restrict;

create unique index counter_readings_account_client_request_key
  on public.counter_readings (account_id, client_request_id);

create unique index counter_readings_one_replacement_per_reading_key
  on public.counter_readings (corrects_reading_id)
  where corrects_reading_id is not null;

create index counter_readings_effective_history_idx
  on public.counter_readings (
    account_id,
    machine_id,
    counter_type_id,
    observed_at desc,
    created_at desc
  )
  where status = 'effective';

create index counter_readings_machine_history_idx
  on public.counter_readings (account_id, machine_id, observed_at desc, created_at desc);

create or replace function public.protect_counter_reading_history()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  if (to_jsonb(new) - 'status' - 'correction_reason')
      is distinct from
     (to_jsonb(old) - 'status' - 'correction_reason') then
    raise exception 'historical counter reading values are immutable'
      using errcode = '42501';
  end if;

  if old.status <> 'effective'
      or new.status not in ('voided', 'superseded')
      or nullif(btrim(new.correction_reason), '') is null then
    raise exception 'invalid counter reading correction transition'
      using errcode = '42501';
  end if;

  return new;
end;
$$;

revoke all on function public.protect_counter_reading_history() from public;

create trigger counter_readings_protect_history
before update on public.counter_readings
for each row execute function public.protect_counter_reading_history();

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
  case
    when previous.id is null then null
    else reading.reading_value - previous.reading_value
  end as usage,
  reading.observed_at,
  reading.shift_code,
  reading.entered_by,
  reading.source,
  reading.status,
  reading.correction_reason,
  reading.notes,
  reading.client_request_id,
  reading.previous_reading_id,
  reading.corrects_reading_id,
  reading.created_at
from public.counter_readings as reading
join public.counter_types as counter_type on counter_type.id = reading.counter_type_id
left join public.counter_readings as previous on previous.id = reading.previous_reading_id;

comment on view public.machine_counter_history is
  'Tenant-filtered counter history. Usage is derived as reading_value minus the explicitly linked previous effective reading.';
