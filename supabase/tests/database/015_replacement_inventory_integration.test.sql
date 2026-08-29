begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

select extensions.ok((select prosecdef and proconfig @> array['search_path=""']::text[] from pg_proc where oid='public.replace_machine_component(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,public.component_removal_condition,boolean,uuid,text,text,uuid,public.component_replacement_inventory_source,uuid,uuid,numeric,text)'::regprocedure),'M2.4B replacement RPC is SECURITY DEFINER with empty search_path');
select extensions.ok(not has_function_privilege('anon','public.replace_machine_component(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,public.component_removal_condition,boolean,uuid,text,text,uuid,public.component_replacement_inventory_source,uuid,uuid,numeric,text)','EXECUTE'),'anonymous cannot execute M2.4B replacement');
select extensions.ok(not has_function_privilege('authenticated','public.replace_machine_component(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,public.component_removal_condition,boolean,uuid,text,text,uuid)','EXECUTE'),'retired M2.3C endpoint cannot bypass inventory source semantics');
select extensions.ok(not has_table_privilege('authenticated','public.inventory_movements','INSERT') and not has_table_privilege('authenticated','public.inventory_movements','UPDATE') and not has_table_privilege('authenticated','public.inventory_movements','DELETE'),'authenticated clients have no direct ledger mutation privileges');
select extensions.is((select count(*)::int from pg_catalog.pg_class c cross join lateral pg_catalog.aclexplode(coalesce(c.relacl,pg_catalog.acldefault('r',c.relowner))) a where c.oid=any(array['public.component_replacement_events'::regclass,'public.inventory_movements'::regclass,'public.component_replacement_history'::regclass,'public.inventory_movement_history'::regclass]) and a.grantee=0),0,'PUBLIC has no replacement or inventory relation privileges');

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('c0000000-0000-4000-8000-000000000001','authenticated','authenticated','m24b-owner@test.invalid','',now(),'{}','{"display_name":"M24B Owner"}',now(),now()),
('c0000000-0000-4000-8000-000000000002','authenticated','authenticated','m24b-admin@test.invalid','',now(),'{}','{"display_name":"M24B Admin"}',now(),now()),
('c0000000-0000-4000-8000-000000000003','authenticated','authenticated','m24b-tech@test.invalid','',now(),'{}','{"display_name":"M24B Tech"}',now(),now()),
('c0000000-0000-4000-8000-000000000004','authenticated','authenticated','m24b-op@test.invalid','',now(),'{}','{"display_name":"M24B Operator"}',now(),now()),
('c0000000-0000-4000-8000-000000000005','authenticated','authenticated','m24b-suspended@test.invalid','',now(),'{}','{"display_name":"M24B Suspended"}',now(),now()),
('c0000000-0000-4000-8000-000000000006','authenticated','authenticated','m24b-other@test.invalid','',now(),'{}','{"display_name":"M24B Other"}',now(),now());
insert into public.accounts(id,code,name) values
('c1000000-0000-4000-8000-000000000001','M24B-A','M2.4B Account A'),
('c1000000-0000-4000-8000-000000000002','M24B-B','M2.4B Account B');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at) values
('c2000000-0000-4000-8000-000000000001','c1000000-0000-4000-8000-000000000001','c0000000-0000-4000-8000-000000000001','owner','active',now()),
('c2000000-0000-4000-8000-000000000002','c1000000-0000-4000-8000-000000000001','c0000000-0000-4000-8000-000000000002','admin','active',now()),
('c2000000-0000-4000-8000-000000000003','c1000000-0000-4000-8000-000000000001','c0000000-0000-4000-8000-000000000003','technician','active',now()),
('c2000000-0000-4000-8000-000000000004','c1000000-0000-4000-8000-000000000001','c0000000-0000-4000-8000-000000000004','operator','active',now()),
('c2000000-0000-4000-8000-000000000005','c1000000-0000-4000-8000-000000000001','c0000000-0000-4000-8000-000000000005','admin','suspended',now()),
('c2000000-0000-4000-8000-000000000006','c1000000-0000-4000-8000-000000000002','c0000000-0000-4000-8000-000000000006','owner','active',now());
insert into public.branches(id,account_id,code,name) values
('c3000000-0000-4000-8000-000000000001','c1000000-0000-4000-8000-000000000001','A','Branch A'),
('c3000000-0000-4000-8000-000000000002','c1000000-0000-4000-8000-000000000002','B','Branch B');
insert into public.operational_people(id,account_id,name,linked_user_id,code) values
('c3100000-0000-4000-8000-000000000001','c1000000-0000-4000-8000-000000000001','M2.4B Owner PIC','c0000000-0000-4000-8000-000000000001','OWNER'),
('c3100000-0000-4000-8000-000000000002','c1000000-0000-4000-8000-000000000001','M2.5A Operational PIC',null,'OPS-PIC');

