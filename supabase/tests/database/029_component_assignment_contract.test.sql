begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

select extensions.ok((select relrowsecurity from pg_class where oid='public.machine_component_assignments'::regclass),'machine assignments have RLS');
select extensions.ok((select relrowsecurity from pg_class where oid='public.machine_component_profile_exclusions'::regclass),'machine exclusions have RLS');
select extensions.ok((select prosecdef and proconfig @> array['search_path=""']::text[] from pg_proc where oid='public.remove_machine_component_assignment(uuid,uuid,text,uuid)'::regprocedure),'remove RPC is SECURITY DEFINER with empty search path');
select extensions.ok(not has_table_privilege('authenticated','public.machine_component_assignments','INSERT') and not has_table_privilege('authenticated','public.machine_component_assignments','UPDATE') and not has_table_privilege('authenticated','public.machine_component_assignments','DELETE'),'assignment mutation is RPC-only');

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('e0000000-0000-4000-8000-000000000001','authenticated','authenticated','assignment-owner@test.invalid','',now(),'{}','{}',now(),now()),
('e0000000-0000-4000-8000-000000000002','authenticated','authenticated','assignment-admin@test.invalid','',now(),'{}','{}',now(),now()),
('e0000000-0000-4000-8000-000000000003','authenticated','authenticated','assignment-tech@test.invalid','',now(),'{}','{}',now(),now()),
('e0000000-0000-4000-8000-000000000004','authenticated','authenticated','assignment-operator@test.invalid','',now(),'{}','{}',now(),now()),
('e0000000-0000-4000-8000-000000000005','authenticated','authenticated','assignment-suspended@test.invalid','',now(),'{}','{}',now(),now()),
('e0000000-0000-4000-8000-000000000006','authenticated','authenticated','assignment-other@test.invalid','',now(),'{}','{}',now(),now());
insert into public.accounts(id,code,name) values
('e0100000-0000-4000-8000-000000000001','ASSIGN-A','Assignment A'),('e0100000-0000-4000-8000-000000000002','ASSIGN-B','Assignment B');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at) values
('e0200000-0000-4000-8000-000000000001','e0100000-0000-4000-8000-000000000001','e0000000-0000-4000-8000-000000000001','owner','active',now()),
('e0200000-0000-4000-8000-000000000002','e0100000-0000-4000-8000-000000000001','e0000000-0000-4000-8000-000000000002','admin','active',now()),
('e0200000-0000-4000-8000-000000000003','e0100000-0000-4000-8000-000000000001','e0000000-0000-4000-8000-000000000003','technician','active',now()),
('e0200000-0000-4000-8000-000000000004','e0100000-0000-4000-8000-000000000001','e0000000-0000-4000-8000-000000000004','operator','active',now()),
('e0200000-0000-4000-8000-000000000005','e0100000-0000-4000-8000-000000000001','e0000000-0000-4000-8000-000000000005','admin','suspended',now()),
('e0200000-0000-4000-8000-000000000006','e0100000-0000-4000-8000-000000000002','e0000000-0000-4000-8000-000000000006','owner','active',now());
insert into public.branches(id,account_id,code,name) values ('e0300000-0000-4000-8000-000000000001','e0100000-0000-4000-8000-000000000001','A','Assignment Branch');
insert into public.manufacturers(id,code,name) values ('e0400000-0000-4000-8000-000000000001','ASSIGN-MFG','Assignment Manufacturer');
insert into public.machine_models(id,manufacturer_id,model_code,name,machine_category,color_capability) values
('e0500000-0000-4000-8000-000000000001','e0400000-0000-4000-8000-000000000001','ASSIGN-MODEL','Assignment Model','digital_a3','color'),
('e0500000-0000-4000-8000-000000000002','e0400000-0000-4000-8000-000000000001','EMPTY-MODEL','Empty Model','digital_a3','color');
insert into public.components(id,account_id,code,name,category) values
('e0600000-0000-4000-8000-000000000001','e0100000-0000-4000-8000-000000000001','GEAR','Gear','Drive'),
('e0600000-0000-4000-8000-000000000002','e0100000-0000-4000-8000-000000000001','ROLLER','Cleaning Roller','Cleaning'),
('e0600000-0000-4000-8000-000000000003','e0100000-0000-4000-8000-000000000001','UNUSED','Unused Catalog','Test');
insert into public.machine_model_components(id,account_id,machine_model_id,component_id,slot_code,tracking_method,baseline_expected_clicks,display_order) values
('e0700000-0000-4000-8000-000000000001','e0100000-0000-4000-8000-000000000001','e0500000-0000-4000-8000-000000000001','e0600000-0000-4000-8000-000000000001','GEAR-A','counter_based',150000,1),
('e0700000-0000-4000-8000-000000000002','e0100000-0000-4000-8000-000000000001','e0500000-0000-4000-8000-000000000001','e0600000-0000-4000-8000-000000000001','GEAR-B','counter_based',150000,2);
insert into public.machines(id,account_id,branch_id,machine_model_id,machine_code,display_name) values
('e0800000-0000-4000-8000-000000000001','e0100000-0000-4000-8000-000000000001','e0300000-0000-4000-8000-000000000001','e0500000-0000-4000-8000-000000000001','ASSIGN-01','Assignment One'),
('e0800000-0000-4000-8000-000000000002','e0100000-0000-4000-8000-000000000001','e0300000-0000-4000-8000-000000000001','e0500000-0000-4000-8000-000000000001','ASSIGN-02','Assignment Two'),
('e0800000-0000-4000-8000-000000000003','e0100000-0000-4000-8000-000000000001','e0300000-0000-4000-8000-000000000001','e0500000-0000-4000-8000-000000000002','ASSIGN-EMPTY','Empty Model Machine');

