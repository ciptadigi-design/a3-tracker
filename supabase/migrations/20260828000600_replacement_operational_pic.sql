-- POST-M2.5A acceptance patch: align component replacement PIC selection with Daily Counter.
-- The existing UUID parameter remains backward compatible with account-member user IDs,
-- and now also accepts an active operational_people.id while preserving immutable snapshots.

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
  target_client_request_id uuid,
  target_inventory_source public.component_replacement_inventory_source,
  target_inventory_item_id uuid,
  target_inventory_location_id uuid,
  target_inventory_quantity numeric,
  target_external_inventory_reason text
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
  item_record public.inventory_items%rowtype;
  location_record public.inventory_locations%rowtype;
  issue_movement public.inventory_movements%rowtype;
  resolved_performer_id uuid := target_performed_by_user_id;
  resolved_performer_name text := nullif(btrim(target_performed_by_name_snapshot), '');
  normalized_notes text := nullif(btrim(target_notes), '');
  normalized_external_reason text := nullif(btrim(target_external_inventory_reason), '');
  resolved_learning boolean;
  current_quantity numeric(20,4);
  actor_name text;
  operational_person_id uuid;
  selected_person public.operational_people%rowtype;
  replacement_event_id uuid := gen_random_uuid();
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
  if target_inventory_source is null then
    raise exception 'inventory source is required' using errcode = '22023';
  elsif target_inventory_source = 'inventory' then
    if target_inventory_item_id is null or target_inventory_location_id is null then
      raise exception 'inventory item and stock location are required' using errcode = '22023';
    end if;
    if target_inventory_quantity is null or target_inventory_quantity <= 0
      or round(target_inventory_quantity, 4) <> target_inventory_quantity then
      raise exception 'inventory quantity must be positive with at most four decimal places' using errcode = '22003';
    end if;
    if normalized_external_reason is not null then
      raise exception 'external stock reason is only valid for external / untracked stock' using errcode = '22023';
    end if;
  else
    if normalized_external_reason is null then
      raise exception 'external / untracked stock reason is required' using errcode = '22023';
    end if;
    if target_inventory_item_id is not null or target_inventory_location_id is not null or target_inventory_quantity is not null then
      raise exception 'inventory item, location, and quantity must be empty for external / untracked stock' using errcode = '22023';
    end if;
  end if;

  resolved_learning := coalesce(target_include_in_adaptive_learning,target_replacement_reason in ('normal_eol','depleted','print_quality'));
  if resolved_performer_id is null and resolved_performer_name is null then resolved_performer_id := actor_id; end if;
  if resolved_performer_id is not null then
    select * into selected_person
    from public.operational_people person
    where person.id=resolved_performer_id and person.account_id=target_account_id and person.is_active;

    if found then
      operational_person_id := selected_person.id;
      resolved_performer_id := selected_person.linked_user_id;
      resolved_performer_name := selected_person.name;
    else
      if not exists (select 1 from public.account_memberships membership where membership.account_id=target_account_id
        and membership.user_id=resolved_performer_id and membership.status='active') then
        raise exception 'selected PIC is not an active operational person or account member' using errcode = '23514';
      end if;
      if resolved_performer_name is null then
        select nullif(btrim(profile.display_name),'') into resolved_performer_name
        from public.profiles profile where profile.user_id=resolved_performer_id;
      end if;
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
      and existing_event.notes is not distinct from normalized_notes
      and existing_event.inventory_source=target_inventory_source
      and ((target_inventory_source='external_untracked' and existing_event.external_inventory_reason=normalized_external_reason)
        or (target_inventory_source='inventory' and exists (
          select 1 from public.inventory_movements movement
          where movement.id=existing_event.inventory_movement_id
            and movement.inventory_item_id=target_inventory_item_id
            and movement.location_id=target_inventory_location_id
            and movement.quantity=-target_inventory_quantity))) then
      return existing_event;
    end if;
    raise exception 'client request id was already used for a different replacement' using errcode = '23505';
  end if;

  -- Deterministic lock order: machine -> lifecycle -> inventory item -> location.
  select * into machine_record from public.machines
  where id=target_machine_id and account_id=target_account_id and is_active for update;
  if not found then raise exception 'active machine not found in this account' using errcode = 'P0002'; end if;

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
      and existing_event.notes is not distinct from normalized_notes
      and existing_event.inventory_source=target_inventory_source
      and ((target_inventory_source='external_untracked' and existing_event.external_inventory_reason=normalized_external_reason)
        or (target_inventory_source='inventory' and exists (
          select 1 from public.inventory_movements movement where movement.id=existing_event.inventory_movement_id
            and movement.inventory_item_id=target_inventory_item_id and movement.location_id=target_inventory_location_id
            and movement.quantity=-target_inventory_quantity))) then return existing_event; end if;
    raise exception 'client request id was already used for a different replacement' using errcode = '23505';
  end if;

  select * into lifecycle_record from public.machine_component_lifecycles
  where id=target_lifecycle_id and account_id=target_account_id and machine_id=target_machine_id for update;
  if not found then raise exception 'component lifecycle not found for this machine' using errcode = 'P0002'; end if;
  if lifecycle_record.status='unknown' then raise exception 'unknown lifecycle must be initialized before replacement' using errcode = '22023'; end if;
  if lifecycle_record.status<>'active' then raise exception 'component lifecycle is no longer active' using errcode = '40001'; end if;

  select * into profile_record from public.machine_model_components profile
  where profile.machine_model_id=machine_record.machine_model_id
    and profile.component_id=lifecycle_record.component_id
    and lower(btrim(profile.slot_code))=lower(btrim(lifecycle_record.slot_code))
    and (profile.account_id is null or profile.account_id=target_account_id) and profile.is_active
  order by (profile.account_id=target_account_id) desc nulls last,profile.created_at desc,profile.id desc limit 1;
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

  -- Validate and lock stock before writing any counter or lifecycle fact.
  if target_inventory_source='inventory' then
    select * into item_record from public.inventory_items
    where id=target_inventory_item_id and account_id=target_account_id and is_active for update;
    if not found then raise exception 'active inventory item not found in this account' using errcode = 'P0002'; end if;
    if item_record.component_id is distinct from lifecycle_record.component_id then
      raise exception 'inventory item is not linked to the component being replaced' using errcode = '23514';
    end if;
    select * into location_record from public.inventory_locations
    where id=target_inventory_location_id and account_id=target_account_id and is_active for key share;
    if not found then raise exception 'active inventory location not found in this account' using errcode = 'P0002'; end if;
    select coalesce(sum(quantity),0) into current_quantity from public.inventory_movements
    where account_id=target_account_id and inventory_item_id=item_record.id and location_id=location_record.id;
    if current_quantity < target_inventory_quantity then
      raise exception 'Stock % di % tidak mencukupi. Tersedia % %.',
        item_record.name, location_record.name, current_quantity, item_record.unit using errcode = '22003';
    end if;
  end if;

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

  if target_inventory_source='inventory' then
    if operational_person_id is null then
      select person.id into operational_person_id from public.operational_people person
      where person.account_id=target_account_id and person.linked_user_id=resolved_performer_id and person.is_active
      order by person.created_at,person.id limit 1;
    end if;
    actor_name := coalesce(public.inventory_actor_name(actor_id), resolved_performer_name, 'Authenticated user');
    insert into public.inventory_movements(
      account_id,inventory_item_id,location_id,movement_type,quantity,unit_snapshot,occurred_at,
      operational_person_id,operational_person_name_snapshot,reference_type,reference_id,reason,notes,
      client_request_id,created_by,created_by_name_snapshot
    ) values (
      target_account_id,item_record.id,location_record.id,'issue',-target_inventory_quantity,item_record.unit,target_replaced_at,
      operational_person_id,resolved_performer_name,'component_replacement',replacement_event_id,
      'Machine component replacement',concat(machine_record.machine_code,' · ',lifecycle_record.slot_code),
      target_client_request_id,actor_id,actor_name
    ) returning * into issue_movement;
  end if;

  insert into public.component_replacement_events(
    id,account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code_snapshot,
    previous_lifecycle_id,new_lifecycle_id,previous_installed_counter,replacement_counter,actual_usage,
    expected_at_install,baseline_expected_snapshot,adaptive_expected_snapshot,replacement_reason,
    condition_at_removal,include_in_adaptive_learning,performed_by_user_id,performed_by_name_snapshot,
    replaced_at,notes,counter_reading_id,client_request_id,created_by,inventory_source,
    inventory_movement_id,external_inventory_reason
  ) values (
    replacement_event_id,target_account_id,machine_record.branch_id,target_machine_id,profile_record.id,profile_record.component_id,
    lifecycle_record.slot_code,lifecycle_record.id,new_lifecycle.id,lifecycle_record.installed_counter,
    target_replacement_counter,target_replacement_counter-lifecycle_record.installed_counter,
    lifecycle_record.expected_at_install,lifecycle_record.baseline_expected_clicks_snapshot,
    lifecycle_record.adaptive_expected_snapshot,target_replacement_reason,target_condition_at_removal,
    resolved_learning,resolved_performer_id,resolved_performer_name,target_replaced_at,normalized_notes,
    counter_reading_record.id,target_client_request_id,actor_id,target_inventory_source,
    issue_movement.id,normalized_external_reason
  ) returning * into existing_event;
  return existing_event;
end;
$$;
comment on function public.replace_machine_component(
  uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,
  public.component_removal_condition,boolean,uuid,text,text,uuid,
  public.component_replacement_inventory_source,uuid,uuid,numeric,text
) is 'Atomically replaces a component with inventory or external source evidence. PIC accepts an active operational person ID or a legacy active account-member user ID.';