insert into public.machines(id,account_id,branch_id,machine_model_id,machine_code,display_name) values
('c4000000-0000-4000-8000-000000000001','c1000000-0000-4000-8000-000000000001','c3000000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','M24B-OWNER','Owner Machine'),
('c4000000-0000-4000-8000-000000000002','c1000000-0000-4000-8000-000000000001','c3000000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','M24B-ADMIN','Admin Machine'),
('c4000000-0000-4000-8000-000000000003','c1000000-0000-4000-8000-000000000001','c3000000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','M24B-TECH','Tech Machine'),
('c4000000-0000-4000-8000-000000000004','c1000000-0000-4000-8000-000000000001','c3000000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','M24B-OP','Operator Machine'),
('c4000000-0000-4000-8000-000000000005','c1000000-0000-4000-8000-000000000001','c3000000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','M24B-EXT','External Machine'),
('c4000000-0000-4000-8000-000000000006','c1000000-0000-4000-8000-000000000001','c3000000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','M24B-TONER','Toner Machine'),
('c4000000-0000-4000-8000-000000000007','c1000000-0000-4000-8000-000000000001','c3000000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','M24B-LOW','Lower Counter Machine'),
('c4000000-0000-4000-8000-000000000008','c1000000-0000-4000-8000-000000000001','c3000000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','M24B-LEGACY','Legacy Machine'),
('c4000000-0000-4000-8000-000000000009','c1000000-0000-4000-8000-000000000002','c3000000-0000-4000-8000-000000000002','51000000-0000-0000-0000-000000000001','M24B-OTHER','Other Machine');

insert into public.counter_readings(id,account_id,machine_id,counter_type_id,reading_value,observed_at,entered_by,client_request_id,created_by)
select ('c5000000-0000-4000-8000-'||lpad(n::text,12,'0'))::uuid,
  case when n=9 then 'c1000000-0000-4000-8000-000000000002'::uuid else 'c1000000-0000-4000-8000-000000000001'::uuid end,
  ('c4000000-0000-4000-8000-'||lpad(n::text,12,'0'))::uuid,
  '52000000-0000-0000-0000-000000000001',1000,'2026-08-01',
  case when n=9 then 'c0000000-0000-4000-8000-000000000006'::uuid else 'c0000000-0000-4000-8000-000000000001'::uuid end,
  ('c6000000-0000-4000-8000-'||lpad(n::text,12,'0'))::uuid,
  case when n=9 then 'c0000000-0000-4000-8000-000000000006'::uuid else 'c0000000-0000-4000-8000-000000000001'::uuid end
from generate_series(1,9) n;

insert into public.machine_component_lifecycles(id,account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code,status,installed_counter,installed_at,installation_source,baseline_expected_clicks_snapshot,expected_at_install) values
('c7000000-0000-4000-8000-000000000001','c1000000-0000-4000-8000-000000000001','c3000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000001','54000000-0000-0000-0000-000000000001','53000000-0000-0000-0000-000000000001','CHARGING_CORONA_C','active',900,'2026-07-01','tracking_start',40000,40000),
('c7000000-0000-4000-8000-000000000002','c1000000-0000-4000-8000-000000000001','c3000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000002','54000000-0000-0000-0000-000000000002','53000000-0000-0000-0000-000000000002','CHARGING_CORONA_M','active',900,'2026-07-01','tracking_start',40000,40000),
('c7000000-0000-4000-8000-000000000003','c1000000-0000-4000-8000-000000000001','c3000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000003','54000000-0000-0000-0000-000000000003','53000000-0000-0000-0000-000000000003','CHARGING_CORONA_Y','active',900,'2026-07-01','tracking_start',40000,40000),
('c7000000-0000-4000-8000-000000000004','c1000000-0000-4000-8000-000000000001','c3000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000004','54000000-0000-0000-0000-000000000004','53000000-0000-0000-0000-000000000004','CHARGING_CORONA_K','active',900,'2026-07-01','tracking_start',40000,40000),
('c7000000-0000-4000-8000-000000000005','c1000000-0000-4000-8000-000000000001','c3000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000005','54000000-0000-0000-0000-000000000001','53000000-0000-0000-0000-000000000001','CHARGING_CORONA_C','active',900,'2026-07-01','tracking_start',40000,40000),
('c7000000-0000-4000-8000-000000000006','c1000000-0000-4000-8000-000000000001','c3000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000006','54000000-0000-0000-0000-000000000025','53000000-0000-0000-0000-000000000025','TONER_C','active',900,'2026-07-01','tracking_start',14000,14000),
('c7000000-0000-4000-8000-000000000007','c1000000-0000-4000-8000-000000000001','c3000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000007','54000000-0000-0000-0000-000000000001','53000000-0000-0000-0000-000000000001','CHARGING_CORONA_C','active',900,'2026-07-01','tracking_start',40000,40000),
('c7000000-0000-4000-8000-000000000008','c1000000-0000-4000-8000-000000000001','c3000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000008','54000000-0000-0000-0000-000000000001','53000000-0000-0000-0000-000000000001','CHARGING_CORONA_C','active',900,'2026-07-01','tracking_start',40000,40000),
('c7000000-0000-4000-8000-000000000009','c1000000-0000-4000-8000-000000000002','c3000000-0000-4000-8000-000000000002','c4000000-0000-4000-8000-000000000009','54000000-0000-0000-0000-000000000001','53000000-0000-0000-0000-000000000001','CHARGING_CORONA_C','active',900,'2026-07-01','tracking_start',40000,40000);

