-- M2.3D integration: future replacements snapshot the effective workspace profile.

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
    where membership.account_id = new.account_id and membership.user_id = new.performed_by_user_id
      and membership.status = 'active'
  ) then
    raise exception 'replacement performer is not an active account member' using errcode = '23514';
  end if;

  if new.counter_reading_id is not null then
    select * into linked_counter from public.counter_readings where id = new.counter_reading_id;
    if linked_counter.account_id <> new.account_id or linked_counter.machine_id <> new.machine_id
      or linked_counter.reading_value <> new.replacement_counter or linked_counter.observed_at <> new.replaced_at
      or linked_counter.status <> 'effective' or linked_counter.source <> 'component_replacement'
      or linked_counter.client_request_id <> new.client_request_id then
      raise exception 'replacement counter reading does not match event' using errcode = '23514';
    end if;
  end if;
  return new;
end;
$$;

create or replace function public.replace_machine_component(
  target_account_id uuid,
  target_machine_id uuid,
  target_lifecycle_id uuid,
  target_replacement_counter numeric,
  target_replaced_at timestamptz,
  target_replacement_reason public.component_replacement_reason,
  target_condition_at_removal public.component_removal_condition,
  target_include_in_adaptive_learning boolean,
  target_performed_by_user_id uuid,
  target_performed_by_name_snapshot text,
  target_notes text,
  target_client_request_id uuid
)
returns public.component_replacement_events
language plpgsql
security definer
set search_path = ''
as $$
declare
  actor_id uuid := (select auth.uid());
  machine_record public.machines%rowtype;
  lifecycle_record public.machine_component_lifecycles%rowtype;
  profile_record public.machine_model_components%rowtype;
  latest_reading public.counter_readings%rowtype;
  counter_type_record public.counter_types%rowtype;
  counter_reading_record public.counter_readings%rowtype;
  new_lifecycle public.machine_component_lifecycles%rowtype;
  existing_event public.component_replacement_events%rowtype;
  result_event public.component_replacement_events%rowtype;
  resolved_performer_id uuid := target_performed_by_user_id;
  resolved_performer_name text := nullif(btrim(target_performed_by_name_snapshot), '');
  normalized_notes text := nullif(btrim(target_notes), '');
  resolved_learning boolean;
