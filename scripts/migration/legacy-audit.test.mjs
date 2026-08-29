import assert from 'node:assert/strict'
import test from 'node:test'
import {
  analyzeLegacyData, assertDispositionTotal, assignCounterMigrationTimestamps, auditLegacyProject,
  buildEligibilitySet, classifyCounterCollision, classifyCounterDate, deterministicUuidV5,
  fingerprintRows, normalizeForComparison, planLegacyPurchase, transformLegacyLoss,
} from './legacy-audit.mjs'

test('profiles legacy evidence without exposing person/customer values', () => {
  const result = analyzeLegacyData({
    click_history: [
      { id: 1, date_for: '2026-01-01', date_str: '2026-01-01 08:00:00', operator: 'Alice', total_clicks: 100, daily_clicks: 0 },
      { id: 2, date_for: '2026-01-01', date_str: '2026-01-01 09:00:00', operator: ' alice ', total_clicks: 90, daily_clicks: -10 },
    ],
    part_replacements: [{ id: 1, part_name: 'Drum C', operator: 'Tech', replaced_at_click: 80, created_at: '2026-01-01T00:00:00Z' }],
    error_logs: [{ id: 1, tgl: '2026-01-01', jenis_kesalahan: 'Human Error', kategori_kesalahan: 'Quality', pic: 'Alice', qty_kesalahan: 1, kerugian_bahan: 10, kerugian_jasa: 5, jumlah_kerugian: 12 }],
    inventory_parts: [{ id: 1, part_name: 'Drum C', stock: 2 }, { id: 2, part_name: ' drum c ', stock: 3 }],
    part_purchases: [{ id: 1, tgl_pembelian: '2026-01-01', part_name: 'Drum C', qty: 2, harga_satuan: 10, total_harga: 19, supplier: 'Supplier A' }],
  })

  assert.equal(result.safety, 'READ_ONLY_GET_REQUESTS_ONLY')
  assert.equal(result.domains.counters.dates_with_multiple_readings, 1)
  assert.equal(result.domains.counters.chronological_counter_regressions, 1)
  assert.equal(result.domains.counters.distinct_operator_snapshots, 1)
  assert.equal(result.domains.inventory.duplicate_normalized_names, 1)
  assert.equal(result.domains.operational_errors.loss_total_mismatches, 1)
  assert.equal(result.domains.purchases.stored_total_mismatches, 1)
  assert.doesNotMatch(JSON.stringify(result), /Alice|Supplier A/)
})

test('handles empty tables deterministically', () => {
  const result = analyzeLegacyData({})
  assert.equal(result.tables.click_history.row_count, 0)
  assert.equal(result.domains.counters.counter_range, null)
  assert.equal(result.domains.inventory.total_legacy_balance_units, 0)
})

test('hosted audit is restricted to GET requests on the explicit allowlist', async () => {
  const originalFetch = globalThis.fetch
  const requests = []
  globalThis.fetch = async (url, options) => {
    requests.push({ url: String(url), options })
    return {
      ok: true,
      json: async () => String(url).includes('/auth/v1/settings') ? {} : [],
    }
  }

  try {
    const result = await auditLegacyProject({ url: 'https://legacy.invalid', key: 'not-a-real-key' })
    assert.equal(result.services.storage_bucket_count, 0)
    assert.equal(requests.length, 7)
    assert.ok(requests.every((request) => request.options.method === 'GET'))
    assert.ok(requests.every((request) => /^https:\/\/legacy\.invalid\/(rest\/v1\/(click_history|part_replacements|error_logs|inventory_parts|part_purchases)|auth\/v1\/settings|storage\/v1\/bucket)/.test(request.url)))
  } finally {
    globalThis.fetch = originalFetch
  }
})

test('source fingerprints are independent of row and object-key order', () => {
  assert.equal(fingerprintRows([{ id: 2, value: 'b' }, { id: 1, value: 'a' }]), fingerprintRows([{ value: 'a', id: 1 }, { value: 'b', id: 2 }]))
})

