begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

insert into public.accounts(id,code,name) values ('a4100000-0000-0000-0000-000000000001','CAP','Capability Test');
insert into public.branches(id,account_id,code,name) values
  ('a4200000-0000-0000-0000-000000000001','a4100000-0000-0000-0000-000000000001','A','Branch A'),
  ('a4200000-0000-0000-0000-000000000002','a4100000-0000-0000-0000-000000000001','B','Branch B');
insert into public.operational_people(id,account_id,name,code) values
  ('a4300000-0000-0000-0000-000000000001','a4100000-0000-0000-0000-000000000001','Capability Person','CAP-1'),
  ('a4300000-0000-0000-0000-000000000002','a4100000-0000-0000-0000-000000000001','Inactive Person','CAP-2');
insert into public.operational_person_branches(account_id,operational_person_id,branch_id,assigned_by,updated_by)
values ('a4100000-0000-0000-0000-000000000001','a4300000-0000-0000-0000-000000000001','a4200000-0000-0000-0000-000000000001',null,null);
insert into public.operational_person_branches(account_id,operational_person_id,branch_id,can_record_counter,is_active,assigned_by,updated_by)
values
 ('a4100000-0000-0000-0000-000000000001','a4300000-0000-0000-0000-000000000001','a4200000-0000-0000-0000-000000000002',false,true,null,null),
 ('a4100000-0000-0000-0000-000000000001','a4300000-0000-0000-0000-000000000002','a4200000-0000-0000-0000-000000000001',true,true,null,null);
update public.operational_people set is_active=false where id='a4300000-0000-0000-0000-000000000002';

select extensions.is((select can_record_counter from public.operational_person_branches where operational_person_id='a4300000-0000-0000-0000-000000000001' and branch_id='a4200000-0000-0000-0000-000000000001'),false,'capability defaults false');
select extensions.is(public.is_counter_operator_for_branch('a4100000-0000-0000-0000-000000000001','a4300000-0000-0000-0000-000000000001','a4200000-0000-0000-0000-000000000001'),false,'default-false assignment is not eligible');
update public.operational_person_branches set can_record_counter=true where operational_person_id='a4300000-0000-0000-0000-000000000001' and branch_id='a4200000-0000-0000-0000-000000000001';
select extensions.is(public.is_counter_operator_for_branch('a4100000-0000-0000-0000-000000000001','a4300000-0000-0000-0000-000000000001','a4200000-0000-0000-0000-000000000001'),true,'eligible true branch resolves');
select extensions.is(public.is_counter_operator_for_branch('a4100000-0000-0000-0000-000000000001','a4300000-0000-0000-0000-000000000001','a4200000-0000-0000-0000-000000000002'),false,'false other branch resolves');
update public.operational_person_branches set is_active=false where operational_person_id='a4300000-0000-0000-0000-000000000001' and branch_id='a4200000-0000-0000-0000-000000000001';
select extensions.is(public.is_counter_operator_for_branch('a4100000-0000-0000-0000-000000000001','a4300000-0000-0000-0000-000000000001','a4200000-0000-0000-0000-000000000001'),false,'inactive assignment is not eligible');
update public.operational_person_branches set is_active=true where operational_person_id='a4300000-0000-0000-0000-000000000001' and branch_id='a4200000-0000-0000-0000-000000000001';
select extensions.is(public.is_counter_operator_for_branch('a4100000-0000-0000-0000-000000000001','a4300000-0000-0000-0000-000000000002','a4200000-0000-0000-0000-000000000001'),false,'inactive person is not eligible');
select extensions.is(public.is_counter_operator_for_branch('a4100000-0000-0000-0000-000000000999','a4300000-0000-0000-0000-000000000001','a4200000-0000-0000-0000-000000000001'),false,'cross-account person is rejected');
select extensions.finish();
rollback;