insert into public.inventory_locations(id,account_id,branch_id,code,name,is_active) values
('c8000000-0000-4000-8000-000000000001','c1000000-0000-4000-8000-000000000001','c3000000-0000-4000-8000-000000000001','WH','Gudang Sparepart',true),
('c8000000-0000-4000-8000-000000000002','c1000000-0000-4000-8000-000000000001','c3000000-0000-4000-8000-000000000001','OLD','Archived Cabinet',false),
('c8000000-0000-4000-8000-000000000003','c1000000-0000-4000-8000-000000000002','c3000000-0000-4000-8000-000000000002','OTHER','Other Warehouse',true);
insert into public.inventory_items(id,account_id,component_id,sku,name,unit,is_active) values
('c8100000-0000-4000-8000-000000000001','c1000000-0000-4000-8000-000000000001','53000000-0000-0000-0000-000000000001',null,'Corona Cyan','pcs',true),
('c8100000-0000-4000-8000-000000000002','c1000000-0000-4000-8000-000000000001','53000000-0000-0000-0000-000000000002','COR-M','Corona Magenta','pcs',true),
('c8100000-0000-4000-8000-000000000003','c1000000-0000-4000-8000-000000000001','53000000-0000-0000-0000-000000000003','COR-Y','Corona Yellow','pcs',true),
('c8100000-0000-4000-8000-000000000004','c1000000-0000-4000-8000-000000000001','53000000-0000-0000-0000-000000000004','COR-K','Corona Black','pcs',true),
('c8100000-0000-4000-8000-000000000005','c1000000-0000-4000-8000-000000000001','53000000-0000-0000-0000-000000000002','WRONG','Wrong Component','pcs',true),
('c8100000-0000-4000-8000-000000000006','c1000000-0000-4000-8000-000000000001','53000000-0000-0000-0000-000000000001','ARCH','Archived Item','pcs',false),
('c8100000-0000-4000-8000-000000000007','c1000000-0000-4000-8000-000000000001','53000000-0000-0000-0000-000000000025','TON-C','Toner Cyan','bottle',true),
('c8100000-0000-4000-8000-000000000008','c1000000-0000-4000-8000-000000000002','53000000-0000-0000-0000-000000000001','OTHER','Other Corona','pcs',true);

insert into public.inventory_cost_inputs(account_id,client_request_id,operation_type,unit_cost) values
('c1000000-0000-4000-8000-000000000001','c8300000-0000-4000-8000-000000000001','opening_balance',2650000);
insert into public.inventory_movements(id,account_id,inventory_item_id,location_id,movement_type,quantity,unit_snapshot,occurred_at,operational_person_name_snapshot,reference_type,client_request_id,created_by,created_by_name_snapshot) values
('c8200000-0000-4000-8000-000000000001','c1000000-0000-4000-8000-000000000001','c8100000-0000-4000-8000-000000000001','c8000000-0000-4000-8000-000000000001','opening_balance',5,'pcs','2026-08-10','Opening PIC','opening_balance','c8300000-0000-4000-8000-000000000001','c0000000-0000-4000-8000-000000000001','M24B Owner'),
('c8200000-0000-4000-8000-000000000002','c1000000-0000-4000-8000-000000000001','c8100000-0000-4000-8000-000000000002','c8000000-0000-4000-8000-000000000001','opening_balance',3,'pcs','2026-08-10','Opening PIC','opening_balance','c8300000-0000-4000-8000-000000000002','c0000000-0000-4000-8000-000000000001','M24B Owner'),
('c8200000-0000-4000-8000-000000000003','c1000000-0000-4000-8000-000000000001','c8100000-0000-4000-8000-000000000003','c8000000-0000-4000-8000-000000000001','opening_balance',2,'pcs','2026-08-10','Opening PIC','opening_balance','c8300000-0000-4000-8000-000000000003','c0000000-0000-4000-8000-000000000001','M24B Owner'),
('c8200000-0000-4000-8000-000000000004','c1000000-0000-4000-8000-000000000001','c8100000-0000-4000-8000-000000000004','c8000000-0000-4000-8000-000000000001','opening_balance',2,'pcs','2026-08-10','Opening PIC','opening_balance','c8300000-0000-4000-8000-000000000004','c0000000-0000-4000-8000-000000000001','M24B Owner'),
('c8200000-0000-4000-8000-000000000005','c1000000-0000-4000-8000-000000000001','c8100000-0000-4000-8000-000000000007','c8000000-0000-4000-8000-000000000001','opening_balance',2,'bottle','2026-08-10','Opening PIC','opening_balance','c8300000-0000-4000-8000-000000000005','c0000000-0000-4000-8000-000000000001','M24B Owner');

