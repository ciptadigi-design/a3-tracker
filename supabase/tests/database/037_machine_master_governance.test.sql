begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('f7000000-0000-4000-8000-000000000001','authenticated','authenticated','owner-master@test.invalid','x',now(),'{}','{"display_name":"Master Owner"}',now(),now()),
('f7000000-0000-4000-8000-000000000002','authenticated','authenticated','platform-master@test.invalid','x',now(),'{}','{"display_name":"Master Platform"}',now(),now());
insert into public.accounts(id,code,name) values
('f7100000-0000-4000-8000-000000000001','MASTER-A','Machine Master A'),
('f7100000-0000-4000-8000-000000000002','MASTER-B','Machine Master B');
insert into public.branches(id,account_id,code,name) values
('f7200000-0000-4000-8000-000000000001','f7100000-0000-4000-8000-000000000001','GRH','Graha Fixture'),
('f7200000-0000-4000-8000-000000000002','f7100000-0000-4000-8000-000000000002','OTH','Other Fixture');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at) values
('f7300000-0000-4000-8000-000000000001','f7100000-0000-4000-8000-000000000001','f7000000-0000-4000-8000-000000000001','owner','active',now());
insert into public.platform_user_privileges(user_id,role) values
('f7000000-0000-4000-8000-000000000002','superuser');

set local role authenticated;
select set_config('request.jwt.claims','{"sub":"f7000000-0000-4000-8000-000000000001","role":"authenticated"}',true);
select extensions.ok(not public.is_platform_superuser(),'Owner remains distinct from Platform Superuser');
select extensions.throws_ok($$insert into public.manufacturers(account_id,code,name) values
  ('f7100000-0000-4000-8000-000000000001','XEROX','Xerox')$$,'42501',null,'non-Superuser cannot create Manufacturer');

select set_config('request.jwt.claims','{"sub":"f7000000-0000-4000-8000-000000000002","role":"authenticated"}',true);
select extensions.lives_ok($$insert into public.manufacturers(id,account_id,code,name) values
  ('f7400000-0000-4000-8000-000000000001','f7100000-0000-4000-8000-000000000001','XEROX','Xerox')$$,
  'Platform Superuser creates Manufacturer');
select extensions.throws_ok($$insert into public.manufacturers(account_id,code,name) values
  ('f7100000-0000-4000-8000-000000000001','XEROX-ALT','  xErOx  ')$$,'23505',null,
  'normalized Manufacturer name prevents duplicates');
select extensions.lives_ok($$insert into public.machine_models(id,account_id,manufacturer_id,model_code,name,machine_category,color_capability)
  values('f7500000-0000-4000-8000-000000000001','f7100000-0000-4000-8000-000000000001','f7400000-0000-4000-8000-000000000001','VERSANT-180','Versant 180','digital_a3','color')$$,
  'Platform Superuser creates Machine Model under Manufacturer');
select extensions.throws_ok($$insert into public.machine_models(account_id,manufacturer_id,model_code,name,machine_category,color_capability)
  values('f7100000-0000-4000-8000-000000000001','f7400000-0000-4000-8000-000000000001','VERSANT-180-ALT',' versant 180 ','digital_a3','color')$$,
  '23505',null,'normalized Model name prevents duplicates within Manufacturer');
select extensions.throws_ok($$insert into public.machine_models(account_id,manufacturer_id,model_code,name,machine_category,color_capability)
  values('f7100000-0000-4000-8000-000000000002','f7400000-0000-4000-8000-000000000001','CROSS','Cross Scope','digital_a3','color')$$,
  '23514','MACHINE_MASTER_SCOPE_MISMATCH','Manufacturer and Model scope association is enforced');

select set_config('request.jwt.claims','{"sub":"f7000000-0000-4000-8000-000000000001","role":"authenticated"}',true);
select extensions.throws_ok($$insert into public.machine_models(account_id,manufacturer_id,model_code,name,machine_category,color_capability)
  values('f7100000-0000-4000-8000-000000000001','f7400000-0000-4000-8000-000000000001','OWNER-MODEL','Owner Model','digital_a3','color')$$,
  '42501',null,'non-Superuser cannot create Machine Model');

select set_config('request.jwt.claims','{"sub":"f7000000-0000-4000-8000-000000000002","role":"authenticated"}',true);
select extensions.lives_ok($$insert into public.machines(account_id,branch_id,machine_model_id,machine_code,display_name)
  values('f7100000-0000-4000-8000-000000000001','f7200000-0000-4000-8000-000000000001','f7500000-0000-4000-8000-000000000001','XEROX-GRH-01','Xerox Graha Fixture')$$,
  'Machine creation accepts an active Model with zero profiles');
select extensions.is((select count(*)::integer from public.machine_model_components where machine_model_id='f7500000-0000-4000-8000-000000000001'),0,
  'new Machine Model starts with zero Model Profiles');
select extensions.is((select count(*)::integer from public.machine_component_assignments assignment join public.machines machine on machine.id=assignment.machine_id where machine.machine_code='XEROX-GRH-01'),0,
  'Machine creation fabricates no component assignments');
select extensions.throws_ok($$update public.manufacturers set is_active=false where id='f7400000-0000-4000-8000-000000000001'$$,
  '23514','MANUFACTURER_HAS_ACTIVE_MODELS','Manufacturer archive requires dependent active Models to be archived first');
select extensions.lives_ok($$update public.machine_models set is_active=false where id='f7500000-0000-4000-8000-000000000001'$$,
  'Machine Model archives without deleting Machines');
select extensions.is((select count(*)::integer from public.machines where machine_code='XEROX-GRH-01'),1,
  'existing Machine survives Model archive');
select extensions.throws_ok($$insert into public.machines(account_id,branch_id,machine_model_id,machine_code,display_name)
  values('f7100000-0000-4000-8000-000000000001','f7200000-0000-4000-8000-000000000001','f7500000-0000-4000-8000-000000000001','XEROX-GRH-02','Archived Model Denied')$$,
  '23503','ACTIVE_MACHINE_MODEL_NOT_FOUND','archived Model cannot be used for new Machine');
select extensions.lives_ok($$update public.manufacturers set is_active=false where id='f7400000-0000-4000-8000-000000000001'$$,
  'Manufacturer archives after Models are inactive');
select extensions.throws_ok($$update public.machine_models set is_active=true where id='f7500000-0000-4000-8000-000000000001'$$,
  '23514','MANUFACTURER_INACTIVE','Model cannot restore beneath archived Manufacturer');
select extensions.lives_ok($$update public.manufacturers set is_active=true where id='f7400000-0000-4000-8000-000000000001'; update public.machine_models set is_active=true where id='f7500000-0000-4000-8000-000000000001'$$,
  'Manufacturer then Model restore succeeds without history loss');
select extensions.ok(public.is_platform_superuser(),'explicit privilege remains UUID-bound throughout master changes');
select extensions.ok(not public.can_access_branch('f7100000-0000-4000-8000-000000000002','f7200000-0000-4000-8000-000000000001'),
  'operational Branch scope cannot cross account identity');

select * from extensions.finish();
rollback;
