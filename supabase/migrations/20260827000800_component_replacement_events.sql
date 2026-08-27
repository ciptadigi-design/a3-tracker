-- A3 Tracker V2 - M2.3C Component Replacement Workflow
-- Immutable replacement facts and completed lifecycle sample foundation.

alter type public.component_installation_source add value if not exists 'replacement';

create type public.component_replacement_reason as enum (
  'normal_eol',
  'depleted',
  'print_quality',
  'preventive',
  'failure',
  'damage',
  'contamination',
  'diagnostic',
  'other'
);

create type public.component_removal_condition as enum (
  'good',
  'fair',
  'worn',
  'failed'
);

create unique index machine_component_lifecycles_id_scope_key
  on public.machine_component_lifecycles (id, account_id, branch_id, machine_id);

create table public.component_replacement_events (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  branch_id uuid not null,
  machine_id uuid not null,
  model_component_profile_id uuid not null references public.machine_model_components(id) on delete restrict,
  component_id uuid not null references public.components(id) on delete restrict,
  slot_code_snapshot text not null,
  previous_lifecycle_id uuid not null,
  new_lifecycle_id uuid not null,
  previous_installed_counter numeric(20,4) not null,
  replacement_counter numeric(20,4) not null,
  actual_usage numeric(20,4) not null,
  expected_at_install bigint not null,
  baseline_expected_snapshot bigint not null,
  adaptive_expected_snapshot bigint,
  replacement_reason public.component_replacement_reason not null,
  condition_at_removal public.component_removal_condition not null,
  include_in_adaptive_learning boolean not null,
  performed_by_user_id uuid references auth.users(id) on delete set null,
  performed_by_name_snapshot text not null,
  replaced_at timestamptz not null,
  notes text,
  counter_reading_id uuid references public.counter_readings(id) on delete restrict,
  client_request_id uuid not null,
  created_by uuid not null default auth.uid() references auth.users(id) on delete restrict,
  created_at timestamptz not null default statement_timestamp(),
  constraint component_replacement_events_machine_scope_fkey
    foreign key (machine_id, account_id, branch_id)
    references public.machines(id, account_id, branch_id)
    on delete restrict,
  constraint component_replacement_events_previous_lifecycle_scope_fkey
    foreign key (previous_lifecycle_id, account_id, branch_id, machine_id)
    references public.machine_component_lifecycles(id, account_id, branch_id, machine_id)
    on delete restrict,
  constraint component_replacement_events_new_lifecycle_scope_fkey
    foreign key (new_lifecycle_id, account_id, branch_id, machine_id)
    references public.machine_component_lifecycles(id, account_id, branch_id, machine_id)
    on delete restrict,
  constraint component_replacement_events_lifecycles_distinct check (previous_lifecycle_id <> new_lifecycle_id),
  constraint component_replacement_events_slot_not_blank check (btrim(slot_code_snapshot) <> ''),
  constraint component_replacement_events_counter_order check (
    replacement_counter >= previous_installed_counter
    and actual_usage = replacement_counter - previous_installed_counter
  ),
  constraint component_replacement_events_expected_positive check (
    expected_at_install > 0
    and baseline_expected_snapshot > 0
    and (adaptive_expected_snapshot is null or adaptive_expected_snapshot > 0)
  ),
  constraint component_replacement_events_performer_not_blank check (btrim(performed_by_name_snapshot) <> ''),
  constraint component_replacement_events_other_detail_required check (
    replacement_reason <> 'other' or nullif(btrim(notes), '') is not null
  ),
  constraint component_replacement_events_account_request_key unique (account_id, client_request_id),
  constraint component_replacement_events_previous_lifecycle_key unique (previous_lifecycle_id),
  constraint component_replacement_events_new_lifecycle_key unique (new_lifecycle_id)
);

create index component_replacement_events_machine_history_idx
  on public.component_replacement_events (account_id, machine_id, replaced_at desc, created_at desc);
create index component_replacement_events_learning_samples_idx
  on public.component_replacement_events (account_id, model_component_profile_id, replaced_at desc)
  where include_in_adaptive_learning;

create or replace function public.validate_component_replacement_event()
returns trigger
language plpgsql
set search_path = ''
as $$
declare
  previous_lifecycle public.machine_component_lifecycles%rowtype;
  next_lifecycle public.machine_component_lifecycles%rowtype;
  linked_counter public.counter_readings%rowtype;