select extensions.is((select count(*)::int from public.machine_component_assignments where machine_id='e0800000-0000-4000-8000-000000000001' and component_id='e0600000-0000-4000-8000-000000000001' and status='configured'),2,'same logical Gear provisions two distinct slots');
select extensions.is((select count(distinct lower(slot_code))::int from public.machine_component_assignments where machine_id='e0800000-0000-4000-8000-000000000001'),2,'slot code is physical assignment identity');
select extensions.is((select count(*)::int from public.machine_component_assignments where machine_id='e0800000-0000-4000-8000-000000000003'),0,'machine with model but zero profiles gets zero components');
select extensions.throws_ok($$insert into public.machine_model_components(account_id,machine_model_id,component_id,slot_code,tracking_method) values('e0100000-0000-4000-8000-000000000001','e0500000-0000-4000-8000-000000000001','e0600000-0000-4000-8000-000000000002',' gear-a ','counter_based')$$,'23505',null,'same model and normalized slot is rejected');
select extensions.throws_ok($$update public.machine_model_components set slot_code='GEAR-X' where id='e0700000-0000-4000-8000-000000000001'$$,'42501',null,'slot code is immutable after provisioning');
select extensions.is(public.sync_machine_component_assignments_internal('e0100000-0000-4000-8000-000000000001',null)>=0,true,'repeated internal sync is safe');
select extensions.is((select count(*)::int from public.machine_component_assignments where machine_id in ('e0800000-0000-4000-8000-000000000001','e0800000-0000-4000-8000-000000000002')),4,'idempotent sync creates no duplicate machine slots');

insert into public.machine_model_components(id,account_id,machine_model_id,component_id,slot_code,tracking_method,baseline_expected_clicks,display_order) values
('e0700000-0000-4000-8000-000000000003','e0100000-0000-4000-8000-000000000001','e0500000-0000-4000-8000-000000000001','e0600000-0000-4000-8000-000000000001','GEAR-C','counter_based',150000,3);
insert into public.machine_component_lifecycles(id,account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code,status,installation_source,baseline_expected_clicks_snapshot,expected_at_install) values
('e0850000-0000-4000-8000-000000000001','e0100000-0000-4000-8000-000000000001','e0300000-0000-4000-8000-000000000001','e0800000-0000-4000-8000-000000000002','e0700000-0000-4000-8000-000000000003','e0600000-0000-4000-8000-000000000001','GEAR-C','unknown','legacy_import',150000,150000);

