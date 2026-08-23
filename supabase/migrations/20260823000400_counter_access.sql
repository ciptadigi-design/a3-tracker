-- A3 Tracker V2 - Product Milestone M2.1
-- Counter RLS, controlled write APIs, and least-privilege grants.

alter table public.counter_readings enable row level security;

create policy counter_readings_select_account_members
on public.counter_readings
for select
to authenticated
using (public.is_account_member(account_id));

create or replace function public.record_machine_counter(
  target_account_id uuid,
  target_machine_id uuid,
  target_reading_value numeric,
  target_observed_at timestamptz,
  target_client_request_id uuid,
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
  normalized_shift text := nullif(upper(btrim(target_shift_code)), '');
  normalized_notes text := nullif(btrim(target_notes), '');
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

  if target_reading_value is null or target_reading_value < 0 then
    raise exception 'counter value must be zero or greater' using errcode = '22003';
  end if;

  if target_observed_at is null then
    raise exception 'observed date and time are required' using errcode = '22007';
  end if;

  if target_observed_at > statement_timestamp() + interval '5 minutes' then
    raise exception 'observed date and time cannot be in the future' using errcode = '22007';
  end if;

  if normalized_shift is not null and normalized_shift not in ('S1', 'S2') then
    raise exception 'shift must be S1, S2, or blank' using errcode = '22023';
  end if;

  -- Serialize submissions for the machine so previous-reading resolution and
  -- monotonic validation remain atomic under concurrent requests.
  perform 1
  from public.machines as machine
  where machine.id = target_machine_id
    and machine.account_id = target_account_id
    and machine.is_active
  for update;

  if not found then
    raise exception 'active machine not found in this account' using errcode = 'P0002';
  end if;

  select counter_type.*
  into counter_type_record
  from public.counter_types as counter_type
  where lower(btrim(counter_type.code)) = lower(btrim(target_counter_type_code))
    and counter_type.is_active;

  if not found then
    raise exception 'active counter type not found' using errcode = 'P0002';
  end if;

  if round(target_reading_value, counter_type_record.decimal_scale) <> target_reading_value then
    raise exception 'counter value has more decimal places than this counter type allows'
      using errcode = '22003';
  end if;

  select reading.*
  into existing_reading
  from public.counter_readings as reading
  where reading.account_id = target_account_id
    and reading.client_request_id = target_client_request_id;

  if found then
    if existing_reading.machine_id = target_machine_id
      and existing_reading.counter_type_id = counter_type_record.id
      and existing_reading.reading_value = target_reading_value
      and existing_reading.observed_at = target_observed_at
      and existing_reading.shift_code is not distinct from normalized_shift
      and existing_reading.notes is not distinct from normalized_notes then
      return existing_reading;
    end if;

    raise exception 'client request id was already used for a different counter submission'
      using errcode = '23505';
  end if;

  select reading.*
  into previous_reading
  from public.counter_readings as reading
  where reading.account_id = target_account_id
    and reading.machine_id = target_machine_id
    and reading.counter_type_id = counter_type_record.id
    and reading.status = 'effective'
  order by reading.observed_at desc, reading.created_at desc, reading.id desc
  limit 1;

  if previous_reading.id is not null and target_observed_at < previous_reading.observed_at then
    raise exception 'counter reading is older than the latest effective reading'
      using errcode = '22007';
  end if;

  if counter_type_record.is_monotonic
      and previous_reading.id is not null
      and target_reading_value < previous_reading.reading_value then
    raise exception 'counter regression: new reading % must be at least previous reading %',
      target_reading_value, previous_reading.reading_value
      using errcode = '22003';
  end if;

  insert into public.counter_readings (
    account_id,
    machine_id,
    counter_type_id,
    reading_value,
    observed_at,
    shift_code,
    entered_by,
    source,
    previous_reading_id,
    notes,
    client_request_id,
    created_by
  ) values (
    target_account_id,
    target_machine_id,
    counter_type_record.id,
    target_reading_value,
    target_observed_at,
    normalized_shift,
    actor_id,
    'manual',
    previous_reading.id,
    normalized_notes,
    target_client_request_id,
    actor_id
  )
  returning * into result_reading;

  return result_reading;
end;
$$;

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
  actor_id uuid := (select auth.uid());
  target_reading public.counter_readings%rowtype;
  previous_reading public.counter_readings%rowtype;
  existing_replacement public.counter_readings%rowtype;
  result_reading public.counter_readings%rowtype;
  counter_type_record public.counter_types%rowtype;
  latest_effective_id uuid;
  normalized_reason text := nullif(btrim(target_correction_reason), '');
  normalized_notes text := nullif(btrim(target_replacement_notes), '');
begin
  if actor_id is null then
    raise exception 'authentication required' using errcode = '42501';
  end if;

  select reading.*
  into target_reading
  from public.counter_readings as reading
  where reading.id = target_reading_id;

  if not found then
    raise exception 'counter reading not found' using errcode = 'P0002';
  end if;

  if not public.has_account_role(
    target_reading.account_id,
    array['owner', 'admin']::public.account_role[]
  ) then
    raise exception 'owner or admin role required for counter correction'
      using errcode = '42501';
  end if;

  if normalized_reason is null then
    raise exception 'correction reason is required' using errcode = '22023';
  end if;

  if target_client_request_id is not null then
    select reading.*
    into existing_replacement
    from public.counter_readings as reading
    where reading.account_id = target_reading.account_id
      and reading.client_request_id = target_client_request_id;

    if found then
      if existing_replacement.corrects_reading_id = target_reading_id
        and existing_replacement.reading_value = target_replacement_value then
        return existing_replacement;
      end if;

      raise exception 'client request id was already used for a different correction'
        using errcode = '23505';
    end if;
  end if;

  perform 1
  from public.machines as machine
  where machine.id = target_reading.machine_id
    and machine.account_id = target_reading.account_id
  for update;

  select reading.*
  into target_reading
  from public.counter_readings as reading
  where reading.id = target_reading_id
  for update;

  if target_reading.status = 'voided'
      and target_replacement_value is null
      and target_reading.correction_reason = normalized_reason then
    return target_reading;
  end if;

  if target_reading.status <> 'effective' then
    raise exception 'only an effective reading can be corrected'
      using errcode = '22023';
  end if;

  select reading.id
  into latest_effective_id
  from public.counter_readings as reading
  where reading.account_id = target_reading.account_id
    and reading.machine_id = target_reading.machine_id
    and reading.counter_type_id = target_reading.counter_type_id
    and reading.status = 'effective'
  order by reading.observed_at desc, reading.created_at desc, reading.id desc
  limit 1;

  if latest_effective_id <> target_reading.id then
    raise exception 'only the latest effective reading can be corrected in M2.1'
      using errcode = '22023';
  end if;

  if target_replacement_value is null then
    update public.counter_readings
    set status = 'voided', correction_reason = normalized_reason
    where id = target_reading.id
    returning * into result_reading;

    return result_reading;
  end if;

  if target_client_request_id is null then
    raise exception 'client request id is required for a replacement reading'
      using errcode = '22023';
  end if;

  if target_replacement_value < 0 then
    raise exception 'counter value must be zero or greater' using errcode = '22003';
  end if;

  select counter_type.*
  into counter_type_record
  from public.counter_types as counter_type
  where counter_type.id = target_reading.counter_type_id;

  if round(target_replacement_value, counter_type_record.decimal_scale) <> target_replacement_value then
    raise exception 'counter value has more decimal places than this counter type allows'
      using errcode = '22003';
  end if;

  if target_reading.previous_reading_id is not null then
    select reading.*
    into previous_reading
    from public.counter_readings as reading
    where reading.id = target_reading.previous_reading_id;
  end if;

  if counter_type_record.is_monotonic
      and previous_reading.id is not null
      and target_replacement_value < previous_reading.reading_value then
    raise exception 'counter regression: replacement reading % must be at least previous reading %',
      target_replacement_value, previous_reading.reading_value
      using errcode = '22003';
  end if;

  update public.counter_readings
  set status = 'superseded', correction_reason = normalized_reason
  where id = target_reading.id;

  insert into public.counter_readings (
    account_id,
    machine_id,
    counter_type_id,
    reading_value,
    observed_at,
    shift_code,
    entered_by,
    source,
    previous_reading_id,
    corrects_reading_id,
    notes,
    client_request_id,
    created_by
  ) values (
    target_reading.account_id,
    target_reading.machine_id,
    target_reading.counter_type_id,
    target_replacement_value,
    target_reading.observed_at,
    target_reading.shift_code,
    actor_id,
    'correction',
    target_reading.previous_reading_id,
    target_reading.id,
    coalesce(normalized_notes, target_reading.notes),
    target_client_request_id,
    actor_id
  )
  returning * into result_reading;

  return result_reading;
end;
$$;

revoke all on type public.counter_reading_status
  from public;
grant usage on type public.counter_reading_status
  to authenticated, service_role;

revoke all on table public.counter_readings
  from public, anon, authenticated, service_role;
grant select on table public.counter_readings to authenticated;
grant select, insert, update on table public.counter_readings to service_role;

revoke all on table public.machine_counter_history
  from public, anon, authenticated, service_role;
grant select on table public.machine_counter_history to authenticated, service_role;

revoke all on function public.protect_counter_reading_history()
  from public, anon, authenticated, service_role;
revoke all on function public.record_machine_counter(
  uuid, uuid, numeric, timestamptz, uuid, text, text, text
) from public, anon, authenticated, service_role;
revoke all on function public.correct_machine_counter(
  uuid, text, numeric, uuid, text
) from public, anon, authenticated, service_role;

grant execute on function public.record_machine_counter(
  uuid, uuid, numeric, timestamptz, uuid, text, text, text
) to authenticated;
grant execute on function public.correct_machine_counter(
  uuid, text, numeric, uuid, text
) to authenticated;
