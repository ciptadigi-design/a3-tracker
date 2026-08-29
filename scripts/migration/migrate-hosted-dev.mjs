#!/usr/bin/env node
import { readFileSync } from 'node:fs'
import { buildApplySql, buildMigrationPlan, HOSTED_DEV_REF, IDENTITY, verifySource } from './lib/m2-10a-engine.mjs'
import {
  assertHostedDevSafety, hostedReconciliation, hostedTargetPreflight, mutationSummary,
  runHostedSql, writeHostedReports,
} from './lib/m2-10b-engine.mjs'

function argsOf(values) {
  const result = { mode: 'dry-run', apply: false }
  for (let index = 0; index < values.length; index += 1) {
    const value = values[index]
    if (['audit','plan','dry-run','apply','reconcile'].includes(value)) { result.mode = value; result.apply = value === 'apply'; continue }
    if (value === '--apply') { result.apply = true; result.mode = 'apply'; continue }
    if (!value.startsWith('--')) throw new Error(`Unknown argument: ${value}`)
    result[value.slice(2).replaceAll('-','_')] = values[++index]
  }
  return result
}

const args = argsOf(process.argv.slice(2))
if (!args.source_snapshot) throw new Error('--source-snapshot is required; source mutation is never supported')
const snapshot = JSON.parse(readFileSync(args.source_snapshot,'utf8'))
if (snapshot.source_project !== IDENTITY.sourceProject) throw new Error('Legacy source project identity validation failed')
const source = snapshot.tables ?? snapshot
if (!args.expected_source_fingerprints) throw new Error('--expected-source-fingerprints is required')
const sourceFingerprints = verifySource(source, JSON.parse(readFileSync(args.expected_source_fingerprints,'utf8')))

if (args.mode === 'audit') {
  process.stdout.write(`${JSON.stringify({ source_project:snapshot.source_project,captured_at:snapshot.captured_at,rows:Object.fromEntries(Object.entries(source).map(([table,rows])=>[table,rows.length])),fingerprints:sourceFingerprints },null,2)}\n`)
  process.exit(0)
}

const required = ['manifest','recovery_manifest','target_type','target_project_ref','account_id','branch_id','machine_id','execution_id','expected_target_fingerprint','output_dir']
for (const key of required) if (!args[key]) throw new Error(`--${key.replaceAll('_','-')} is required for ${args.mode}`)
assertHostedDevSafety({ targetType:args.target_type,targetProjectRef:args.target_project_ref,apply:args.apply })
if (args.account_id !== IDENTITY.accountId || args.branch_id !== IDENTITY.branchId || args.machine_id !== IDENTITY.machineId) throw new Error('Stable Account/Branch/Machine UUID validation failed')

const inputManifest = JSON.parse(readFileSync(args.manifest,'utf8'))
const recovery = JSON.parse(readFileSync(args.recovery_manifest,'utf8'))
if (inputManifest.migration_version !== 'm2.10b-v1' || inputManifest.target_type !== 'DEV' || inputManifest.target_project_ref !== HOSTED_DEV_REF) throw new Error('M2.10B manifest target validation failed')
if (inputManifest.account_id !== IDENTITY.accountId || inputManifest.branch_id !== IDENTITY.branchId || inputManifest.machine_id !== IDENTITY.machineId || !inputManifest.denylisted_branch_ids.includes(IDENTITY.grahaId)) throw new Error('M2.10B manifest identity/Graha validation failed')
if (inputManifest.unresolved_staging_approvals !== 0 || inputManifest.five_table_scope !== 'ACCEPTED') throw new Error('Unresolved staging approvals or five-table scope gate failed')
if (recovery.execution_id !== args.execution_id || recovery.target_project_ref !== HOSTED_DEV_REF || recovery.verified !== true) throw new Error('Verified recovery manifest validation failed')

const startedAt = new Date().toISOString()
const before = hostedTargetPreflight()
if (before.fingerprint !== args.expected_target_fingerprint) throw new Error(`Target fingerprint mismatch: expected ${args.expected_target_fingerprint}, received ${before.fingerprint}`)
if (before.state.schema_ledger !== IDENTITY.schemaLedger) throw new Error('Hosted schema ledger mismatch; schema change review is required before import')
if (before.state.account?.id !== IDENTITY.accountId || before.state.branch?.id !== IDENTITY.branchId || before.state.machine?.id !== IDENTITY.machineId || before.state.machine?.branch_id !== IDENTITY.branchId || before.state.graha?.id !== IDENTITY.grahaId) throw new Error('Hosted target identity preflight failed')
if (before.state.migration_actor_exists !== 1) throw new Error('Hosted migration audit actor is unavailable')

const plan = buildMigrationPlan(source,{hostedStaging:true,targetState:before.state})
if (plan.dispositionTotals.MANUAL_REVIEW !== 0 || plan.unexplainedRemainder !== 0) throw new Error('Hosted staging disposition gate failed')
if (JSON.stringify(plan.dispositionTotals) !== JSON.stringify(inputManifest.disposition_totals)) throw new Error('Hosted staging disposition manifest mismatch')

if (args.mode === 'plan') {
  const planned={target_preflight_fingerprint:before.fingerprint,dispositions:plan.dispositionTotals,unresolved_staging_approvals:0,operations:Object.fromEntries(Object.entries(plan.operations).map(([key,rows])=>[key,rows.length]))}
  await writeHostedReports(args.output_dir,{plan,executionId:args.execution_id,startedAt,finishedAt:new Date().toISOString(),sourceCapturedAt:snapshot.captured_at,sourceFingerprints,before,after:before,recovery,mode:args.mode,mutationSummary:{planned:planned.operations},reconciliation:{planned:true,...planned}})
  process.stdout.write(`${JSON.stringify(planned,null,2)}\n`)
  process.exit(0)
}

if (args.mode === 'reconcile') {
  const reconciliation = hostedReconciliation(plan)
  process.stdout.write(`${JSON.stringify(reconciliation,null,2)}\n`)
  process.exit(0)
}

const sql = buildApplySql(plan,{dryRun:!args.apply,psqlMeta:false})
await runHostedSql(sql,{targetType:args.target_type,targetProjectRef:args.target_project_ref,apply:args.apply})
const after = hostedTargetPreflight()
if (!args.apply && after.fingerprint !== before.fingerprint) throw new Error('Hosted dry-run changed target state')
const reconciliation = args.apply ? hostedReconciliation(plan) : { dry_run:true,validated_operations:Object.fromEntries(Object.entries(plan.operations).map(([key,rows])=>[key,rows.length])),dispositions:plan.dispositionTotals,unexplained:plan.unexplainedRemainder }
const summary = mutationSummary(plan,before.state,after.state)
await writeHostedReports(args.output_dir,{plan,executionId:args.execution_id,startedAt,finishedAt:new Date().toISOString(),sourceCapturedAt:snapshot.captured_at,sourceFingerprints,before,after,recovery,mode:args.mode,mutationSummary:summary,reconciliation})
process.stdout.write(`${JSON.stringify({mode:args.mode,wrote:args.apply,target_before:before.fingerprint,target_after:after.fingerprint,mutation_summary:summary,reconciliation},null,2)}\n`)
