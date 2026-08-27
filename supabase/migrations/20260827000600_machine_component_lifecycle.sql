-- A3 Tracker V2 - M2.3B Active Machine Component Lifecycle
-- Installed counters and expected-life snapshots are immutable lifecycle facts.
-- Current usage and health are derived from the latest effective Daily Counter.

create type public.machine_component_lifecycle_status as enum (
  'unknown',
  'active',
  'closed'
);

create type public.component_installation_source as enum (
  'legacy_import',
  'manual_historical',
  'tracking_start'
);

create table public.machine_component_lifecycles (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  branch_id uuid not null,
  machine_id uuid not null,
  model_component_profile_id uuid not null references public.machine_model_components(id) on delete restrict,
  component_id uuid not null references public.components(id) on delete restrict,
  slot_code text not null,
  status public.machine_component_lifecycle_status not null,
  installed_counter numeric(20,4),
  installed_at timestamptz,
  installation_source public.component_installation_source not null,
  baseline_expected_clicks_snapshot bigint not null,
  expected_at_install bigint not null,
  adaptive_expected_snapshot bigint,
  removed_counter numeric(20,4),
  removed_at timestamptz,
  actual_usage numeric(20,4),
  initialization_request_id uuid,
  created_by uuid default auth.uid() references auth.users(id) on delete set null,
  created_at timestamptz not null default statement_timestamp(),
  updated_at timestamptz not null default statement_timestamp(),
  notes text,
  constraint machine_component_lifecycles_machine_scope_fkey
    foreign key (machine_id, account_id, branch_id)
    references public.machines(id, account_id, branch_id)
    on delete restrict,
  constraint machine_component_lifecycles_slot_not_blank check (btrim(slot_code) <> ''),
  constraint machine_component_lifecycles_expected_positive check (
    baseline_expected_clicks_snapshot > 0
    and expected_at_install > 0
    and (adaptive_expected_snapshot is null or adaptive_expected_snapshot > 0)
  ),
  constraint machine_component_lifecycles_installation_consistent check (
    (status = 'unknown' and installed_counter is null and installed_at is null)
    or (status in ('active', 'closed') and installed_counter is not null)
  ),
  constraint machine_component_lifecycles_removal_consistent check (
    (status <> 'closed' and removed_counter is null and removed_at is null and actual_usage is null)
    or (
      status = 'closed'
      and removed_counter is not null
      and actual_usage = removed_counter - installed_counter
    )
  ),
  constraint machine_component_lifecycles_counter_order check (
    removed_counter is null or removed_counter >= installed_counter
  )
);

create unique index machine_component_lifecycles_open_slot_key
  on public.machine_component_lifecycles (machine_id, lower(btrim(slot_code)))
  where status in ('unknown', 'active');

create unique index machine_component_lifecycles_initialization_request_key
  on public.machine_component_lifecycles (account_id, initialization_request_id)
  where initialization_request_id is not null;

create index machine_component_lifecycles_machine_status_idx
  on public.machine_component_lifecycles (account_id, machine_id, status);
create index machine_component_lifecycles_profile_idx
  on public.machine_component_lifecycles (model_component_profile_id);

create or replace function public.validate_machine_component_lifecycle()
returns trigger
language plpgsql
set search_path = ''
as $$
declare
  machine_record public.machines%rowtype;
  profile_record public.machine_model_components%rowtype;
