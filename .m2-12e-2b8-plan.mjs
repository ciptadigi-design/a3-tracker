#!/usr/bin/env node
import { readFileSync, writeFileSync } from 'node:fs'
import {
  MIGRATION_NAMESPACE,
  SOURCE_TABLES,
  buildMigrationPlan,
  calculateSourceFingerprints,
  idFor,
  slotByLabel,
} from './scripts/migration/lib/m2-10a-engine.mjs'
import {
  assignCounterMigrationTimestamps,
  deterministicFingerprint,
  deterministicUuidV5,
  fingerprintRows,
  normalizeForComparison,
} from './scripts/migration/legacy-audit.mjs'

const args = Object.fromEntries(process.argv.slice(2).map((value, index, all) =>
  value.startsWith('--') ? [value.slice(2), all[index + 1]] : null).filter(Boolean))
if (!args.source || !args.output) throw new Error('Usage: .m2-12e-2b8-plan.mjs --source <frozen.json> --output <plan.json>')

const EXPECTED = Object.freeze({
  counts: { click_history:185, part_replacements:70, error_logs:91, inventory_parts:22, part_purchases:161 },
  fingerprints: {
    click_history:'a577d071b32150c7ad13fd4b70c0fe6d9c4d3a7be08c27ad3efb8679cdeca537',
    part_replacements:'2dc08b4a2d2f6dcaf354732c44823485e895da922aa9583d7b5a2c8f4a2e207e',
    error_logs:'65d17c0c54b712ee04c5848e83147a2f892c1face3a48504a19e3d2a1e4cf35b',
    inventory_parts:'ffcd9463f006fc531987f7b3a17c0f1d3d9dd58a2c47ffef21252ef3347ca632',
    part_purchases:'6354204d285987b56764268ff22a06480003886528470401fdfe19766d7ee306',
  },
  disposition:{ IMPORT:480, MERGE:0, SKIP_DUPLICATE:2, ARCHIVE_ONLY:45, APPROVED_EXCLUDE:2, MANUAL_REVIEW:0 },
})
const PRODUCTION = Object.freeze({
  app_sha:'b69c7e125f083f52dc519f4a3cc3d401ba5a64b0',
  database:'u777904340_a3production',
  master_user_id:'c83e9f52-9a34-49eb-b99a-de8dcb7b7431',
  account_id:'4b26a0ee-e06f-4563-a6cc-9dfc7fbc0e0c',
  branch_id:'94051ab9-235c-455f-b7ce-63f255cda3f6',
  machine_model_id:'f3b91ad2-7ecc-4d0c-a6ce-648c08691dab',
  machine_id:'708e199e-7f77-4219-b278-37d0b94821d4',
})
const NEW_COUNTER_IDS = new Set([
  '9900bcd0-3754-4e69-9e90-6c84dc2f8b2d',
  'd6f465f3-341d-4230-ae09-e2a87d084e11',
  '96cddc6e-ac52-4a54-a839-1a9e47fa28e1',
])
const DEV_HIERARCHY_IDS = [
  '357e420a-c9ea-4404-9da4-f254c5dce5ef',
  '76d3c7ab-55c3-40f7-b133-0ef54a448893',
  'b4ca07ee-c588-404d-abcf-b6a029e68776',
]
const normalized = normalizeForComparison
const prodId = (kind, businessKey) => deterministicUuidV5(MIGRATION_NAMESPACE, `production:${PRODUCTION.account_id}:${kind}:${normalized(businessKey)}`)

