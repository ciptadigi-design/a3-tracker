import { execFileSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { canonicalize, deterministicFingerprint } from '../legacy-audit.mjs'
import { HOSTED_DEV_REF, IDENTITY } from './m2-10a-engine.mjs'

const q = (value) => value === null || value === undefined || value === '' ? 'null' : `'${String(value).replaceAll("'", "''")}'`
const emptyUuid = "'00000000-0000-0000-0000-000000000000'"
const array = (sql) => `coalesce((${sql}),'[]'::jsonb)`

export const HOSTED_TARGET_STATE_SQL = `select jsonb_build_object(
'project_ref','${HOSTED_DEV_REF}',
'schema_ledger',(select max(version) from supabase_migrations.schema_migrations),
'account',(select to_jsonb(x) from (select id,code,name,status from public.accounts where id='${IDENTITY.accountId}') x),
'branch',(select to_jsonb(x) from (select id,account_id,code,name,is_active from public.branches where id='${IDENTITY.branchId}') x),
'machine',(select to_jsonb(x) from (select id,account_id,branch_id,machine_model_id,machine_code,display_name,status,is_active from public.machines where id='${IDENTITY.machineId}') x),
'graha',(select to_jsonb(x) from (select id,account_id,code,name,is_active from public.branches where id='${IDENTITY.grahaId}') x),
'counters',${array(`select jsonb_agg(to_jsonb(x) order by observed_at,id) from (select id,machine_id,counter_type_id,reading_value,observed_at,status,previous_reading_id,client_request_id,operator_person_id,operator_name_snapshot,source,notes from public.counter_readings where machine_id='${IDENTITY.machineId}') x`)},
'people',${array(`select jsonb_agg(to_jsonb(x) order by lower(name),id) from (select id,name,code,is_active,linked_user_id,notes from public.operational_people where account_id='${IDENTITY.accountId}') x`)},
'person_branches',${array(`select jsonb_agg(to_jsonb(x) order by operational_person_id,branch_id) from (select operational_person_id,branch_id,is_active from public.operational_person_branches where account_id='${IDENTITY.accountId}') x`)},
'assignments',${array(`select jsonb_agg(to_jsonb(x) order by slot_code,id) from (select id,component_id,slot_code,status,source_type,source_profile_id from public.machine_component_assignments where machine_id='${IDENTITY.machineId}') x`)},
'lifecycles',${array(`select jsonb_agg(to_jsonb(x) order by slot_code,installed_counter,id) from (select id,branch_id,machine_id,component_id,model_component_profile_id,slot_code,status,installed_counter,installed_at,installation_source,removed_counter,removed_at,actual_usage,notes from public.machine_component_lifecycles where machine_id='${IDENTITY.machineId}') x`)},
'replacements',${array(`select jsonb_agg(to_jsonb(x) order by replaced_at,id) from (select id,branch_id,machine_id,previous_lifecycle_id,new_lifecycle_id,slot_code_snapshot,replacement_counter,replaced_at,client_request_id from public.component_replacement_events where machine_id='${IDENTITY.machineId}') x`)},
'suppliers',${array(`select jsonb_agg(to_jsonb(x) order by lower(name),id) from (select id,supplier_code,name,is_active,notes from public.inventory_suppliers where account_id='${IDENTITY.accountId}') x`)},
'items',${array(`select jsonb_agg(to_jsonb(x) order by lower(name),id) from (select id,component_id,sku,name,unit,is_active,is_canonical,notes from public.inventory_items where account_id='${IDENTITY.accountId}') x`)},
'locations',${array(`select jsonb_agg(to_jsonb(x) order by id) from (select id,branch_id,code,name,is_active from public.inventory_locations where account_id='${IDENTITY.accountId}') x`)},
'purchases',${array(`select jsonb_agg(to_jsonb(x) order by purchase_date,id) from (select id,branch_id,supplier_id,purchase_number,purchase_date,status,client_request_id,notes from public.inventory_purchases where account_id='${IDENTITY.accountId}') x`)},
'purchase_lines',${array(`select jsonb_agg(to_jsonb(x) order by purchase_id,id) from (select id,purchase_id,inventory_item_id,ordered_quantity,unit_price,line_total,notes from public.inventory_purchase_lines where account_id='${IDENTITY.accountId}') x`)},
'receipts',${array(`select jsonb_agg(to_jsonb(x) order by received_at,id) from (select id,purchase_id,location_id,receipt_number,received_at,client_request_id from public.inventory_receipts where account_id='${IDENTITY.accountId}') x`)},
'receipt_lines',${array(`select jsonb_agg(to_jsonb(x) order by receipt_id,id) from (select id,receipt_id,purchase_line_id,inventory_movement_id,quantity,acquisition_value from public.inventory_receipt_lines where account_id='${IDENTITY.accountId}') x`)},
'movements',${array(`select jsonb_agg(to_jsonb(x) order by occurred_at,id) from (select id,inventory_item_id,location_id,movement_type,quantity,occurred_at,reference_type,reference_id,client_request_id,notes from public.inventory_movements where account_id='${IDENTITY.accountId}') x`)},
'balances',${array(`select jsonb_agg(to_jsonb(x) order by inventory_item_id,location_id) from (select inventory_item_id,location_id,quantity from public.inventory_stock_balances where account_id='${IDENTITY.accountId}') x`)},
'fifo_lots',${array(`select jsonb_agg(to_jsonb(x) order by id) from (select id,inventory_item_id,location_id,source_receipt_line_id,source_type,source_quantity,unit_cost,effective_at from public.inventory_cost_lots where account_id='${IDENTITY.accountId}') x`)},
'fifo_allocations',${array(`select jsonb_agg(to_jsonb(x) order by id) from (select id,outbound_movement_id,source_cost_lot_id,quantity,unit_cost,allocated_cost,allocation_policy from public.inventory_cost_allocations where account_id='${IDENTITY.accountId}') x`)},
'incidents',${array(`select jsonb_agg(to_jsonb(x) order by occurred_at,id) from (select id,branch_id,machine_id,occurred_at,category,incident_type,qty_affected,material_loss,service_loss,penalty_multiplier,assessed_loss,status,client_request_id,responsible_person_id,responsible_name_snapshot from public.operational_incidents where account_id='${IDENTITY.accountId}') x`)},
'operating_costs',${array(`select jsonb_agg(to_jsonb(x) order by id) from (select id,branch_id,machine_id,category,amount,effective_at,period_start,period_end,status,source_type,client_request_id from public.machine_operating_costs where account_id='${IDENTITY.accountId}') x`)},
'selling_prices',${array(`select jsonb_agg(to_jsonb(x) order by id) from (select id,branch_id,machine_id,price_per_click,effective_from,status,client_request_id from public.machine_selling_prices where account_id='${IDENTITY.accountId}') x`)},
'components',${array(`select jsonb_agg(to_jsonb(x) order by id) from (select id,account_id,code,name,is_active from public.components where account_id is null or account_id='${IDENTITY.accountId}') x`)},
'model_components',${array(`select jsonb_agg(to_jsonb(x) order by slot_code,id) from (select id,account_id,machine_model_id,component_id,slot_code,is_active,baseline_expected_clicks from public.machine_model_components where machine_model_id=(select machine_model_id from public.machines where id='${IDENTITY.machineId}') and (account_id is null or account_id='${IDENTITY.accountId}')) x`)},
'auth_user_count',(select count(*) from auth.users),
'migration_actor_id',(select created_by from public.counter_readings where id='6e40a1e2-72ef-4878-b669-65271ac144b9'),
'migration_actor_exists',(select count(*) from auth.users where id=(select created_by from public.counter_readings where id='6e40a1e2-72ef-4878-b669-65271ac144b9')),
'graha_counts',jsonb_build_object(
  'machines',(select count(*) from public.machines where branch_id='${IDENTITY.grahaId}'),
  'counters',(select count(*) from public.counter_readings c join public.machines m on m.id=c.machine_id where m.branch_id='${IDENTITY.grahaId}'),
  'lifecycles',(select count(*) from public.machine_component_lifecycles where branch_id='${IDENTITY.grahaId}'),
  'replacements',(select count(*) from public.component_replacement_events where branch_id='${IDENTITY.grahaId}'),
  'incidents',(select count(*) from public.operational_incidents where branch_id='${IDENTITY.grahaId}'),
  'costs',(select count(*) from public.machine_operating_costs where branch_id='${IDENTITY.grahaId}'),
  'movements',(select count(*) from public.inventory_movements mv join public.inventory_locations l on l.id=mv.location_id where l.branch_id='${IDENTITY.grahaId}'),
  'stock',(select coalesce(sum(sb.quantity),0) from public.inventory_stock_balances sb join public.inventory_locations l on l.id=sb.location_id where l.branch_id='${IDENTITY.grahaId}')
)) as state;`

function linkedProjectRef() {
  return readFileSync(new URL('../../../supabase/.temp/project-ref', import.meta.url), 'utf8').trim()
}

export function assertHostedDevSafety({ targetType, targetProjectRef, apply = false, linkedRef = linkedProjectRef() }) {
  if (targetType !== 'DEV') throw new Error(`Hosted APPLY refused: target type ${targetType || 'UNKNOWN'} is not DEV`)
  if (targetProjectRef !== HOSTED_DEV_REF || linkedRef !== HOSTED_DEV_REF) throw new Error('Hosted APPLY refused: exact DEV project ref validation failed')
  if (/prod(uction)?/i.test(`${targetType}:${targetProjectRef}:${linkedRef}`)) throw new Error('Hosted APPLY refused: Production is hard blocked')
  if (apply !== true) return true
  return true
}

function parseQueryOutput(output, column) {
  const parsed = JSON.parse(output)
  if (!Array.isArray(parsed.rows) || parsed.rows.length !== 1 || !(column in parsed.rows[0])) throw new Error(`Hosted query did not return exactly one ${column} row`)
  return parsed.rows[0][column]
}

export function runHostedQuery(sql, column = 'state') {
  assertHostedDevSafety({ targetType: 'DEV', targetProjectRef: HOSTED_DEV_REF })
  const output = execFileSync('supabase', ['db','query','--linked','--output','json',sql], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
  return parseQueryOutput(output, column)
}

export function hostedTargetPreflight() {
  const state = runHostedQuery(HOSTED_TARGET_STATE_SQL)
  return { fingerprint: deterministicFingerprint(state), state }
}

export async function runHostedSql(sql, { targetType, targetProjectRef, apply }) {
  assertHostedDevSafety({ targetType, targetProjectRef, apply })
  if (/\b(delete|truncate|drop|alter)\b/i.test(sql)) throw new Error('Hosted SQL refused: destructive statement detected')
  const dir = await mkdtemp(join(tmpdir(), 'm2-10b-sql-'))
  const file = join(dir, 'transaction.sql')
  try {
    await writeFile(file, sql, { mode: 0o600 })
    return execFileSync('supabase', ['db','query','--linked','--output','json','--file',file], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
  } finally {
    await rm(dir, { recursive: true, force: true })
  }
}

const idsFor = (plan, domain) => plan.crosswalk.filter((row) => row.target_entity === domain && row.target_id).map((row) => q(row.target_id)).join(',') || emptyUuid

export function hostedReconciliation(plan) {
  const purchaseIds = idsFor(plan, 'inventory_purchases')
  const sql = `select jsonb_build_object(
  'source',526,
  'dispositions',${q(JSON.stringify(plan.dispositionTotals))}::jsonb,
  'eligible_accounted',${plan.eligibleAccounted},
  'unresolved_staging_approvals',${plan.dispositionTotals.MANUAL_REVIEW},
  'unexplained',${plan.unexplainedRemainder},
  'counters',(select jsonb_build_object('accounted',count(*),'imported',count(*) filter(where id<>'6e40a1e2-72ef-4878-b669-65271ac144b9'),'min_date',min(observed_at),'max_date',max(observed_at),'min_value',min(reading_value),'max_value',max(reading_value),'latest_value',(array_agg(reading_value order by observed_at desc,id desc))[1]) from public.counter_readings where id in (${idsFor(plan,'counter_readings')})),
  'counter_merge',(select count(*) from public.counter_readings where id='6e40a1e2-72ef-4878-b669-65271ac144b9'),
  'synthetic_time_counters',(select count(*) from public.counter_readings where id in (${idsFor(plan,'counter_readings')}) and notes like '%MIGRATION_SYNTHETIC_TIME%'),
  'lifecycles',(select count(*) from public.machine_component_lifecycles where id in (${idsFor(plan,'machine_component_lifecycles')})),
  'purchases',(select jsonb_build_object('rows',count(distinct p.id),'qty',coalesce(sum(l.ordered_quantity),0),'value',coalesce(sum(l.line_total),0)) from public.inventory_purchases p join public.inventory_purchase_lines l on l.purchase_id=p.id where p.id in (${purchaseIds})),
  'receipts_from_legacy_purchases',(select count(*) from public.inventory_receipts where purchase_id in (${purchaseIds})),
  'movements_from_legacy_purchases',(select count(*) from public.inventory_movements where reference_id in (${purchaseIds})),
  'fifo_from_legacy_purchases',(select count(*) from public.inventory_cost_lots where source_receipt_line_id in (select rl.id from public.inventory_receipt_lines rl join public.inventory_receipts r on r.id=rl.receipt_id where r.purchase_id in (${purchaseIds}))),
  'opening_stock_from_legacy',(select count(*) from public.inventory_movements where movement_type='opening_balance' and notes like '%LEGACY_IMPORT%'),
  'incidents',(select jsonb_build_object('rows',count(*),'assessed_loss',coalesce(sum(assessed_loss),0)) from public.operational_incidents where id in (${idsFor(plan,'operational_incidents')})),
  'people',(select jsonb_build_object('rows',count(*),'names',coalesce(jsonb_agg(name order by name),'[]'::jsonb)) from public.operational_people where id in (${plan.operations.people.map((row)=>q(row.id)).join(',') || emptyUuid})),
  'auth_user_count',(select count(*) from auth.users),
  'target_state',jsonb_build_object('assignments',(select count(*) from public.machine_component_assignments where machine_id='${IDENTITY.machineId}'),'lifecycles_total',(select count(*) from public.machine_component_lifecycles where machine_id='${IDENTITY.machineId}'),'replacement_events',(select count(*) from public.component_replacement_events where machine_id='${IDENTITY.machineId}')),
  'graha_legacy_leakage',(
    (select count(*) from public.counter_readings c join public.machines m on m.id=c.machine_id where c.id in (${idsFor(plan,'counter_readings')}) and m.branch_id='${IDENTITY.grahaId}')+
    (select count(*) from public.machine_component_lifecycles where id in (${idsFor(plan,'machine_component_lifecycles')}) and branch_id='${IDENTITY.grahaId}')+
    (select count(*) from public.operational_incidents where id in (${idsFor(plan,'operational_incidents')}) and branch_id='${IDENTITY.grahaId}')+
    (select count(*) from public.inventory_purchases where id in (${purchaseIds}) and branch_id='${IDENTITY.grahaId}')+
    (select count(*) from public.inventory_receipts r join public.inventory_locations l on l.id=r.location_id where r.purchase_id in (${purchaseIds}) and l.branch_id='${IDENTITY.grahaId}')
  ),
  'legacy_cost_rows',(select count(*) from public.machine_operating_costs where notes like '%LEGACY_IMPORT%')
  ) as reconciliation;`
  return runHostedQuery(sql, 'reconciliation')
}

function idSet(state, key) {
  return new Set((state[key] ?? []).map((row) => row.id))
}

export function mutationSummary(plan, before, after) {
  const domains = {
    people: plan.operations.people.map((row) => row.id), counters: plan.operations.counters.map((row) => row.id),
    lifecycles: plan.operations.lifecycles.map((row) => row.id), purchases: plan.operations.purchases.map((row) => row.id),
    incidents: plan.operations.incidents.map((row) => row.id), suppliers: plan.operations.suppliers.map((row) => row.id), items: plan.operations.items.map((row) => row.id),
  }
  const stateKeys = { people:'people',counters:'counters',lifecycles:'lifecycles',purchases:'purchases',incidents:'incidents',suppliers:'suppliers',items:'items' }
  const result = { created: {}, already_present: {}, merged: { counters: 1 }, skipped: Object.values(plan.dispositionTotals).reduce((sum,value)=>sum+value,0)-plan.dispositionTotals.IMPORT-plan.dispositionTotals.MERGE, failed: 0 }
  for (const [domain, ids] of Object.entries(domains)) {
    const beforeIds = idSet(before, stateKeys[domain]); const afterIds = idSet(after, stateKeys[domain])
    result.created[domain] = ids.filter((id) => !beforeIds.has(id) && afterIds.has(id)).length
    result.already_present[domain] = ids.filter((id) => beforeIds.has(id) && afterIds.has(id)).length
  }
  return result
}

export async function writeHostedReports(outputDir, payload) {
  await mkdir(outputDir, { recursive: true })
  const expectedDifferences = payload.plan.crosswalk.filter((row) => !['IMPORT','MERGE'].includes(row.disposition))
  const manifest = canonicalize({
    migration_version: payload.plan.version, execution_id: payload.executionId, started_at: payload.startedAt,
    finished_at: payload.finishedAt, source_project: IDENTITY.sourceProject, source_snapshot_captured_at: payload.sourceCapturedAt,
    source_fingerprints: payload.sourceFingerprints, target_type: 'DEV', target_project_ref: HOSTED_DEV_REF,
    target_identity: { account_id: IDENTITY.accountId, branch_id: IDENTITY.branchId, machine_id: IDENTITY.machineId, graha_denylist_id: IDENTITY.grahaId },
    target_schema_ledger: IDENTITY.schemaLedger, target_preflight_fingerprint: payload.before.fingerprint,
    target_postflight_fingerprint: payload.after.fingerprint, disposition_totals: payload.plan.dispositionTotals,
    unresolved_staging_approvals: payload.plan.dispositionTotals.MANUAL_REVIEW, expected_differences: expectedDifferences,
    people_resolution: { oan: 'MAP_EXISTING: Akmal Fauzan', sri_bulan: 'ARCHIVE_TEXT_ONLY: identity not sufficiently proven' },
    recovery: payload.recovery, mode: payload.mode, mutation_summary: payload.mutationSummary, reconciliation: payload.reconciliation,
  })
  await writeFile(join(outputDir,'manifest.json'), `${JSON.stringify(manifest,null,2)}\n`)
  await writeFile(join(outputDir,'crosswalk.json'), `${JSON.stringify({migration_version:payload.plan.version,rows:payload.plan.crosswalk},null,2)}\n`)
  await writeFile(join(outputDir,'expected-differences.json'), `${JSON.stringify({migration_version:payload.plan.version,rows:expectedDifferences},null,2)}\n`)
  await writeFile(join(outputDir,'target-preflight.json'), `${JSON.stringify(payload.before,null,2)}\n`)
  await writeFile(join(outputDir,'target-postflight.json'), `${JSON.stringify(payload.after,null,2)}\n`)
  await writeFile(join(outputDir,'reconciliation.json'), `${JSON.stringify(payload.reconciliation,null,2)}\n`)
  await writeFile(join(outputDir,'mutation-summary.json'), `${JSON.stringify(payload.mutationSummary,null,2)}\n`)
  return manifest
}
