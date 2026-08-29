-- Align machine sync with the accepted effective Model Profile precedence:
-- workspace over shared, then newest created row and UUID per normalized slot.

create or replace function public.sync_machine_component_assignments_internal(
  target_account_id uuid,
  target_machine_id uuid default null
)
returns integer
language plpgsql
set search_path = ''
as $$
declare changed integer := 0; affected integer;
begin
  perform pg_advisory_xact_lock(hashtextextended(target_account_id::text || ':component-assignment', 0));

  -- Retire stale no-history inheritance first so its effective replacement can
  -- be provisioned in this same synchronization call.
  update public.machine_component_assignments assignment set
    status='retired',retired_at=statement_timestamp(),retired_by=auth.uid(),updated_at=statement_timestamp(),updated_by=auth.uid()
  where assignment.account_id=target_account_id and assignment.source_type='model_profile' and assignment.status='configured'
    and (target_machine_id is null or assignment.machine_id=target_machine_id)
    and (
      not exists (
        select 1 from public.machines machine
        join lateral (
          select effective.* from (
            select distinct on (lower(btrim(candidate.slot_code))) candidate.*
            from public.machine_model_components candidate
            where candidate.machine_model_id=machine.machine_model_id
              and (candidate.account_id is null or candidate.account_id=machine.account_id)
            order by lower(btrim(candidate.slot_code)),
              (candidate.account_id=machine.account_id) desc nulls last,
              candidate.created_at desc,candidate.id desc
          ) effective where effective.is_active
        ) profile on profile.id=assignment.source_profile_id
        where machine.id=assignment.machine_id
      )
      or exists (select 1 from public.machine_component_profile_exclusions exclusion
        where exclusion.machine_id=assignment.machine_id and lower(btrim(exclusion.slot_code))=lower(btrim(assignment.slot_code)) and exclusion.cleared_at is null)
    )
    and not exists (select 1 from public.machine_component_lifecycles lifecycle
      where lifecycle.machine_id=assignment.machine_id and lower(btrim(lifecycle.slot_code))=lower(btrim(assignment.slot_code)));
  get diagnostics affected = row_count; changed := changed + affected;

  insert into public.machine_component_assignments(
    account_id,branch_id,machine_id,component_id,slot_code,display_order,tracking_method,
    baseline_expected_clicks,healthy_threshold_percent,watch_threshold_percent,
    warning_threshold_percent,critical_threshold_percent,source_type,source_profile_id,created_by
  )
  select machine.account_id,machine.branch_id,machine.id,profile.component_id,profile.slot_code,
    profile.display_order,profile.tracking_method,profile.baseline_expected_clicks,
    profile.healthy_threshold_percent,profile.watch_threshold_percent,
    profile.warning_threshold_percent,profile.critical_threshold_percent,
    'model_profile'::public.machine_component_source,profile.id,auth.uid()
  from public.machines machine
  join lateral (
    select effective.* from (
      select distinct on (lower(btrim(candidate.slot_code))) candidate.*
      from public.machine_model_components candidate
      where candidate.machine_model_id=machine.machine_model_id
        and (candidate.account_id is null or candidate.account_id=machine.account_id)
      order by lower(btrim(candidate.slot_code)),
        (candidate.account_id=machine.account_id) desc nulls last,
        candidate.created_at desc,candidate.id desc
    ) effective where effective.is_active
      and lower(btrim(effective.slot_code)) <> 'test_component'
  ) profile on true
  where machine.account_id=target_account_id and machine.is_active
    and (target_machine_id is null or machine.id=target_machine_id)
    and not exists (select 1 from public.machine_component_assignments override_assignment
      where override_assignment.machine_id=machine.id and override_assignment.status='configured'
        and lower(btrim(override_assignment.slot_code))=lower(btrim(profile.slot_code))
        and override_assignment.source_profile_id is distinct from profile.id)
    and not exists (select 1 from public.machine_component_profile_exclusions exclusion
      where exclusion.machine_id=machine.id and lower(btrim(exclusion.slot_code))=lower(btrim(profile.slot_code)) and exclusion.cleared_at is null)
  on conflict (machine_id,source_profile_id) where source_profile_id is not null do update set
    status='configured',retired_at=null,retired_by=null,component_id=excluded.component_id,
    slot_code=excluded.slot_code,display_order=excluded.display_order,tracking_method=excluded.tracking_method,
    baseline_expected_clicks=excluded.baseline_expected_clicks,
    healthy_threshold_percent=excluded.healthy_threshold_percent,
    watch_threshold_percent=excluded.watch_threshold_percent,
    warning_threshold_percent=excluded.warning_threshold_percent,
    critical_threshold_percent=excluded.critical_threshold_percent,
    updated_at=statement_timestamp(),updated_by=auth.uid();
  get diagnostics affected = row_count; changed := changed + affected;
  return changed;
end
$$;

-- Forward-only deterministic reconciliation. History-backed assignments are
-- retained by the function's lifecycle guard; no lifecycle or inventory rows
-- are created by synchronization.
do $$
declare account_record record;
begin
  for account_record in select id from public.accounts loop
    perform public.sync_machine_component_assignments_internal(account_record.id,null);
  end loop;
end
$$;