begin
  if actor_id is null then raise exception 'authentication required' using errcode = '42501'; end if;
  if not public.has_account_role(target_account_id,array['owner','admin','technician','operator']::public.account_role[]) then
    raise exception 'active account membership required to replace a component' using errcode = '42501';
  end if;
  if target_client_request_id is null then raise exception 'client request id is required' using errcode = '22023'; end if;
  if target_replacement_counter is null or target_replacement_counter < 0 then
    raise exception 'replacement counter must be zero or greater' using errcode = '22003';
  end if;
  if target_replaced_at is null or target_replaced_at > statement_timestamp() + interval '5 minutes' then
    raise exception 'replacement date and time are required and cannot be in the future' using errcode = '22007';
  end if;
  if target_replacement_reason is null or target_condition_at_removal is null then
    raise exception 'replacement reason and removal condition are required' using errcode = '22023';
  end if;
  if target_replacement_reason = 'other' and normalized_notes is null then
    raise exception 'notes are required when replacement reason is other' using errcode = '22023';
  end if;

  resolved_learning := coalesce(target_include_in_adaptive_learning,target_replacement_reason in ('normal_eol','depleted','print_quality'));
  if resolved_performer_id is null and resolved_performer_name is null then resolved_performer_id := actor_id; end if;
  if resolved_performer_id is not null then
    if not exists (select 1 from public.account_memberships membership where membership.account_id=target_account_id
      and membership.user_id=resolved_performer_id and membership.status='active') then
      raise exception 'selected PIC is not an active account member' using errcode = '23514';
    end if;
    if resolved_performer_name is null then
      select nullif(btrim(profile.display_name),'') into resolved_performer_name from public.profiles profile where profile.user_id=resolved_performer_id;
    end if;
  end if;
  if resolved_performer_name is null then raise exception 'performed-by name is required' using errcode = '22023'; end if;

  select * into existing_event from public.component_replacement_events
  where account_id=target_account_id and client_request_id=target_client_request_id;
  if found then
    if existing_event.machine_id=target_machine_id and existing_event.previous_lifecycle_id=target_lifecycle_id
      and existing_event.replacement_counter=target_replacement_counter and existing_event.replaced_at=target_replaced_at
      and existing_event.replacement_reason=target_replacement_reason
      and existing_event.condition_at_removal=target_condition_at_removal
      and existing_event.include_in_adaptive_learning=resolved_learning
      and existing_event.performed_by_user_id is not distinct from resolved_performer_id
      and existing_event.performed_by_name_snapshot=resolved_performer_name
      and existing_event.notes is not distinct from normalized_notes then return existing_event; end if;
    raise exception 'client request id was already used for a different replacement' using errcode = '23505';
  end if;

  select * into machine_record from public.machines where id=target_machine_id and account_id=target_account_id and is_active for update;
  if not found then raise exception 'active machine not found in this account' using errcode = 'P0002'; end if;

  select * into existing_event from public.component_replacement_events
  where account_id=target_account_id and client_request_id=target_client_request_id;
  if found then return existing_event; end if;

  select * into lifecycle_record from public.machine_component_lifecycles
  where id=target_lifecycle_id and account_id=target_account_id and machine_id=target_machine_id for update;
  if not found then raise exception 'component lifecycle not found for this machine' using errcode = 'P0002'; end if;
  if lifecycle_record.status='unknown' then raise exception 'unknown lifecycle must be initialized before replacement' using errcode = '22023'; end if;
  if lifecycle_record.status<>'active' then raise exception 'component lifecycle is no longer active' using errcode = '40001'; end if;

  -- Resolve the current effective workspace override for this model-slot. The
  -- installed lifecycle keeps its original profile/snapshots; only the new
  -- lifecycle receives the newly approved effective profile.
  select * into profile_record from public.machine_model_components profile
  where profile.machine_model_id=machine_record.machine_model_id
    and profile.component_id=lifecycle_record.component_id
    and lower(btrim(profile.slot_code))=lower(btrim(lifecycle_record.slot_code))
    and (profile.account_id is null or profile.account_id=target_account_id)
    and profile.is_active
  order by (profile.account_id=target_account_id) desc nulls last, profile.created_at desc, profile.id desc
  limit 1;
  if not found then raise exception 'active effective component profile does not match lifecycle and machine' using errcode = '23514'; end if;
  if lower(btrim(profile_record.slot_code))='test_component' then raise exception 'TEST_COMPONENT replacement is not allowed' using errcode = '23514'; end if;
  if profile_record.baseline_expected_clicks is null then raise exception 'component profile needs an expected baseline before replacement' using errcode = '23514'; end if;

  select * into counter_type_record from public.counter_types counter_type
  where lower(btrim(counter_type.code))='total_impressions' and counter_type.is_active;
  if not found then raise exception 'active Total Impressions counter type not found' using errcode = 'P0002'; end if;
  if round(target_replacement_counter,counter_type_record.decimal_scale)<>target_replacement_counter then
    raise exception 'replacement counter has more decimal places than Total Impressions allows' using errcode = '22003';
  end if;
  select * into latest_reading from public.counter_readings reading
  where reading.account_id=target_account_id and reading.machine_id=target_machine_id
    and reading.counter_type_id=counter_type_record.id and reading.status='effective'
  order by reading.observed_at desc,reading.created_at desc,reading.id desc limit 1;
  if not found then raise exception 'machine has no effective Total Impressions counter' using errcode = 'P0002'; end if;
  if target_replacement_counter<latest_reading.reading_value then
    raise exception 'replacement counter cannot be lower than the latest effective counter; use Daily Counter correction' using errcode = '22003';
  end if;
  if target_replaced_at<latest_reading.observed_at then raise exception 'replacement date cannot be earlier than the latest effective counter' using errcode = '22007'; end if;
  if target_replacement_counter<lifecycle_record.installed_counter then raise exception 'replacement counter cannot be lower than the installed counter' using errcode = '22003'; end if;

  if target_replacement_counter>latest_reading.reading_value then
    insert into public.counter_readings(account_id,machine_id,counter_type_id,reading_value,observed_at,entered_by,source,previous_reading_id,notes,client_request_id,created_by)
    values(target_account_id,target_machine_id,counter_type_record.id,target_replacement_counter,target_replaced_at,actor_id,
      'component_replacement',latest_reading.id,concat('Component replacement: ',lifecycle_record.slot_code),target_client_request_id,actor_id)
    returning * into counter_reading_record;
  end if;

  update public.machine_component_lifecycles set status='closed',removed_counter=target_replacement_counter,
    removed_at=target_replaced_at,actual_usage=target_replacement_counter-lifecycle_record.installed_counter
  where id=lifecycle_record.id;

  insert into public.machine_component_lifecycles(
    account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code,status,
    installed_counter,installed_at,installation_source,baseline_expected_clicks_snapshot,
    expected_at_install,adaptive_expected_snapshot,created_by,notes
  ) values (
    target_account_id,machine_record.branch_id,target_machine_id,profile_record.id,profile_record.component_id,
    profile_record.slot_code,'active',target_replacement_counter,target_replaced_at,'replacement',
    profile_record.baseline_expected_clicks,profile_record.baseline_expected_clicks,null,actor_id,normalized_notes
  ) returning * into new_lifecycle;

  insert into public.component_replacement_events(
    account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code_snapshot,
    previous_lifecycle_id,new_lifecycle_id,previous_installed_counter,replacement_counter,actual_usage,
    expected_at_install,baseline_expected_snapshot,adaptive_expected_snapshot,replacement_reason,
    condition_at_removal,include_in_adaptive_learning,performed_by_user_id,performed_by_name_snapshot,
    replaced_at,notes,counter_reading_id,client_request_id,created_by
  ) values (
    target_account_id,machine_record.branch_id,target_machine_id,profile_record.id,profile_record.component_id,
    lifecycle_record.slot_code,lifecycle_record.id,new_lifecycle.id,lifecycle_record.installed_counter,
    target_replacement_counter,target_replacement_counter-lifecycle_record.installed_counter,
    lifecycle_record.expected_at_install,lifecycle_record.baseline_expected_clicks_snapshot,
    lifecycle_record.adaptive_expected_snapshot,target_replacement_reason,target_condition_at_removal,
    resolved_learning,resolved_performer_id,resolved_performer_name,target_replaced_at,normalized_notes,
    counter_reading_record.id,target_client_request_id,actor_id
  ) returning * into result_event;
  return result_event;
end;
$$;

revoke all on function public.replace_machine_component(
  uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,
  public.component_removal_condition,boolean,uuid,text,text,uuid
) from public, anon, authenticated, service_role;
grant execute on function public.replace_machine_component(
  uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,
  public.component_removal_condition,boolean,uuid,text,text,uuid
) to authenticated;
