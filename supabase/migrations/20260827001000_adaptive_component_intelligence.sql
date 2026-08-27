-- A3 Tracker V2 - M2.3D Adaptive Component Intelligence
-- Deterministic advisory statistics over immutable completed lifecycle facts.

create type public.component_baseline_change_source as enum (
  'manual',
  'adaptive_recommendation'
);

create unique index machine_model_components_id_account_key
  on public.machine_model_components (id, account_id)
  where account_id is not null;

create table public.component_profile_baseline_revisions (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  machine_model_component_id uuid not null references public.machine_model_components(id) on delete restrict,
  previous_expected_clicks bigint,
  new_expected_clicks bigint,
  change_source public.component_baseline_change_source not null,
  algorithm_version text,
  sample_fingerprint text,
  total_completed_samples integer,
  eligible_samples integer,
  usable_samples integer,
  outlier_count integer,
  confidence_score numeric(5,2),
  confidence_label text,
  observed_expected numeric(20,4),
  recommendation_state text,
  intelligence_snapshot jsonb,
  reason text,
  client_request_id uuid,
  changed_by uuid references auth.users(id) on delete set null,
  changed_at timestamptz not null default statement_timestamp(),
  constraint component_profile_baseline_revisions_values_positive check (
    (previous_expected_clicks is null or previous_expected_clicks > 0)
    and (new_expected_clicks is null or new_expected_clicks > 0)
  ),
  constraint component_profile_baseline_revisions_changed check (
    previous_expected_clicks is distinct from new_expected_clicks
  ),
  constraint component_profile_baseline_revisions_algorithm_consistent check (
    (change_source = 'manual' and algorithm_version is null)
    or (change_source = 'adaptive_recommendation' and nullif(btrim(algorithm_version), '') is not null)
  )
);

create unique index component_profile_baseline_revisions_request_key
  on public.component_profile_baseline_revisions (account_id, client_request_id)
  where client_request_id is not null;
create index component_profile_baseline_revisions_profile_history_idx
  on public.component_profile_baseline_revisions (account_id, machine_model_component_id, changed_at desc);

create or replace function public.audit_component_profile_baseline_change()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  actor_id uuid := (select auth.uid());
begin
  if current_setting('a3tracker.adaptive_adoption', true) = 'on' then
    return new;
  end if;

  if new.account_id is null then
    return new;
  end if;

  if tg_op = 'INSERT' then
    if new.baseline_expected_clicks is null then return new; end if;
    insert into public.component_profile_baseline_revisions (
      account_id, machine_model_component_id, previous_expected_clicks,
      new_expected_clicks, change_source, reason, changed_by
    ) values (
      new.account_id, new.id, null, new.baseline_expected_clicks,
      'manual', 'Workspace profile created with baseline', actor_id
    );
  elsif new.baseline_expected_clicks is distinct from old.baseline_expected_clicks then
    insert into public.component_profile_baseline_revisions (
      account_id, machine_model_component_id, previous_expected_clicks,
      new_expected_clicks, change_source, reason, changed_by
    ) values (
      new.account_id, new.id, old.baseline_expected_clicks,
      new.baseline_expected_clicks, 'manual', 'Workspace profile baseline edited', actor_id
    );
  end if;

  return new;
end;
$$;

revoke all on function public.audit_component_profile_baseline_change()
  from public, anon, authenticated, service_role;

create trigger machine_model_components_audit_baseline_insert
after insert on public.machine_model_components
for each row execute function public.audit_component_profile_baseline_change();

create trigger machine_model_components_audit_baseline_update
after update of baseline_expected_clicks on public.machine_model_components
for each row execute function public.audit_component_profile_baseline_change();

create or replace function public.protect_component_profile_baseline_revision()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  raise exception 'component profile baseline revision history is immutable' using errcode = '42501';
end;
$$;

revoke all on function public.protect_component_profile_baseline_revision()
  from public, anon, authenticated, service_role;

