-- M2.3D intelligence RLS, grants, and audited human adoption.

alter table public.component_profile_baseline_revisions enable row level security;

create policy component_profile_baseline_revisions_select_members
on public.component_profile_baseline_revisions
for select to authenticated
using (public.is_account_member(account_id));

create or replace function public.adopt_component_intelligence_recommendation(
  target_account_id uuid,
  target_effective_profile_id uuid,
  target_current_baseline bigint,
  target_sample_fingerprint text,
  target_algorithm_version text,
  target_client_request_id uuid,
  target_reason text default null
)
returns public.component_profile_baseline_revisions
language plpgsql
security definer
set search_path = ''
as $$
declare
  actor_id uuid := (select auth.uid());
  intelligence_record public.component_adaptive_intelligence%rowtype;
  source_profile public.machine_model_components%rowtype;
  workspace_profile public.machine_model_components%rowtype;
  existing_revision public.component_profile_baseline_revisions%rowtype;
  result_revision public.component_profile_baseline_revisions%rowtype;
  normalized_reason text := nullif(btrim(target_reason), '');
begin
  if actor_id is null then
    raise exception 'authentication required' using errcode = '42501';
  end if;
  if not public.has_account_role(target_account_id, array['owner','admin']::public.account_role[]) then
    raise exception 'owner or admin role required to adopt an intelligence recommendation' using errcode = '42501';
  end if;
  if target_client_request_id is null then
    raise exception 'client request id is required' using errcode = '22023';
  end if;

  select * into existing_revision
  from public.component_profile_baseline_revisions
  where account_id = target_account_id and client_request_id = target_client_request_id;
  if found then
    if existing_revision.previous_expected_clicks = target_current_baseline
      and existing_revision.algorithm_version = target_algorithm_version
      and existing_revision.sample_fingerprint is not distinct from target_sample_fingerprint then
      return existing_revision;
    end if;
    raise exception 'client request id was already used for a different baseline adoption' using errcode = '23505';
  end if;

  perform 1 from public.accounts where id = target_account_id and status = 'active' for update;
  if not found then raise exception 'active account not found' using errcode = 'P0002'; end if;

  select * into existing_revision
  from public.component_profile_baseline_revisions
  where account_id = target_account_id and client_request_id = target_client_request_id;
  if found then return existing_revision; end if;

  select * into intelligence_record
  from public.component_adaptive_intelligence intelligence
  where intelligence.account_id = target_account_id
    and intelligence.effective_profile_id = target_effective_profile_id;

  if not found then
    raise exception 'intelligence recommendation is stale or no longer effective' using errcode = '40001';
  end if;
  if intelligence_record.current_baseline is distinct from target_current_baseline then
    raise exception 'baseline changed since this recommendation was loaded; refresh intelligence before adopting' using errcode = '40001';
  end if;
  if intelligence_record.sample_fingerprint is distinct from target_sample_fingerprint then
    raise exception 'lifecycle samples changed since this recommendation was loaded; refresh intelligence before adopting' using errcode = '40001';
  end if;
  if intelligence_record.algorithm_version <> target_algorithm_version or target_algorithm_version <> 'v1' then
    raise exception 'intelligence algorithm changed; refresh before adopting' using errcode = '40001';
  end if;
  if not intelligence_record.adaptive_enabled then
    raise exception 'adaptive recommendations are disabled for this profile' using errcode = '22023';
  end if;
  if not intelligence_record.can_adopt or intelligence_record.suggested_baseline is null then
    raise exception 'this intelligence state has no actionable recommendation' using errcode = '22023';
  end if;

  select * into source_profile from public.machine_model_components where id = target_effective_profile_id;
  if not found or (source_profile.account_id is not null and source_profile.account_id <> target_account_id) then
    raise exception 'effective profile is outside this account' using errcode = '23514';
  end if;

  perform set_config('a3tracker.adaptive_adoption', 'on', true);

  if source_profile.account_id = target_account_id then
    update public.machine_model_components
    set baseline_expected_clicks = intelligence_record.suggested_baseline,
        updated_by = actor_id
    where id = source_profile.id
      and account_id = target_account_id
      and baseline_expected_clicks = target_current_baseline
    returning * into workspace_profile;
    if not found then
      raise exception 'baseline changed since this recommendation was loaded; refresh intelligence before adopting' using errcode = '40001';
    end if;
  else
    insert into public.machine_model_components (
      account_id, machine_model_id, component_id, slot_code, display_order,
      tracking_method, baseline_expected_clicks, adaptive_enabled,
      healthy_threshold_percent, watch_threshold_percent,
      warning_threshold_percent, critical_threshold_percent,
      notes, is_active, created_by, updated_by
    ) values (
      target_account_id, source_profile.machine_model_id, source_profile.component_id,
      source_profile.slot_code, source_profile.display_order, source_profile.tracking_method,
      intelligence_record.suggested_baseline, source_profile.adaptive_enabled,
      source_profile.healthy_threshold_percent, source_profile.watch_threshold_percent,
      source_profile.warning_threshold_percent, source_profile.critical_threshold_percent,
      source_profile.notes, true, actor_id, actor_id
    ) returning * into workspace_profile;
  end if;

  insert into public.component_profile_baseline_revisions (
    account_id, machine_model_component_id, previous_expected_clicks,
    new_expected_clicks, change_source, algorithm_version, sample_fingerprint,
    total_completed_samples, eligible_samples, usable_samples, outlier_count,
    confidence_score, confidence_label, observed_expected, recommendation_state,
    intelligence_snapshot, reason, client_request_id, changed_by
  ) values (
    target_account_id, workspace_profile.id, target_current_baseline,
    intelligence_record.suggested_baseline, 'adaptive_recommendation',
    intelligence_record.algorithm_version, intelligence_record.sample_fingerprint,
    intelligence_record.total_completed_samples, intelligence_record.eligible_samples,
    intelligence_record.usable_samples, intelligence_record.outlier_count,
    intelligence_record.confidence_score, intelligence_record.confidence_label,
    intelligence_record.observed_expected_life, intelligence_record.recommendation_state,
    jsonb_build_object(
      'difference_clicks', intelligence_record.difference_clicks,
      'difference_percent', intelligence_record.difference_percent,
      'coefficient_of_variation', intelligence_record.coefficient_of_variation,
      'minimum', intelligence_record.minimum_actual_usage,
      'maximum', intelligence_record.maximum_actual_usage,
      'dead_band_percent', intelligence_record.recommendation_dead_band_percent,
      'maximum_adjustment_percent', intelligence_record.maximum_adjustment_percent
    ), normalized_reason, target_client_request_id, actor_id
  ) returning * into result_revision;

  return result_revision;
end;
$$;

revoke all on type public.component_baseline_change_source from public;
grant usage on type public.component_baseline_change_source to authenticated, service_role;

revoke all on table public.component_profile_baseline_revisions,
  public.component_adaptive_sample_diagnostics,
  public.component_machine_adaptive_observations,
  public.component_adaptive_intelligence
  from public, anon, authenticated, service_role;
grant select on table public.component_profile_baseline_revisions,
  public.component_adaptive_sample_diagnostics,
  public.component_machine_adaptive_observations,
  public.component_adaptive_intelligence
  to authenticated, service_role;
grant select, insert on table public.component_profile_baseline_revisions to service_role;

revoke all on function public.adopt_component_intelligence_recommendation(
  uuid,uuid,bigint,text,text,uuid,text
) from public, anon, authenticated, service_role;
grant execute on function public.adopt_component_intelligence_recommendation(
  uuid,uuid,bigint,text,text,uuid,text
) to authenticated;