set local role authenticated;
select set_config('request.jwt.claim.sub','e0000000-0000-4000-8000-000000000001',true);
select extensions.lives_ok($$select public.save_machine_model_component_profile('e0100000-0000-4000-8000-000000000001','e0500000-0000-4000-8000-000000000001',null,'e0600000-0000-4000-8000-000000000002','ROLLER-P',4,'counter_based',90000,true,30,15,5,0,'Profile retry','e0900000-0000-4000-8000-000000000011')$$,'owner creates profile through idempotent RPC');
select extensions.lives_ok($$select public.save_machine_model_component_profile('e0100000-0000-4000-8000-000000000001','e0500000-0000-4000-8000-000000000001',null,'e0600000-0000-4000-8000-000000000002','ROLLER-P',4,'counter_based',90000,true,30,15,5,0,'Profile retry','e0900000-0000-4000-8000-000000000011')$$,'identical profile retry returns existing profile');
select extensions.is((select count(*)::int from public.machine_model_components where account_id='e0100000-0000-4000-8000-000000000001' and slot_code='ROLLER-P'),1,'profile retry creates one slot');
select extensions.is((select count(*)::int from public.machine_component_assignments where source_profile_id=(select id from public.machine_model_components where account_id='e0100000-0000-4000-8000-000000000001' and slot_code='ROLLER-P')),2,'profile created after machines provisions both eligible machines');
select extensions.throws_ok($$select public.save_machine_model_component_profile('e0100000-0000-4000-8000-000000000001','e0500000-0000-4000-8000-000000000001',null,'e0600000-0000-4000-8000-000000000002','ROLLER-CHANGED',4,'counter_based',90000,true,30,15,5,0,'Changed','e0900000-0000-4000-8000-000000000011')$$,'23505',null,'changed profile retry is rejected');
select extensions.lives_ok($$select public.remove_machine_component_assignment('e0100000-0000-4000-8000-000000000001',(select id from public.machine_component_assignments where machine_id='e0800000-0000-4000-8000-000000000001' and slot_code='GEAR-A'),'Machine-specific exclusion','e0900000-0000-4000-8000-000000000001')$$,'owner removes uninitialized inherited slot');
select extensions.is((select count(*)::int from public.machine_component_configuration where machine_id='e0800000-0000-4000-8000-000000000001' and slot_code='GEAR-A'),0,'removed slot disappears from machine configuration');
select extensions.is(public.sync_machine_component_assignments('e0100000-0000-4000-8000-000000000001','e0800000-0000-4000-8000-000000000001')>=0,true,'explicit sync succeeds');
select extensions.is((select count(*)::int from public.machine_component_configuration where machine_id='e0800000-0000-4000-8000-000000000001' and slot_code='GEAR-A'),0,'sync respects durable machine exclusion');
select extensions.is((select count(*)::int from public.machine_component_configuration where machine_id='e0800000-0000-4000-8000-000000000002' and slot_code='GEAR-A'),1,'other machine remains inherited');
select extensions.lives_ok($$select public.clear_machine_component_exclusion('e0100000-0000-4000-8000-000000000001','e0800000-0000-4000-8000-000000000001','e0700000-0000-4000-8000-000000000001','e0900000-0000-4000-8000-000000000002')$$,'owner clears exclusion');
select extensions.lives_ok($$select public.clear_machine_component_exclusion('e0100000-0000-4000-8000-000000000001','e0800000-0000-4000-8000-000000000001','e0700000-0000-4000-8000-000000000001','e0900000-0000-4000-8000-000000000002')$$,'identical clear-exclusion retry returns restored assignment');
select extensions.throws_ok($$select public.clear_machine_component_exclusion('e0100000-0000-4000-8000-000000000001','e0800000-0000-4000-8000-000000000002','e0700000-0000-4000-8000-000000000001','e0900000-0000-4000-8000-000000000002')$$,'23505',null,'clear-exclusion request key rejects a changed machine payload');
select extensions.ok((select lifecycle_id is null and lifecycle_status='unknown' from public.machine_component_configuration where machine_id='e0800000-0000-4000-8000-000000000001' and slot_code='GEAR-A'),'re-included slot is UNKNOWN without fabricated lifecycle');