-- M2.7B legacy-fixture continuity: scoped memberships and Operational People
-- are explicitly assigned to the fixture branches that were account-wide before M2.7B.
insert into public.account_membership_branches (account_id, membership_id, branch_id, assigned_by, updated_by)
select membership.account_id, membership.id, branch.id, membership.created_by, membership.created_by
from public.account_memberships membership
join public.branches branch on branch.account_id = membership.account_id
where membership.role <> 'owner'
on conflict (membership_id, branch_id) do update set is_active = true;

insert into public.operational_person_branches (account_id, operational_person_id, branch_id, assigned_by, updated_by)
select person.account_id, person.id, branch.id, person.created_by, person.created_by
from public.operational_people person
join public.branches branch on branch.account_id = person.account_id
on conflict (operational_person_id, branch_id) do update set is_active = true;

set local role anon;
select extensions.throws_ok($$select public.replace_machine_component('c1000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000001','c7000000-0000-4000-8000-000000000001',1000,'2026-08-20','normal_eol','worn',true,null,'Anonymous',null,'c9000000-0000-4000-8000-000000000001','external_untracked',null,null,null,'External')$$,'42501',null,'anonymous replacement denied');
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub','c0000000-0000-4000-8000-000000000005',true);
select extensions.throws_ok($$select public.replace_machine_component('c1000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000001','c7000000-0000-4000-8000-000000000001',1000,'2026-08-20','normal_eol','worn',true,null,'Suspended',null,'c9000000-0000-4000-8000-000000000002','external_untracked',null,null,null,'External')$$,'42501',null,'suspended member denied');
select set_config('request.jwt.claim.sub','c0000000-0000-4000-8000-000000000001',true);
select extensions.throws_ok($$select public.replace_machine_component('c1000000-0000-4000-8000-000000000002','c4000000-0000-4000-8000-000000000009','c7000000-0000-4000-8000-000000000009',1000,'2026-08-20','normal_eol','worn',true,null,'Owner',null,'c9000000-0000-4000-8000-000000000003','external_untracked',null,null,null,'External')$$,'42501',null,'cross-account replacement denied');
select extensions.throws_ok($$select public.replace_machine_component('c1000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000001','c7000000-0000-4000-8000-000000000001',1000,'2026-08-20','normal_eol','worn',true,'c0000000-0000-4000-8000-000000000001','M2.4B Owner PIC',null,'c9000000-0000-4000-8000-000000000004','inventory','c8100000-0000-4000-8000-000000000005','c8000000-0000-4000-8000-000000000001',1,null)$$,'23514',null,'mismatched component inventory item rejected');
select extensions.throws_ok($$select public.replace_machine_component('c1000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000001','c7000000-0000-4000-8000-000000000001',1000,'2026-08-20','normal_eol','worn',true,'c0000000-0000-4000-8000-000000000001','M2.4B Owner PIC',null,'c9000000-0000-4000-8000-000000000005','inventory','c8100000-0000-4000-8000-000000000006','c8000000-0000-4000-8000-000000000001',1,null)$$,'P0002',null,'archived inventory item rejected');
select extensions.throws_ok($$select public.replace_machine_component('c1000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000001','c7000000-0000-4000-8000-000000000001',1000,'2026-08-20','normal_eol','worn',true,'c0000000-0000-4000-8000-000000000001','M2.4B Owner PIC',null,'c9000000-0000-4000-8000-000000000006','inventory','c8100000-0000-4000-8000-000000000001','c8000000-0000-4000-8000-000000000002',1,null)$$,'P0002',null,'archived inventory location rejected');
select extensions.throws_ok($$select public.replace_machine_component('c1000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000001','c7000000-0000-4000-8000-000000000001',1000,'2026-08-20','normal_eol','worn',true,'c0000000-0000-4000-8000-000000000001','M2.4B Owner PIC',null,'c9000000-0000-4000-8000-000000000007','inventory','c8100000-0000-4000-8000-000000000008','c8000000-0000-4000-8000-000000000001',1,null)$$,'P0002',null,'cross-account inventory item rejected');
select extensions.throws_ok($$select public.replace_machine_component('c1000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000001','c7000000-0000-4000-8000-000000000001',1000,'2026-08-20','normal_eol','worn',true,'c0000000-0000-4000-8000-000000000001','M2.4B Owner PIC',null,'c9000000-0000-4000-8000-000000000008','inventory','c8100000-0000-4000-8000-000000000001','c8000000-0000-4000-8000-000000000003',1,null)$$,'P0002',null,'cross-account inventory location rejected');