const snapshot = JSON.parse(readFileSync(args.source, 'utf8'))
const source = snapshot.tables ?? snapshot
const counts = Object.fromEntries(SOURCE_TABLES.map((table) => [table, source[table]?.length ?? 0]))
if (JSON.stringify(counts) !== JSON.stringify(EXPECTED.counts)) throw new Error(`Frozen source count mismatch: ${JSON.stringify(counts)}`)
const totalSource = Object.values(counts).reduce((sum, count) => sum + count, 0)
if (totalSource !== 529) throw new Error(`Frozen source total mismatch: ${totalSource}`)
const fingerprints = calculateSourceFingerprints(source)
const canonicalFingerprints = Object.fromEntries(SOURCE_TABLES.map((table) => [table, fingerprintRows(source[table])]))
if (JSON.stringify(fingerprints) !== JSON.stringify(EXPECTED.fingerprints)) throw new Error('Canonical engine source fingerprint mismatch')
if (JSON.stringify(canonicalFingerprints) !== JSON.stringify(EXPECTED.fingerprints)) throw new Error('legacy-audit canonical fingerprint mismatch')

// Reuse the accepted 526-row planner verbatim, then extend it only with the three
// frozen counters. This prevents a second implementation of the 526 approved rules.
const baselineSource = { ...source, click_history:source.click_history.filter((row) => !NEW_COUNTER_IDS.has(String(row.id))) }
if (Object.values(baselineSource).reduce((sum, rows) => sum + rows.length, 0) !== 526) throw new Error('Final-source delta is not exactly the three approved counters')
const plan = buildMigrationPlan(baselineSource, { hostedStaging:true, productionRehearsal:true })
const timestampById = new Map(assignCounterMigrationTimestamps(source.click_history).map((row) => [String(row.id), row]))
const lastBaselineCounter = [...plan.operations.counters].sort((a,b) => a.observed_at.localeCompare(b.observed_at) || a.id.localeCompare(b.id)).at(-1)
let previousId = lastBaselineCounter?.id ?? null
const newCounters = source.click_history.filter((row) => NEW_COUNTER_IDS.has(String(row.id)))
  .map((row) => ({ row, timestamp:timestampById.get(String(row.id)) }))
  .sort((a,b) => a.timestamp.migration_timestamp.localeCompare(b.timestamp.migration_timestamp) || String(a.row.id).localeCompare(String(b.row.id)))
  .map(({row,timestamp}) => {
    const operator = normalized(row.operator) === 'ijal'
      ? { id:null, name:null, treatment:'ARCHIVE_TEXT_ONLY' }
      : { id:null, name:'Akmal Fauzan', treatment:'MAP_EXISTING' }
    const result = { id:idFor('row','click_history',row.id), sourceId:String(row.id), value:Number(row.total_clicks), observed_at:timestamp.migration_timestamp, evidence:timestamp.timestamp_evidence, operator, rawOperator:row.operator, disposition:'IMPORT', previousId }
    previousId = result.id
    return result
  })
plan.operations.counters.push(...newCounters)
for (const counter of newCounters) plan.crosswalk.push({
  source_project:'wtslqxjwjqyjgcapfrrz', source_schema:'public', source_table:'click_history', source_id:counter.sourceId,
  target_entity:'counter_readings', target_id:counter.id, mapping_rule:'EXACT_IDENTITY_AND_APPROVED_MAPPING', confidence:'HIGH',
  disposition:'IMPORT', eligibility:'PRODUCTION_CREATE_NO_TARGET_COLLISION', reason:counter.evidence,
  actor_resolution:{ operational_person_id:counter.operator.id, operational_person_name:counter.operator.name, treatment:counter.operator.treatment },
  source_evidence:{ operator:counter.rawOperator, total_clicks:counter.value, observed_at:counter.observed_at },
})
plan.crosswalk.sort((a,b) => a.source_table.localeCompare(b.source_table) || String(a.source_id).localeCompare(String(b.source_id)))
plan.dispositionTotals.IMPORT += 3
plan.eligibleAccounted += 3
plan.unexplainedRemainder = 0
if (JSON.stringify(plan.dispositionTotals) !== JSON.stringify(EXPECTED.disposition)) throw new Error(`Disposition mismatch: ${JSON.stringify(plan.dispositionTotals)}`)
if (plan.crosswalk.length !== 529) throw new Error(`Crosswalk count mismatch: ${plan.crosswalk.length}`)