create trigger component_profile_baseline_revisions_immutable
before update or delete on public.component_profile_baseline_revisions
for each row execute function public.protect_component_profile_baseline_revision();

create view public.component_adaptive_sample_diagnostics
with (security_invoker = true)
as
with sample_base as (
  select
    event.id as replacement_event_id,
    event.account_id,
    machine.machine_model_id,
    event.machine_id,
    machine.machine_code,
    machine.display_name as machine_name,
    event.model_component_profile_id,
    event.component_id,
    component.code as component_code,
    component.name as component_name,
    event.slot_code_snapshot as slot_code,
    profile.tracking_method,
    event.actual_usage,
    event.expected_at_install,
    round(event.actual_usage / nullif(event.expected_at_install, 0) * 100, 2) as performance_percent,
    event.replacement_reason,
    event.condition_at_removal,
    event.include_in_adaptive_learning,
    event.replaced_at,
    event.performed_by_name_snapshot
  from public.component_replacement_events event
  join public.machines machine on machine.id = event.machine_id
  join public.machine_model_components profile on profile.id = event.model_component_profile_id
  join public.components component on component.id = event.component_id
  where lower(event.slot_code_snapshot) <> 'test_component'
    and lower(component.code) <> 'test_component'
), quartiles as (
  select
    account_id,
    machine_model_id,
    lower(btrim(slot_code)) as slot_key,
    count(*)::integer as eligible_count,
    percentile_cont(0.25) within group (order by actual_usage)::numeric as q1,
    percentile_cont(0.75) within group (order by actual_usage)::numeric as q3
  from sample_base
  where include_in_adaptive_learning
  group by account_id, machine_model_id, lower(btrim(slot_code))
)
select
  sample.*,
  sample.include_in_adaptive_learning as is_eligible,
  case
    when not sample.include_in_adaptive_learning then false
    when quartiles.eligible_count < 4 then false
    when quartiles.q3 - quartiles.q1 <= 0 then false
    else sample.actual_usage < quartiles.q1 - 1.5 * (quartiles.q3 - quartiles.q1)
      or sample.actual_usage > quartiles.q3 + 1.5 * (quartiles.q3 - quartiles.q1)
  end as is_outlier,
  case
    when not sample.include_in_adaptive_learning then 'Marked ineligible at replacement'
    when quartiles.eligible_count < 4 then null
    when quartiles.q3 - quartiles.q1 <= 0 then null
    when sample.actual_usage < quartiles.q1 - 1.5 * (quartiles.q3 - quartiles.q1) then 'Below the 1.5×IQR lower bound'
    when sample.actual_usage > quartiles.q3 + 1.5 * (quartiles.q3 - quartiles.q1) then 'Above the 1.5×IQR upper bound'
    else null
  end as outlier_reason,
  quartiles.q1,
  quartiles.q3
from sample_base sample
left join quartiles on quartiles.account_id = sample.account_id
  and quartiles.machine_model_id = sample.machine_model_id
  and quartiles.slot_key = lower(btrim(sample.slot_code));

create view public.component_machine_adaptive_observations
with (security_invoker = true)
as
select
  account_id,
  machine_model_id,
  machine_id,
  machine_code,
  machine_name,
  slot_code,
  tracking_method,
  count(*) filter (where is_eligible)::integer as eligible_samples,
  count(*) filter (where is_eligible and not is_outlier)::integer as usable_samples,
  round(avg(actual_usage) filter (where is_eligible and not is_outlier), 2) as mean_actual_usage,
  percentile_cont(0.5) within group (order by actual_usage)
    filter (where is_eligible and not is_outlier)::numeric as median_actual_usage,
  min(actual_usage) filter (where is_eligible and not is_outlier) as minimum_actual_usage,
  max(actual_usage) filter (where is_eligible and not is_outlier) as maximum_actual_usage
from public.component_adaptive_sample_diagnostics
group by account_id, machine_model_id, machine_id, machine_code, machine_name, slot_code, tracking_method;