select extensions.throws_ok($$select public.replace_machine_component('c1000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000001','c7000000-0000-4000-8000-000000000001',1100,'2026-08-20','normal_eol','worn',true,'c0000000-0000-4000-8000-000000000001','M2.4B Owner PIC',null,'c9000000-0000-4000-8000-000000000009','inventory','c8100000-0000-4000-8000-000000000001','c8000000-0000-4000-8000-000000000001',6,null)$$,'22003',null,'insufficient location stock rejects replacement');
select extensions.is((select status::text from public.machine_component_lifecycles where id='c7000000-0000-4000-8000-000000000001'),'active','insufficient stock does not close lifecycle');
select extensions.is((select count(*)::int from public.component_replacement_events where client_request_id='c9000000-0000-4000-8000-000000000009'),0,'insufficient stock creates no replacement event');
select extensions.is((select count(*)::int from public.inventory_movements where client_request_id='c9000000-0000-4000-8000-000000000009'),0,'insufficient stock creates no issue');
select extensions.is((select count(*)::int from public.counter_readings where client_request_id='c9000000-0000-4000-8000-000000000009'),0,'insufficient stock creates no counter reading');

select extensions.lives_ok($$select public.replace_machine_component('c1000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000001','c7000000-0000-4000-8000-000000000001',1000,'2026-08-20','normal_eol','worn',true,'c0000000-0000-4000-8000-000000000001','M2.4B Owner PIC','Owner inventory issue','c9000000-0000-4000-8000-000000000011','inventory','c8100000-0000-4000-8000-000000000001','c8000000-0000-4000-8000-000000000001',1,null)$$,'owner inventory-backed replacement succeeds with a NULL-SKU item');
select extensions.is((select inventory_source::text from public.component_replacement_events where client_request_id='c9000000-0000-4000-8000-000000000011'),'inventory','replacement records inventory source');
select extensions.is((select count(*)::int from public.inventory_movements where client_request_id='c9000000-0000-4000-8000-000000000011' and movement_type='issue'),1,'successful replacement creates exactly one issue');
select extensions.is((select quantity from public.inventory_movements where client_request_id='c9000000-0000-4000-8000-000000000011'),-1::numeric,'issue quantity is signed negative');
select extensions.is((select location_id from public.inventory_movements where client_request_id='c9000000-0000-4000-8000-000000000011'),'c8000000-0000-4000-8000-000000000001'::uuid,'issue uses selected physical location');
select extensions.ok((select event.inventory_movement_id=movement.id from public.component_replacement_events event join public.inventory_movements movement on movement.client_request_id=event.client_request_id where event.client_request_id='c9000000-0000-4000-8000-000000000011'),'replacement links its movement');
select extensions.ok((select movement.reference_id=event.id and movement.reference_type='component_replacement' from public.component_replacement_events event join public.inventory_movements movement on movement.id=event.inventory_movement_id where event.client_request_id='c9000000-0000-4000-8000-000000000011'),'movement references replacement event');
select extensions.is((select quantity from public.inventory_stock_balances where inventory_item_id='c8100000-0000-4000-8000-000000000001' and location_id='c8000000-0000-4000-8000-000000000001'),4::numeric,'stock decreases from five to four');
select extensions.is((select status::text from public.machine_component_lifecycles where id='c7000000-0000-4000-8000-000000000001'),'closed','successful replacement closes previous lifecycle');
select extensions.is((select new_lifecycle_status::text from public.component_replacement_history where previous_lifecycle_id='c7000000-0000-4000-8000-000000000001'),'active','successful replacement creates active lifecycle');
select extensions.is((select current_usage::bigint from public.machine_component_health where lifecycle_id=(select new_lifecycle_id from public.component_replacement_events where client_request_id='c9000000-0000-4000-8000-000000000011')),0::bigint,'new lifecycle usage starts at zero');
select extensions.is((select operational_person_name_snapshot from public.inventory_movements where client_request_id='c9000000-0000-4000-8000-000000000011'),'M2.4B Owner PIC','issue inherits exact replacement PIC snapshot');
select extensions.is((select operational_person_id from public.inventory_movements where client_request_id='c9000000-0000-4000-8000-000000000011'),'c3100000-0000-4000-8000-000000000001'::uuid,'linked operational person is resolved without a second PIC');
select extensions.is((select count(*)::int from public.counter_readings where client_request_id='c9000000-0000-4000-8000-000000000011'),0,'equal replacement counter creates no counter reading');
select extensions.lives_ok($$select public.replace_machine_component('c1000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000001','c7000000-0000-4000-8000-000000000001',1000,'2026-08-20','normal_eol','worn',true,'c0000000-0000-4000-8000-000000000001','M2.4B Owner PIC','Owner inventory issue','c9000000-0000-4000-8000-000000000011','inventory','c8100000-0000-4000-8000-000000000001','c8000000-0000-4000-8000-000000000001',1,null)$$,'identical combined retry succeeds');
select extensions.is((select count(*)::int from public.component_replacement_events where client_request_id='c9000000-0000-4000-8000-000000000011'),1,'retry creates no duplicate replacement');
select extensions.is((select count(*)::int from public.inventory_movements where client_request_id='c9000000-0000-4000-8000-000000000011'),1,'retry creates no duplicate issue');
select extensions.is((select count(*)::int from public.inventory_cost_allocations where outbound_movement_id=(select inventory_movement_id from public.component_replacement_events where client_request_id='c9000000-0000-4000-8000-000000000011')),1,'retry creates no duplicate cost allocation');
select extensions.throws_ok($$select public.replace_machine_component('c1000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000001','c7000000-0000-4000-8000-000000000001',1000,'2026-08-20','normal_eol','worn',true,'c0000000-0000-4000-8000-000000000001','M2.4B Owner PIC','Owner inventory issue','c9000000-0000-4000-8000-000000000011','inventory','c8100000-0000-4000-8000-000000000001','c8000000-0000-4000-8000-000000000001',2,null)$$,'23505',null,'changed inventory payload under same request ID is rejected');