select extensions.lives_ok($$select public.add_machine_component_assignment('e0100000-0000-4000-8000-000000000001','e0800000-0000-4000-8000-000000000001','e0600000-0000-4000-8000-000000000002','ROLLER-X','counter_based',80000,'Only machine one','e0900000-0000-4000-8000-000000000003')$$,'machine-specific component can be added');
select extensions.is((select source_type::text from public.machine_component_assignments where creation_request_id='e0900000-0000-4000-8000-000000000003'),'machine_specific','manual assignment origin is explicit');
select extensions.is((select count(*)::int from public.machine_model_components where slot_code='ROLLER-X'),0,'machine-specific add does not change Model Profile');
select extensions.lives_ok($$select public.add_machine_component_assignment('e0100000-0000-4000-8000-000000000001','e0800000-0000-4000-8000-000000000001','e0600000-0000-4000-8000-000000000002','ROLLER-X','counter_based',80000,'Only machine one','e0900000-0000-4000-8000-000000000003')$$,'identical add retry returns existing assignment');
select extensions.throws_ok($$select public.add_machine_component_assignment('e0100000-0000-4000-8000-000000000001','e0800000-0000-4000-8000-000000000001','e0600000-0000-4000-8000-000000000002','ROLLER-Y','counter_based',80000,'Changed','e0900000-0000-4000-8000-000000000003')$$,'23505',null,'changed-payload retry is rejected');
select extensions.throws_ok($$select public.remove_machine_component_assignment('e0100000-0000-4000-8000-000000000001',(select id from public.machine_component_assignments where creation_request_id='e0900000-0000-4000-8000-000000000003'),'Different removal','e0900000-0000-4000-8000-000000000001')$$,'23505',null,'remove request key cannot be reused for another payload');

select extensions.throws_ok($$select public.manage_component_catalog_status('e0100000-0000-4000-8000-000000000001','e0600000-0000-4000-8000-000000000001','archive','e0900000-0000-4000-8000-000000000004')$$,'23514',null,'Catalog archive is blocked while active profiles exist');
select extensions.lives_ok($$select public.manage_component_catalog_status('e0100000-0000-4000-8000-000000000001','e0600000-0000-4000-8000-000000000003','archive','e0900000-0000-4000-8000-000000000012')$$,'unused Catalog can be archived');
select extensions.throws_ok($$select public.add_machine_component_assignment('e0100000-0000-4000-8000-000000000001','e0800000-0000-4000-8000-000000000001','e0600000-0000-4000-8000-000000000003','ARCHIVED','counter_based',1000,null,'e0900000-0000-4000-8000-000000000016')$$,'23514',null,'archived Catalog is unavailable for new machine assignment');
select extensions.lives_ok($$select public.manage_component_catalog_status('e0100000-0000-4000-8000-000000000001','e0600000-0000-4000-8000-000000000003','restore','e0900000-0000-4000-8000-000000000013')$$,'archived Catalog can be restored');
select extensions.ok((select is_active from public.components where id='e0600000-0000-4000-8000-000000000003'),'Catalog restore returns it to active without restoring profiles');
select extensions.is((select count(*)::int from public.machine_model_components where component_id='e0600000-0000-4000-8000-000000000003'),0,'Catalog restore creates no Model Profile');
select extensions.lives_ok($$select public.manage_machine_component_profile('e0100000-0000-4000-8000-000000000001','e0700000-0000-4000-8000-000000000002','archive','e0900000-0000-4000-8000-000000000005')$$,'profile archive succeeds');
select extensions.is((select status::text from public.machine_component_assignments where machine_id='e0800000-0000-4000-8000-000000000001' and source_profile_id='e0700000-0000-4000-8000-000000000002'),'retired','archived no-history inherited slot is retired');
select extensions.ok((select is_active from public.components where id='e0600000-0000-4000-8000-000000000001'),'profile archive does not archive Catalog');
select extensions.lives_ok($$select public.manage_machine_component_profile('e0100000-0000-4000-8000-000000000001','e0700000-0000-4000-8000-000000000003','archive','e0900000-0000-4000-8000-000000000009')$$,'profile with history archives safely');
select extensions.is((select status::text from public.machine_component_assignments where machine_id='e0800000-0000-4000-8000-000000000002' and source_profile_id='e0700000-0000-4000-8000-000000000003'),'configured','historical inherited assignment remains configured after profile archive');
select extensions.is((select count(*)::int from public.machine_component_lifecycles where id='e0850000-0000-4000-8000-000000000001'),1,'profile archive deletes no lifecycle history');
select extensions.throws_ok($$select public.remove_machine_component_assignment('e0100000-0000-4000-8000-000000000001',(select id from public.machine_component_assignments where machine_id='e0800000-0000-4000-8000-000000000002' and source_profile_id='e0700000-0000-4000-8000-000000000003'),'Attempt history removal','e0900000-0000-4000-8000-000000000010')$$,'23514',null,'assignment with history cannot be removed');
select extensions.lives_ok($$select public.manage_machine_component_profile('e0100000-0000-4000-8000-000000000001','e0700000-0000-4000-8000-000000000002','restore','e0900000-0000-4000-8000-000000000006')$$,'profile restore succeeds');
select extensions.is((select status::text from public.machine_component_assignments where machine_id='e0800000-0000-4000-8000-000000000001' and source_profile_id='e0700000-0000-4000-8000-000000000002'),'configured','restore reuses retired assignment');
select extensions.lives_ok($$select public.manage_machine_component_profile('e0100000-0000-4000-8000-000000000001','e0700000-0000-4000-8000-000000000002','archive','e0900000-0000-4000-8000-000000000014')$$,'profile can be archived again safely');
select extensions.lives_ok($$select public.save_machine_model_component_profile('e0100000-0000-4000-8000-000000000001','e0500000-0000-4000-8000-000000000001',null,'e0600000-0000-4000-8000-000000000001','GEAR-B',4,'counter_based',160000,true,30,15,5,0,null,'e0900000-0000-4000-8000-000000000017')$$,'conflicting active slot fixture is created through the authorized RPC');
select extensions.throws_ok($$select public.manage_machine_component_profile('e0100000-0000-4000-8000-000000000001','e0700000-0000-4000-8000-000000000002','restore','e0900000-0000-4000-8000-000000000015')$$,'23505',null,'restore rejects an active slot-code conflict');