const peopleNames = new Set(plan.operations.people.map((row) => row.name))
for (const operation of [...plan.operations.counters, ...plan.operations.lifecycles, ...plan.operations.incidents]) {
  const person = operation.operator ?? operation.operatorPerson ?? operation.person
  if (person?.name) peopleNames.add(person.name)
}
const people = [...peopleNames].sort().map((name) => ({ id:prodId('operational_person',name), name, state:'PLANNED_CREATE_2B9', linked_auth_user_id:null }))
const personIdByName = new Map(people.map((row) => [row.name,row.id]))
const remapPerson = (person) => person?.name ? { ...person, id:personIdByName.get(person.name) } : { ...(person ?? {}), id:null, name:null, treatment:person?.treatment ?? 'ARCHIVE_TEXT_ONLY' }
for (const counter of plan.operations.counters) counter.operator = remapPerson(counter.operator)
for (const lifecycle of plan.operations.lifecycles) lifecycle.operatorPerson = remapPerson(lifecycle.operatorPerson)
for (const incident of plan.operations.incidents) incident.person = remapPerson(incident.person)
for (const row of plan.crosswalk) {
  if (row.actor_resolution?.operational_person_name) row.actor_resolution.operational_person_id = personIdByName.get(row.actor_resolution.operational_person_name)
}

const slots = Object.values(slotByLabel).concat(['CLEANING_UNIT','ROLL_MESIN']).filter((value,index,all) => all.indexOf(value) === index).sort()
if (slots.includes('TEST_COMPONENT')) throw new Error('TEST_COMPONENT is forbidden')
const catalogs = slots.map((slot) => ({ id:prodId('component_catalog',slot), code:slot, state:'PLANNED_CREATE_2B9' }))
const catalogBySlot = new Map(catalogs.map((row) => [row.code,row.id]))
const modelProfile = { id:prodId('model_profile',`${PRODUCTION.machine_model_id}:legacy-approved-profile`), machine_model_id:PRODUCTION.machine_model_id, name:'Legacy Approved C1070 Profile', state:'PLANNED_CREATE_2B9' }
const profileSlots = slots.map((slot,index) => ({ id:prodId('model_profile_slot',`${modelProfile.id}:${slot}`), profile_id:modelProfile.id, component_id:catalogBySlot.get(slot), slot_code:slot, display_order:index+1, state:'PLANNED_CREATE_2B9' }))
const machineComponents = slots.map((slot,index) => ({ id:prodId('machine_component',`${PRODUCTION.machine_id}:${slot}`), machine_id:PRODUCTION.machine_id, component_id:catalogBySlot.get(slot), profile_slot_id:profileSlots[index].id, slot_code:slot, display_order:index+1, state:'PLANNED_CREATE_2B9' }))
const machineComponentBySlot = new Map(machineComponents.map((row) => [row.slot_code,row.id]))
for (const lifecycle of plan.operations.lifecycles) {
  lifecycle.componentId = catalogBySlot.get(lifecycle.slot)
  lifecycle.profileId = profileSlots.find((row) => row.slot_code === lifecycle.slot)?.id
  lifecycle.machineComponentId = machineComponentBySlot.get(lifecycle.slot)
}
for (const item of plan.operations.items) {
  const slot = slotByLabel[normalized(item.name)]
  item.componentId = slot ? catalogBySlot.get(slot) : null
}

