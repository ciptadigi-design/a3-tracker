import { execFileSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { mkdir, writeFile } from 'node:fs/promises'
import { canonicalize, deterministicFingerprint, deterministicUuidV5, fingerprintRows, normalizeForComparison } from '../legacy-audit.mjs'

export const IDENTITY = Object.freeze({
  sourceProject: 'wtslqxjwjqyjgcapfrrz', accountId: '357e420a-c9ea-4404-9da4-f254c5dce5ef',
  branchId: '76d3c7ab-55c3-40f7-b133-0ef54a448893', machineId: 'b4ca07ee-c588-404d-abcf-b6a029e68776',
  grahaId: '9f753339-0d54-42c9-9bb6-afe2461803f8', actorId: 'a0000000-0000-4000-8000-000000000001',
  counterTypeId: '52000000-0000-0000-0000-000000000001', schemaLedger: '20260829000600',
})
export const MIGRATION_NAMESPACE = 'd0adcc63-af52-5aa9-b93f-276d5d78ea32'
export const HOSTED_DEV_REF = 'sxitqjxljoqsnpepymrl'
export const SOURCE_TABLES = ['click_history', 'part_replacements', 'error_logs', 'inventory_parts', 'part_purchases']

const expectedSourceFingerprints = Object.freeze({
  click_history: 'c1e69897a12caf54eb61064f30a799be7fd41e960a3909aff3d8969834393ee5',
  part_replacements: '2dc08b4a2d2f6dcaf354732c44823485e895da922aa9583d7b5a2c8f4a2e207e',
  error_logs: '65d17c0c54b712ee04c5848e83147a2f892c1face3a48504a19e3d2a1e4cf35b',
  inventory_parts: 'ffcd9463f006fc531987f7b3a17c0f1d3d9dd58a2c47ffef21252ef3347ca632',
  part_purchases: '6354204d285987b56764268ff22a06480003886528470401fdfe19766d7ee306',
})

const loadJson = (relative) => JSON.parse(readFileSync(new URL(relative, import.meta.url), 'utf8'))
const dispositionArtifact = loadJson('../source-row-dispositions.json')
const counterArtifact = loadJson('../counter-decisions.json')
const picArtifact = loadJson('../pic-mapping.json')
const componentArtifact = loadJson('../component-mapping.json')
const componentEvidence = new Map(componentArtifact.rows.map((row) => [`${row.legacy_table}:${row.legacy_id}`, row]))
const q = (value) => value === null || value === undefined || value === '' ? 'null' : `'${String(value).replaceAll("'", "''")}'`
const n = (value, fallback = 0) => Number.isFinite(Number(value)) ? Number(value) : fallback
const idFor = (kind, table, id) => deterministicUuidV5(MIGRATION_NAMESPACE, `${kind}:${IDENTITY.sourceProject}:public:${table}:${id}`)
const normalized = normalizeForComparison

const slotByLabel = Object.freeze({
  'charging corona cyan': 'CHARGING_CORONA_C', 'charging corona magenta': 'CHARGING_CORONA_M',
  'charging corona yellow': 'CHARGING_CORONA_Y', 'charging corona black': 'CHARGING_CORONA_K',
  'cleaning blade': 'CLEANING_BLADE', 'developer cyan': 'DEVELOPER_C', 'developer magenta': 'DEVELOPER_M',
  'developer yellow': 'DEVELOPER_Y', 'developer black': 'DEVELOPER_K',
  'developing unit cyan': 'DEVELOPING_UNIT_C', 'developing unit magenta': 'DEVELOPING_UNIT_M',
  'developing unit yellow': 'DEVELOPING_UNIT_Y', 'developing unit black': 'DEVELOPING_UNIT_K',
  'drum unit cyan': 'DRUM_C', 'drum unit magenta': 'DRUM_M', 'drum unit yellow': 'DRUM_Y', 'drum unit black': 'DRUM_K',
  'fuser belt': 'FUSER_BELT', gear: 'GEAR', 'intermediate transfer belt (ibt)': 'IBT',
  'laser unit': 'LASER_UNIT', sensor: 'SENSOR', 'toner cyan': 'TONER_C', 'toner magenta': 'TONER_M',
  'toner yellow': 'TONER_Y', 'toner black': 'TONER_K',
})
const profileIndexBySlot = Object.fromEntries([
  'CHARGING_CORONA_C','CHARGING_CORONA_M','CHARGING_CORONA_Y','CHARGING_CORONA_K','CLEANING_BLADE','CLEANING_UNIT',
  'DEVELOPER_C','DEVELOPER_M','DEVELOPER_Y','DEVELOPER_K','DEVELOPING_UNIT_C','DEVELOPING_UNIT_M','DEVELOPING_UNIT_Y','DEVELOPING_UNIT_K',
  'DRUM_C','DRUM_M','DRUM_Y','DRUM_K','FUSER_BELT','GEAR','IBT','LASER_UNIT','ROLL_MESIN','SENSOR','TONER_C','TONER_M','TONER_Y','TONER_K',
].map((slot, index) => [slot, index + 1]))
const profileId = (slot) => `54000000-0000-0000-0000-${String(profileIndexBySlot[slot]).padStart(12, '0')}`
const componentId = (slot) => `53000000-0000-0000-0000-${String(profileIndexBySlot[slot]).padStart(12, '0')}`

function buildPicLookup() {
  const lookup = new Map()
  for (const mapping of picArtifact.mappings) {
    const values = mapping.legacy_value.split('/').map(normalized).filter(Boolean)
    for (const value of new Set([mapping.normalized_value, ...values].map(normalized))) lookup.set(value, mapping)
  }
  return lookup
}
const picLookup = buildPicLookup()
const resolvePic = (value) => {
  const mapping = picLookup.get(normalized(value))
  if (!mapping || !['MAP_EXISTING', 'CREATE_PERSON'].includes(mapping.treatment)) return { id: null, name: null, treatment: mapping?.treatment ?? 'ARCHIVE_TEXT_ONLY' }
  return { id: mapping.target_person_id ?? idFor('person', 'actor', mapping.candidate_target_person), name: mapping.candidate_target_person, treatment: mapping.treatment }
}

export function assertTargetSafety({ targetType, targetContainer, apply = false }) {
  if (!apply) return true
  if (targetType !== 'DISPOSABLE') throw new Error(`M2.10A APPLY refused: target type ${targetType || 'UNKNOWN'} is not DISPOSABLE`)
  if (!/^supabase_db_[a-z0-9_-]+$/i.test(targetContainer ?? '')) throw new Error('M2.10A APPLY refused: disposable Docker database container is required')
  const lower = String(targetContainer).toLowerCase()
  if (lower.includes(HOSTED_DEV_REF) || lower.includes('production') || lower.includes('prod')) throw new Error('M2.10A APPLY refused: hosted DEV and Production are hard blocked')
  return true
}

export function calculateSourceFingerprints(source) {
  return Object.fromEntries(SOURCE_TABLES.map((table) => [table, fingerprintRows(source[table] ?? [])]))
}
export function verifySource(source, expected = expectedSourceFingerprints) {
  const actual = calculateSourceFingerprints(source)
  const mismatches = SOURCE_TABLES.filter((table) => actual[table] !== expected[table])
  if (mismatches.length) throw new Error(`Source fingerprint mismatch: ${mismatches.join(', ')}`)
  return actual
}

const STAGING_APPROVED_COUNTER_EXCLUSIONS = new Set([
  '54da82a6-b723-442c-880f-19215e1f35bb',
  '82d3b425-7273-415d-8c49-338d92405962',
])

function dispositionMap({ hostedStaging = false } = {}) {
  return new Map(dispositionArtifact.rows.map((sourceRow) => {
    const row = { ...sourceRow }
    if (hostedStaging && row.disposition === 'MANUAL_REVIEW') {
      if (row.legacy_table === 'click_history' && STAGING_APPROVED_COUNTER_EXCLUSIONS.has(row.legacy_id)) {
        row.disposition = 'APPROVED_EXCLUDE'
        row.eligibility = 'APPROVED_EXCLUDE_FOR_STAGING'
        row.reason = 'APPROVED_EXCLUDE_FOR_STAGING: possible counter overlap remains excluded by explicit M2.10B approval.'
      } else {
        row.disposition = 'ARCHIVE_ONLY'
        row.eligibility = 'ARCHIVE_ONLY'
        row.reason = row.legacy_table === 'inventory_parts'
          ? 'ARCHIVE_ONLY: mutable legacy inventory snapshot is evidence only; no opening stock is approved for hosted staging.'
          : 'ARCHIVE_ONLY: physical component slot is unproven; operational lifecycle fabrication is explicitly rejected.'
      }
    }
    return [`${row.legacy_table}:${row.legacy_id}`, row]
  }))
}

export function stagingDispositionRows() {
  return [...dispositionMap({ hostedStaging: true }).values()]
}

export function buildMigrationPlan(source, { hostedStaging = false, productionRehearsal = false, targetState = null } = {}) {
  const disposition = dispositionMap({ hostedStaging })
  if (productionRehearsal) {
    for (const [key, row] of disposition) {
      if (row.disposition === 'MERGE') disposition.set(key, { ...row, disposition: 'IMPORT', eligibility: 'PRODUCTION_CREATE_NO_TARGET_COLLISION', reason: 'Production-like target has no matching pre-existing counter; create deterministic evidence row' })
    }
  }
  const counterDecisions = new Map(counterArtifact.decisions.map((row) => [row.legacy_id, row]))
  const crosswalk = []
  const operations = { people: [], counters: [], lifecycles: [], suppliers: [], items: [], purchases: [], incidents: [] }
  const createdPeople = new Map()
  const targetPeopleByName = new Map((targetState?.people ?? []).map((row) => [normalized(row.name), row]))
  for (const mapping of picArtifact.mappings.filter((row) => row.treatment === 'CREATE_PERSON')) {
    const existing = targetPeopleByName.get(normalized(mapping.candidate_target_person))
    const id = existing?.id ?? idFor('person', 'actor', mapping.candidate_target_person)
    createdPeople.set(mapping.candidate_target_person, id)
    operations.people.push({ id, name: mapping.candidate_target_person, alreadyExists: Boolean(existing) })
  }

  const resolvePlanPic = (value) => {
    const mapping = picLookup.get(normalized(value))
    if (!mapping || !['MAP_EXISTING', 'CREATE_PERSON'].includes(mapping.treatment)) return { id: null, name: null, treatment: mapping?.treatment === 'MANUAL_REVIEW' && hostedStaging ? 'ARCHIVE_TEXT_ONLY' : mapping?.treatment ?? 'ARCHIVE_TEXT_ONLY' }
    const id = mapping.treatment === 'CREATE_PERSON'
      ? createdPeople.get(mapping.candidate_target_person)
      : mapping.target_person_id
    return { id, name: mapping.candidate_target_person, treatment: mapping.treatment }
  }

  const baselineCounterEvents = [
    { id: '44086d7c-c480-4039-a115-4002b0c94e66', observed_at: '2026-08-26T11:15:00.000Z' },
    { id: '6e40a1e2-72ef-4878-b669-65271ac144b9', observed_at: '2026-08-26T14:22:00.000Z' },
    { id: 'd222f09e-99e8-4b13-9281-0b64d7570dc4', observed_at: '2026-08-27T15:08:00.000Z' },
  ]
  const counterImports = source.click_history.map((row) => ({ row, d: disposition.get(`click_history:${row.id}`), decision: counterDecisions.get(row.id) }))
    .filter(({ d }) => d?.disposition === 'IMPORT')
    .map(({ row, d, decision }) => ({ id: idFor('row', 'click_history', row.id), sourceId: row.id, value: n(row.total_clicks), observed_at: decision.migration_timestamp, evidence: decision.timestamp_evidence, operator: resolvePlanPic(row.operator), rawOperator: row.operator, disposition: d.disposition }))
  const importedCounterIds = new Set(counterImports.map((row) => row.id))
  const targetCounterEvents = (targetState?.counters ?? (productionRehearsal ? [] : baselineCounterEvents)).filter((row) => !importedCounterIds.has(row.id))
  const stream = [...targetCounterEvents, ...counterImports].sort((a, b) => a.observed_at.localeCompare(b.observed_at) || a.id.localeCompare(b.id))
  for (let index = 0; index < stream.length; index += 1) if (stream[index].sourceId) operations.counters.push({ ...stream[index], previousId: index ? stream[index - 1].id : null })
  for (const row of source.click_history) {
    const d = disposition.get(`click_history:${row.id}`); const decision = counterDecisions.get(row.id)
    const targetId = d.disposition === 'MERGE' ? decision.target_counter_id : d.disposition === 'IMPORT' ? idFor('row', 'click_history', row.id) : null
    crosswalk.push(crosswalkRow('click_history', row.id, d, targetId ? 'counter_readings' : null, targetId, decision?.timestamp_evidence, resolvePlanPic(row.operator), ['APPROVED_EXCLUDE','MANUAL_REVIEW'].includes(d.disposition) ? { date_str:row.date_str,date_for:row.date_for,created_at:row.created_at,operator:row.operator,total_clicks:row.total_clicks,daily_clicks:row.daily_clicks,collision:decision?.collision,candidate_target_counter_id:decision?.target_counter_id } : null))
  }

  const replacementGroups = Object.groupBy([...source.part_replacements].sort((a,b) => n(a.replaced_at_click)-n(b.replaced_at_click) || String(a.created_at).localeCompare(String(b.created_at)) || String(a.id).localeCompare(String(b.id))), (row) => normalized(row.part_name))
  for (const rows of Object.values(replacementGroups)) {
    for (let index = 0; index < rows.length; index += 1) {
      const row = rows[index]; const d = disposition.get(`part_replacements:${row.id}`); const slot = slotByLabel[normalized(row.part_name)]
      if (d.disposition === 'IMPORT') {
        const previous = rows[index - 1]
        if (!previous || !slot) throw new Error(`Eligible replacement lacks verified prior boundary/slot: ${row.id}`)
        const id = idFor('row', 'part_replacements', row.id)
        const operatorPerson = resolvePlanPic(row.operator)
        operations.lifecycles.push({ id, sourceId: row.id, slot, profileId: profileId(slot), componentId: componentId(slot), installed: n(previous.replaced_at_click), removed: n(row.replaced_at_click), startSourceId: previous.id, operator: row.operator, operatorPerson, createdAt: row.created_at })
        crosswalk.push(crosswalkRow('part_replacements', row.id, d, 'machine_component_lifecycles', id, `LEVEL_A interval starts at ${previous.id}`, operatorPerson))
      } else crosswalk.push(crosswalkRow('part_replacements', row.id, d, null, null, d.reason, null, componentEvidence.get(`part_replacements:${row.id}`) ?? { legacy_label:row.part_name,source_created_at:row.created_at,replacement_counter:row.replaced_at_click,pic_snapshot:row.operator,source_cost:null }))
    }
  }

  const suppliers = new Map(); const items = new Map()
  const targetSuppliersByName = new Map((targetState?.suppliers ?? []).map((row) => [normalized(row.name), row]))
  const targetItemsByName = new Map((targetState?.items ?? []).map((row) => [normalized(row.name), row]))
  for (const row of source.part_purchases) {
    const d = disposition.get(`part_purchases:${row.id}`); const supplierName = String(row.supplier ?? '').trim() || 'Legacy supplier not recorded'
    const supplierKey = normalized(supplierName); const itemKey = normalized(row.part_name); const slot = slotByLabel[itemKey]
    if (!suppliers.has(supplierKey)) {
      const existing = targetSuppliersByName.get(supplierKey)
      suppliers.set(supplierKey, existing
        ? { id: existing.id, name: existing.name, code: existing.supplier_code, alreadyExists: true }
        : { id: idFor('supplier', 'supplier', supplierKey), name: supplierName, code: `LEG-${deterministicFingerprint(supplierKey).slice(0, 10).toUpperCase()}`, alreadyExists: false })
    }
    if (!items.has(itemKey)) {
      const existing = targetItemsByName.get(itemKey)
      items.set(itemKey, existing
        ? { id: existing.id, name: existing.name, sku: existing.sku, componentId: existing.component_id, alreadyExists: true }
        : { id: idFor('item', 'inventory_item', itemKey), name: row.part_name, sku: `LEG-${deterministicFingerprint(itemKey).slice(0, 12).toUpperCase()}`, componentId: slot ? componentId(slot) : null, alreadyExists: false })
    }
    const purchaseId = idFor('row', 'part_purchases', row.id)
    operations.purchases.push({ id: purchaseId, lineId: idFor('line', 'part_purchases', row.id), requestId: idFor('request', 'part_purchases', row.id), sourceId: row.id, supplier: suppliers.get(supplierKey), item: items.get(itemKey), date: row.tgl_pembelian, qty: n(row.qty), unitPrice: n(row.harga_satuan), sourceTotal: n(row.total_harga) })
    crosswalk.push(crosswalkRow('part_purchases', row.id, d, 'inventory_purchases', purchaseId, 'draft acquisition evidence; no receipt/stock/FIFO'))
  }
  operations.suppliers = [...suppliers.values()].sort((a,b) => a.id.localeCompare(b.id)); operations.items = [...items.values()].sort((a,b) => a.id.localeCompare(b.id))

  const incidentType = { 'human error': 'human', 'print test': 'test_print', 'machine error': 'machine_operation' }
  const incidentCategory = { 'kesesuaian/ketepatan': 'kesesuaian', kualitas: 'kualitas', desain: 'desain', bahan: 'bahan', 'prosedur/proses': 'prosedur' }
  for (const row of source.error_logs) {
    const d = disposition.get(`error_logs:${row.id}`)
    if (d.disposition === 'IMPORT') {
      const person = resolvePlanPic(row.pic); const base = n(row.kerugian_bahan) + n(row.kerugian_jasa); const stored = n(row.jumlah_kerugian); const multiplier = base > 0 ? stored / base : 1
      const id = idFor('row', 'error_logs', row.id)
      operations.incidents.push({ id, requestId: idFor('request', 'error_logs', row.id), sourceId: row.id, occurredAt: `${row.tgl}T05:00:00.000Z`, invoice: row.nomor_invoice, customer: row.nama_konsumen, product: row.nama_produk, category: incidentCategory[normalized(row.kategori_kesalahan)], type: incidentType[normalized(row.jenis_kesalahan)], qty: n(row.qty_kesalahan) || null, person, rawPic: row.pic, material: n(row.kerugian_bahan), service: n(row.kerugian_jasa), multiplier, stored, description: row.deskripsi_kesalahan || 'Legacy operational incident', cause: row.penyebab, prevention: row.pencegahan_solusi, resolution: row.penyelesaian, createdAt: row.created_at })
      if (!operations.incidents.at(-1).category || !operations.incidents.at(-1).type) throw new Error(`Incident category mapping missing: ${row.id}`)
      crosswalk.push(crosswalkRow('error_logs', row.id, d, 'operational_incidents', id, multiplier > 1 ? `preserved source multiplier ${multiplier}` : null, person))
    } else crosswalk.push(crosswalkRow('error_logs', row.id, d, null, null, d.reason))
  }
  for (const row of source.inventory_parts) { const d = disposition.get(`inventory_parts:${row.id}`); crosswalk.push(crosswalkRow('inventory_parts', row.id, d, null, null, 'historical display snapshot; no opening stock', null, { legacy_label:row.part_name,legacy_displayed_stock:row.stock })) }
  crosswalk.sort((a,b) => a.source_table.localeCompare(b.source_table) || String(a.source_id).localeCompare(String(b.source_id)))
  const dispositionKeys = hostedStaging
    ? ['IMPORT','MERGE','SKIP_DUPLICATE','ARCHIVE_ONLY','APPROVED_EXCLUDE','MANUAL_REVIEW']
    : ['IMPORT','MERGE','SKIP_DUPLICATE','ARCHIVE_ONLY','MANUAL_REVIEW']
  const dispositionTotals = Object.fromEntries(dispositionKeys.map((key) => [key, crosswalk.filter((row) => row.disposition === key).length]))
  const total = Object.values(dispositionTotals).reduce((sum, value) => sum + value, 0)
  if (total !== 526) throw new Error(`Disposition accounting failed: ${total}/526`)
  return { version: productionRehearsal ? 'm2.11-rehearsal-v1' : hostedStaging ? 'm2.10b-v1' : 'm2.10a-v1', actorId: targetState?.migration_actor_id ?? IDENTITY.actorId, operations, crosswalk, dispositionTotals, eligibleAccounted: total - dispositionTotals.MANUAL_REVIEW, unexplainedRemainder: 526 - total }
}

function crosswalkRow(table, id, disposition, targetEntity, targetId, notes, actor = null, sourceEvidence = null) {
  return { source_project: IDENTITY.sourceProject, source_schema: 'public', source_table: table, source_id: String(id), target_entity: targetEntity, target_id: targetId, mapping_rule: disposition.eligibility, confidence: disposition.disposition === 'MANUAL_REVIEW' ? 'LOW' : 'HIGH', disposition: disposition.disposition, actor_target_entity: actor?.id ? 'operational_people' : null, actor_target_id: actor?.id ?? null, actor_target_name: actor?.name ?? null, actor_treatment: actor?.treatment ?? null, source_evidence: sourceEvidence, notes: notes ?? disposition.reason }
}

export function buildApplySql(plan, { dryRun = false, injectFailure = false, psqlMeta = true } = {}) {
  const o = plan.operations; const sql = psqlMeta ? ['\\set ON_ERROR_STOP on', 'begin;'] : ['begin;']
  const actorName = plan.version === 'm2.10b-v1' ? 'M2.10B Hosted DEV Migration' : plan.version === 'm2.11-rehearsal-v1' ? 'M2.11 Disposable Rehearsal' : 'M2.10A Fixture Owner'
  const actorId = plan.actorId ?? IDENTITY.actorId
  for (const person of o.people) {
    sql.push(`insert into public.operational_people(id,account_id,name,notes) values(${q(person.id)},${q(IDENTITY.accountId)},${q(person.name)},'LEGACY_IMPORT; historical person only; no Auth identity') on conflict (id) do nothing;`)
    sql.push(`insert into public.operational_person_branches(account_id,operational_person_id,branch_id) values(${q(IDENTITY.accountId)},${q(person.id)},${q(IDENTITY.branchId)}) on conflict (operational_person_id,branch_id) do update set is_active=true;`)
  }
  for (const counter of o.counters) sql.push(`insert into public.counter_readings(id,account_id,machine_id,counter_type_id,reading_value,observed_at,entered_by,source,previous_reading_id,client_request_id,created_by,operator_person_id,operator_name_snapshot,notes) values(${q(counter.id)},${q(IDENTITY.accountId)},${q(IDENTITY.machineId)},${q(IDENTITY.counterTypeId)},${counter.value},${q(counter.observed_at)},${q(actorId)},'legacy_import',${q(counter.previousId)},${q(idFor('request','click_history',counter.sourceId))},${q(actorId)},${q(counter.operator.id)},${q(counter.operator.name)},${q(`LEGACY_IMPORT source=${counter.sourceId}; ${counter.evidence}; daily_clicks=SKIP_DERIVED; raw_operator=${counter.rawOperator ?? ''}`)}) on conflict (id) do nothing;`)
  for (const lifecycle of o.lifecycles) sql.push(`insert into public.machine_component_lifecycles(id,account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code,status,installed_counter,installed_at,installation_source,baseline_expected_clicks_snapshot,expected_at_install,removed_counter,removed_at,actual_usage,created_by,notes) select ${q(lifecycle.id)},${q(IDENTITY.accountId)},${q(IDENTITY.branchId)},${q(IDENTITY.machineId)},profile.id,profile.component_id,${q(lifecycle.slot)},'closed',${lifecycle.installed},null,'legacy_import',profile.baseline_expected_clicks,profile.baseline_expected_clicks,${lifecycle.removed},null,${lifecycle.removed-lifecycle.installed},${q(actorId)},${q(`LEGACY_IMPORT verified interval; start_boundary=${lifecycle.startSourceId}; end_boundary=${lifecycle.sourceId}; source timestamps retained in manifest; no predecessor start invented; raw_operator=${lifecycle.operator ?? ''}; mapped_operator_person_id=${lifecycle.operatorPerson?.id ?? ''}; mapped_operator_name=${lifecycle.operatorPerson?.name ?? ''}`)} from (select candidate.* from public.machine_model_components candidate where candidate.machine_model_id='51000000-0000-0000-0000-000000000001' and lower(btrim(candidate.slot_code))=lower(${q(lifecycle.slot)}) and (candidate.account_id is null or candidate.account_id=${q(IDENTITY.accountId)}) and candidate.is_active order by (candidate.account_id=${q(IDENTITY.accountId)}) desc nulls last,candidate.id limit 1) profile on conflict (id) do nothing;`)
  for (const supplier of o.suppliers) sql.push(`insert into public.inventory_suppliers(id,account_id,supplier_code,name,notes) values(${q(supplier.id)},${q(IDENTITY.accountId)},${q(supplier.code)},${q(supplier.name)},'LEGACY_IMPORT supplier snapshot') on conflict (id) do nothing;`)
  for (const item of o.items) sql.push(`insert into public.inventory_items(id,account_id,component_id,sku,name,unit,notes,is_active,is_canonical) values(${q(item.id)},${q(IDENTITY.accountId)},${q(item.componentId)},${q(item.sku)},${q(item.name)},'pcs','LEGACY_IMPORT acquisition-only item; no opening stock',true,false) on conflict (id) do nothing;`)
  for (const purchase of o.purchases) {
    sql.push(`insert into public.inventory_purchases(id,account_id,branch_id,supplier_id,purchase_number,purchase_date,currency_code,status,notes,supplier_code_snapshot,supplier_name_snapshot,client_request_id,created_by,created_by_name_snapshot,updated_by) values(${q(purchase.id)},${q(IDENTITY.accountId)},${q(IDENTITY.branchId)},${q(purchase.supplier.id)},${q(`LEGACY-${purchase.sourceId}`)},${q(purchase.date)},'IDR','draft',${q(`LEGACY_IMPORT; RECEIPT_UNKNOWN_NOT_REPRESENTED; source_id=${purchase.sourceId}`)},${q(purchase.supplier.code)},${q(purchase.supplier.name)},${q(purchase.requestId)},${q(actorId)},${q(actorName)},${q(actorId)}) on conflict (id) do nothing;`)
    sql.push(`insert into public.inventory_purchase_lines(id,account_id,purchase_id,inventory_item_id,ordered_quantity,unit_price,item_sku_snapshot,item_name_snapshot,unit_snapshot,notes) values(${q(purchase.lineId)},${q(IDENTITY.accountId)},${q(purchase.id)},${q(purchase.item.id)},${purchase.qty},${purchase.unitPrice},${q(purchase.item.sku)},${q(purchase.item.name)},'pcs',${q(`LEGACY_IMPORT source_total=${purchase.sourceTotal}`)}) on conflict (id) do nothing;`)
  }
  for (const incident of o.incidents) sql.push(`insert into public.operational_incidents(id,account_id,branch_id,machine_id,occurred_at,invoice_number,customer_name_snapshot,product_name_snapshot,category,incident_type,qty_affected,responsible_name_snapshot,material_loss,service_loss,penalty_multiplier,description,cause,prevention,customer_resolution,status,client_request_id,created_by,created_at,updated_by,updated_at,responsible_person_id) values(${q(incident.id)},${q(IDENTITY.accountId)},${q(IDENTITY.branchId)},${q(IDENTITY.machineId)},${q(incident.occurredAt)},${q(incident.invoice)},${q(incident.customer)},${q(incident.product)},${q(incident.category)},${q(incident.type)},${incident.qty ?? 'null'},${q(incident.rawPic || null)},${incident.material},${incident.service},${incident.multiplier},${q(incident.description)},${q(incident.cause)},${q(incident.prevention)},${q(incident.resolution)},'open',${q(incident.requestId)},${q(actorId)},${q(incident.createdAt)},${q(actorId)},${q(incident.createdAt)},${q(incident.person.id)}) on conflict (id) do nothing;`)
  if (injectFailure) sql.push("select 1/0 as deliberate_failure_injection;")
  sql.push(dryRun ? 'rollback;' : 'commit;')
  return `${sql.join('\n')}\n`
}

export function runPsql(container, sql) {
  assertTargetSafety({ targetType: 'DISPOSABLE', targetContainer: container, apply: true })
  return execFileSync('docker', ['exec', '-i', container, 'psql', '-U', 'postgres', '-d', 'postgres', '-X', '-q', '-v', 'ON_ERROR_STOP=1'], { input: sql, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
}

const preflightSql = `select jsonb_build_object(
'schema_ledger',(select max(version) from supabase_migrations.schema_migrations),
'machine',(select jsonb_agg(to_jsonb(x) order by id) from (select id,account_id,branch_id,machine_model_id,machine_code,is_active from public.machines where id='${IDENTITY.machineId}') x),
'counters',(select jsonb_agg(to_jsonb(x) order by observed_at,id) from (select id,reading_value,observed_at,status from public.counter_readings where machine_id='${IDENTITY.machineId}') x),
'assignments',(select jsonb_agg(to_jsonb(x) order by slot_code,id) from (select id,component_id,slot_code,status from public.machine_component_assignments where machine_id='${IDENTITY.machineId}') x),
'lifecycles',(select jsonb_agg(to_jsonb(x) order by slot_code,id) from (select id,slot_code,status,installed_counter,removed_counter from public.machine_component_lifecycles where machine_id='${IDENTITY.machineId}') x),
'replacements',(select jsonb_agg(to_jsonb(x) order by replaced_at,id) from (select id,slot_code_snapshot,replacement_counter,replaced_at from public.component_replacement_events where machine_id='${IDENTITY.machineId}') x),
'inventory',(select jsonb_build_object('movements',count(*),'balance',coalesce(sum(quantity),0)) from public.inventory_movements where account_id='${IDENTITY.accountId}'),
'purchases',(select count(*) from public.inventory_purchases where account_id='${IDENTITY.accountId}'),
'incidents',(select count(*) from public.operational_incidents where account_id='${IDENTITY.accountId}'))::text;`

export function targetPreflight(container) {
  const output = runPsql(container, `\\pset tuples_only on\n\\pset format unaligned\n${preflightSql}\n`).trim()
  const state = JSON.parse(output)
  return { fingerprint: deterministicFingerprint(state), state }
}

export function reconcileTarget(container, plan) {
  const ids = (domain) => plan.crosswalk.filter((row) => row.target_entity === domain && row.target_id).map((row) => q(row.target_id)).join(',') || "'00000000-0000-0000-0000-000000000000'"
  const sql = `\\pset tuples_only on
\\pset format unaligned
select jsonb_build_object(
'source',526,'dispositions',${q(JSON.stringify(plan.dispositionTotals))}::jsonb,'eligible_accounted',${plan.eligibleAccounted},'manual_excluded',${plan.dispositionTotals.MANUAL_REVIEW},'unexplained',${plan.unexplainedRemainder},
'counters',(select jsonb_build_object('accounted',count(*),'imported',count(*) filter(where id<>'6e40a1e2-72ef-4878-b669-65271ac144b9'),'min_date',min(observed_at),'max_date',max(observed_at),'min_value',min(reading_value),'max_value',max(reading_value),'latest_value',(array_agg(reading_value order by observed_at desc,id desc))[1]) from public.counter_readings where id in (${ids('counter_readings')})),
'counter_merge',(select count(*) from public.counter_readings where id='6e40a1e2-72ef-4878-b669-65271ac144b9'),
'lifecycles',(select count(*) from public.machine_component_lifecycles where id in (${ids('machine_component_lifecycles')})),
'purchases',(select jsonb_build_object('rows',count(distinct p.id),'qty',coalesce(sum(l.ordered_quantity),0),'value',coalesce(sum(l.line_total),0)) from public.inventory_purchases p join public.inventory_purchase_lines l on l.purchase_id=p.id where p.id in (${ids('inventory_purchases')})),
'receipts_from_legacy_purchases',(select count(*) from public.inventory_receipts where purchase_id in (${ids('inventory_purchases')})),
'movements_from_legacy_purchases',(select count(*) from public.inventory_movements where reference_id in (${ids('inventory_purchases')})),
'fifo_from_legacy_purchases',(select count(*) from public.inventory_cost_lots where source_receipt_line_id in (select rl.id from public.inventory_receipt_lines rl join public.inventory_receipts r on r.id=rl.receipt_id where r.purchase_id in (${ids('inventory_purchases')}))),
'incidents',(select jsonb_build_object('rows',count(*),'assessed_loss',coalesce(sum(assessed_loss),0)) from public.operational_incidents where id in (${ids('operational_incidents')})),
'people',(select count(*) from public.operational_people where notes like 'LEGACY_IMPORT;%'),
'target_state',(select jsonb_build_object('assignments',(select count(*) from public.machine_component_assignments where machine_id='${IDENTITY.machineId}'),'lifecycles_total',(select count(*) from public.machine_component_lifecycles where machine_id='${IDENTITY.machineId}'),'accepted_replacement_events',(select count(*) from public.component_replacement_events where machine_id='${IDENTITY.machineId}'))),
'graha_leakage',(select (select count(*) from public.counter_readings c join public.machines m on m.id=c.machine_id where m.branch_id='${IDENTITY.grahaId}')+(select count(*) from public.operational_incidents where branch_id='${IDENTITY.grahaId}')+(select count(*) from public.machine_component_lifecycles where branch_id='${IDENTITY.grahaId}')+(select count(*) from public.inventory_movements mv join public.inventory_locations l on l.id=mv.location_id where l.branch_id='${IDENTITY.grahaId}'))
)::text;`
  return JSON.parse(runPsql(container, sql).trim())
}

export async function writeReports(outputDir, { plan, sourceFingerprints, targetBefore, targetAfter, executionId, mode, reconciliation, startedAt, finishedAt }) {
  await mkdir(outputDir, { recursive: true })
  const manifest = canonicalize({ migration_version: plan.version, execution_id: executionId, started_at: startedAt ?? null, finished_at: finishedAt ?? null, source_project: IDENTITY.sourceProject, source_snapshot_audit_time: '2026-08-29T14:28:26.656Z', source_fingerprints: sourceFingerprints, target_type: 'DISPOSABLE', target_identity: { container_class: 'local_supabase_postgres_17', account_id: IDENTITY.accountId, branch_id: IDENTITY.branchId, machine_id: IDENTITY.machineId }, target_schema_ledger: IDENTITY.schemaLedger, target_preflight_fingerprint: targetBefore.fingerprint, target_postflight_fingerprint: targetAfter?.fingerprint ?? null, mapping_version: 'm2.9c-v1', disposition_totals: plan.dispositionTotals, mode, domain_reconciliation: reconciliation, expected_differences: plan.crosswalk.filter((row) => !['IMPORT','MERGE'].includes(row.disposition)), unresolved_manual_rows: plan.crosswalk.filter((row) => row.disposition === 'MANUAL_REVIEW') })
  await writeFile(`${outputDir}/manifest.json`, `${JSON.stringify(manifest, null, 2)}\n`)
  await writeFile(`${outputDir}/crosswalk.json`, `${JSON.stringify({ migration_version: plan.version, rows: plan.crosswalk }, null, 2)}\n`)
  await writeFile(`${outputDir}/reconciliation.json`, `${JSON.stringify(reconciliation, null, 2)}\n`)
  const human = `# M2.10A disposable reconciliation\n\n- Source: **526**\n- Dispositions: ${Object.entries(plan.dispositionTotals).map(([k,v]) => `${k} ${v}`).join(', ')}\n- Eligible/accounted: **${plan.eligibleAccounted}**\n- Manual exclusions: **${plan.dispositionTotals.MANUAL_REVIEW}**\n- Unexplained: **${plan.unexplainedRemainder}**\n- Counters imported: **${reconciliation?.counters?.imported ?? 0}**; merge: **${reconciliation?.counter_merge ?? 0}**\n- Purchases: **${reconciliation?.purchases?.rows ?? 0} rows / ${reconciliation?.purchases?.qty ?? 0} units / IDR ${reconciliation?.purchases?.value ?? 0}**\n- Legacy purchase receipts / movements / FIFO: **${reconciliation?.receipts_from_legacy_purchases ?? 0} / ${reconciliation?.movements_from_legacy_purchases ?? 0} / ${reconciliation?.fifo_from_legacy_purchases ?? 0}**\n- Graha leakage: **${reconciliation?.graha_leakage ?? 0}**\n`
  await writeFile(`${outputDir}/reconciliation.md`, human)
  return manifest
}

export { expectedSourceFingerprints, idFor, slotByLabel }
