begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at)
values ('fa000000-0000-4000-8000-000000000001','authenticated','authenticated','restore-reconciliation@test.invalid','',now(),'{}','{}',now(),now());
insert into public.accounts(id,code,name) values ('fa100000-0000-4000-8000-000000000001','RESTORE-RECON','Restore Reconciliation');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at)
values ('fa200000-0000-4000-8000-000000000001','fa100000-0000-4000-8000-000000000001','fa000000-0000-4000-8000-000000000001','owner','active',now());
insert into public.branches(id,account_id,code,name)
values ('fa300000-0000-4000-8000-000000000001','fa100000-0000-4000-8000-000000000001','RECON','Reconciliation');
insert into public.manufacturers(id,code,name)
values ('fa400000-0000-4000-8000-000000000001','RESTORE-RECON','Restore Reconciliation');
insert into public.machine_models(id,manufacturer_id,model_code,name,machine_category,color_capability)
values ('fa500000-0000-4000-8000-000000000001','fa400000-0000-4000-8000-000000000001','RESTORE-RECON','Restore Reconciliation','digital_a3','color');
insert into public.components(id,account_id,code,name)
values
('fa600000-0000-4000-8000-000000000001',null,'RECON-GEAR-SHARED','Shared Gear'),
('fa600000-0000-4000-8000-000000000002','fa100000-0000-4000-8000-000000000001','RECON-GEAR-WORKSPACE','Workspace Gear');
insert into public.machine_model_components(id,account_id,machine_model_id,component_id,slot_code,tracking_method,display_order,created_at)
values
('fa700000-0000-4000-8000-000000000001',null,'fa500000-0000-4000-8000-000000000001','fa600000-0000-4000-8000-000000000001','GEAR','counter_based',1,'2026-08-28 00:00:00+00'),
('fa700000-0000-4000-8000-000000000002','fa100000-0000-4000-8000-000000000001','fa500000-0000-4000-8000-000000000001','fa600000-0000-4000-8000-000000000002','GEAR','counter_based',2,'2026-08-28 00:00:01+00'),
('fa700000-0000-4000-8000-000000000003','fa100000-0000-4000-8000-000000000001','fa500000-0000-4000-8000-000000000001','fa600000-0000-4000-8000-000000000002','GEAR_TEST','counter_based',3,'2026-08-28 00:00:02+00');
insert into public.machines(id,account_id,branch_id,machine_model_id,machine_code,display_name)
values ('fa800000-0000-4000-8000-000000000001','fa100000-0000-4000-8000-000000000001','fa300000-0000-4000-8000-000000000001','fa500000-0000-4000-8000-000000000001','RECON-01','Reconciliation Machine');

select extensions.is(
  (select source_profile_id from public.machine_component_assignments where machine_id='fa800000-0000-4000-8000-000000000001' and lower(btrim(slot_code))='gear' and status='configured'),
  'fa700000-0000-4000-8000-000000000002'::uuid,
  'workspace profile takes precedence over shared profile during sync'
);
select extensions.is(
  (select count(*)::int from public.machine_component_assignments where machine_id='fa800000-0000-4000-8000-000000000001' and status='configured'),
  2,
  'GEAR and GEAR_TEST remain independent active slots'
);

set local role authenticated;
select set_config('request.jwt.claim.sub','fa000000-0000-4000-8000-000000000001',true);
select extensions.lives_ok($$select public.manage_machine_component_profile('fa100000-0000-4000-8000-000000000001','fa700000-0000-4000-8000-000000000002','archive','fa900000-0000-4000-8000-000000000001')$$,'workspace profile archives');
select extensions.is(
  (select count(*)::int from public.machine_component_assignments where machine_id='fa800000-0000-4000-8000-000000000001' and lower(btrim(slot_code))='gear' and status='configured'),
  0,
  'archived workspace profile shadows shared and releases the effective machine slot'
);
select extensions.lives_ok($$select public.manage_machine_component_profile('fa100000-0000-4000-8000-000000000001','fa700000-0000-4000-8000-000000000002','restore','fa900000-0000-4000-8000-000000000002')$$,'workspace profile restores with a free scoped slot');
select extensions.is(
  (select source_profile_id from public.machine_component_assignments where machine_id='fa800000-0000-4000-8000-000000000001' and lower(btrim(slot_code))='gear' and status='configured'),
  'fa700000-0000-4000-8000-000000000002'::uuid,
  'restore provisions the effective workspace profile in the same sync call'
);

reset role;
insert into public.machine_model_components(id,account_id,machine_model_id,component_id,slot_code,tracking_method,display_order,is_active,created_at)
values ('fa700000-0000-4000-8000-000000000004','fa100000-0000-4000-8000-000000000001','fa500000-0000-4000-8000-000000000001','fa600000-0000-4000-8000-000000000002','GEAR','counter_based',4,false,'2026-08-28 00:00:00.5+00');
set local role authenticated;
select set_config('request.jwt.claim.sub','fa000000-0000-4000-8000-000000000001',true);
select extensions.throws_ok($$select public.manage_machine_component_profile('fa100000-0000-4000-8000-000000000001','fa700000-0000-4000-8000-000000000004','restore','fa900000-0000-4000-8000-000000000003')$$,'23505',null,'historical archived row cannot restore over the real active workspace owner');
select extensions.ok(not (select is_active from public.machine_model_components where id='fa700000-0000-4000-8000-000000000004'),'failed restore leaves historical row archived');
select extensions.ok((select is_active from public.machine_model_components where id='fa700000-0000-4000-8000-000000000002'),'failed restore leaves active owner unchanged');

reset role;
select extensions.is((select count(*)::int from public.machine_component_lifecycles where account_id='fa100000-0000-4000-8000-000000000001'),0,'archive restore and sync fabricate no lifecycle');
select extensions.is((select count(*)::int from public.inventory_movements where account_id='fa100000-0000-4000-8000-000000000001'),0,'archive restore and sync fabricate no inventory movement');
select extensions.is((select count(*)::int from public.inventory_cost_allocations where account_id='fa100000-0000-4000-8000-000000000001'),0,'archive restore and sync fabricate no FIFO allocation');

select * from extensions.finish();
rollback;