select set_config('request.jwt.claim.sub','e0000000-0000-4000-8000-000000000002',true);
select extensions.is(public.sync_machine_component_assignments('e0100000-0000-4000-8000-000000000001',null)>=0,true,'admin can synchronize machine configuration');
select set_config('request.jwt.claim.sub','e0000000-0000-4000-8000-000000000003',true);
select extensions.throws_ok($$select public.sync_machine_component_assignments('e0100000-0000-4000-8000-000000000001',null)$$,'42501',null,'technician cannot sync configuration');
select extensions.throws_ok($$select public.add_machine_component_assignment('e0100000-0000-4000-8000-000000000001','e0800000-0000-4000-8000-000000000001','e0600000-0000-4000-8000-000000000002','TECH','counter_based',1000,null,'e0900000-0000-4000-8000-000000000007')$$,'42501',null,'technician cannot add assignment');
select set_config('request.jwt.claim.sub','e0000000-0000-4000-8000-000000000004',true);
select extensions.throws_ok($$select public.manage_machine_component_profile('e0100000-0000-4000-8000-000000000001','e0700000-0000-4000-8000-000000000001','archive','e0900000-0000-4000-8000-000000000008')$$,'42501',null,'operator cannot archive profile');
select set_config('request.jwt.claim.sub','e0000000-0000-4000-8000-000000000005',true);
select extensions.throws_ok($$select public.sync_machine_component_assignments('e0100000-0000-4000-8000-000000000001',null)$$,'42501',null,'suspended member denied');
select set_config('request.jwt.claim.sub','e0000000-0000-4000-8000-000000000006',true);
select extensions.throws_ok($$select public.sync_machine_component_assignments('e0100000-0000-4000-8000-000000000001',null)$$,'42501',null,'cross-account owner denied');
reset role;
set local role anon;
select extensions.throws_ok('select * from public.machine_component_configuration','42501',null,'anonymous read denied');
select extensions.throws_ok($$select public.sync_machine_component_assignments('e0100000-0000-4000-8000-000000000001',null)$$,'42501',null,'anonymous mutation denied');
reset role;

select extensions.is((select count(*)::int from public.machine_component_lifecycles where account_id='e0100000-0000-4000-8000-000000000001'),1,'provisioning and sync fabricate no lifecycle beyond the explicit history fixture');
select extensions.is((select count(*)::int from public.inventory_movements where account_id='e0100000-0000-4000-8000-000000000001'),0,'assignment changes fabricate no inventory movement');
select extensions.is((select count(*)::int from public.inventory_cost_lots where account_id='e0100000-0000-4000-8000-000000000001'),0,'assignment changes fabricate no FIFO cost lot');
select extensions.is((select count(*)::int from public.inventory_cost_allocations where account_id='e0100000-0000-4000-8000-000000000001'),0,'assignment changes fabricate no FIFO allocation');
select * from extensions.finish();
rollback;
