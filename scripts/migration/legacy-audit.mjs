import { createHash } from 'node:crypto'

const LEGACY_TABLES = [
  'click_history',
  'part_replacements',
  'error_logs',
  'inventory_parts',
  'part_purchases',
]

export const normalizeForComparison = (value) => String(value ?? '').trim().replace(/\s+/g, ' ').toLocaleLowerCase('en-US')
const normalize = normalizeForComparison
const countWhere = (rows, predicate) => rows.filter(predicate).length
const distinctCount = (values) => new Set(values.map(normalize).filter(Boolean)).size
const duplicateCount = (rows, key) => rows.length - new Set(rows.map(key)).size
const range = (values) => {
  const present = values.filter((value) => value !== null && value !== undefined && value !== '')
  const sorted = [...present].sort((left, right) => {
    if (typeof left === 'number' && typeof right === 'number') return left - right
    return String(left).localeCompare(String(right))
  })
  return sorted.length ? { min: sorted[0], max: sorted.at(-1) } : null
}
const groupedCounts = (values) => Object.fromEntries(
  [...values.reduce((groups, value) => {
    const key = normalize(value) || '<empty>'
    groups.set(key, (groups.get(key) ?? 0) + 1)
    return groups
  }, new Map())].sort(([left], [right]) => left.localeCompare(right)),
)

export const canonicalize = (value) => {
  if (Array.isArray(value)) return value.map(canonicalize)
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonicalize(value[key])]))
  }
  return value
}
export const deterministicFingerprint = (value) => createHash('sha256').update(JSON.stringify(canonicalize(value))).digest('hex')
export const fingerprintRows = (rows) => deterministicFingerprint([...rows].sort((left, right) => String(left.id).localeCompare(String(right.id))))

export function deterministicUuidV5(namespace, name) {
  const hex = namespace.replaceAll('-', '')
  if (!/^[0-9a-f]{32}$/i.test(hex)) throw new Error('Invalid UUID namespace')
  const hash = createHash('sha1').update(Buffer.concat([Buffer.from(hex, 'hex'), Buffer.from(name)])).digest()
  hash[6] = (hash[6] & 0x0f) | 0x50
  hash[8] = (hash[8] & 0x3f) | 0x80
  const value = hash.subarray(0, 16).toString('hex')
  return `${value.slice(0, 8)}-${value.slice(8, 12)}-${value.slice(12, 16)}-${value.slice(16, 20)}-${value.slice(20)}`
}

export function classifyCounterDate(row) {
  const entryDate = String(row.date_str ?? '').slice(0, 10)
  if (row.date_for === entryDate) return { status: 'RESOLVED_DETERMINISTICALLY', precision: 'source_local_datetime' }
  return {
    status: 'RESOLVED_DATE_ONLY',
    precision: 'source_calendar_date',
    migration_time_rule: 'MIGRATION_SYNTHETIC_TIME: date_for + date_str local time; retain date_str and created_at as source evidence',
  }
}

const counterOrder = (left, right) => String(left.date_for).localeCompare(String(right.date_for))
  || Number(left.total_clicks) - Number(right.total_clicks)
  || String(left.date_str).localeCompare(String(right.date_str))
  || String(left.created_at).localeCompare(String(right.created_at))
  || String(left.id).localeCompare(String(right.id))

export function assignCounterMigrationTimestamps(rows) {
  let previous = null
  return [...rows].sort(counterOrder).map((row) => {
    const dateOnly = String(row.date_for) !== String(row.date_str).slice(0, 10)
    const localTime = String(row.date_str).slice(11, 19)
    let timestamp = Date.parse(`${dateOnly ? row.date_for : String(row.date_str).slice(0, 10)}T${localTime}+07:00`)
    if (!Number.isFinite(timestamp)) throw new Error(`Invalid counter timestamp for ${row.id}`)
    if (previous !== null && timestamp <= previous) {
      if (!dateOnly) throw new Error(`Source-recorded counter chronology conflicts at ${row.id}`)
      timestamp = previous + 1
    }
    if (new Date(timestamp + 7 * 60 * 60_000).toISOString().slice(0, 10) !== row.date_for) {
      throw new Error(`Synthetic counter timestamp escaped operational date for ${row.id}`)
    }
    previous = timestamp
    return {
      ...row,
      migration_timestamp: new Date(timestamp).toISOString(),
      timestamp_evidence: dateOnly ? 'MIGRATION_SYNTHETIC_TIME' : 'SOURCE_RECORDED_LOCAL_TIME',
    }
  })
}