begin
  select * into previous_lifecycle from public.machine_component_lifecycles where id = new.previous_lifecycle_id;
  select * into next_lifecycle from public.machine_component_lifecycles where id = new.new_lifecycle_id;

  if previous_lifecycle.status <> 'closed'
    or next_lifecycle.status <> 'active'
    or previous_lifecycle.model_component_profile_id <> new.model_component_profile_id
    or next_lifecycle.model_component_profile_id <> new.model_component_profile_id
    or previous_lifecycle.component_id <> new.component_id
    or next_lifecycle.component_id <> new.component_id
    or lower(btrim(previous_lifecycle.slot_code)) <> lower(btrim(new.slot_code_snapshot))
    or lower(btrim(next_lifecycle.slot_code)) <> lower(btrim(new.slot_code_snapshot))
    or previous_lifecycle.installed_counter <> new.previous_installed_counter
    or previous_lifecycle.removed_counter <> new.replacement_counter
    or previous_lifecycle.actual_usage <> new.actual_usage
    or next_lifecycle.installed_counter <> new.replacement_counter
    or previous_lifecycle.expected_at_install <> new.expected_at_install
    or previous_lifecycle.baseline_expected_clicks_snapshot <> new.baseline_expected_snapshot
    or previous_lifecycle.adaptive_expected_snapshot is distinct from new.adaptive_expected_snapshot then
    raise exception 'replacement event does not match its lifecycle transition' using errcode = '23514';
  end if;

  if new.performed_by_user_id is not null and not exists (
    select 1 from public.account_memberships membership
    where membership.account_id = new.account_id
      and membership.user_id = new.performed_by_user_id
      and membership.status = 'active'
  ) then
    raise exception 'replacement performer is not an active account member' using errcode = '23514';
  end if;

  if new.counter_reading_id is not null then
    select * into linked_counter from public.counter_readings where id = new.counter_reading_id;
    if linked_counter.account_id <> new.account_id
      or linked_counter.machine_id <> new.machine_id
      or linked_counter.reading_value <> new.replacement_counter
      or linked_counter.observed_at <> new.replaced_at
      or linked_counter.status <> 'effective'
      or linked_counter.source <> 'component_replacement'
      or linked_counter.client_request_id <> new.client_request_id then
      raise exception 'replacement counter reading does not match event' using errcode = '23514';
    end if;
  end if;

  return new;
end;
$$;

revoke all on function public.validate_component_replacement_event()
  from public, anon, authenticated, service_role;

create trigger component_replacement_events_validate
before insert on public.component_replacement_events
for each row execute function public.validate_component_replacement_event();

create or replace function public.protect_component_replacement_history()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  raise exception 'component replacement history is immutable' using errcode = '42501';
end;
$$;

revoke all on function public.protect_component_replacement_history()
  from public, anon, authenticated, service_role;

create trigger component_replacement_events_immutable
before update or delete on public.component_replacement_events
for each row execute function public.protect_component_replacement_history();

create view public.component_replacement_history
with (security_invoker = true)
as
select
  event.id as replacement_event_id,
  event.account_id,
  event.branch_id,
  event.machine_id,
  machine.machine_code,
  machine.display_name as machine_name,
  event.model_component_profile_id,
  event.component_id,
  component.code as component_code,
  component.name as component_name,
  profile.tracking_method,
  event.slot_code_snapshot,
  event.previous_lifecycle_id,
  event.new_lifecycle_id,
  event.previous_installed_counter,
  event.replacement_counter,
  event.actual_usage,
  event.expected_at_install,
  event.baseline_expected_snapshot,
  event.adaptive_expected_snapshot,
  case when event.expected_at_install > 0
    then round(event.actual_usage / event.expected_at_install * 100, 2) end as performance_percent,
  event.replacement_reason,
  event.condition_at_removal,
  event.include_in_adaptive_learning,
  event.performed_by_user_id,
  event.performed_by_name_snapshot,
  event.replaced_at,
  event.notes,
  event.counter_reading_id,
  new_lifecycle.status as new_lifecycle_status,
  new_lifecycle.installed_counter as new_installed_counter,
  new_lifecycle.expected_at_install as new_expected_at_install,
  event.created_by,
  event.created_at
from public.component_replacement_events event
join public.machines machine on machine.id = event.machine_id
join public.components component on component.id = event.component_id
join public.machine_model_components profile on profile.id = event.model_component_profile_id
join public.machine_component_lifecycles new_lifecycle on new_lifecycle.id = event.new_lifecycle_id;

create view public.component_lifecycle_samples
with (security_invoker = true)
as
select
  account_id,
  branch_id,
  machine_id,
  model_component_profile_id,
  component_id,
  slot_code_snapshot as slot_code,
  actual_usage,
  expected_at_install,
  replacement_reason,
  condition_at_removal,
  include_in_adaptive_learning,
  replaced_at
from public.component_replacement_events;

comment on table public.component_replacement_events is
  'Immutable, auditable transitions between closed and newly active machine component lifecycles.';
comment on view public.component_lifecycle_samples is
  'Completed lifecycle facts for a future adaptive engine; M2.3C performs no adaptive calculation.';
