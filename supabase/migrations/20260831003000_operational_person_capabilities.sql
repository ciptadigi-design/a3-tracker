alter table public.operational_person_branches
  add column if not exists can_record_counter boolean not null default false;

create or replace function public.is_counter_operator_for_branch(target_account_id uuid, target_person_id uuid, target_branch_id uuid)
returns boolean language sql stable security definer set search_path='' as $$
  select exists (
    select 1 from public.operational_people person
    join public.operational_person_branches assignment on assignment.operational_person_id=person.id and assignment.account_id=person.account_id
    join public.branches branch on branch.id=assignment.branch_id and branch.account_id=assignment.account_id
    where person.account_id=target_account_id and person.id=target_person_id and person.is_active
      and assignment.branch_id=target_branch_id and assignment.is_active and assignment.can_record_counter and branch.is_active
  )
$$;

revoke all on function public.is_counter_operator_for_branch(uuid,uuid,uuid) from public,anon,authenticated,service_role;
grant execute on function public.is_counter_operator_for_branch(uuid,uuid,uuid) to authenticated,service_role;

create or replace function public.validate_counter_operator_capability()
returns trigger language plpgsql security definer set search_path='' as $$
begin
  if new.source <> 'manual' or new.operator_person_id is null then return new; end if;
  if new.operator_person_id is null or not public.is_counter_operator_for_branch(new.account_id, new.operator_person_id, (select branch_id from public.machines where id=new.machine_id and account_id=new.account_id)) then
    raise exception 'counter operator is not eligible for this branch' using errcode='42501';
  end if;
  return new;
end $$;

revoke all on function public.validate_counter_operator_capability() from public,anon,authenticated,service_role;
grant execute on function public.validate_counter_operator_capability() to service_role;

drop trigger if exists counter_readings_operator_capability on public.counter_readings;
create trigger counter_readings_operator_capability
before insert on public.counter_readings
for each row execute function public.validate_counter_operator_capability();

-- New governance entry point keeps branch assignment and capability changes atomic.
create or replace function public.manage_operational_person_branches(
  target_account_id uuid,target_person_id uuid,target_branch_ids uuid[],target_counter_branch_ids uuid[],target_client_request_id uuid
) returns public.operational_people language plpgsql security definer set search_path='' as $$
declare person public.operational_people%rowtype; normalized uuid[]:=coalesce(target_branch_ids,array[]::uuid[]); counters uuid[]:=coalesce(target_counter_branch_ids,array[]::uuid[]); payload jsonb;
begin
  if not public.can_manage_account_governance(target_account_id) then raise exception 'workspace owner required' using errcode='42501'; end if;
  select * into person from public.operational_people where id=target_person_id and account_id=target_account_id for update;
  if not found then raise exception 'operational person not found' using errcode='P0002'; end if;
  if exists(select 1 from unnest(counters) c(id) where not (c.id=any(normalized))) then raise exception 'counter capability requires branch assignment' using errcode='22023'; end if;
  if exists(select 1 from unnest(normalized) b(id) left join public.branches branch on branch.id=b.id and branch.account_id=target_account_id and branch.is_active where branch.id is null) then raise exception 'branch assignment is invalid' using errcode='23503'; end if;
  payload:=jsonb_build_object('person_id',target_person_id,'branch_ids',normalized,'counter_branch_ids',counters);
  if not public.claim_settings_change(target_account_id,target_client_request_id,'operational_person.branches','operational_person',payload) then return person; end if;
  update public.operational_person_branches set is_active=false,can_record_counter=false,updated_at=statement_timestamp(),updated_by=auth.uid() where operational_person_id=target_person_id and is_active and not(branch_id=any(normalized));
  insert into public.operational_person_branches(account_id,operational_person_id,branch_id,can_record_counter,assigned_by,updated_by)
    select target_account_id,target_person_id,b.id,(b.id=any(counters)),auth.uid(),auth.uid() from unnest(normalized) b(id)
    on conflict(operational_person_id,branch_id) do update set is_active=true,can_record_counter=excluded.can_record_counter,updated_at=statement_timestamp(),updated_by=auth.uid();
  perform public.finish_settings_change(target_account_id,target_client_request_id,target_person_id::text,null,payload); return person;
end $$;
revoke all on function public.manage_operational_person_branches(uuid,uuid,uuid[],uuid[],uuid) from public,anon,authenticated,service_role;
grant execute on function public.manage_operational_person_branches(uuid,uuid,uuid[],uuid[],uuid) to authenticated,service_role;
