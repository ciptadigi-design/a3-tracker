-- M2.10A DISPOSABLE-ONLY target baseline. Never apply to hosted Supabase.
\set ON_ERROR_STOP on
begin;

do $$ begin
  if current_setting('server_version_num')::int < 170000 then
    raise exception 'M2.10A requires the repository PostgreSQL 17 ledger';
  end if;
  if (select max(version) from supabase_migrations.schema_migrations) <> '20260829000600' then
    raise exception 'unexpected schema migration ledger';
  end if;
end $$;

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at)
values ('a0000000-0000-4000-8000-000000000001','authenticated','authenticated','m210a-owner@test.invalid','',now(),'{}','{"display_name":"M2.10A Fixture Owner"}',now(),now());

insert into public.accounts(id,code,name,default_timezone)
values ('357e420a-c9ea-4404-9da4-f254c5dce5ef','CG','Cipta Grafika','Asia/Jakarta');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at)
values ('a0100000-0000-4000-8000-000000000001','357e420a-c9ea-4404-9da4-f254c5dce5ef','a0000000-0000-4000-8000-000000000001','owner','active',now());
insert into public.branches(id,account_id,code,name,timezone) values
('76d3c7ab-55c3-40f7-b133-0ef54a448893','357e420a-c9ea-4404-9da4-f254c5dce5ef','CG-TUP','Tuparev','Asia/Jakarta'),
('9f753339-0d54-42c9-9bb6-afe2461803f8','357e420a-c9ea-4404-9da4-f254c5dce5ef','CG-GRAHA','Graha','Asia/Jakarta');
insert into public.machine_model_components(id,account_id,machine_model_id,component_id,slot_code,display_order,tracking_method,baseline_expected_clicks,adaptive_enabled,healthy_threshold_percent,watch_threshold_percent,warning_threshold_percent,critical_threshold_percent,notes,is_active)
select 'a0200000-0000-4000-8000-000000000001','357e420a-c9ea-4404-9da4-f254c5dce5ef',machine_model_id,component_id,slot_code,display_order,tracking_method,13500,adaptive_enabled,healthy_threshold_percent,watch_threshold_percent,warning_threshold_percent,critical_threshold_percent,'Accepted Cipta Grafika Toner Cyan override fixture',true
from public.machine_model_components where id='54000000-0000-0000-0000-000000000025';
insert into public.machines(id,account_id,branch_id,machine_model_id,machine_code,display_name,timezone)
values ('b4ca07ee-c588-404d-abcf-b6a029e68776','357e420a-c9ea-4404-9da4-f254c5dce5ef','76d3c7ab-55c3-40f7-b133-0ef54a448893','51000000-0000-0000-0000-000000000001','CG-TUP-A3-01','Konica Minolta bizhub PRESS C1070/1070P','Asia/Jakarta');

-- Provisioning creates the correct 28 slots; replace their random fixture IDs
-- before any evidence exists so fresh disposable rebuilds are byte-stable.
delete from public.machine_component_assignments where machine_id='b4ca07ee-c588-404d-abcf-b6a029e68776';
insert into public.machine_component_assignments(
 id,account_id,branch_id,machine_id,component_id,slot_code,display_order,tracking_method,
 baseline_expected_clicks,healthy_threshold_percent,watch_threshold_percent,warning_threshold_percent,
 critical_threshold_percent,source_type,source_profile_id,status)
select ('a1000000-0000-4000-8000-'||lpad(row_number() over(order by profile.id)::text,12,'0'))::uuid,
 '357e420a-c9ea-4404-9da4-f254c5dce5ef','76d3c7ab-55c3-40f7-b133-0ef54a448893',
 'b4ca07ee-c588-404d-abcf-b6a029e68776',profile.component_id,profile.slot_code,profile.display_order,
 profile.tracking_method,profile.baseline_expected_clicks,profile.healthy_threshold_percent,
 profile.watch_threshold_percent,profile.warning_threshold_percent,profile.critical_threshold_percent,
 'model_profile',profile.id,'configured'
