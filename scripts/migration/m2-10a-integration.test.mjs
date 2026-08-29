import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { buildApplySql, buildMigrationPlan, reconcileTarget, runPsql, targetPreflight, verifySource } from './lib/m2-10a-engine.mjs'

const snapshotPath = process.env.M2_10A_SOURCE_SNAPSHOT
const integration = snapshotPath ? test : test.skip
const container = 'supabase_db_konica-tracker-next'
const reset = () => execFileSync(process.execPath, ['scripts/migration/prepare-disposable.mjs','--reset'], { cwd: new URL('../../', import.meta.url), stdio: 'ignore' })

integration('full exact-schema disposable apply, retry, fresh rerun, and failure rollback', { timeout: 180_000 }, () => {
  const snapshot = JSON.parse(readFileSync(snapshotPath, 'utf8')); const source = snapshot.tables ?? snapshot
  verifySource(source); const plan = buildMigrationPlan(source); const applySql = buildApplySql(plan)
  reset()
  const baseline = targetPreflight(container)
  assert.equal(baseline.state.schema_ledger, '20260829000600')
  assert.equal(baseline.state.assignments.length, 28)
  assert.equal(baseline.state.lifecycles.length, 30)
  assert.equal(baseline.state.replacements.length, 2)
  runPsql(container, buildApplySql(plan, { dryRun: true }))
  assert.equal(targetPreflight(container).fingerprint, baseline.fingerprint)
  runPsql(container, applySql)
  const first = targetPreflight(container); const reconciliation = reconcileTarget(container, plan)
  assert.equal(reconciliation.source, 526)
  assert.equal(reconciliation.eligible_accounted, 497)
  assert.equal(reconciliation.manual_excluded, 29)
  assert.equal(reconciliation.unexplained, 0)
  assert.deepEqual(reconciliation.purchases, { rows: 161, qty: 208, value: 371029998 })
  assert.equal(reconciliation.receipts_from_legacy_purchases, 0)
  assert.equal(reconciliation.movements_from_legacy_purchases, 0)
  assert.equal(reconciliation.fifo_from_legacy_purchases, 0)
  assert.equal(reconciliation.graha_leakage, 0)
  assert.ok(baseline.state.replacements.every((row) => first.state.replacements.some((candidate) => candidate.id === row.id)))

  runPsql(container, applySql)
  assert.equal(targetPreflight(container).fingerprint, first.fingerprint)
  assert.throws(() => runPsql(container, buildApplySql(plan, { injectFailure: true })), /Command failed/)
  assert.equal(targetPreflight(container).fingerprint, first.fingerprint)

  reset()
  const freshBaseline = targetPreflight(container)
  assert.equal(freshBaseline.fingerprint, baseline.fingerprint)
  runPsql(container, applySql)
  assert.equal(targetPreflight(container).fingerprint, first.fingerprint)
})