test('locked identity mapping uses Tuparev and deny-lists Graha', async () => {
  const mapping = JSON.parse(await (await import('node:fs/promises')).readFile(new URL('./mapping.json', import.meta.url)))
  assert.equal(mapping.identity.account.id, '357e420a-c9ea-4404-9da4-f254c5dce5ef')
  assert.equal(mapping.identity.branch.code, 'CG-TUP')
  assert.equal(mapping.identity.machine.code, 'CG-TUP-A3-01')
  assert.ok(mapping.identity.denylisted_branch_ids.includes('9f753339-0d54-42c9-9bb6-afe2461803f8'))
})

test('counter date precision and collision mechanics preserve evidence', () => {
  const yesterday = classifyCounterDate({ date_for: '2026-01-01', date_str: '2026-01-02 08:00:00' })
  assert.equal(yesterday.status, 'RESOLVED_DATE_ONLY')
  const collision = classifyCounterCollision({ total_clicks: 100, date_str: '2026-01-02 08:00:00' }, [{ id: 'target', reading_value: 100, observed_at: '2026-01-02T01:02:00Z' }])
  assert.equal(collision.collision, 'SAME_EVENT_HIGH_CONFIDENCE')
  assert.equal(collision.disposition, 'MERGE')
})

test('daily clicks, unknown lifecycle, purchases, and opening stock stay conservative', async () => {
  const mapping = JSON.parse(await (await import('node:fs/promises')).readFile(new URL('./mapping.json', import.meta.url)))
  assert.match(mapping.entities[0].field_rules.daily_clicks, /SKIP_DERIVED/)
  assert.equal(mapping.policies.lifecycle.unknown_stays_unknown, true)
  assert.equal(mapping.policies.purchases.create_receipts, false)
  assert.equal(mapping.policies.opening_stock.requires_physical_count_approval, true)
  assert.equal(mapping.policies.components.other_part_treatment, 'MANUAL_REVIEW_OR_ARCHIVE_TEXT_ONLY')
})

test('duplicate mechanics, dispositions, UUIDv5, and crosswalk are stable', () => {
  assertDispositionTotal([{ disposition: 'IMPORT' }, { disposition: 'SKIP_DUPLICATE' }], 2)
  const namespace = '8d17ee87-c890-5f1e-92bf-5ea595530e2f'
  assert.equal(deterministicUuidV5(namespace, 'row-1'), deterministicUuidV5(namespace, 'row-1'))
  assert.notEqual(deterministicUuidV5(namespace, 'row-1'), deterministicUuidV5(namespace, 'row-2'))
  assert.equal(normalizeForComparison('  Akmal   OJAN '), 'akmal ojan')
})

test('generated register accounts for all 526 rows and incident duplicates', async () => {
  const fs = await import('node:fs/promises')
  const register = JSON.parse(await fs.readFile(new URL('./source-row-dispositions.json', import.meta.url)))
  assert.equal(register.row_count, 526)
  assert.equal(register.unexplained_remainder, 0)
  assert.equal(Object.values(register.counts).reduce((sum, count) => sum + count, 0), 526)
  assert.equal(register.counts.SKIP_DUPLICATE, 2)
  assert.deepEqual(register.rows.filter((row) => row.disposition === 'SKIP_DUPLICATE').map((row) => row.legacy_id).sort(), [
    '3436509c-f729-4f78-b9b7-33d0d41f6837', '76c2f97c-cae5-44a8-ac0c-29af462b5994',
  ])
})