const scopedWrites = {
  counters:plan.operations.counters.map((row) => ({...row,account_id:PRODUCTION.account_id,branch_id:PRODUCTION.branch_id,machine_id:PRODUCTION.machine_id})),
  lifecycles:plan.operations.lifecycles.map((row) => ({...row,account_id:PRODUCTION.account_id,branch_id:PRODUCTION.branch_id,machine_id:PRODUCTION.machine_id})),
  purchases:plan.operations.purchases.map((row) => ({...row,account_id:PRODUCTION.account_id,branch_id:PRODUCTION.branch_id})),
  incidents:plan.operations.incidents.map((row) => ({...row,account_id:PRODUCTION.account_id,branch_id:PRODUCTION.branch_id,machine_id:PRODUCTION.machine_id})),
}
const purchaseUnits = scopedWrites.purchases.reduce((sum,row) => sum + row.qty, 0)
const purchaseTotal = scopedWrites.purchases.reduce((sum,row) => sum + row.sourceTotal, 0)
const ijal = scopedWrites.counters.filter((row) => normalized(row.rawOperator) === 'ijal')
if (ijal.length !== 1 || ijal[0].operator.id !== null || ijal[0].operator.treatment !== 'ARCHIVE_TEXT_ONLY') throw new Error('ijal preservation rule failed')
const newCounterEvidence = newCounters.map((row) => ({source_id:row.sourceId,target_id:row.id,date_for:source.click_history.find((x)=>String(x.id)===row.sourceId).date_for,reading_value:row.value,observed_at:row.observed_at,raw_operator:row.rawOperator,operator_person_id:row.operator.id,evidence:row.evidence}))

const futureMasters = {
  classification:'DETERMINISTIC_PLANNED_CREATE_2B9_NOT_CREATED_2B8',
  operational_people:people,
  operational_person_branches:people.map((person) => ({id:prodId('operational_person_branch',`${person.id}:${PRODUCTION.branch_id}`),person_id:person.id,branch_id:PRODUCTION.branch_id,state:'PLANNED_CREATE_2B9'})),
  component_catalogs:catalogs, model_profile:modelProfile, model_profile_slots:profileSlots, machine_components:machineComponents,
  suppliers:plan.operations.suppliers.map((row) => ({...row,state:row.alreadyExists?'EXISTING':'PLANNED_CREATE_2B9'})),
  inventory_items:plan.operations.items.map((row) => ({...row,state:row.alreadyExists?'EXISTING':'PLANNED_CREATE_2B9'})),
  inventory_location:{required_for_legacy_writes:false,reason:'Purchases are acquisition evidence only; no receipts, movements, FIFO, or opening stock.'},
}
const allOperational = [...scopedWrites.counters,...scopedWrites.lifecycles,...scopedWrites.purchases,...scopedWrites.incidents]
const hierarchyPass = allOperational.every((row) => row.account_id === PRODUCTION.account_id && row.branch_id === PRODUCTION.branch_id)
  && [...scopedWrites.counters,...scopedWrites.lifecycles,...scopedWrites.incidents].every((row) => row.machine_id === PRODUCTION.machine_id)
