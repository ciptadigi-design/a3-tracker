import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { IDENTITY, HOSTED_DEV_REF, buildMigrationPlan, idFor, runPsql } from './m2-10a-engine.mjs'

export const M211_LEDGER = '20260830000100'
export const M211_TARGET = 'DISPOSABLE_REHEARSAL'

export function assertM211Target({ targetType, targetContainer, targetProjectRef, productionMode = false }) {
  if (productionMode) throw new Error('M2.11 production mode is unavailable; Production mutation is hard blocked')
  if (targetProjectRef) throw new Error(`M2.11 hosted project ${targetProjectRef} refused; rehearsal accepts no hosted project ref`)
  if (targetType !== M211_TARGET) throw new Error(`M2.11 target ${targetType || 'UNKNOWN'} refused; only ${M211_TARGET} is allowed`)
  if (!/^supabase_db_[a-z0-9_-]+$/i.test(targetContainer ?? '')) throw new Error('Disposable Docker target container is required')
  const name = targetContainer.toLowerCase()
  if (name.includes(HOSTED_DEV_REF) || name.includes('production') || name.includes('prod')) throw new Error('DEV and Production targets are hard blocked')
  return true
}

export function buildM211Plan(source) {
  const plan = buildMigrationPlan(source, { hostedStaging: true, productionRehearsal: true })
  if (plan.dispositionTotals.MERGE !== 0 || plan.operations.counters.length !== 180) throw new Error('Production-specific empty-target counter plan was not produced')
  return plan
}

export function validateCutoverGates(manifest, { rehearsal = true } = {}) {
  const required = ['execution_uuid','source_fingerprints','source_freeze','target_fingerprint','backup_proof','stock_manifest','approvals']
  for (const key of required) if (!manifest?.[key]) throw new Error(`Cutover gate missing: ${key}`)
  if (!manifest.source_freeze.confirmed) throw new Error('Cutover gate missing: freeze confirmation')
  if (!manifest.backup_proof.verified_restore) throw new Error('Cutover gate missing: verified backup restore')
  if (!manifest.stock_manifest.approved) throw new Error('Cutover gate missing: approved stock manifest')
  if (rehearsal && manifest.stock_manifest.classification !== 'NON_PRODUCTION_STOCK_OPNAME_FIXTURE') throw new Error('Rehearsal stock manifest must be explicitly classified')
  return true
}

const sqlQuote = (value) => value === null || value === undefined ? 'null' : `'${String(value).replaceAll("'", "''")}'`
export function validateStockManifest(manifest) {
  if (manifest.classification !== 'NON_PRODUCTION_STOCK_OPNAME_FIXTURE') throw new Error('M2.11 accepts rehearsal stock fixtures only')
  if (!manifest.execution_uuid || !manifest.approval?.approved || !manifest.approval?.reviewed_by) throw new Error('Opening stock requires execution UUID and reviewer approval')
  if (manifest.target?.type !== M211_TARGET || manifest.target?.project_ref) throw new Error('Opening stock target must be a non-hosted disposable rehearsal')
  if (manifest.target.account_id !== IDENTITY.accountId || manifest.target.branch_id !== IDENTITY.branchId || !manifest.target.location_id) throw new Error('Opening stock identity mismatch')
  if (!Array.isArray(manifest.rows) || manifest.rows.length === 0) throw new Error('Opening stock rows are required')
  for (const row of manifest.rows) {
    if (!row.inventory_item_id || !(Number(row.counted_quantity) > 0) || !row.unit || !row.counted_by || !row.verified_by || !row.counted_at) throw new Error('Opening stock row is incomplete')
    if (!['KNOWN','UNKNOWN'].includes(row.cost_status)) throw new Error('Opening stock cost status must be KNOWN or UNKNOWN')
    if (row.cost_status === 'UNKNOWN' && row.unit_cost !== null) throw new Error('Unknown opening cost must remain null')
    if (row.cost_status === 'KNOWN' && !(Number(row.unit_cost) >= 0)) throw new Error('Known opening cost is invalid')
  }
  return true
}

export function stockApplySql(manifest, { dryRun = false } = {}) {
  validateStockManifest(manifest)
  const lines = ['\\set ON_ERROR_STOP on', 'begin;', `select set_config('request.jwt.claim.sub',${sqlQuote(IDENTITY.actorId)},true);`]
  for (const row of manifest.rows) {
    const requestId = idFor('m2-11-opening', manifest.execution_uuid, row.inventory_item_id)
    lines.push(`select (public.initialize_inventory_stock_costed(${sqlQuote(manifest.target.account_id)},${sqlQuote(row.inventory_item_id)},${sqlQuote(manifest.target.location_id)},${Number(row.counted_quantity)},${sqlQuote(row.counted_at)},${sqlQuote(row.operational_person_id ?? null)},${sqlQuote(`NON_PRODUCTION_STOCK_OPNAME_FIXTURE; execution=${manifest.execution_uuid}; ${row.notes ?? ''}`)},${sqlQuote(requestId)},${row.cost_status === 'KNOWN' ? Number(row.unit_cost) : 'null'})).id;`)
  }
  lines.push(dryRun ? 'rollback;' : 'commit;')
  return `${lines.join('\n')}\n`
}

export function applyStockFixture(container, manifest, { dryRun = false } = {}) {
  assertM211Target({ targetType: manifest.target.type, targetContainer: container, targetProjectRef: manifest.target.project_ref })
  return runPsql(container, stockApplySql(manifest, { dryRun }))
}

export const sha256File = (path) => createHash('sha256').update(readFileSync(path)).digest('hex')
