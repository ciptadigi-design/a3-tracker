-- M2.11G.1: configured machine-specific assignments may initialize lifecycle.
-- The existing profile-based RPC remains unchanged for inherited callers.
alter table public.machine_component_lifecycles
  alter column model_component_profile_id drop not null;

create or replace function public.validate_machine_component_lifecycle()
returns trigger language plpgsql set search_path = '' as $$
declare machine_record public.machines%rowtype; profile_record public.machine_model_components%rowtype; assignment public.machine_component_assignments%rowtype;
begin
  select * into machine_record from public.machines where id=new.machine_id;
  if not found or machine_record.account_id<>new.account_id or machine_record.branch_id<>new.branch_id then raise exception 'machine does not belong to lifecycle account and branch' using errcode='23514'; end if;
  if new.model_component_profile_id is null then
    select * into assignment from public.machine_component_assignments where id=new.machine_component_assignment_id;
    if not found or assignment.machine_id<>new.machine_id or assignment.account_id<>new.account_id or assignment.component_id<>new.component_id or lower(btrim(assignment.slot_code))<>lower(btrim(new.slot_code)) then raise exception 'machine component assignment does not match lifecycle identity' using errcode='23514'; end if;
  else
    select * into profile_record from public.machine_model_components where id=new.model_component_profile_id;
    if not found or profile_record.machine_model_id<>machine_record.machine_model_id or profile_record.component_id<>new.component_id or lower(btrim(profile_record.slot_code))<>lower(btrim(new.slot_code)) or (profile_record.account_id is not null and profile_record.account_id<>new.account_id) then raise exception 'component profile does not match lifecycle machine, component, slot, or account' using errcode='23514'; end if;
  end if;
  if lower(btrim(new.slot_code))='test_component' then raise exception 'TEST_COMPONENT cannot be bootstrapped as a machine lifecycle' using errcode='23514'; end if;
  if tg_op='UPDATE' then
    if new.account_id<>old.account_id or new.branch_id<>old.branch_id or new.machine_id<>old.machine_id or new.model_component_profile_id is distinct from old.model_component_profile_id or new.machine_component_assignment_id is distinct from old.machine_component_assignment_id or new.component_id<>old.component_id or new.slot_code<>old.slot_code or (not (old.status='unknown' and new.status='active') and (new.baseline_expected_clicks_snapshot<>old.baseline_expected_clicks_snapshot or new.expected_at_install<>old.expected_at_install or new.installation_source<>old.installation_source)) then raise exception 'lifecycle identity and installation snapshots are immutable' using errcode='42501'; end if;
    new.updated_at:=statement_timestamp();
  end if;
  return new;
end $$;

create or replace function public.initialize_machine_component_assignment_lifecycle(
  target_account_id uuid,
  target_machine_id uuid,
  target_assignment_id uuid,
  target_installed_counter numeric default null,
  target_installed_at timestamptz default null,
  target_client_request_id uuid default null,
  target_notes text default null
)
returns public.machine_component_lifecycles
language plpgsql security definer set search_path = ''
as $$
declare actor_id uuid := auth.uid(); machine_record public.machines%rowtype;
  assignment public.machine_component_assignments%rowtype; lifecycle_record public.machine_component_lifecycles%rowtype;
  latest_counter numeric(20,4); resolved_counter numeric(20,4);