select set_config('request.jwt.claim.sub','c0000000-0000-4000-8000-000000000002',true);
select extensions.lives_ok($$select public.replace_machine_component('c1000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000002','c7000000-0000-4000-8000-000000000002',1100,'2026-08-21','preventive','fair',false,'c0000000-0000-4000-8000-000000000002','M2.4B Admin',null,'c9000000-0000-4000-8000-000000000012','inventory','c8100000-0000-4000-8000-000000000002','c8000000-0000-4000-8000-000000000001',1,null)$$,'admin inventory-backed replacement succeeds');
select extensions.is((select reading_value::bigint from public.counter_readings where client_request_id='c9000000-0000-4000-8000-000000000012'),1100::bigint,'higher replacement counter creates atomic reading');

select set_config('request.jwt.claim.sub','c0000000-0000-4000-8000-000000000003',true);
select extensions.lives_ok($$select public.replace_machine_component('c1000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000003','c7000000-0000-4000-8000-000000000003',1000,'2026-08-21','print_quality','worn',true,'c0000000-0000-4000-8000-000000000003','M2.4B Tech',null,'c9000000-0000-4000-8000-000000000013','inventory','c8100000-0000-4000-8000-000000000003','c8000000-0000-4000-8000-000000000001',1,null)$$,'technician controlled replacement issue succeeds');
select extensions.throws_ok($$select public.adjust_inventory_stock('c1000000-0000-4000-8000-000000000001','c8100000-0000-4000-8000-000000000003','c8000000-0000-4000-8000-000000000001',1,now(),'c3100000-0000-4000-8000-000000000001','Denied',null,'c9000000-0000-4000-8000-000000000014')$$,'42501',null,'technician still cannot manually adjust inventory');

select set_config('request.jwt.claim.sub','c0000000-0000-4000-8000-000000000004',true);
select extensions.lives_ok($$select public.replace_machine_component('c1000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000004','c7000000-0000-4000-8000-000000000004',1000,'2026-08-21','failure','failed',false,'c0000000-0000-4000-8000-000000000004','M2.4B Operator',null,'c9000000-0000-4000-8000-000000000015','inventory','c8100000-0000-4000-8000-000000000004','c8000000-0000-4000-8000-000000000001',1,null)$$,'operator controlled replacement issue succeeds');
select extensions.throws_ok($$select public.adjust_inventory_stock('c1000000-0000-4000-8000-000000000001','c8100000-0000-4000-8000-000000000004','c8000000-0000-4000-8000-000000000001',1,now(),'c3100000-0000-4000-8000-000000000001','Denied',null,'c9000000-0000-4000-8000-000000000016')$$,'42501',null,'operator still cannot manually adjust inventory');

