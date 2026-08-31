-- M2.11G.1.2: catalog-first and slot-based manual assignment guardrails.
create or replace function public.add_machine_component_assignment(
  target_account_id uuid,target_machine_id uuid,target_component_id uuid,target_slot_code text,
  target_tracking_method public.component_tracking_method,target_baseline_expected_clicks bigint,
  target_notes text,target_client_request_id uuid)
returns public.machine_component_assignments language plpgsql security definer set search_path='' as $$
declare actor uuid:=auth.uid(); machine_record public.machines%rowtype; existing public.machine_component_assignments%rowtype;
  catalog_record public.components%rowtype; standard_slot public.machine_model_components%rowtype; normalized_slot text:=upper(btrim(target_slot_code)); model_name text;
begin
  if actor is null or not public.has_account_role(target_account_id,array['owner','admin']::public.account_role[]) then raise exception 'owner or admin role required' using errcode='42501'; end if;
  if target_client_request_id is null or normalized_slot='' then raise exception 'client request id and slot code are required' using errcode='22023'; end if;
  perform pg_advisory_xact_lock(hashtextextended(target_account_id::text||':component-assignment',0));
  select * into existing from public.machine_component_assignments where account_id=target_account_id and creation_request_id=target_client_request_id;
  if found then
    if existing.machine_id=target_machine_id and existing.component_id=target_component_id
      and lower(btrim(existing.slot_code))=lower(normalized_slot)
      and existing.tracking_method=target_tracking_method
      and existing.baseline_expected_clicks is not distinct from target_baseline_expected_clicks
      and existing.notes is not distinct from nullif(btrim(target_notes),'') then return existing; end if;
    raise exception 'client request id was already used with different assignment data' using errcode='23505';
  end if;
  select * into machine_record from public.machines where id=target_machine_id and account_id=target_account_id and is_active for update;
  if not found then raise exception '[INVALID_MACHINE_MODEL] active machine not found for this account' using errcode='P0002'; end if;
  select * into catalog_record from public.components where id=target_component_id and is_active and (account_id is null or account_id=target_account_id);
  if not found then raise exception '[COMPONENT_NOT_FOUND] active Component Catalog entry not found for this account' using errcode='23514'; end if;
  select profile.* into standard_slot from public.machine_model_components profile
    join public.machine_models model on model.id=profile.machine_model_id
    where profile.machine_model_id=machine_record.machine_model_id and profile.is_active
      and lower(btrim(profile.slot_code))=lower(normalized_slot) and (profile.account_id is null or profile.account_id=target_account_id)
      and not exists(select 1 from public.machine_component_profile_exclusions exclusion where exclusion.machine_id=target_machine_id and lower(btrim(exclusion.slot_code))=lower(normalized_slot) and exclusion.cleared_at is null)
      and exists(select 1 from public.machine_models model2 where model2.id=profile.machine_model_id and model2.is_active)
    limit 1;
  if found then select name into model_name from public.machine_models where id=machine_record.machine_model_id; end if;
  if found then raise exception '[STANDARD_PROFILE_SLOT] This slot is already defined as a standard component for %s. Use Sync Model Profile instead.', model_name using errcode='P0001'; end if;
  if exists(select 1 from public.machine_component_profile_exclusions exclusion where exclusion.machine_id=target_machine_id and lower(btrim(exclusion.slot_code))=lower(normalized_slot) and exclusion.cleared_at is null) then
    raise exception '[PROFILE_SLOT_EXCLUDED] This standard slot is currently excluded from this machine. Restore the Model Profile assignment if the component should be active.' using errcode='P0001';
  end if;
  if exists(select 1 from public.machine_component_assignments assignment where assignment.machine_id=target_machine_id and assignment.status='configured' and lower(btrim(assignment.slot_code))=lower(normalized_slot)) then
    raise exception '[EXISTING_MACHINE_ASSIGNMENT] This machine already has a component assigned to slot %s.', normalized_slot using errcode='P0001';
  end if;
  insert into public.machine_component_assignments(account_id,branch_id,machine_id,component_id,slot_code,tracking_method,baseline_expected_clicks,source_type,notes,creation_request_id,created_by)
  values(target_account_id,machine_record.branch_id,machine_record.id,target_component_id,normalized_slot,target_tracking_method,target_baseline_expected_clicks,'machine_specific',nullif(btrim(target_notes),''),target_client_request_id,actor) returning * into existing;
  return existing;
end $$;

comment on function public.add_machine_component_assignment(uuid,uuid,uuid,text,public.component_tracking_method,bigint,text,uuid)
  is 'Catalog-first manual assignment with model-profile slot, exclusion, duplicate, and tenant guardrails.';
