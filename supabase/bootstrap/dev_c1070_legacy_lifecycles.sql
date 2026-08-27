-- DEV-ONLY, GUARDED, ONE-TIME M2.3B LEGACY LIFECYCLE BOOTSTRAP.
-- Target project must be independently confirmed as sxitqjxljoqsnpepymrl.
-- This file is not a migration and is never loaded by seed.sql/db push.

begin;

do $$
declare
  target_machine public.machines%rowtype;
  latest_counter numeric(20,4);
  inserted_count integer;
  initialized_count integer;
  unknown_count integer;
  invalid_count integer;
begin
  select * into strict target_machine
  from public.machines
  where machine_code = 'CG-TUP-A3-01';

  if target_machine.machine_model_id <> '51000000-0000-0000-0000-000000000001'::uuid then
    raise exception 'CG-TUP-A3-01 is not linked to the approved AccurioPress C1070 model';
  end if;

  if exists (
    select 1 from public.machine_component_lifecycles
    where machine_id = target_machine.id
  ) then
    raise exception 'CG-TUP-A3-01 lifecycle bootstrap already ran; refusing duplicate bootstrap';
  end if;

  select reading.reading_value into latest_counter
  from public.counter_readings reading
  join public.counter_types counter_type on counter_type.id = reading.counter_type_id
  where reading.account_id = target_machine.account_id
    and reading.machine_id = target_machine.id
    and reading.status = 'effective'
    and lower(btrim(counter_type.code)) = 'total_impressions'
  order by reading.observed_at desc, reading.created_at desc, reading.id desc
  limit 1;

  if latest_counter is null then
    raise exception 'CG-TUP-A3-01 has no effective Total Impressions counter';
  end if;

  if latest_counter < 1437911 then
    raise exception 'current effective counter % predates legacy snapshot 1437911', latest_counter;
  end if;

  create temporary table m23b_legacy_rows (
    slot_code text primary key,
    legacy_used bigint not null,
    legacy_estimated_replacement bigint not null,
    historical_expected bigint not null,
    is_unknown boolean not null
  ) on commit drop;

  insert into m23b_legacy_rows values
    ('CHARGING_CORONA_C',32136,1445775,40000,false),
    ('CHARGING_CORONA_M',101463,1376448,40000,false),
    ('CHARGING_CORONA_Y',102387,1375524,40000,false),
    ('CHARGING_CORONA_K',154116,1323795,40000,false),
    ('CLEANING_BLADE',51348,1486563,100000,false),
    ('CLEANING_UNIT',1437911,200000,200000,true),
    ('DEVELOPER_C',22342,1515569,100000,false),
    ('DEVELOPER_M',163267,1374644,100000,false),
    ('DEVELOPER_Y',52462,1485449,100000,false),
    ('DEVELOPER_K',1437911,100000,100000,true),
    ('DEVELOPING_UNIT_C',1437911,200000,200000,true),
    ('DEVELOPING_UNIT_M',1437911,200000,200000,true),
    ('DEVELOPING_UNIT_Y',48450,1589461,200000,false),
    ('DEVELOPING_UNIT_K',1437911,200000,200000,true),
    ('DRUM_C',32136,1445775,40000,false),
    ('DRUM_M',97119,1380792,40000,false),
    ('DRUM_Y',51397,1426514,40000,false),
    ('DRUM_K',1437911,40000,40000,true),
    ('FUSER_BELT',39763,1598148,200000,false),
    ('GEAR',1437911,150000,150000,true),
    ('IBT',31635,1606276,200000,false),
    ('LASER_UNIT',1437911,300000,300000,true),
    ('ROLL_MESIN',1437911,150000,150000,true),
    ('SENSOR',1437911,250000,250000,true),
    ('TONER_C',7273,1444638,14000,false),
    ('TONER_M',2045,1449866,14000,false),
    ('TONER_Y',5516,1446395,14000,false),
    ('TONER_K',3245,1448666,14000,false);

  select count(*) into invalid_count
  from m23b_legacy_rows legacy
  where not legacy.is_unknown
    and 1437911 - (legacy.legacy_estimated_replacement - legacy.historical_expected)
      <> legacy.legacy_used;

  if invalid_count <> 0 then
    raise exception '% trusted legacy rows failed reconstruction; import aborted', invalid_count;
  end if;

  if exists (
    select 1 from m23b_legacy_rows legacy
    where legacy.is_unknown
      and not (
        legacy.legacy_used = 1437911
        and legacy.legacy_estimated_replacement = legacy.historical_expected
      )
  ) then
    raise exception 'unknown legacy sentinel classification failed validation';
  end if;

  with ranked_profiles as (
    select profile.*,
      row_number() over (
        partition by lower(btrim(profile.slot_code))
        order by (profile.account_id = target_machine.account_id) desc nulls last,
          profile.updated_at desc, profile.id
      ) as precedence
    from public.machine_model_components profile
    where profile.machine_model_id = target_machine.machine_model_id
      and (profile.account_id is null or profile.account_id = target_machine.account_id)
      and profile.is_active
  ), effective_profiles as (
    select * from ranked_profiles where precedence = 1
  )
  select count(*) into invalid_count
  from m23b_legacy_rows legacy
  left join effective_profiles profile on lower(btrim(profile.slot_code)) = lower(legacy.slot_code)
  where profile.id is null
    or lower(btrim(profile.slot_code)) = 'test_component'
    or profile.baseline_expected_clicks is null
    or (legacy.slot_code <> 'TONER_C' and profile.baseline_expected_clicks <> legacy.historical_expected)
    or (legacy.slot_code = 'TONER_C' and profile.baseline_expected_clicks <> 13500);

  if invalid_count <> 0 then
    raise exception '% DEV effective profiles differ from approved legacy bootstrap inputs', invalid_count;
  end if;

  with ranked_profiles as (
    select profile.*,
      row_number() over (
        partition by lower(btrim(profile.slot_code))
        order by (profile.account_id = target_machine.account_id) desc nulls last,
          profile.updated_at desc, profile.id
      ) as precedence
    from public.machine_model_components profile
    where profile.machine_model_id = target_machine.machine_model_id
      and (profile.account_id is null or profile.account_id = target_machine.account_id)
      and profile.is_active
  ), effective_profiles as (
    select * from ranked_profiles where precedence = 1
  )
  insert into public.machine_component_lifecycles (
    account_id, branch_id, machine_id, model_component_profile_id, component_id,
    slot_code, status, installed_counter, installed_at, installation_source,
    baseline_expected_clicks_snapshot, expected_at_install, notes
  )
  select
    target_machine.account_id,
    target_machine.branch_id,
    target_machine.id,
    profile.id,
    profile.component_id,
    legacy.slot_code,
    case when legacy.is_unknown then 'unknown' else 'active' end::public.machine_component_lifecycle_status,
    case when legacy.is_unknown then null
      else legacy.legacy_estimated_replacement - legacy.historical_expected end,
    null,
    'legacy_import'::public.component_installation_source,
    legacy.historical_expected,
    legacy.historical_expected,
    case
      when legacy.slot_code = 'TONER_C' then
        'Legacy import: installed counter reconstructed with historical 14,000 expectation; DEV profile was later overridden to 13,500. Lifecycle target intentionally preserves 14,000.'
      when legacy.is_unknown then
        'Legacy sentinel detected. Installation history unknown; no usage or health is inferred.'
      else
        'Legacy import: installed counter reconstructed from snapshot 1,437,911 and verified legacy expectation.'
    end
  from m23b_legacy_rows legacy
  join effective_profiles profile
    on lower(btrim(profile.slot_code)) = lower(legacy.slot_code);

  get diagnostics inserted_count = row_count;

  select count(*) filter (where status = 'active'), count(*) filter (where status = 'unknown')
  into initialized_count, unknown_count
  from public.machine_component_lifecycles
  where machine_id = target_machine.id;

  if inserted_count <> 28 or initialized_count <> 18 or unknown_count <> 10 then
    raise exception 'bootstrap count mismatch: inserted %, active %, unknown %',
      inserted_count, initialized_count, unknown_count;
  end if;

  if exists (
    select 1 from public.machine_component_lifecycles
    where machine_id = target_machine.id
      and lower(btrim(slot_code)) = 'test_component'
  ) then
    raise exception 'TEST_COMPONENT lifecycle exclusion failed';
  end if;

  raise notice 'M2.3B bootstrap verified current counter %, inserted 18 active and 10 unknown lifecycles', latest_counter;
end;
$$;

commit;