select extensions.throws_ok($$select public.replace_machine_component('c1000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000005','c7000000-0000-4000-8000-000000000005',1000,'2026-08-22','normal_eol','worn',true,'c0000000-0000-4000-8000-000000000004','M2.4B Operator',null,'c9000000-0000-4000-8000-000000000017','external_untracked',null,null,null,'')$$,'22023',null,'external source requires a meaningful reason');
select extensions.lives_ok($$select public.replace_machine_component('c1000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000005','c7000000-0000-4000-8000-000000000005',1000,'2026-08-22','normal_eol','worn',true,'c0000000-0000-4000-8000-000000000004','M2.4B Operator',null,'c9000000-0000-4000-8000-000000000018','external_untracked',null,null,null,'Teknisi membawa sparepart')$$,'external / untracked replacement succeeds');
select extensions.is((select count(*)::int from public.inventory_movements where client_request_id='c9000000-0000-4000-8000-000000000018'),0,'external replacement creates no inventory movement');
select extensions.is((select external_inventory_reason from public.component_replacement_history where previous_lifecycle_id='c7000000-0000-4000-8000-000000000005'),'Teknisi membawa sparepart','external reason remains readable in history');

select extensions.lives_ok($$select public.replace_machine_component('c1000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000006','c7000000-0000-4000-8000-000000000006',1000,'2026-08-22','depleted','worn',true,'c0000000-0000-4000-8000-000000000004','M2.4B Operator','Toner consumed','c9000000-0000-4000-8000-000000000019','inventory','c8100000-0000-4000-8000-000000000007','c8000000-0000-4000-8000-000000000001',1,null)$$,'toner replacement consumes inventory through same transaction');
select extensions.is((select inventory_unit::text from public.component_replacement_history where previous_lifecycle_id='c7000000-0000-4000-8000-000000000006'),'bottle','toner history preserves item UOM');

select extensions.throws_ok($$select public.replace_machine_component('c1000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000007','c7000000-0000-4000-8000-000000000007',999,'2026-08-22','failure','failed',false,'c0000000-0000-4000-8000-000000000004','M2.4B Operator',null,'c9000000-0000-4000-8000-000000000020','external_untracked',null,null,null,'External')$$,'22003',null,'lower replacement counter remains rejected');
reset role;

-- Superuser invokes the retired function only to prove pre-M2.4B rows remain readable.
select set_config('request.jwt.claim.sub','c0000000-0000-4000-8000-000000000001',true);
select extensions.lives_ok($$select public.replace_machine_component('c1000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000008','c7000000-0000-4000-8000-000000000008',1000,'2026-08-23','normal_eol','worn',true,'c0000000-0000-4000-8000-000000000001','Legacy Owner','Legacy fixture','c9000000-0000-4000-8000-000000000021')$$,'legacy replacement fixture remains insertable by database owner');
select extensions.ok((select inventory_source is null and inventory_movement_id is null from public.component_replacement_history where previous_lifecycle_id='c7000000-0000-4000-8000-000000000008'),'historical replacement with null inventory relation remains readable');

set local role authenticated;
select set_config('request.jwt.claim.sub','c0000000-0000-4000-8000-000000000001',true);
select extensions.throws_ok($$update public.inventory_movements set notes='tampered' where client_request_id='c9000000-0000-4000-8000-000000000011'$$,'42501',null,'replacement inventory movement remains immutable');
select extensions.throws_ok($$update public.component_replacement_events set notes='tampered' where client_request_id='c9000000-0000-4000-8000-000000000011'$$,'42501',null,'replacement event remains immutable');
select extensions.throws_ok($$insert into public.inventory_movements(account_id,inventory_item_id,location_id,movement_type,quantity,unit_snapshot,occurred_at,operational_person_name_snapshot,reference_type,client_request_id,created_by,created_by_name_snapshot) values('c1000000-0000-4000-8000-000000000001','c8100000-0000-4000-8000-000000000001','c8000000-0000-4000-8000-000000000001','issue',-1,'pcs',now(),'PIC','maintenance',gen_random_uuid(),'c0000000-0000-4000-8000-000000000001','Owner')$$,'42501',null,'direct ledger insert remains denied');
select extensions.lives_ok($$select public.replace_machine_component(
  'c1000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000001',
  (select new_lifecycle_id from public.component_replacement_events where client_request_id='c9000000-0000-4000-8000-000000000011'),
  1210,'2026-08-24','normal_eol','worn',true,'c0000000-0000-4000-8000-000000000001','M2.4B Owner PIC',
  'Lifecycle cost close','c9000000-0000-4000-8000-000000000022','inventory','c8100000-0000-4000-8000-000000000001',
  'c8000000-0000-4000-8000-000000000001',1,null)$$,'known-cost replacement closes the installed lifecycle');
