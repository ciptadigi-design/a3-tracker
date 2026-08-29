#!/usr/bin/env node
import { execFileSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { HOSTED_DEV_REF, IDENTITY } from './lib/m2-10a-engine.mjs'
import { assertCleanupSafety, buildCleanupSql, CLEANUP_IDS, cleanupFingerprint, loadProtectedIds, protectedIntersection, removalIds } from './lib/m2-10c-engine.mjs'

const args = Object.fromEntries(process.argv.slice(2).reduce((rows, value, index, all) => {
  if (value.startsWith('--')) rows.push([value.slice(2).replaceAll('-','_'), all[index + 1]?.startsWith('--') ? true : all[index + 1]])
  return rows
}, []))
const mode = args.apply === true || args.apply === 'true' ? 'apply' : 'preview'
const linkedRef = readFileSync(new URL('../../supabase/.temp/project-ref', import.meta.url), 'utf8').trim()
const required = ['target','project_ref','execution_id','expected_fingerprint','protected_crosswalk','recovery_manifest','output_dir']
for (const key of required) if (!args[key]) throw new Error(`--${key.replaceAll('_','-')} is required`)

const protectedIds = loadProtectedIds(args.protected_crosswalk)
const manifest = JSON.parse(readFileSync(args.recovery_manifest,'utf8'))
if (manifest.target_project_ref !== HOSTED_DEV_REF || manifest.verified !== true) throw new Error('Verified DEV recovery manifest required')
assertCleanupSafety({
  targetType:args.target, targetProjectRef:args.project_ref, linkedRef,
  apply:mode === 'apply', executionId:args.execution_id,
  suppliedIds:[...removalIds()], protectedIds,
})

const idList = (values) => values.map((id)=>`'${id}'::uuid`).join(',')
const stateSql = `select jsonb_build_object(
  'project_ref','${HOSTED_DEV_REF}',
  'schema_ledger',(select max(version) from supabase_migrations.schema_migrations),
  'dummy',jsonb_build_object(
    'purchases',(select count(*) from public.inventory_purchases where id in (${idList(CLEANUP_IDS.purchases)})),
    'purchase_lines',(select count(*) from public.inventory_purchase_lines where id in (${idList(CLEANUP_IDS.purchaseLines)})),
    'receipts',(select count(*) from public.inventory_receipts where id in (${idList(CLEANUP_IDS.receipts)})),
    'receipt_lines',(select count(*) from public.inventory_receipt_lines where id in (${idList(CLEANUP_IDS.receiptLines)})),
    'movements',(select count(*) from public.inventory_movements where id in (${idList(CLEANUP_IDS.movements)})),
    'lots',(select count(*) from public.inventory_cost_lots where id in (${idList(CLEANUP_IDS.lots)})),
    'allocations',(select count(*) from public.inventory_cost_allocations where id in (${idList(CLEANUP_IDS.allocations)})),
    'replacements',(select count(*) from public.component_replacement_events where id in (${idList(CLEANUP_IDS.replacements)})),
    'lifecycles',(select count(*) from public.machine_component_lifecycles where id in (${idList(CLEANUP_IDS.dummyLifecycles)}))),
  'affected_stock',(select coalesce(jsonb_agg(to_jsonb(x) order by inventory_item_id),'[]'::jsonb) from (select inventory_item_id,location_id,quantity from public.inventory_stock_balances where inventory_item_id in (${idList(CLEANUP_IDS.itemsPreserved)})) x),
  'previous_lifecycles',(select jsonb_agg(to_jsonb(x) order by id) from (select id,status,removed_at,removed_counter,actual_usage from public.machine_component_lifecycles where id in (${idList(CLEANUP_IDS.restoredLifecycles)})) x),
  'legacy',jsonb_build_object(
    'purchases',(select count(*) from public.inventory_purchases where notes like '%LEGACY_IMPORT%'),
    'counters',(select count(*) from public.counter_readings where notes like '%LEGACY_IMPORT%'),
    'lifecycles',(select count(*) from public.machine_component_lifecycles where notes like '%LEGACY_IMPORT%'),
    'incidents',(select count(*) from public.operational_incidents where description like '%LEGACY_IMPORT%' or customer_resolution like '%LEGACY_IMPORT%')),
  'machine_cost',jsonb_build_object(
    'clicks',(select max(reading_value)-min(reading_value) from public.counter_readings where machine_id='${IDENTITY.machineId}' and observed_at>='2026-08-01' and observed_at<'2026-08-31'),
    'component_consumption',(select coalesce(sum(a.allocated_cost),0) from public.inventory_cost_allocations a join public.inventory_movements m on m.id=a.outbound_movement_id where m.reference_id in (${idList(CLEANUP_IDS.replacements)})),
    'error_waste',(select coalesce(sum(assessed_loss),0) from public.operational_incidents where branch_id='${IDENTITY.branchId}' and occurred_at>='2026-08-01' and occurred_at<'2026-08-31' and status<>'voided')),
  'graha',jsonb_build_object(
    'counters',(select count(*) from public.counter_readings c join public.machines m on m.id=c.machine_id where m.branch_id='${IDENTITY.grahaId}'),
    'lifecycles',(select count(*) from public.machine_component_lifecycles where branch_id='${IDENTITY.grahaId}'),
    'replacements',(select count(*) from public.component_replacement_events where branch_id='${IDENTITY.grahaId}'),
    'incidents',(select count(*) from public.operational_incidents where branch_id='${IDENTITY.grahaId}'),
    'movements',(select count(*) from public.inventory_movements m join public.inventory_locations l on l.id=m.location_id where l.branch_id='${IDENTITY.grahaId}'))
) as state;`

function query(sql, column='state') {
  const output=execFileSync('supabase',['db','query','--linked','--output','json',sql],{encoding:'utf8',maxBuffer:32*1024*1024})
  const parsed=JSON.parse(output); return parsed.rows[0][column]
}

async function transaction(sql) {
  const dir=await mkdtemp(join(tmpdir(),'m2-10c-cleanup-')); const file=join(dir,'cleanup.sql')
  try { await writeFile(file,sql,{mode:0o600}); return execFileSync('supabase',['db','query','--linked','--file',file],{encoding:'utf8',maxBuffer:32*1024*1024}) }
  finally { await rm(dir,{recursive:true,force:true}) }
}

const before=stateSql && query(stateSql)
const beforeFingerprint=cleanupFingerprint(before)
if (beforeFingerprint !== args.expected_fingerprint) throw new Error(`Pre-cleanup fingerprint mismatch: expected ${args.expected_fingerprint}, received ${beforeFingerprint}`)
await transaction(buildCleanupSql({dryRun:mode!=='apply'}))
const after=query(stateSql); const afterFingerprint=cleanupFingerprint(after)
if(mode==='preview' && afterFingerprint!==beforeFingerprint) throw new Error('Dry-run changed hosted DEV')
if(mode==='apply' && (after.dummy.purchases!==0 || after.dummy.movements!==0 || after.legacy.purchases!==161)) throw new Error('Post-cleanup reconciliation failed')

await mkdir(args.output_dir,{recursive:true})
const report={version:'m2.10c-v1',execution_id:args.execution_id,mode,target_project_ref:HOSTED_DEV_REF,performed_at:new Date().toISOString(),protected_set_size:protectedIds.size,protected_intersection:protectedIntersection(protectedIds),candidate_ids:Object.fromEntries(Object.entries(CLEANUP_IDS).filter(([,v])=>Array.isArray(v))),before_fingerprint:beforeFingerprint,after_fingerprint:afterFingerprint,before,after,recovery:manifest}
await writeFile(join(args.output_dir,`${mode}.json`),`${JSON.stringify(report,null,2)}\n`)
process.stdout.write(`${JSON.stringify(report,null,2)}\n`)