begin
  if actor_id is null or not public.has_account_role(target_account_id, array['owner','admin']::public.account_role[]) then
    raise exception 'owner or admin role required to initialize lifecycle' using errcode='42501';
  end if;
  if target_client_request_id is null then raise exception 'client request id is required' using errcode='22023'; end if;
  select * into machine_record from public.machines where id=target_machine_id and account_id=target_account_id and is_active for update;
  if not found then raise exception 'active machine not found in this account' using errcode='P0002'; end if;
  select * into assignment from public.machine_component_assignments where id=target_assignment_id and account_id=target_account_id and machine_id=target_machine_id and status='configured' for update;
  if not found then raise exception 'configured machine component assignment not found' using errcode='P0002'; end if;
  if assignment.source_type <> 'machine_specific' or assignment.source_profile_id is not null then
    raise exception 'assignment is not machine-specific' using errcode='23514';
  end if;
  if assignment.tracking_method <> 'counter_based'::public.component_tracking_method then
    raise exception 'only counter-based lifecycle tracking is currently supported' using errcode='23514';
  end if;
  if assignment.baseline_expected_clicks is null or assignment.baseline_expected_clicks <= 0 then
    raise exception 'machine-specific assignment needs a positive expected baseline before initialization' using errcode='23514';
  end if;
  select reading.reading_value into latest_counter from public.counter_readings reading join public.counter_types ct on ct.id=reading.counter_type_id
    where reading.account_id=target_account_id and reading.machine_id=target_machine_id and reading.status='effective' and lower(btrim(ct.code))='total_impressions'
    order by reading.observed_at desc,reading.created_at desc,reading.id desc limit 1;
  if latest_counter is null then raise exception 'machine has no effective Total Impressions counter' using errcode='P0002'; end if;
  resolved_counter := coalesce(target_installed_counter, latest_counter);
  if target_installed_counter is null and target_installed_at is not null then raise exception 'replacement date requires a known historical replacement counter' using errcode='22023'; end if;
  if resolved_counter < 0 or resolved_counter > latest_counter then raise exception 'installation counter must be between zero and the current effective counter' using errcode='22003'; end if;
  select * into lifecycle_record from public.machine_component_lifecycles where account_id=target_account_id and initialization_request_id=target_client_request_id;
  if found then return lifecycle_record; end if;
  select * into lifecycle_record from public.machine_component_lifecycles where machine_component_assignment_id=assignment.id and status in ('unknown','active') for update;
  if lifecycle_record.status='active' then raise exception 'this machine component lifecycle is already active' using errcode='23505'; end if;
  if lifecycle_record.id is not null then
    update public.machine_component_lifecycles set status='active',installed_counter=resolved_counter,installed_at=target_installed_at,
      installation_source=case when target_installed_counter is null then 'tracking_start' else 'manual_historical' end,
      baseline_expected_clicks_snapshot=assignment.baseline_expected_clicks,expected_at_install=assignment.baseline_expected_clicks,
      initialization_request_id=target_client_request_id,notes=nullif(btrim(target_notes),'') where id=lifecycle_record.id returning * into lifecycle_record;
  else
    insert into public.machine_component_lifecycles(account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code,status,installed_counter,installed_at,installation_source,baseline_expected_clicks_snapshot,expected_at_install,initialization_request_id,created_by,notes,machine_component_assignment_id)
      values(target_account_id,machine_record.branch_id,target_machine_id,null,assignment.component_id,assignment.slot_code,'active',resolved_counter,target_installed_at,
        case when target_installed_counter is null then 'tracking_start' else 'manual_historical' end,assignment.baseline_expected_clicks,assignment.baseline_expected_clicks,target_client_request_id,actor_id,nullif(btrim(target_notes),''),assignment.id) returning * into lifecycle_record;
  end if;
  return lifecycle_record;
end $$;

revoke all on function public.initialize_machine_component_assignment_lifecycle(uuid,uuid,uuid,numeric,timestamptz,uuid,text) from public,anon,service_role;
grant execute on function public.initialize_machine_component_assignment_lifecycle(uuid,uuid,uuid,numeric,timestamptz,uuid,text) to authenticated;

