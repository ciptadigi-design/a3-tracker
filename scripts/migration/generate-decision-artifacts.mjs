import { mkdir, writeFile } from 'node:fs/promises'
import { auditLegacyProject, classifyCounterCollision, classifyCounterDate } from './legacy-audit.mjs'

const outputDir = new URL('./', import.meta.url)
const sourceProject = 'wtslqxjwjqyjgcapfrrz'
const targetCounters = [
  { id: '44086d7c-c480-4039-a115-4002b0c94e66', reading_value: 1437283, observed_at: '2026-08-26T11:15:00Z' },
  { id: '6e40a1e2-72ef-4878-b669-65271ac144b9', reading_value: 1437911, observed_at: '2026-08-26T14:22:00Z' },
  { id: 'd222f09e-99e8-4b13-9281-0b64d7570dc4', reading_value: 1438992, observed_at: '2026-08-27T15:08:00Z' },
]
const ambiguousComponents = new Set(['charging corona', 'drum unit', 'developing unit', 'fuser unit', 'other part'])
const duplicateIds = new Set(['3436509c-f729-4f78-b9b7-33d0d41f6837', '76c2f97c-cae5-44a8-ac0c-29af462b5994'])
const normalize = (value) => String(value ?? '').trim().replace(/\s+/g, ' ').toLocaleLowerCase('en-US')

async function getRows(url, key, table) {
  const response = await fetch(`${url}/rest/v1/${table}?select=*`, { method: 'GET', headers: { apikey: key, Authorization: `Bearer ${key}` } })
  if (!response.ok) throw new Error(`${table}: HTTP ${response.status}`)
  return response.json()
}

