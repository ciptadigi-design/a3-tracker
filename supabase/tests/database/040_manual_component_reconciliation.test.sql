create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

select extensions.ok(exists(select 1 from pg_proc where proname='reconcile_manual_component_assignment'), 'reconciliation RPC exists');
select extensions.ok(exists(select 1 from pg_proc where proname='get_manual_component_reconciliation_candidate'), 'candidate RPC exists');
select extensions.ok((select prosrc like '%source_type<>''machine_specific''%' from pg_proc where proname='reconcile_manual_component_assignment' limit 1), 'reconciliation only accepts machine-specific assignments');
select extensions.ok((select prosrc like '%component_id=a.component_id%' and prosrc like '%slot_code%' from pg_proc where proname='reconcile_manual_component_assignment' limit 1), 'reconciliation uses durable component and slot identity');
select extensions.ok((select prosrc like '%machine_model_id=m.machine_model_id%' from pg_proc where proname='reconcile_manual_component_assignment' limit 1), 'reconciliation requires matching machine model');
select extensions.ok((select prosrc like '%profile exclusion%' or prosrc like '%profile_exclusions%' from pg_proc where proname='reconcile_manual_component_assignment' limit 1), 'reconciliation checks exclusions');
select extensions.ok((select prosrc like '%update public.machine_component_assignments%' from pg_proc where proname='reconcile_manual_component_assignment' limit 1), 'reconciliation updates assignment in place');
select extensions.ok((select prosrc not like '%delete from public.machine_component_lifecycles%' from pg_proc where proname='reconcile_manual_component_assignment' limit 1), 'reconciliation does not delete lifecycle history');
select * from extensions.finish();