const serializedTargets = JSON.stringify({futureMasters,scopedWrites,production:PRODUCTION})
const devLeaks = DEV_HIERARCHY_IDS.filter((id) => serializedTargets.includes(id))
const sourceIdsBlindlyReused = plan.crosswalk.filter((row) => row.target_id && row.target_id === row.source_id)
const result = {
  version:'m2.12e-2b8-production-crosswalk-v1', mode:'LOCAL_READ_ONLY_PLAN', source:{path:args.source,source_project:snapshot.source_project,captured_at:snapshot.captured_at,counts,total:totalSource,fingerprints,canonical_fingerprints:canonicalFingerprints,aggregate_fingerprint:deterministicFingerprint(fingerprints)},
  production:{...PRODUCTION,account:{name:'Cipta Grafika'},branch:{name:'Tuparev',account_id:PRODUCTION.account_id},machine:{code:'CG-TUP-A3-01',branch_id:PRODUCTION.branch_id,machine_model_id:PRODUCTION.machine_model_id}},
  disposition:plan.dispositionTotals,
  domain:{planned_counter_readings:scopedWrites.counters.length,latest_counter:Math.max(...scopedWrites.counters.map((row)=>row.value)),planned_component_lifecycles:scopedWrites.lifecycles.length,planned_purchases:scopedWrites.purchases.length,planned_purchase_units:purchaseUnits,planned_purchase_total_idr:purchaseTotal,planned_operational_incidents:scopedWrites.incidents.length,legacy_receipts:0,legacy_inventory_movements:0,legacy_fifo_layers:0,fake_stock:0,unknown_cost_coerced_to_zero:0,graha_legacy_rows:0,unresolved_mappings:0},
  new_frozen_counters:newCounterEvidence,
  ijal:{source_rows:ijal.length,preserved_as_source_evidence:true,operational_person_id:null,auth_user_created:false,invented_mapping:false,graha_relationship:false,treatment:'ARCHIVE_TEXT_ONLY'},
  future_target_masters:futureMasters,
  crosswalk:{rows:plan.crosswalk,scoped_writes:scopedWrites},
  validation:{source_fingerprints:'PASS',production_crosswalk:hierarchyPass?'PASS':'FAIL',dev_target_uuid_leakage:devLeaks,source_uuid_blind_reuse:sourceIdsBlindlyReused,test_component:false,fabricated_boundary_counter:false,unresolved_mappings:0},
}
// Neutral manifest: all semantic decisions remain owned by the canonical Node
// planner; Laravel receives only deterministic, target-independent records.
// Volatile generation time is deliberately excluded from the hashed content.
result.manifest = {
  version:'m2.12e-neutral-import-v1',
  app_sha:PRODUCTION.app_sha,
  source:{captured_at:result.source.captured_at,source_project:result.source.source_project,count:529,counts:result.source.counts,fingerprints:result.source.fingerprints},
  target:{database:PRODUCTION.database,account_id:PRODUCTION.account_id,branch_id:PRODUCTION.branch_id,machine_model_id:PRODUCTION.machine_model_id,machine_id:PRODUCTION.machine_id,counter_type_id:'00000000-0000-0000-0000-000000000001'},
  disposition:result.disposition,
  masters:{operational_people:people,operational_person_branches:futureMasters.operational_person_branches,component_catalogs:catalogs,model_profile:[modelProfile],model_profile_slots:profileSlots,machine_components:machineComponents,suppliers:futureMasters.suppliers,inventory_items:futureMasters.inventory_items},
  records:{counters:scopedWrites.counters,lifecycles:scopedWrites.lifecycles,purchases:scopedWrites.purchases,incidents:scopedWrites.incidents},
  archive_only:plan.crosswalk.filter((row)=>['ARCHIVE_ONLY','APPROVED_EXCLUDE','SKIP_DUPLICATE'].includes(row.disposition)),
  invariants:result.domain,
  crosswalk:plan.crosswalk,
  safety:{legacy_receipts:0,legacy_inventory_movements:0,legacy_fifo_layers:0,fake_stock:0,unknown_cost_coerced_to_zero:0,graha_leakage:0,dev_target_uuid_leakage:0,unresolved_mappings:0,ijal_identity_fabricated:false}
}
result.planned_writeset_fingerprint = deterministicFingerprint({source:result.source,production:result.production,disposition:result.disposition,domain:result.domain,new_frozen_counters:result.new_frozen_counters,ijal:result.ijal,future_target_masters:result.future_target_masters,crosswalk:result.crosswalk,validation:result.validation})
result.manifest.fingerprint = deterministicFingerprint(result.manifest)
if (!hierarchyPass || devLeaks.length || sourceIdsBlindlyReused.length) throw new Error('Production crosswalk invariant failed')
if (result.domain.planned_counter_readings !== 183 || result.domain.latest_counter !== 1441597 || result.domain.planned_component_lifecycles !== 47 || result.domain.planned_purchases !== 161 || purchaseUnits !== 208 || purchaseTotal !== 371029998 || result.domain.planned_operational_incidents !== 89) throw new Error('Domain result mismatch')
writeFileSync(args.output, `${JSON.stringify(result,null,2)}\n`, {mode:0o600})
process.stdout.write(`${result.planned_writeset_fingerprint}\n`)