test('date-only synthetic ordering is deterministic and preserves counter chronology', () => {
  const rows = [
    { id: 'b', date_for: '2026-01-01', date_str: '2026-01-02 09:00:00', created_at: '2026-01-02T02:00:00Z', total_clicks: 101 },
    { id: 'a', date_for: '2026-01-01', date_str: '2026-01-02 08:00:00', created_at: '2026-01-02T01:00:00Z', total_clicks: 100 },
  ]
  const first = assignCounterMigrationTimestamps(rows)
  const second = assignCounterMigrationTimestamps([...rows].reverse())
  assert.deepEqual(first, second)
  assert.ok(first[0].migration_timestamp < first[1].migration_timestamp)
  assert.ok(first.every((row) => row.timestamp_evidence === 'MIGRATION_SYNTHETIC_TIME'))
})

test('manual exclusions remain visible and eligibility reconciles to 526', async () => {
  const fs = await import('node:fs/promises')
  const register = JSON.parse(await fs.readFile(new URL('./source-row-dispositions.json', import.meta.url)))
  const eligibility = buildEligibilitySet(register.rows)
  assert.equal(eligibility.length, 526)
  assert.equal(eligibility.filter((row) => row.eligibility === 'EXCLUDED_MANUAL').length, 29)
  assert.equal(register.m2_10a_eligible_count, 497)
  assert.equal(register.unexplained_remainder, 0)
})

test('purchase plan creates no receipt, stock movement, or FIFO and permits component-null acquisition', () => {
  const plan = planLegacyPurchase({ id: 301 })
  assert.equal(plan.purchase.status, 'draft')
  assert.equal(plan.purchase_line.inventory_item_component_id, null)
  assert.deepEqual(plan.receipts, [])
  assert.deepEqual(plan.inventory_movements, [])
  assert.deepEqual(plan.fifo_lots, [])
})

test('Other Part and first replacements never fabricate operational structure', async () => {
  const fs = await import('node:fs/promises')
  const components = JSON.parse(await fs.readFile(new URL('./component-mapping.json', import.meta.url)))
  const other = components.rows.filter((row) => row.legacy_label === 'Other Part')
  assert.ok(other.every((row) => row.target_component_id === null && row.target_slot_code === null))
  const register = JSON.parse(await fs.readFile(new URL('./source-row-dispositions.json', import.meta.url)))
  assert.ok(register.rows.filter((row) => row.disposition === 'ARCHIVE_ONLY').every((row) => /does not prove predecessor installation/.test(row.reason)))
})

test('incident duplicates stay skipped and legacy multiplier is not applied twice', async () => {
  const fs = await import('node:fs/promises')
  const register = JSON.parse(await fs.readFile(new URL('./source-row-dispositions.json', import.meta.url)))
  const skipped = register.rows.filter((row) => row.disposition === 'SKIP_DUPLICATE').map((row) => row.legacy_id).sort()
  assert.deepEqual(skipped, ['3436509c-f729-4f78-b9b7-33d0d41f6837', '76c2f97c-cae5-44a8-ac0c-29af462b5994'])
  const loss = transformLegacyLoss({ material_loss: 950, service_loss: 1000, stored_total: 3900 })
  assert.equal(loss.penalty_multiplier, 2)
  assert.equal(loss.reconciled_total, 3900)
})

test('production-only gates do not block M2.10A and contract has zero hosted mutation paths', async () => {
  const fs = await import('node:fs/promises')
  const gates = JSON.parse(await fs.readFile(new URL('./approval-gates.json', import.meta.url)))
  assert.equal(gates.decisions.length, 12)
  assert.ok(gates.decisions.every((decision) => decision.blocks_m2_10a === false))
  assert.ok(gates.decisions.filter((decision) => decision.category === 'PRODUCTION_CUTOVER_GATE').every((decision) => decision.blocks_production))
  const contract = JSON.parse(await fs.readFile(new URL('./m2-10a-contract.json', import.meta.url)))
  assert.deepEqual(contract.hosted_dev_mutation_paths, [])
  assert.deepEqual(contract.production_mutation_paths, [])
  assert.deepEqual(contract.writable_target_classes, ['DISPOSABLE_LOCAL_EXACT_SCHEMA'])
})
