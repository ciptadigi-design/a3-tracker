import assert from 'node:assert/strict'
import test from 'node:test'
import { analyzeLegacyData, auditLegacyProject } from './legacy-audit.mjs'

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