export function buildDecisionArtifacts(data) {
  const counterDecisions = [...data.click_history].sort((a, b) => String(a.date_str).localeCompare(String(b.date_str))).map((row) => {
    const date = classifyCounterDate(row)
    const collision = classifyCounterCollision(row, targetCounters)
    return {
      legacy_id: row.id, date_for: row.date_for, date_str: row.date_str, created_at: row.created_at,
      total_clicks: row.total_clicks, daily_clicks_treatment: 'SKIP_DERIVED',
      interpreted_timestamp: date.status === 'RESOLVED_DETERMINISTICALLY' ? `${row.date_str.replace(' ', 'T')}+07:00` : null,
      date_resolution: date.status, migration_synthetic_time: date.migration_time_rule ?? null,
      collision: collision.collision, target_counter_id: collision.target_id ?? null,
      disposition: collision.disposition, reason: collision.collision === 'DISTINCT_EVENT' ? 'Unique chronological cumulative evidence.' : 'Exact cumulative-value overlap classified with timestamp distance; never value-only dedupe.',
    }
  })

  const replacementFirst = new Set()
  const rows = []
  for (const row of counterDecisions) rows.push({ legacy_table: 'click_history', legacy_id: row.legacy_id, disposition: row.disposition, reason: row.collision })
  for (const row of [...data.part_replacements].sort((a, b) => Number(a.replaced_at_click) - Number(b.replaced_at_click))) {
    const label = normalize(row.part_name)
    let disposition = 'IMPORT'; let reason = 'Verified boundary participates in a reconstructable interval; never fabricate predecessor start.'
    if (ambiguousComponents.has(label)) { disposition = 'MANUAL_REVIEW'; reason = 'Component slot is not identified by source evidence.' }
    else if (!replacementFirst.has(label)) { disposition = 'ARCHIVE_ONLY'; reason = 'First boundary does not prove predecessor installation.' }
    replacementFirst.add(label)
    rows.push({ legacy_table: 'part_replacements', legacy_id: row.id, disposition, reason })
  }
  for (const row of data.error_logs) rows.push({ legacy_table: 'error_logs', legacy_id: row.id, disposition: duplicateIds.has(row.id) ? 'SKIP_DUPLICATE' : 'IMPORT', reason: duplicateIds.has(row.id) ? 'Later sub-second byte-equivalent duplicate write.' : 'Distinct incident evidence; preserve source loss fields and approved multiplier.' })
  for (const row of data.inventory_parts) rows.push({ legacy_table: 'inventory_parts', legacy_id: String(row.id), disposition: 'MANUAL_REVIEW', reason: 'Mutable snapshot is not opening stock until physical cutover count and approval.' })
  for (const row of data.part_purchases) rows.push({ legacy_table: 'part_purchases', legacy_id: String(row.id), disposition: normalize(row.part_name) === 'other part' ? 'MANUAL_REVIEW' : 'IMPORT', reason: normalize(row.part_name) === 'other part' ? 'Unidentified item must not map arbitrarily.' : 'Historical acquisition evidence only; no receipt, stock movement, or FIFO.' })
  rows.sort((a, b) => a.legacy_table.localeCompare(b.legacy_table) || a.legacy_id.localeCompare(b.legacy_id))
  const counts = Object.fromEntries(['IMPORT', 'MERGE', 'SKIP_DUPLICATE', 'SKIP_DERIVED', 'ARCHIVE_ONLY', 'MANUAL_REVIEW'].map((value) => [value, rows.filter((row) => row.disposition === value).length]))
  const componentRows = [['part_replacements', data.part_replacements], ['inventory_parts', data.inventory_parts], ['part_purchases', data.part_purchases]].flatMap(([table, tableRows]) => tableRows.filter((row) => ambiguousComponents.has(normalize(row.part_name))).map((row) => {
    const genericAcquisition = table === 'part_purchases' && normalize(row.part_name) !== 'other part'
    return {
      legacy_table: table, legacy_id: String(row.id), legacy_label: row.part_name,
      target_component_id: null, target_slot_code: null,
      treatment: genericAcquisition ? 'MAP_LEGACY_NONCANONICAL_INVENTORY_ITEM' : 'MANUAL_REVIEW',
      reason: genericAcquisition ? 'Preserve acquisition without claiming color/physical slot; item has no component_id.' : 'Insufficient evidence for a physical component/slot; TEST_COMPONENT is forbidden.',
    }
  }))
  return {
    counters: { source_project: sourceProject, row_count: counterDecisions.length, decisions: counterDecisions },
    dispositions: { source_project: sourceProject, row_count: rows.length, counts, unexplained_remainder: 526 - rows.length, rows },
    components: { source_project: sourceProject, exact_label_rule: 'Map normalized exact label to existing C1070 component and slot IDs; slot identity is mandatory.', repeated_logical_component_rule: 'Never dedupe by component_id/name; preserve distinct slot_code and existing machine_component_assignment_id.', ambiguous_row_count: componentRows.length, rows: componentRows },
    openingStock: {
      policy: 'PHYSICAL_STOCK_OPNAME_AT_CUTOVER', legacy_snapshot_approved_as_opening: false,
      candidate_location_id: 'b6296488-5479-4dd0-9463-091891b4cbe4',
      rows: data.inventory_parts.map((row) => ({ legacy_id: String(row.id), legacy_label: row.part_name, canonical_inventory_item_id: null, legacy_displayed_stock: row.stock, physical_cutover_count: null, approved_opening_qty: null, cost_evidence: null, location_id: 'b6296488-5479-4dd0-9463-091891b4cbe4', reviewer: null, approval_status: 'PENDING' })),
    },
  }
}

async function main() {
  if (process.argv.slice(2).some((arg) => arg !== '--generate')) throw new Error('Only --generate is supported; hosted requests remain GET-only.')
  const url = process.env.LEGACY_SUPABASE_URL
  const key = process.env.LEGACY_SUPABASE_ANON_KEY
  if (!url || !key) throw new Error('Set legacy read-only client environment variables.')
  await auditLegacyProject({ url, key })
  const tables = ['click_history', 'part_replacements', 'error_logs', 'inventory_parts', 'part_purchases']
  const data = Object.fromEntries(await Promise.all(tables.map(async (table) => [table, await getRows(url, key, table)])))
  const artifacts = buildDecisionArtifacts(data)
  await mkdir(outputDir, { recursive: true })
  await writeFile(new URL('counter-decisions.json', outputDir), `${JSON.stringify(artifacts.counters, null, 2)}\n`)
  await writeFile(new URL('source-row-dispositions.json', outputDir), `${JSON.stringify(artifacts.dispositions, null, 2)}\n`)
  await writeFile(new URL('component-mapping.json', outputDir), `${JSON.stringify(artifacts.components, null, 2)}\n`)
  await writeFile(new URL('opening-stock-worksheet.json', outputDir), `${JSON.stringify(artifacts.openingStock, null, 2)}\n`)
}

if (process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href) main().catch((error) => { process.stderr.write(`${error.message}\n`); process.exitCode = 1 })
