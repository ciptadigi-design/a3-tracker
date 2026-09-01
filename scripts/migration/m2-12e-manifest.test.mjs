import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { spawnSync } from 'node:child_process'

const source = '/var/folders/44/bzxc_f7n7r72ht1vgnh43fxm0000gn/T//a3-m212e-source.8SIKM9/final-frozen-source.json'
test('neutral manifest is deterministic and exact', () => {
  const dir = mkdtempSync(join(tmpdir(), 'm212e-manifest-'))
  try {
    const one = join(dir, 'one.json'); const two = join(dir, 'two.json')
    for (const output of [one, two]) {
      const r = spawnSync(process.execPath, ['scripts/migration/generate-m2-12e-manifest.mjs', '--source', source, '--output', output], { encoding: 'utf8' })
      assert.equal(r.status, 0, r.stderr)
    }
    assert.equal(readFileSync(one, 'utf8'), readFileSync(two, 'utf8'))
    const m = JSON.parse(readFileSync(one, 'utf8'))
    assert.equal(m.source.count, 529)
    assert.deepEqual(m.disposition, { IMPORT:480, MERGE:0, SKIP_DUPLICATE:2, ARCHIVE_ONLY:45, APPROVED_EXCLUDE:2, MANUAL_REVIEW:0 })
    assert.equal(m.records.counters.length, 183)
    assert.equal(Math.max(...m.records.counters.map((r) => r.value)), 1441597)
    assert.equal(m.records.lifecycles.length, 47)
    assert.equal(m.records.purchases.length, 161)
    assert.equal(m.records.purchases.reduce((n, r) => n + r.qty, 0), 208)
    assert.equal(m.records.purchases.reduce((n, r) => n + r.sourceTotal, 0), 371029998)
    assert.equal(m.records.incidents.length, 89)
    assert.equal(m.safety.legacy_receipts, 0)
    assert.equal(m.safety.legacy_inventory_movements, 0)
    assert.equal(m.safety.legacy_fifo_layers, 0)
    assert.equal(m.safety.unknown_cost_coerced_to_zero, 0)
    assert.equal(m.safety.ijal_identity_fabricated, false)
    assert.equal(m.records.counters.find((r) => r.rawOperator?.toLowerCase() === 'ijal').operator.id, null)
  } finally { rmSync(dir, { recursive: true, force: true }) }
})