select extensions.is((select inventory_consumption_cost from public.component_replacement_history where replacement_event_id=(select id from public.component_replacement_events where client_request_id='c9000000-0000-4000-8000-000000000022')),2650000::numeric,'replacement history derives consumption cost from FIFO allocation');
select extensions.is((select realized_lifecycle_cost from public.component_lifecycle_costs where lifecycle_id=(select previous_lifecycle_id from public.component_replacement_events where client_request_id='c9000000-0000-4000-8000-000000000022')),2650000::numeric,'closed lifecycle realizes its installation consumption cost');
select extensions.is((select realized_cost_per_click from public.component_lifecycle_costs where lifecycle_id=(select previous_lifecycle_id from public.component_replacement_events where client_request_id='c9000000-0000-4000-8000-000000000022')),12619.0476::numeric,'closed lifecycle derives realized cost per click from actual usage');
select extensions.ok((select installed_component_cost=2650000 and realized_cost_per_click is null from public.component_lifecycle_costs where lifecycle_id=(select new_lifecycle_id from public.component_replacement_events where client_request_id='c9000000-0000-4000-8000-000000000022')),'active lifecycle exposes installed cost without premature realized cost per click');
select extensions.lives_ok($$select public.replace_machine_component(
  'c1000000-0000-4000-8000-000000000001','c4000000-0000-4000-8000-000000000001',
  (select new_lifecycle_id from public.component_replacement_events where client_request_id='c9000000-0000-4000-8000-000000000022'),
  1210,'2026-08-25','preventive','fair',false,'c3100000-0000-4000-8000-000000000002',null,
  'Operational person selector','c9000000-0000-4000-8000-000000000023','inventory','c8100000-0000-4000-8000-000000000001',
  'c8000000-0000-4000-8000-000000000001',1,null)$$,'unlinked active operational person can be the physical PIC');
select extensions.is((select performed_by_user_id from public.component_replacement_events where client_request_id='c9000000-0000-4000-8000-000000000023'),null::uuid,'operational PIC is not conflated with an authenticated user');
select extensions.is((select performed_by_name_snapshot from public.component_replacement_events where client_request_id='c9000000-0000-4000-8000-000000000023'),'M2.5A Operational PIC','replacement preserves operational PIC snapshot');
select extensions.is((select operational_person_id from public.inventory_movements where client_request_id='c9000000-0000-4000-8000-000000000023'),'c3100000-0000-4000-8000-000000000002'::uuid,'inventory issue references selected operational person directly');
select extensions.is((select operational_person_name_snapshot from public.inventory_movements where client_request_id='c9000000-0000-4000-8000-000000000023'),'M2.5A Operational PIC','inventory issue preserves the same immutable PIC snapshot');
select extensions.lives_ok($$update public.inventory_items set is_active=false where id='c8100000-0000-4000-8000-000000000001'$$,'replacement-referenced item can be safely archived');
select extensions.lives_ok($$update public.inventory_locations set is_active=false where id='c8000000-0000-4000-8000-000000000001'$$,'replacement-referenced location can be safely archived');
select extensions.is((select inventory_item_name from public.component_replacement_history where previous_lifecycle_id='c7000000-0000-4000-8000-000000000001'),'Corona Cyan','archive preserves replacement inventory history');
reset role;

select extensions.ok((select bool_and(quantity>=0) from public.inventory_stock_balances where account_id='c1000000-0000-4000-8000-000000000001'),'all derived stock balances remain nonnegative');
select extensions.is((select replacement_machine_code from public.inventory_movement_history where client_request_id='c9000000-0000-4000-8000-000000000011'),'M24B-OWNER','movement history resolves replacement machine without raw ID');
select extensions.is((select replacement_component_name from public.inventory_movement_history where client_request_id='c9000000-0000-4000-8000-000000000011'),'Charging Corona Cyan','movement history resolves replacement component');
select extensions.ok(position('machine_record' in lower(pg_get_functiondef('public.replace_machine_component(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,public.component_removal_condition,boolean,uuid,text,text,uuid,public.component_replacement_inventory_source,uuid,uuid,numeric,text)'::regprocedure)))>0 and position('for update' in lower(pg_get_functiondef('public.replace_machine_component(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,public.component_removal_condition,boolean,uuid,text,text,uuid,public.component_replacement_inventory_source,uuid,uuid,numeric,text)'::regprocedure)))>0,'replacement RPC retains explicit row-locking strategy');

select * from extensions.finish();
rollback;