export function classifyCounterCollision(row, targetRows = []) {
  const sameValue = targetRows.filter((target) => Number(target.reading_value) === Number(row.total_clicks))
  if (!sameValue.length) return { collision: 'DISTINCT_EVENT', disposition: 'IMPORT' }
  const interpreted = Date.parse(`${String(row.date_str).replace(' ', 'T')}+07:00`)
  const nearest = sameValue.map((target) => ({ target, gap: Math.abs(Date.parse(target.observed_at) - interpreted) })).sort((a, b) => a.gap - b.gap)[0]
  if (nearest.gap <= 5 * 60_000) return { collision: 'SAME_EVENT_HIGH_CONFIDENCE', disposition: 'MERGE', target_id: nearest.target.id }
  return { collision: 'POSSIBLE_DUPLICATE', disposition: 'MANUAL_REVIEW', target_id: nearest.target.id }
}

export function assertDispositionTotal(rows, expected) {
  const dispositions = ['IMPORT', 'MERGE', 'SKIP_DUPLICATE', 'SKIP_DERIVED', 'ARCHIVE_ONLY', 'MANUAL_REVIEW']
  if (rows.some((row) => !dispositions.includes(row.disposition))) throw new Error('Unknown source-row disposition')
  if (rows.length !== expected) throw new Error(`Disposition total ${rows.length} does not equal expected ${expected}`)
  return true
}

export function buildEligibilitySet(rows) {
  const mapping = {
    IMPORT: 'APPLY_ELIGIBLE', MERGE: 'MERGE_ELIGIBLE', SKIP_DUPLICATE: 'SKIP_DUPLICATE',
    SKIP_DERIVED: 'SKIP_DERIVED', ARCHIVE_ONLY: 'ARCHIVE_ONLY', MANUAL_REVIEW: 'EXCLUDED_MANUAL',
  }
  const eligible = rows.map((row) => ({ ...row, eligibility: mapping[row.disposition] }))
  if (eligible.some((row) => !row.eligibility)) throw new Error('Disposition has no M2.10A eligibility mapping')
  return eligible
}

export function planLegacyPurchase(row) {
  return {
    purchase: { status: 'draft', notes: `LEGACY_IMPORT; RECEIPT_UNKNOWN_NOT_REPRESENTED; source_id=${row.id}` },
    purchase_line: { inventory_item_component_id: null }, receipts: [], inventory_movements: [], fifo_lots: [],
  }
}

export function transformLegacyLoss({ material_loss, service_loss, stored_total }) {
  const base = Number(material_loss) + Number(service_loss)
  if (!(base > 0)) return { material_loss, service_loss, penalty_multiplier: 1, reconciled_total: base }
  const multiplier = Number(stored_total) / base
  return { material_loss, service_loss, penalty_multiplier: multiplier, reconciled_total: base * multiplier }
}

function genericProfile(rows) {
  const columns = [...new Set(rows.flatMap((row) => Object.keys(row)))].sort()
  return {
    row_count: rows.length,
    columns,
    observed_json_types_by_column: Object.fromEntries(columns.map((column) => [
      column,
      [...new Set(rows.map((row) => row[column] === null ? 'null' : typeof row[column]))].sort(),
    ])),
    null_or_blank_by_column: Object.fromEntries(columns.map((column) => [
      column,
      countWhere(rows, (row) => row[column] === null || row[column] === undefined || row[column] === ''),
    ])),
    duplicate_primary_key_candidates: duplicateCount(rows, (row) => String(row.id)),
    ordered_row_fingerprint_sha256: fingerprintRows(rows),
  }
}