from (
 select distinct on (lower(btrim(candidate.slot_code))) candidate.*
 from public.machine_model_components candidate
 where candidate.machine_model_id='51000000-0000-0000-0000-000000000001'
   and (candidate.account_id is null or candidate.account_id='357e420a-c9ea-4404-9da4-f254c5dce5ef')
 order by lower(btrim(candidate.slot_code)),(candidate.account_id='357e420a-c9ea-4404-9da4-f254c5dce5ef') desc,candidate.id
) profile
order by profile.slot_code;

insert into public.operational_people(id,account_id,name,code) values
('7bff2e7b-de85-40a7-949f-86acd32aea8c','357e420a-c9ea-4404-9da4-f254c5dce5ef','Muhammad Angga Nugraha','ANGGA'),
('03852a38-51d5-46cb-88a4-145325500e53','357e420a-c9ea-4404-9da4-f254c5dce5ef','Muhammad Daffa Ramadhiansyah','DAFFA'),
('c89d9c9c-b892-4794-b69c-f2680908a068','357e420a-c9ea-4404-9da4-f254c5dce5ef','Akmal Fauzan','AKMAL');
insert into public.operational_person_branches(account_id,operational_person_id,branch_id)
select '357e420a-c9ea-4404-9da4-f254c5dce5ef',person.id,'76d3c7ab-55c3-40f7-b133-0ef54a448893'
from public.operational_people person where person.account_id='357e420a-c9ea-4404-9da4-f254c5dce5ef';

insert into public.inventory_locations(id,account_id,branch_id,code,name,is_active)
values ('b6296488-5479-4dd0-9463-091891b4cbe4','357e420a-c9ea-4404-9da4-f254c5dce5ef','76d3c7ab-55c3-40f7-b133-0ef54a448893','CG_DIGITAL','CG Digital Print',true);

insert into public.counter_readings(id,account_id,machine_id,counter_type_id,reading_value,observed_at,entered_by,source,client_request_id,created_by,operator_person_id,operator_name_snapshot) values
('44086d7c-c480-4039-a115-4002b0c94e66','357e420a-c9ea-4404-9da4-f254c5dce5ef','b4ca07ee-c588-404d-abcf-b6a029e68776','52000000-0000-0000-0000-000000000001',1437283,'2026-08-26 11:15:00+00','a0000000-0000-4000-8000-000000000001','manual','a2000000-0000-4000-8000-000000000001','a0000000-0000-4000-8000-000000000001','7bff2e7b-de85-40a7-949f-86acd32aea8c','Muhammad Angga Nugraha'),
('6e40a1e2-72ef-4878-b669-65271ac144b9','357e420a-c9ea-4404-9da4-f254c5dce5ef','b4ca07ee-c588-404d-abcf-b6a029e68776','52000000-0000-0000-0000-000000000001',1437911,'2026-08-26 14:22:00+00','a0000000-0000-4000-8000-000000000001','manual','a2000000-0000-4000-8000-000000000002','a0000000-0000-4000-8000-000000000001','7bff2e7b-de85-40a7-949f-86acd32aea8c','Muhammad Angga Nugraha'),
('d222f09e-99e8-4b13-9281-0b64d7570dc4','357e420a-c9ea-4404-9da4-f254c5dce5ef','b4ca07ee-c588-404d-abcf-b6a029e68776','52000000-0000-0000-0000-000000000001',1438992,'2026-08-27 15:08:00+00','a0000000-0000-4000-8000-000000000001','component_replacement','a2000000-0000-4000-8000-000000000003','a0000000-0000-4000-8000-000000000001','c89d9c9c-b892-4794-b69c-f2680908a068','Akmal Fauzan');

commit;

-- The accepted lifecycle bootstrap is repository-owned and guarded. The caller loads it next.