begin
  select * into machine_record
  from public.machines
  where id = new.machine_id;

  if not found
    or machine_record.account_id <> new.account_id
    or machine_record.branch_id <> new.branch_id then
    raise exception 'machine does not belong to lifecycle account and branch' using errcode = '23514';
  end if;

  select * into profile_record
  from public.machine_model_components
  where id = new.model_component_profile_id;

  if not found
    or profile_record.machine_model_id <> machine_record.machine_model_id
    or profile_record.component_id <> new.component_id
    or lower(btrim(profile_record.slot_code)) <> lower(btrim(new.slot_code))
    or (profile_record.account_id is not null and profile_record.account_id <> new.account_id) then
    raise exception 'component profile does not match lifecycle machine, component, slot, or account'
      using errcode = '23514';
  end if;

  if lower(btrim(new.slot_code)) = 'test_component' then
    raise exception 'TEST_COMPONENT cannot be bootstrapped as a machine lifecycle'
      using errcode = '23514';
  end if;

  if tg_op = 'UPDATE' then
    if new.account_id <> old.account_id
      or new.branch_id <> old.branch_id
      or new.machine_id <> old.machine_id
      or new.model_component_profile_id <> old.model_component_profile_id
      or new.component_id <> old.component_id
      or new.slot_code <> old.slot_code
      or (
        not (old.status = 'unknown' and new.status = 'active')
        and (
          new.baseline_expected_clicks_snapshot <> old.baseline_expected_clicks_snapshot
          or new.expected_at_install <> old.expected_at_install
          or new.installation_source <> old.installation_source
        )
      ) then
      raise exception 'lifecycle identity and installation snapshots are immutable'
        using errcode = '42501';
    end if;
    new.updated_at := statement_timestamp();
  end if;

  return new;
end;
$$;

revoke all on function public.validate_machine_component_lifecycle()
  from public, anon, authenticated, service_role;

create trigger machine_component_lifecycles_validate
before insert or update on public.machine_component_lifecycles
for each row execute function public.validate_machine_component_lifecycle();

create or replace function public.initialize_machine_component_lifecycle(
  target_account_id uuid,
  target_machine_id uuid,
  target_model_component_profile_id uuid,
  target_installed_counter numeric default null,
  target_installed_at timestamptz default null,
  target_client_request_id uuid default null,
  target_notes text default null
)
returns public.machine_component_lifecycles
language plpgsql
security definer
set search_path = ''
as $$
declare
  actor_id uuid := (select auth.uid());
  machine_record public.machines%rowtype;
  profile_record public.machine_model_components%rowtype;
  lifecycle_record public.machine_component_lifecycles%rowtype;
  latest_counter numeric(20,4);
  resolved_counter numeric(20,4);
  resolved_source public.component_installation_source;
  normalized_notes text := nullif(btrim(target_notes), '');