function profileCounters(rows) {
  const chronological = [...rows].sort((left, right) =>
    String(left.date_str ?? left.date_for).localeCompare(String(right.date_str ?? right.date_for)),
  )
  let regressions = 0
  for (let index = 1; index < chronological.length; index += 1) {
    if (Number(chronological[index].total_clicks) < Number(chronological[index - 1].total_clicks)) regressions += 1
  }
  const readingsByDate = Object.values(groupedCounts(rows.map((row) => row.date_for)))
  return {
    date_range: range(rows.map((row) => row.date_for)),
    recorded_timestamp_range: range(rows.map((row) => row.date_str)),
    created_timestamp_range: range(rows.map((row) => row.created_at)),
    counter_range: range(rows.map((row) => Number(row.total_clicks))),
    distinct_operator_snapshots: distinctCount(rows.map((row) => row.operator)),
    dates_with_multiple_readings: readingsByDate.filter((count) => count > 1).length,
    maximum_readings_on_one_date: readingsByDate.length ? Math.max(...readingsByDate) : 0,
    chronological_counter_regressions: regressions,
    negative_total_counters: countWhere(rows, (row) => Number(row.total_clicks) < 0),
    negative_daily_clicks: countWhere(rows, (row) => Number(row.daily_clicks) < 0),
    duplicate_business_candidates: duplicateCount(rows, (row) => JSON.stringify([
      row.date_str, row.date_for, normalize(row.operator), row.total_clicks,
    ])),
    total_counter_advance: rows.length
      ? Number(range(rows.map((row) => Number(row.total_clicks))).max) - Number(range(rows.map((row) => Number(row.total_clicks))).min)
      : 0,
  }
}

function profileReplacements(rows) {
  return {
    created_timestamp_range: range(rows.map((row) => row.created_at)),
    replacement_counter_range: range(rows.map((row) => Number(row.replaced_at_click))),
    distinct_component_names: distinctCount(rows.map((row) => row.part_name)),
    component_name_counts: groupedCounts(rows.map((row) => row.part_name)),
    distinct_operator_snapshots: distinctCount(rows.map((row) => row.operator)),
    negative_replacement_counters: countWhere(rows, (row) => Number(row.replaced_at_click) < 0),
    duplicate_business_candidates: duplicateCount(rows, (row) => JSON.stringify([
      normalize(row.part_name), row.replaced_at_click, normalize(row.operator), row.created_at,
    ])),
  }
}

function profileErrors(rows) {
  return {
    event_date_range: range(rows.map((row) => row.tgl)),
    created_timestamp_range: range(rows.map((row) => row.created_at)),
    type_counts: groupedCounts(rows.map((row) => row.jenis_kesalahan)),
    category_counts: groupedCounts(rows.map((row) => row.kategori_kesalahan)),
    distinct_pic_snapshots: distinctCount(rows.map((row) => row.pic)),
    distinct_divisions: distinctCount(rows.map((row) => row.divisi)),
    negative_quantities: countWhere(rows, (row) => Number(row.qty_kesalahan ?? 0) < 0),
    negative_loss_values: countWhere(rows, (row) =>
      Number(row.jumlah_kerugian ?? 0) < 0 ||
      Number(row.kerugian_bahan ?? 0) < 0 ||
      Number(row.kerugian_jasa ?? 0) < 0,
    ),
    loss_total_mismatches: countWhere(rows, (row) =>
      Number(row.jumlah_kerugian ?? 0) !==
      Number(row.kerugian_bahan ?? 0) + Number(row.kerugian_jasa ?? 0),
    ),
    duplicate_business_candidates: duplicateCount(rows, (row) => JSON.stringify([
      row.tgl, row.nomor_invoice, row.jenis_kesalahan, row.deskripsi_kesalahan,
      normalize(row.pic), row.jumlah_kerugian,
    ])),
    totals: {
      affected_quantity: rows.reduce((total, row) => total + Number(row.qty_kesalahan ?? 0), 0),
      material_loss: rows.reduce((total, row) => total + Number(row.kerugian_bahan ?? 0), 0),
      service_loss: rows.reduce((total, row) => total + Number(row.kerugian_jasa ?? 0), 0),
      assessed_loss: rows.reduce((total, row) => total + Number(row.jumlah_kerugian ?? 0), 0),
    },
  }
}

function profileInventory(rows) {
  const normalizedNames = rows.map((row) => normalize(row.part_name))
  return {
    distinct_component_names: distinctCount(normalizedNames),
    duplicate_normalized_names: duplicateCount(normalizedNames, (name) => name),
    negative_stock_rows: countWhere(rows, (row) => Number(row.stock) < 0),
    total_legacy_balance_units: rows.reduce((total, row) => total + Number(row.stock ?? 0), 0),
    balance_by_component_name: Object.fromEntries(rows.map((row) => [normalize(row.part_name), Number(row.stock)])),
  }
}