create function public.reconcile_manual_component_assignment(target_account_id uuid,target_assignment_id uuid,target_profile_id uuid,target_client_request_id uuid)
returns public.machine_component_assignments language plpgsql security definer set search_path='' as $$
declare actor uuid:=auth.uid(); a public.machine_component_assignments%rowtype; p public.machine_model_components%rowtype; m public.machines%rowtype; result public.machine_component_assignments%rowtype;
begin
  if actor is null or not public.has_account_role(target_account_id,array['owner','admin']::public.account_role[]) then raise exception 'owner or admin role required' using errcode='42501'; end if;
  if target_client_request_id is null then raise exception 'client request id is required' using errcode='22023'; end if;
  perform pg_advisory_xact_lock(hashtextextended(target_account_id::text||':component-assignment',0));
  select * into a from public.machine_component_assignments where id=target_assignment_id and account_id=target_account_id and status='configured' for update;
  if not found or a.source_type<>'machine_specific' then raise exception 'configured machine-specific assignment not found' using errcode='P0002'; end if;
  select * into m from public.machines where id=a.machine_id and account_id=target_account_id and is_active;
  select * into p from public.machine_model_components where id=target_profile_id and machine_model_id=m.machine_model_id and component_id=a.component_id and lower(btrim(slot_code))=lower(btrim(a.slot_code)) and (account_id is null or account_id=target_account_id) and is_active;
  if not found then raise exception 'profile slot does not deterministically match assignment' using errcode='23514'; end if;
  if exists(select 1 from public.machine_component_profile_exclusions where account_id=target_account_id and machine_id=a.machine_id and lower(btrim(slot_code))=lower(btrim(a.slot_code)) and cleared_at is null) then raise exception 'active profile exclusion blocks reconciliation' using errcode='23514'; end if;
  if exists(select 1 from public.machine_component_assignments where machine_id=a.machine_id and status='configured' and id<>a.id and lower(btrim(slot_code))=lower(btrim(a.slot_code))) then raise exception 'a conflicting configured assignment already exists' using errcode='23505'; end if;
  update public.machine_component_assignments set source_type='model_profile',source_profile_id=p.id,tracking_method=p.tracking_method,baseline_expected_clicks=p.baseline_expected_clicks,updated_by=actor where id=a.id returning * into result;
  return result;
end $$;
revoke all on function public.reconcile_manual_component_assignment(uuid,uuid,uuid,uuid) from public,anon,service_role;
grant execute on function public.reconcile_manual_component_assignment(uuid,uuid,uuid,uuid) to authenticated;

create or replace function public.get_manual_component_reconciliation_candidate(target_account_id uuid, target_assignment_id uuid)
returns jsonb language plpgsql security definer set search_path='' as $$
declare a public.machine_component_assignments%rowtype; m public.machines%rowtype; p public.machine_model_components%rowtype; eligible boolean := false; reason text;
begin
  select * into a from public.machine_component_assignments where id=target_assignment_id and account_id=target_account_id;
  select * into m from public.machines where id=a.machine_id and account_id=target_account_id and is_active;
  select * into p from public.machine_model_components where machine_model_id=m.machine_model_id and component_id=a.component_id and lower(btrim(slot_code))=lower(btrim(a.slot_code)) and is_active and (account_id is null or account_id=target_account_id) order by (account_id is not null) desc, created_at desc, id desc limit 1;
  if a.id is null or a.source_type <> 'machine_specific' then reason:='Assignment is not a machine-specific component';
  elsif p.id is null then reason:='No deterministic compatible profile slot is available';
  elsif exists(select 1 from public.machine_component_profile_exclusions where account_id=target_account_id and machine_id=a.machine_id and model_component_profile_id=p.id and cleared_at is null) then reason:='Active profile exclusion blocks reconciliation';
  elsif exists(select 1 from public.machine_component_assignments where machine_id=a.machine_id and status='configured' and id<>a.id and lower(btrim(slot_code))=lower(btrim(a.slot_code))) then reason:='A conflicting configured assignment already exists';
  else eligible:=true;
  end if;
  return jsonb_build_object('eligible',eligible,'reason',reason,'machine_component_id',a.id,'current_slot_code',a.slot_code,'profile_slot_id',p.id,'profile_slot_code',p.slot_code,'machine_model',m.machine_model_id,'component',a.component_id,'preserves_identity',true);
end $$;
revoke all on function public.get_manual_component_reconciliation_candidate(uuid,uuid) from public,anon,service_role;
grant execute on function public.get_manual_component_reconciliation_candidate(uuid,uuid) to authenticated;