begin
  if actor_id is null then
    raise exception 'authentication required' using errcode = '42501';
  end if;

  if not public.has_account_role(
    target_account_id,
    array['owner', 'admin']::public.account_role[]
  ) then
    raise exception 'owner or admin role required to initialize lifecycle' using errcode = '42501';
  end if;

  if target_client_request_id is null then
    raise exception 'client request id is required' using errcode = '22023';
  end if;

  select * into machine_record
  from public.machines
  where id = target_machine_id
    and account_id = target_account_id
    and is_active
  for update;

  if not found then
    raise exception 'active machine not found in this account' using errcode = 'P0002';
  end if;

  select * into profile_record
  from public.machine_model_components
  where id = target_model_component_profile_id
    and machine_model_id = machine_record.machine_model_id
    and (account_id is null or account_id = target_account_id)
    and is_active;

  if not found then
    raise exception 'active component profile does not match this machine' using errcode = '23514';
  end if;

  if lower(btrim(profile_record.slot_code)) = 'test_component' then
    raise exception 'TEST_COMPONENT lifecycle initialization is not allowed' using errcode = '23514';
  end if;

  select reading.reading_value into latest_counter
  from public.counter_readings reading
  join public.counter_types counter_type on counter_type.id = reading.counter_type_id
  where reading.account_id = target_account_id
    and reading.machine_id = target_machine_id
    and reading.status = 'effective'
    and lower(btrim(counter_type.code)) = 'total_impressions'
  order by reading.observed_at desc, reading.created_at desc, reading.id desc
  limit 1;

  if latest_counter is null then
    raise exception 'machine has no effective Total Impressions counter' using errcode = 'P0002';
  end if;

  resolved_counter := coalesce(target_installed_counter, latest_counter);
  resolved_source := case
    when target_installed_counter is null then 'tracking_start'::public.component_installation_source
    else 'manual_historical'::public.component_installation_source
  end;

  if target_installed_counter is null and target_installed_at is not null then
    raise exception 'replacement date requires a known historical replacement counter'
      using errcode = '22023';
  end if;

  if resolved_counter < 0 or resolved_counter > latest_counter then
    raise exception 'installation counter must be between zero and the current effective counter'
      using errcode = '22003';
  end if;

  if profile_record.baseline_expected_clicks is null then
    raise exception 'component profile needs an expected baseline before initialization'
      using errcode = '23514';
  end if;

  select * into lifecycle_record
  from public.machine_component_lifecycles
  where account_id = target_account_id
    and initialization_request_id = target_client_request_id;

  if found then
    return lifecycle_record;
  end if;

  select * into lifecycle_record
  from public.machine_component_lifecycles
  where machine_id = target_machine_id
    and lower(btrim(slot_code)) = lower(btrim(profile_record.slot_code))
    and status in ('unknown', 'active')
  for update;

  if lifecycle_record.status = 'active' then
    raise exception 'this machine component lifecycle is already active' using errcode = '23505';
  end if;

  if lifecycle_record.id is not null then
    update public.machine_component_lifecycles
    set status = 'active',
        installed_counter = resolved_counter,
        installed_at = target_installed_at,
        installation_source = resolved_source,
        baseline_expected_clicks_snapshot = profile_record.baseline_expected_clicks,
        expected_at_install = profile_record.baseline_expected_clicks,
        initialization_request_id = target_client_request_id,
        notes = normalized_notes
    where id = lifecycle_record.id
    returning * into lifecycle_record;
  else
    insert into public.machine_component_lifecycles (
      account_id, branch_id, machine_id, model_component_profile_id, component_id,
      slot_code, status, installed_counter, installed_at, installation_source,
      baseline_expected_clicks_snapshot, expected_at_install,
      initialization_request_id, created_by, notes
    ) values (
      target_account_id, machine_record.branch_id, target_machine_id, profile_record.id,
      profile_record.component_id, profile_record.slot_code, 'active', resolved_counter,
      target_installed_at, resolved_source, profile_record.baseline_expected_clicks,
      profile_record.baseline_expected_clicks, target_client_request_id, actor_id,
      normalized_notes
    ) returning * into lifecycle_record;
  end if;

  return lifecycle_record;
end;
$$;

revoke all on function public.initialize_machine_component_lifecycle(uuid,uuid,uuid,numeric,timestamptz,uuid,text)
  from public, anon, service_role;
grant execute on function public.initialize_machine_component_lifecycle(uuid,uuid,uuid,numeric,timestamptz,uuid,text)
  to authenticated;

