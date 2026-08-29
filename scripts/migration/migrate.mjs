#!/usr/bin/env node
import { readFileSync } from 'node:fs'
import {
  IDENTITY, assertTargetSafety, buildApplySql, buildMigrationPlan, calculateSourceFingerprints,
  expectedSourceFingerprints, reconcileTarget, runPsql, targetPreflight, verifySource, writeReports,
} from './lib/m2-10a-engine.mjs'

function argsOf(values) {
  const result = { mode: 'dry-run', apply: false }
  for (let index = 0; index < values.length; index += 1) {
    const value = values[index]
    if (['audit','plan','dry-run','apply','reconcile'].includes(value)) { result.mode = value; result.apply = value === 'apply'; continue }
    if (value === '--apply') { result.apply = true; result.mode = 'apply'; continue }
    if (!value.startsWith('--')) throw new Error(`Unknown argument: ${value}`)
    result[value.slice(2).replaceAll('-', '_')] = values[++index]
  }
  return result
}
const args = argsOf(process.argv.slice(2))
if (!args.source_snapshot) throw new Error('--source-snapshot is required; source mutation is never supported')
const snapshot = JSON.parse(readFileSync(args.source_snapshot, 'utf8')); const source = snapshot.tables ?? snapshot
const sourceFingerprints = verifySource(source, args.expected_source_fingerprints ? JSON.parse(readFileSync(args.expected_source_fingerprints, 'utf8')) : expectedSourceFingerprints)
const plan = buildMigrationPlan(source)

if (args.mode === 'audit') {
  process.stdout.write(`${JSON.stringify({ rows: Object.fromEntries(Object.entries(source).map(([table,rows]) => [table,rows.length])), fingerprints: calculateSourceFingerprints(source), dispositions: plan.dispositionTotals }, null, 2)}\n`)
  process.exit(0)
}
if (args.mode === 'plan') {
  process.stdout.write(`${JSON.stringify(plan, null, 2)}\n`)
  process.exit(0)
}

const required = ['manifest','expected_source_fingerprints','target_type','target_container','account_id','branch_id','machine_id','execution_id','expected_target_fingerprint','output_dir']
for (const key of required) if (!args[key]) throw new Error(`--${key.replaceAll('_','-')} is required for ${args.mode}`)
const inputManifest = JSON.parse(readFileSync(args.manifest, 'utf8'))
const manifestManual = [...inputManifest.manual_exclusions].sort()
const plannedManual = plan.crosswalk.filter((row) => row.disposition === 'MANUAL_REVIEW').map((row) => `${row.source_table}:${row.source_id}`).sort()
if (inputManifest.migration_version !== plan.version || inputManifest.target_type !== 'DISPOSABLE' || inputManifest.target_schema_ledger !== IDENTITY.schemaLedger || JSON.stringify(manifestManual) !== JSON.stringify(plannedManual)) throw new Error('Explicit migration manifest validation failed')
if (inputManifest.account_id !== args.account_id || inputManifest.branch_id !== args.branch_id || inputManifest.machine_id !== args.machine_id || !inputManifest.denylisted_branch_ids.includes(IDENTITY.grahaId)) throw new Error('Manifest identity or Graha denylist validation failed')
if (args.account_id !== IDENTITY.accountId || args.branch_id !== IDENTITY.branchId || args.machine_id !== IDENTITY.machineId) throw new Error('Stable Account/Branch/Machine UUID validation failed')
assertTargetSafety({ targetType: args.target_type, targetContainer: args.target_container, apply: args.apply })
if (args.target_type !== 'DISPOSABLE') throw new Error('M2.10A target validation failed: only DISPOSABLE is supported')
const startedAt = new Date().toISOString()
const before = targetPreflight(args.target_container)
if (before.fingerprint !== args.expected_target_fingerprint) throw new Error(`Target fingerprint mismatch: expected ${args.expected_target_fingerprint}, received ${before.fingerprint}`)

if (args.mode === 'reconcile') {
  const reconciliation = reconcileTarget(args.target_container, plan)
  await writeReports(args.output_dir, { plan, sourceFingerprints, targetBefore: before, targetAfter: before, executionId: args.execution_id, mode: args.mode, reconciliation, startedAt, finishedAt: new Date().toISOString() })
  process.stdout.write(`${JSON.stringify(reconciliation, null, 2)}\n`)
  process.exit(0)
}

const sql = buildApplySql(plan, { dryRun: !args.apply, injectFailure: args.inject_failure === 'true' })
runPsql(args.target_container, sql)
const after = targetPreflight(args.target_container)
const reconciliation = args.apply ? reconcileTarget(args.target_container, plan) : { dry_run: true, validated_operations: Object.fromEntries(Object.entries(plan.operations).map(([key, rows]) => [key, rows.length])), dispositions: plan.dispositionTotals, unexplained: plan.unexplainedRemainder }
await writeReports(args.output_dir, { plan, sourceFingerprints, targetBefore: before, targetAfter: after, executionId: args.execution_id, mode: args.mode, reconciliation, startedAt, finishedAt: new Date().toISOString() })
process.stdout.write(`${JSON.stringify({ mode: args.mode, wrote: args.apply, target_before: before.fingerprint, target_after: after.fingerprint, reconciliation }, null, 2)}\n`)