function profilePurchases(rows) {
  return {
    purchase_date_range: range(rows.map((row) => row.tgl_pembelian)),
    created_timestamp_range: range(rows.map((row) => row.created_at)),
    distinct_component_names: distinctCount(rows.map((row) => row.part_name)),
    component_name_counts: groupedCounts(rows.map((row) => row.part_name)),
    distinct_supplier_snapshots: distinctCount(rows.map((row) => row.supplier)),
    negative_quantities: countWhere(rows, (row) => Number(row.qty) < 0),
    zero_quantities: countWhere(rows, (row) => Number(row.qty) === 0),
    negative_prices: countWhere(rows, (row) => Number(row.harga_satuan) < 0 || Number(row.total_harga) < 0),
    stored_total_mismatches: countWhere(rows, (row) =>
      Number(row.total_harga) !== Number(row.qty) * Number(row.harga_satuan),
    ),
    duplicate_business_candidates: duplicateCount(rows, (row) => JSON.stringify([
      row.tgl_pembelian, normalize(row.part_name), row.qty, row.harga_satuan,
      normalize(row.supplier), row.total_harga,
    ])),
    totals: {
      ordered_quantity: rows.reduce((total, row) => total + Number(row.qty ?? 0), 0),
      stored_acquisition_value: rows.reduce((total, row) => total + Number(row.total_harga ?? 0), 0),
      recomputed_acquisition_value: rows.reduce((total, row) => total + Number(row.qty ?? 0) * Number(row.harga_satuan ?? 0), 0),
    },
  }
}

export function analyzeLegacyData(data) {
  return {
    generated_at: new Date().toISOString(),
    safety: 'READ_ONLY_GET_REQUESTS_ONLY',
    tables: Object.fromEntries(LEGACY_TABLES.map((table) => [table, genericProfile(data[table] ?? [])])),
    domains: {
      counters: profileCounters(data.click_history ?? []),
      replacements: profileReplacements(data.part_replacements ?? []),
      operational_errors: profileErrors(data.error_logs ?? []),
      inventory: profileInventory(data.inventory_parts ?? []),
      purchases: profilePurchases(data.part_purchases ?? []),
    },
  }
}

async function getJson(url, key, path, headers = {}) {
  const response = await fetch(new URL(path, url), {
    method: 'GET',
    headers: { apikey: key, Authorization: `Bearer ${key}`, ...headers },
  })
  if (!response.ok) throw new Error(`Legacy read failed for ${path}: HTTP ${response.status}`)
  return response.json()
}

async function fetchAllRows(url, key, table) {
  const pageSize = 1000
  const rows = []
  for (let offset = 0; ; offset += pageSize) {
    const page = await getJson(url, key, `/rest/v1/${table}?select=*&offset=${offset}&limit=${pageSize}`)
    rows.push(...page)
    if (page.length < pageSize) return rows
  }
}

export async function fetchLegacyData({ url, key }) {
  const entries = await Promise.all(LEGACY_TABLES.map(async (table) => [table, await fetchAllRows(url, key, table)]))
  return Object.fromEntries(entries)
}

export async function auditLegacyProject({ url, key }) {
  const data = await fetchLegacyData({ url, key })
  const [authSettings, buckets] = await Promise.all([
    getJson(url, key, '/auth/v1/settings'),
    getJson(url, key, '/storage/v1/bucket'),
  ])
  return {
    ...analyzeLegacyData(data),
    services: {
      auth_endpoint_reachable: Boolean(authSettings),
      auth_user_inventory_available_with_client_key: false,
      storage_bucket_count: Array.isArray(buckets) ? buckets.length : null,
      storage_bucket_names: Array.isArray(buckets) ? buckets.map((bucket) => bucket.name).sort() : [],
    },
  }
}

async function main() {
  const unsupportedArguments = process.argv.slice(2).filter((argument) => argument !== '--dry-run')
  if (unsupportedArguments.length) {
    throw new Error(`Unsupported argument: ${unsupportedArguments.join(', ')}. This analyzer only supports --dry-run.`)
  }
  const url = process.env.LEGACY_SUPABASE_URL
  const key = process.env.LEGACY_SUPABASE_ANON_KEY
  if (!url || !key) {
    throw new Error('Set LEGACY_SUPABASE_URL and LEGACY_SUPABASE_ANON_KEY; secrets are never printed or stored.')
  }
  const result = await auditLegacyProject({ url, key })
  process.stdout.write(`${JSON.stringify(result, null, 2)}\n`)
}

if (process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href) {
  main().catch((error) => {
    process.stderr.write(`${error.message}\n`)
    process.exitCode = 1
  })
}