create view public.machine_component_health
with (security_invoker = true)
as
select
  lifecycle.id as lifecycle_id,
  lifecycle.account_id,
  lifecycle.branch_id,
  lifecycle.machine_id,
  machine.machine_code,
  machine.display_name as machine_name,
  lifecycle.model_component_profile_id,
  lifecycle.component_id,
  component.code as component_code,
  component.name as component_name,
  lifecycle.slot_code,
  profile.display_order,
  profile.tracking_method,
  lifecycle.status as lifecycle_status,
  lifecycle.installation_source,
  lifecycle.installed_counter,
  lifecycle.installed_at,
  lifecycle.baseline_expected_clicks_snapshot,
  lifecycle.expected_at_install,
  profile.baseline_expected_clicks as current_profile_baseline,
  lifecycle.adaptive_expected_snapshot,
  coalesce(lifecycle.adaptive_expected_snapshot, lifecycle.expected_at_install) as effective_expected,
  case when lifecycle.adaptive_expected_snapshot is null then 'Baseline only' else 'Adaptive snapshot' end as expected_source,
  latest.reading_value as latest_effective_counter,
  latest.observed_at as latest_counter_observed_at,
  case when lifecycle.status = 'active' and latest.reading_value is not null
    then latest.reading_value - lifecycle.installed_counter end as current_usage,
  case when lifecycle.status = 'active' and latest.reading_value is not null
    then coalesce(lifecycle.adaptive_expected_snapshot, lifecycle.expected_at_install)
      - (latest.reading_value - lifecycle.installed_counter) end as remaining_clicks,
  case when lifecycle.status = 'active' and latest.reading_value is not null
    then round((coalesce(lifecycle.adaptive_expected_snapshot, lifecycle.expected_at_install)
      - (latest.reading_value - lifecycle.installed_counter))
      / coalesce(lifecycle.adaptive_expected_snapshot, lifecycle.expected_at_install) * 100, 2) end as remaining_percent,
  case when lifecycle.status = 'active' and latest.reading_value is not null
    then lifecycle.installed_counter + coalesce(lifecycle.adaptive_expected_snapshot, lifecycle.expected_at_install) end
    as estimated_replacement_counter,
  case
    when lifecycle.status <> 'active' or latest.reading_value is null then 'unknown'
    when ((coalesce(lifecycle.adaptive_expected_snapshot, lifecycle.expected_at_install)
      - (latest.reading_value - lifecycle.installed_counter))
      / coalesce(lifecycle.adaptive_expected_snapshot, lifecycle.expected_at_install) * 100) > profile.healthy_threshold_percent then 'healthy'
    when ((coalesce(lifecycle.adaptive_expected_snapshot, lifecycle.expected_at_install)
      - (latest.reading_value - lifecycle.installed_counter))
      / coalesce(lifecycle.adaptive_expected_snapshot, lifecycle.expected_at_install) * 100) > profile.watch_threshold_percent then 'watch'
    when ((coalesce(lifecycle.adaptive_expected_snapshot, lifecycle.expected_at_install)
      - (latest.reading_value - lifecycle.installed_counter))
      / coalesce(lifecycle.adaptive_expected_snapshot, lifecycle.expected_at_install) * 100) > profile.warning_threshold_percent then 'warning'
    when ((coalesce(lifecycle.adaptive_expected_snapshot, lifecycle.expected_at_install)
      - (latest.reading_value - lifecycle.installed_counter))
      / coalesce(lifecycle.adaptive_expected_snapshot, lifecycle.expected_at_install) * 100) > profile.critical_threshold_percent then 'critical'
    else 'overdue'
  end as health_status,
  profile.healthy_threshold_percent,
  profile.watch_threshold_percent,
  profile.warning_threshold_percent,
  profile.critical_threshold_percent,
  lifecycle.notes,
  lifecycle.created_at,
  lifecycle.updated_at
from public.machine_component_lifecycles lifecycle
join public.machines machine on machine.id = lifecycle.machine_id
join public.machine_model_components profile on profile.id = lifecycle.model_component_profile_id
join public.components component on component.id = lifecycle.component_id
left join lateral (
  select reading.reading_value, reading.observed_at
  from public.counter_readings reading
  join public.counter_types counter_type on counter_type.id = reading.counter_type_id
  where reading.account_id = lifecycle.account_id
    and reading.machine_id = lifecycle.machine_id
    and reading.status = 'effective'
    and lower(btrim(counter_type.code)) = 'total_impressions'
  order by reading.observed_at desc, reading.created_at desc, reading.id desc
  limit 1
) latest on true;

comment on table public.machine_component_lifecycles is
  'Machine-scoped component lifecycle facts. Active usage and health are never stored; machine_component_health derives them from Daily Counter.';
comment on column public.machine_component_lifecycles.expected_at_install is
  'Immutable expected-life snapshot. Later model profile edits do not move this lifecycle replacement target.';
comment on column public.machine_component_lifecycles.installed_counter is
  'Counter at installation/tracking start. Null means installation history is unknown, never counter zero.';
comment on view public.machine_component_health is
  'Correction-aware operational health derived from latest effective Total Impressions counter and immutable lifecycle snapshots.';