create view public.component_adaptive_intelligence
with (security_invoker = true)
as
with ranked_profiles as (
  select
    account.id as account_id,
    profile.id as effective_profile_id,
    profile.account_id as profile_account_id,
    profile.machine_model_id,
    model.name as machine_model_name,
    manufacturer.name as manufacturer_name,
    profile.component_id,
    component.code as component_code,
    component.name as component_name,
    profile.slot_code,
    profile.tracking_method,
    profile.baseline_expected_clicks as current_baseline,
    profile.adaptive_enabled,
    profile.updated_at as profile_updated_at,
    row_number() over (
      partition by account.id, profile.machine_model_id, lower(btrim(profile.slot_code))
      order by (profile.account_id = account.id) desc, profile.created_at desc, profile.id desc
    ) as preference_rank
  from public.accounts account
  join public.machine_model_components profile
    on profile.account_id is null or profile.account_id = account.id
  join public.machine_models model on model.id = profile.machine_model_id
  join public.manufacturers manufacturer on manufacturer.id = model.manufacturer_id
  join public.components component on component.id = profile.component_id
  where account.status = 'active'
    and profile.is_active
    and component.is_active
    and lower(profile.slot_code) <> 'test_component'
    and lower(component.code) <> 'test_component'
), effective_profiles as (
  select * from ranked_profiles where preference_rank = 1
), sample_rollup as (
  select
    account_id,
    machine_model_id,
    lower(btrim(slot_code)) as slot_key,
    count(*)::integer as total_completed_samples,
    count(*) filter (where is_eligible)::integer as eligible_samples,
    count(*) filter (where is_eligible and is_outlier)::integer as outlier_count,
    count(*) filter (where is_eligible and not is_outlier)::integer as usable_samples,
    round(avg(actual_usage) filter (where is_eligible and not is_outlier), 2) as mean_actual_usage,
    percentile_cont(0.5) within group (order by actual_usage)
      filter (where is_eligible and not is_outlier)::numeric as median_actual_usage,
    min(actual_usage) filter (where is_eligible and not is_outlier) as minimum_actual_usage,
    max(actual_usage) filter (where is_eligible and not is_outlier) as maximum_actual_usage,
    round(stddev_samp(actual_usage) filter (where is_eligible and not is_outlier), 2) as standard_deviation,
    percentile_cont(0.25) within group (order by actual_usage)
      filter (where is_eligible and not is_outlier)::numeric as q1,
    percentile_cont(0.75) within group (order by actual_usage)
      filter (where is_eligible and not is_outlier)::numeric as q3,
    max(replaced_at) filter (where is_eligible) as latest_eligible_sample_at,
    md5(string_agg(
      concat_ws(':', replacement_event_id::text, actual_usage::text, replaced_at::text),
      '|' order by replaced_at, replacement_event_id
    ) filter (where is_eligible)) as sample_fingerprint
  from public.component_adaptive_sample_diagnostics
  group by account_id, machine_model_id, lower(btrim(slot_code))
), measures as (
  select
    profile.*,
    coalesce(sample.total_completed_samples, 0) as total_completed_samples,
    coalesce(sample.eligible_samples, 0) as eligible_samples,
    coalesce(sample.outlier_count, 0) as outlier_count,
    coalesce(sample.usable_samples, 0) as usable_samples,
    sample.mean_actual_usage,
    sample.median_actual_usage as observed_expected_life,
    sample.minimum_actual_usage,
    sample.maximum_actual_usage,
    sample.standard_deviation,
    sample.q1,
    sample.q3,
    sample.q3 - sample.q1 as interquartile_range,
    round(sample.standard_deviation / nullif(sample.mean_actual_usage, 0), 4) as coefficient_of_variation,
    sample.latest_eligible_sample_at,
    sample.sample_fingerprint,
    case coalesce(sample.usable_samples, 0)
      when 0 then 0 when 1 then 10 when 2 then 25 when 3 then 45 when 4 then 55
      when 5 then 65 when 6 then 72 when 7 then 78 when 8 then 84 when 9 then 87
      when 10 then 90 when 11 then 92 when 12 then 94 when 13 then 96 when 14 then 98
      else 100
    end::numeric as quantity_score,
    case
      when coalesce(sample.usable_samples, 0) < 2 then 0
      when sample.standard_deviation / nullif(sample.mean_actual_usage, 0) <= 0.05 then 100
      when sample.standard_deviation / nullif(sample.mean_actual_usage, 0) <= 0.10 then 90
      when sample.standard_deviation / nullif(sample.mean_actual_usage, 0) <= 0.15 then 80
      when sample.standard_deviation / nullif(sample.mean_actual_usage, 0) <= 0.25 then 65
      when sample.standard_deviation / nullif(sample.mean_actual_usage, 0) <= 0.40 then 45
      else 20
    end::numeric as consistency_score,
    case when coalesce(sample.total_completed_samples, 0) = 0 then 0
      else round(coalesce(sample.usable_samples, 0)::numeric / sample.total_completed_samples * 100, 2)
    end as quality_score
  from effective_profiles profile
  left join sample_rollup sample on sample.account_id = profile.account_id
    and sample.machine_model_id = profile.machine_model_id
    and sample.slot_key = lower(btrim(profile.slot_code))
), scored as (
  select
    measures.*,
    round(least(
      quantity_score * 0.45 + consistency_score * 0.40 + quality_score * 0.15,
      case usable_samples when 0 then 0 when 1 then 24 when 2 then 44
        when 3 then 64 when 4 then 64 when 5 then 79 when 6 then 79 when 7 then 79
        when 8 then 89 when 9 then 89 when 10 then 89 when 11 then 89 when 12 then 89
        when 13 then 89 when 14 then 89 else 100 end
    ), 2) as confidence_score,
    observed_expected_life - current_baseline as difference_clicks,
    round((observed_expected_life - current_baseline) / nullif(current_baseline, 0) * 100, 2) as difference_percent
  from measures
), recommended as (
  select
    scored.*,
    case
      when usable_samples = 0 then 'no_data'
      when not adaptive_enabled then 'adaptive_disabled'
      when usable_samples < 3 then 'insufficient_data'
      when coefficient_of_variation > 0.40 then 'high_variability'
      when abs(difference_percent) <= 10 then 'keep_baseline'
      when difference_percent > 10 then 'review_increase'
      else 'review_decrease'
    end as recommendation_state
  from scored
)
select
  recommended.*,
  case
    when usable_samples = 0 then 'no_data'
    when confidence_score < 25 then 'very_low'
    when confidence_score < 45 then 'low'
    when confidence_score < 65 then 'developing'
    when confidence_score < 80 then 'medium'
    when confidence_score < 90 then 'high'
    else 'mature'
  end as confidence_label,
  case
    when usable_samples = 0 then 'no_data'
    when usable_samples < 2 then 'insufficient'
    when coefficient_of_variation <= 0.10 then 'very_consistent'
    when coefficient_of_variation <= 0.25 then 'moderate'
    when coefficient_of_variation <= 0.40 then 'high'
    else 'very_high'
  end as variability_label,
  case when recommendation_state in ('review_increase', 'review_decrease') then
    round(least(greatest(observed_expected_life, current_baseline * 0.75), current_baseline * 1.25))::bigint
  end as suggested_baseline,
  recommendation_state in ('review_increase', 'review_decrease') as can_adopt,
  'v1'::text as algorithm_version,
  10::numeric as recommendation_dead_band_percent,
  25::numeric as maximum_adjustment_percent
from recommended;

comment on view public.component_adaptive_intelligence is
  'M2.3D deterministic advisory intelligence. Median is the observed estimator; recommendations never alter a baseline without explicit adoption.';
comment on view public.component_adaptive_sample_diagnostics is
  'Completed replacement facts with eligibility and conservative 1.5xIQR outlier diagnostics; history is never deleted.';
