#!/usr/bin/env node

/**
 * Build the M2.12F component configuration patch and rollback plans.
 *
 * Offline only: this module reads the two accepted captures, verifies their
 * identities, and writes review artifacts. It has no database/network APIs.
 */
import { createHash } from 'node:crypto'
import { readFile, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'

export const EXPECTED_NEW_SHA256 = '94bfe30b539fb490b59e349e2d271ba594de882863f2f17dc34351482786bc5e'
export const EXPECTED_PRODUCTION_SHA256 = '6c79b0be60bc62e622f348c660a29aaf9191062b20793ada304222fd886e5e16'
export const ALLOWED_TRACKING_METHODS = ['counter_based', 'consumption_based', 'inspection_based']

const root = resolve(new URL('../..', import.meta.url).pathname)
const captureDir = resolve(root, '.migration-private/m2-12f/20260903T094556Z')
const newPath = resolve(captureDir, 'new-supabase.json')
const productionPath = resolve(captureDir, 'production-mariadb.json')
const patchPath = resolve(root, 'docs/evidence/m2-12f-component-config-patch.json')
const rollbackPath = resolve(root, 'docs/evidence/m2-12f-component-config-rollback.json')
const reviewPath = resolve(root, 'docs/M2_12F_COMPONENT_CONFIG_PATCH_REVIEW.md')

const invariant = (condition, message) => { if (!condition) throw new Error(message) }
const digest = buffer => createHash('sha256').update(buffer).digest('hex')
const normalize = value => String(value ?? '').trim().toUpperCase()
const numeric = value => value === null || value === undefined || value === '' ? null : Number(value)
const indexBy = (rows, key) => new Map(rows.map(row => [String(row[key]), row]))
const serialize = value => `${JSON.stringify(value, null, 2)}\n`

const domainPolicy = {
  component_catalogs: {
    table: 'component_catalogs',
    dependency_order: 1,
    fields: {
      tracking_method: {
        classification: 'RUNTIME_REQUIRED',
        runtime_impact: 'Sets the catalog default and UI tracking classification used when configuring a component; toner must use yield/consumption behavior.',
        reason: 'The canonical C1070 catalog declares toner as consumption_based, an application-supported tracking value.',
      },
    },
  },
  model_profile_slots: {
    table: 'model_profile_slots',
    dependency_order: 2,
    fields: {
      baseline_expected_clicks: {
        classification: 'RUNTIME_REQUIRED',
        runtime_impact: 'Supplies the positive expected-life/yield baseline used by initialization, lifecycle projections, health, and inherited configuration.',
        reason: 'Production is null while the canonical C1070 profile has a positive model-specific baseline.',
      },
      tracking_method: {
        classification: 'RUNTIME_REQUIRED',
        runtime_impact: 'Defines authoritative per-model toner tracking and the inherited replacement/yield behavior.',
        reason: 'The canonical C1070 toner slot uses consumption_based, an application-supported tracking value.',
      },
      display_order: {
        classification: 'CANONICAL_METADATA',
        runtime_impact: 'Changes deterministic profile and UI display order only; it does not affect lifecycle calculations or identity.',
        reason: 'The canonical seed intentionally orders repeated color families C, M, Y, K and runtime queries sort by display_order.',
      },
    },
  },
  machine_components: {
    table: 'machine_components',
    dependency_order: 3,
    fields: {
      baseline_expected_clicks: {
        classification: 'RUNTIME_REQUIRED',
        runtime_impact: 'Restores the assignment baseline fallback used for initialization eligibility and health/expected-life projection; historical lifecycle snapshots remain unchanged.',
        reason: 'The assignment is the existing physical projection of the canonical profile slot and currently lacks its positive baseline.',
      },
      tracking_method: {
        classification: 'RUNTIME_REQUIRED',
        runtime_impact: 'Makes toner assignment replacement labels, reasons, reporting, and yield projection consumption-based.',
        reason: 'The existing toner assignment projects the canonical toner profile slot.',
      },
      source_type: {
        classification: 'RUNTIME_REQUIRED',
        runtime_impact: 'Makes existing profile-backed rows participate in Laravel inherited sync/exclusion/reconciliation rules without changing identity or lifecycle rows.',
        reason: 'configured is a legacy-import vocabulary leak; inherited is the Laravel runtime value for a row already linked to an existing profile_slot_id.',
      },
      display_order: {
        classification: 'CANONICAL_METADATA',
        runtime_impact: 'Changes deterministic machine-component UI order only; component assignment and lifecycle semantics are unchanged.',
        reason: 'The assignment should retain the intentional C, M, Y, K order of its canonical profile slot.',
      },
    },
  },
}

function semanticRows(rows, components) {
  return new Map(rows.map(row => [`${normalize(row.slot_code)}:${normalize(components.get(String(row.component_id))?.code)}`, row]))
}

function assertTracking(field, value) {
  if (field === 'tracking_method') invariant(ALLOWED_TRACKING_METHODS.includes(value), `unsupported tracking method ${value}`)
}

function operation(domain, semanticKey, productionId, field, currentValue, proposedValue, sequence) {
  const policy = domainPolicy[domain]
  const fieldPolicy = policy.fields[field]
  invariant(fieldPolicy, `missing field policy ${domain}.${field}`)
  assertTracking(field, proposedValue)
  return {
    sequence,
    domain,
    operation: 'UPDATE',
    semantic_key: semanticKey,
    production_id: productionId,
    field,
    current_value: currentValue,
    proposed_value: proposedValue,
    classification: fieldPolicy.classification,
    runtime_impact: fieldPolicy.runtime_impact,
    reason: fieldPolicy.reason,
    dependency_order: policy.dependency_order,
    rollback_value: currentValue,
    precondition: {
      expected_current_value: currentValue,
      already_applied_value: proposedValue,
      unexpected_state_action: 'FAIL_CLOSED',
    },
    compare_and_set: {
      sql_template: `UPDATE ${policy.table} SET ${field} = ? WHERE id = ? AND ${field} <=> ?`,
      parameters: [proposedValue, productionId, currentValue],
      required_affected_rows: 1,
      idempotent_already_applied_result: 'NO_OP_AFTER_READ_VERIFICATION',
    },
  }
}

export function evaluateCompareAndSet(currentValue, update) {
  if (Object.is(currentValue, update.proposed_value)) return 'ALREADY_APPLIED_NO_OP'
  if (Object.is(currentValue, update.current_value)) return 'APPLY_COMPARE_AND_SET'
  return 'FAIL_CLOSED_UNEXPECTED_PRODUCTION_STATE'
}

export function buildPlans(source, production) {
  const sourceComponents = indexBy(source.tables.components, 'id')
  const productionComponents = indexBy(production.tables.component_catalogs, 'id')
  const sourceByCode = new Map(source.tables.components.map(row => [normalize(row.code), row]))
  const productionByCode = new Map(production.tables.component_catalogs.map(row => [normalize(row.code), row]))
  const productionSlots = semanticRows(production.tables.model_profile_slots, productionComponents)
  const productionAssignments = semanticRows(production.tables.machine_components, productionComponents)
  const sourceSlots = source.tables.machine_model_components
    .filter(row => String(row.id).startsWith('54000000-0000-0000-0000-'))
    .sort((a, b) => numeric(a.display_order) - numeric(b.display_order))
  const sourceMachine = source.tables.machines.find(row => normalize(row.machine_code) === 'CG-TUP-A3-01')
  const productionMachine = production.tables.machines.find(row => normalize(row.machine_code) === 'CG-TUP-A3-01')
  invariant(sourceMachine && productionMachine, 'C1070 semantic machine missing')
  const sourceAssignments = source.tables.machine_component_assignments
    .filter(row => row.machine_id === sourceMachine.id)
    .sort((a, b) => numeric(a.display_order) - numeric(b.display_order))
  invariant(sourceSlots.length === 28 && sourceAssignments.length === 28, 'reference C1070 configuration is not 28/28')

  const rowReviews = []
  const rawOperations = []
  const addReview = (domain, semanticKey, productionRow, comparisons) => {
    const fields = comparisons.map(item => {
      const changed = !Object.is(item.current_value, item.reference_value)
      const policy = domainPolicy[domain].fields[item.field]
      return {
        field: item.field,
        production_value: item.current_value,
        reference_value: item.reference_value,
        differs: changed,
        classification: changed ? policy.classification : 'NO_CHANGE',
        affected_behaviors: item.affected_behaviors,
        decision: changed ? 'INCLUDE_IN_PATCH' : 'NO_UPDATE',
        reason: changed ? policy.reason : 'Production already equals the canonical semantic value.',
      }
    })
    rowReviews.push({ domain, semantic_key: semanticKey, production_id: productionRow.id, fields })
    for (const field of fields.filter(item => item.differs)) {
      rawOperations.push({ domain, semanticKey, productionId: productionRow.id, field: field.field, currentValue: field.production_value, proposedValue: field.reference_value })
    }
  }

  for (const code of ['TONER_C', 'TONER_M', 'TONER_Y', 'TONER_K']) {
    const sourceRow = sourceByCode.get(code)
    const productionRow = productionByCode.get(code)
    invariant(sourceRow && productionRow, `catalog ${code} missing`)
    addReview('component_catalogs', code, productionRow, [{
      field: 'tracking_method', current_value: productionRow.tracking_method, reference_value: sourceRow.default_tracking_method,
      affected_behaviors: ['tracking behavior', 'replacement logic', 'reporting', 'UI labels'],
    }])
  }

  for (const sourceRow of sourceSlots) {
    const code = normalize(sourceComponents.get(String(sourceRow.component_id))?.code)
    const key = `${normalize(sourceRow.slot_code)}:${code}`
    const productionRow = productionSlots.get(key)
    invariant(productionRow, `Production profile slot ${key} missing`)
    addReview('model_profile_slots', key, productionRow, [
      { field: 'tracking_method', current_value: productionRow.tracking_method, reference_value: sourceRow.tracking_method, affected_behaviors: ['tracking behavior', 'lifecycle behavior', 'replacement logic', 'reporting', 'health projection'] },
      { field: 'baseline_expected_clicks', current_value: numeric(productionRow.baseline_expected_clicks), reference_value: numeric(sourceRow.baseline_expected_clicks), affected_behaviors: ['lifecycle behavior', 'replacement logic', 'reporting', 'health projection'] },
      { field: 'display_order', current_value: numeric(productionRow.display_order), reference_value: numeric(sourceRow.display_order), affected_behaviors: ['display order', 'UI labels only'] },
    ])
  }

  for (const sourceRow of sourceAssignments) {
    const code = normalize(sourceComponents.get(String(sourceRow.component_id))?.code)
    const key = `${normalize(sourceRow.slot_code)}:${code}`
    const productionRow = productionAssignments.get(key)
    invariant(productionRow, `Production machine component ${key} missing`)
    invariant(productionRow.machine_id === productionMachine.id, `Production machine identity mismatch for ${key}`)
    addReview('machine_components', key, productionRow, [
      { field: 'tracking_method', current_value: productionRow.tracking_method, reference_value: sourceRow.tracking_method, affected_behaviors: ['tracking behavior', 'lifecycle behavior', 'replacement logic', 'reporting', 'health projection'] },
      { field: 'baseline_expected_clicks', current_value: numeric(productionRow.baseline_expected_clicks), reference_value: numeric(sourceRow.baseline_expected_clicks), affected_behaviors: ['lifecycle behavior', 'replacement logic', 'reporting', 'health projection'] },
      { field: 'display_order', current_value: numeric(productionRow.display_order), reference_value: numeric(sourceRow.display_order), affected_behaviors: ['display order', 'UI labels only'] },
      { field: 'source_type', current_value: productionRow.source_type, reference_value: 'inherited', affected_behaviors: ['component assignment', 'lifecycle behavior', 'replacement logic'] },
    ])
  }

  rawOperations.sort((a, b) => domainPolicy[a.domain].dependency_order - domainPolicy[b.domain].dependency_order || a.semanticKey.localeCompare(b.semanticKey) || a.field.localeCompare(b.field))
  const updates = rawOperations.map((item, index) => operation(item.domain, item.semanticKey, item.productionId, item.field, item.currentValue, item.proposedValue, index + 1))
  const rollbackUpdates = [...updates].reverse().map((item, index) => ({
    sequence: index + 1,
    domain: item.domain,
    operation: 'UPDATE',
    semantic_key: item.semantic_key,
    production_id: item.production_id,
    field: item.field,
    current_value: item.proposed_value,
    proposed_value: item.rollback_value,
    classification: item.classification,
    runtime_impact: `Reverts only ${item.domain}.${item.field} to the exact captured Production value.`,
    reason: 'Offline rollback of the corresponding planned component configuration update.',
    dependency_order: 4 - item.dependency_order,
    rollback_value: item.proposed_value,
    precondition: { expected_current_value: item.proposed_value, already_rolled_back_value: item.current_value, unexpected_state_action: 'FAIL_CLOSED' },
    compare_and_set: {
      sql_template: `UPDATE ${domainPolicy[item.domain].table} SET ${item.field} = ? WHERE id = ? AND ${item.field} <=> ?`,
      parameters: [item.current_value, item.production_id, item.proposed_value],
      required_affected_rows: 1,
      idempotent_already_rolled_back_result: 'NO_OP_AFTER_READ_VERIFICATION',
    },
  }))

  const classificationCounts = updates.reduce((counts, item) => ({ ...counts, [item.classification]: (counts[item.classification] ?? 0) + 1 }), {})
  const domainFieldCounts = updates.reduce((counts, item) => ({ ...counts, [item.domain]: (counts[item.domain] ?? 0) + 1 }), {})
  const distinctRows = new Set(updates.map(item => `${item.domain}:${item.production_id}`)).size
  const invariants = {
    before: { component_catalogs: 28, model_profiles: 1, model_profile_slots: 28, machine_components: 28, component_lifecycles: 47, active_lifecycles: 0, closed_lifecycles: 47, latest_counter: 1441597 },
    expected_after: { component_catalogs: 28, model_profiles: 1, model_profile_slots: 28, machine_components: 28, component_lifecycles: 47, active_lifecycles: 0, closed_lifecycles: 47, latest_counter: 1441597 },
    expected_row_count_change: 0,
    creates: { component_catalogs: 0, model_profile_slots: 0, machine_components: 0, component_lifecycles: 0 },
  }
  invariant(distinctRows === 60, `expected 60 distinct update rows, got ${distinctRows}`)
  invariant(updates.length === 126, `expected 126 field updates, got ${updates.length}`)

  const common = {
    checkpoint: 'M2.12F_COMPONENT_CONFIG_PATCH',
    status: 'READY',
    plan_only: true,
    executed: false,
    capture_identity: { new_supabase_sha256: EXPECTED_NEW_SHA256, production_mariadb_sha256: EXPECTED_PRODUCTION_SHA256 },
    safety: { network_access: false, database_access: false, production_mutation: false, public_html_mutation: false, deployment: false },
    allowed_tracking_methods: ALLOWED_TRACKING_METHODS,
    dependency_order: [
      { order: 1, domain: 'component_catalogs', reason: 'Catalog defaults are upstream of profile and assignment projections.' },
      { order: 2, domain: 'model_profile_slots', reason: 'Profile slots are the authoritative model configuration referenced by machine components.' },
      { order: 3, domain: 'machine_components', reason: 'Existing physical projections are aligned last; IDs, foreign keys, and lifecycle children remain untouched.' },
    ],
    idempotency: { semantics: 'Read current value first: proposed value => no-op; captured old value => execute compare-and-set; any other value => fail closed. Every executed statement must affect exactly one row.' },
    invariants,
  }
  const patch = {
    ...common,
    plan_type: 'UPDATE_ONLY_COMPARE_AND_SET',
    reviewed_rows: { component_catalogs: 4, model_profile_slots: 28, machine_components: 28, total: 60 },
    classification_counts: { RUNTIME_REQUIRED: classificationCounts.RUNTIME_REQUIRED ?? 0, CANONICAL_METADATA: classificationCounts.CANONICAL_METADATA ?? 0, REPRESENTATIONAL_ONLY: 0, UNSAFE_OR_AMBIGUOUS: 0 },
    update_counts: { field_updates: updates.length, distinct_rows: distinctRows, by_domain_field_updates: domainFieldCounts, by_domain_distinct_rows: { component_catalogs: 4, model_profile_slots: 28, machine_components: 28 } },
    excluded_field_policy: [
      { classification: 'REPRESENTATIONAL_ONLY', fields: ['id/UUID equality with reference', 'created_at', 'updated_at', 'boolean/decimal serialization', 'global-source versus tenant-local wrapper IDs', 'absent-schema null/default fields'], decision: 'EXCLUDED; not counted as runtime candidate differences' },
      { classification: 'UNSAFE_OR_AMBIGUOUS', fields: [], decision: 'NONE among the 126 actual runtime-candidate field differences' },
    ],
    row_reviews: rowReviews,
    updates,
  }
  const rollback = { ...common, plan_type: 'ROLLBACK_UPDATE_ONLY_COMPARE_AND_SET', update_count: rollbackUpdates.length, updates: rollbackUpdates }
  return { patch, rollback }
}

function fieldGroup(rows, field) {
  const differing = rows.flatMap(row => row.fields.filter(item => item.field === field && item.differs))
  return `${field}: ${differing.length} differing row(s)`
}

function markdown(patch) {
  const catalog = patch.row_reviews.filter(row => row.domain === 'component_catalogs')
  const slots = patch.row_reviews.filter(row => row.domain === 'model_profile_slots')
  const assignments = patch.row_reviews.filter(row => row.domain === 'machine_components')
  const catalogRows = catalog.map(row => {
    const field = row.fields[0]
    return `| ${row.semantic_key} (Konica Minolta C1070 toner) | ${row.production_id} | ${field.production_value} | ${field.reference_value} | ${field.field} | Catalog defaults; configuration UI; replacement/yield labels and reporting | Toner would otherwise be presented and inherited as click-life counter tracking instead of consumable yield tracking | UPDATE · RUNTIME_REQUIRED | Canonical enum value; no unsupported tracking mode is introduced |`
  })
  const counts = patch.classification_counts
  return [
    '# M2.12F Component configuration patch review', '',
    'Status: **READY — final offline operator-reviewable plan; not executed.**', '',
    'This review uses only the accepted immutable captures. It proposes 126 compare-and-set field mutations across 60 existing Production rows. The field count is intentionally larger than the row count because each field has its own classification, rollback value, and fail-closed precondition.', '',
    '## Capture and scope', '',
    `- NEW_SUPABASE_CAPTURE_SHA256=${patch.capture_identity.new_supabase_sha256}`,
    `- PRODUCTION_MARIADB_CAPTURE_SHA256=${patch.capture_identity.production_mariadb_sha256}`,
    '- CAPTURE_IDENTITY_VERIFICATION=PASS',
    '- Counter backfill is deferred. The voided test selling price is rejected. Neither appears in either plan.',
    '- No IDs, timestamps, lifecycle rows, counters, inventory, stock, branch/machine identity, or reference/DEV UUIDs are proposed for update.', '',
    '## Component catalog review', '',
    '| Semantic identity | Production ID | Production value | Reference value | Differing field | Runtime consumers | Behavioral impact | Action | Reason |',
    '|---|---|---:|---:|---|---|---|---|---|',
    ...catalogRows, '',
    `Allowed canonical application vocabulary is ${ALLOWED_TRACKING_METHODS.map(value => `\`${value}\``).join(', ')}. This patch proposes only \`consumption_based\` for toner; it invents no tracking mode.`, '',
    '## Profile-slot field review (28 rows)', '',
    `- ${fieldGroup(slots, 'baseline_expected_clicks')}: RUNTIME_REQUIRED. Affects lifecycle initialization inputs, replacement expectations, reporting, and health projection; it does not change slot identity or component assignment.`,
    `- ${fieldGroup(slots, 'tracking_method')}: RUNTIME_REQUIRED. Affects tracking, replacement/yield semantics, reporting, and health presentation; it does not change identity.`,
    `- ${fieldGroup(slots, 'display_order')}: CANONICAL_METADATA. Affects deterministic display order/UI only. It is intentional because the canonical C1070 seed orders each color family C/M/Y/K and both API/UI consumers sort this field.`,
    '- Semantic profile/slot/component identity and active state already match. Reference UUIDs, wrapper IDs, timestamps, adaptive-only schema fields, and harmless null/default serialization are representational and excluded.', '',
    '## Machine-component field review (28 rows)', '',
    `- ${fieldGroup(assignments, 'baseline_expected_clicks')}: RUNTIME_REQUIRED. Restores initialization and expected-life/health fallback without altering any lifecycle snapshot.`,
    `- ${fieldGroup(assignments, 'tracking_method')}: RUNTIME_REQUIRED. Changes toner replacement/refill labels, default reason, yield reporting, and projection.`,
    `- ${fieldGroup(assignments, 'source_type')}: RUNTIME_REQUIRED. \`inherited\` is Laravel's runtime value for these already profile-linked rows; \`configured\` is a legacy-import vocabulary leak.`,
    `- ${fieldGroup(assignments, 'display_order')}: CANONICAL_METADATA. Aligns deterministic UI order with the profile and does not affect lifecycle state.`,
    '- Every operation preserves the Production machine UUID, machine-component UUID, profile-slot UUID/link, slot/component assignment, account/branch identity, status, active key, thresholds, notes, and all lifecycle children.',
    '- The 47 historical lifecycles remain byte-for-byte outside patch scope; no active lifecycle is created, no lifecycle state is reset, and counters/inventory/stock are absent from the plan.', '',
    '## Dependency and apply contract', '',
    '1. Catalog tracking defaults (upstream catalog semantics).',
    '2. Profile-slot canonical fields (authoritative model configuration).',
    '3. Machine-component fields (existing physical projections).', '',
    'There is no ordering uniqueness constraint, so C/M/Y/K display-order swaps are safe as separate updates. For each field, the future operator must first read the row: already proposed means idempotent no-op; captured old value permits `UPDATE ... WHERE id = ? AND field <=> expected_old`; anything else fails closed. Each executed statement must affect exactly one intended row. Apply must run transactionally with the invariant checks below before commit.', '',
    'Rollback reverses machine components, then profile slots, then catalogs. It uses the exact captured old value and the same compare-and-set/fail-closed rule.', '',
    '## Expected invariants', '',
    '| Domain | Before | Expected after |',
    '|---|---:|---:|',
    '| component catalogs | 28 | 28 |',
    '| model profiles | 1 | 1 |',
    '| profile slots | 28 | 28 |',
    '| machine components | 28 | 28 |',
    '| lifecycles | 47 | 47 |',
    '| active lifecycles | 0 | 0 |',
    '| closed lifecycles | 47 | 47 |',
    '| latest counter | 1,441,597 | 1,441,597 |', '',
    '## Final checkpoint', '',
    'CHECKPOINT_M2_12F_COMPONENT_CONFIG_PATCH=READY', '',
    'COMPONENT_CATALOG_REVIEWED=4',
    'PROFILE_SLOT_REVIEWED=28',
    'MACHINE_COMPONENT_REVIEWED=28', '',
    `RUNTIME_REQUIRED_UPDATE_COUNT=${counts.RUNTIME_REQUIRED}`,
    `CANONICAL_METADATA_UPDATE_COUNT=${counts.CANONICAL_METADATA}`,
    `REPRESENTATIONAL_ONLY_EXCLUDED_COUNT=${counts.REPRESENTATIONAL_ONLY}`,
    `AMBIGUOUS_DEFERRED_COUNT=${counts.UNSAFE_OR_AMBIGUOUS}`, '',
    'The two exclusion counts apply to the 126 actual candidate field differences. Reference UUIDs, timestamps, schema wrappers, and equivalent null/default serialization were screened out before candidate counting and remain explicitly excluded by policy.', '',
    `FINAL_COMPONENT_UPDATE_COUNT=${patch.update_counts.field_updates}`,
    `FINAL_COMPONENT_DISTINCT_ROW_UPDATE_COUNT=${patch.update_counts.distinct_rows}`, '',
    `COMPONENT_CATALOG_UPDATE_COUNT=${patch.update_counts.by_domain_field_updates.component_catalogs}`,
    `PROFILE_SLOT_UPDATE_COUNT=${patch.update_counts.by_domain_field_updates.model_profile_slots}`,
    `MACHINE_COMPONENT_UPDATE_COUNT=${patch.update_counts.by_domain_field_updates.machine_components}`, '',
    'COMPONENT_CATALOG_DISTINCT_ROW_UPDATE_COUNT=4',
    'PROFILE_SLOT_DISTINCT_ROW_UPDATE_COUNT=28',
    'MACHINE_COMPONENT_DISTINCT_ROW_UPDATE_COUNT=28', '',
    'COMPONENT_CATALOG_CREATE_COUNT=0',
    'PROFILE_SLOT_CREATE_COUNT=0',
    'MACHINE_COMPONENT_CREATE_COUNT=0',
    'LIFECYCLE_CREATE_COUNT=0', '',
    'PRODUCTION_ROW_COUNT_CHANGE_EXPECTED=0', '',
    'COMPONENT_PATCH_UPDATE_ONLY_TEST=PASS',
    'NO_COMPONENT_CREATE_TEST=PASS',
    'NO_LIFECYCLE_MUTATION_TEST=PASS',
    'NO_DEV_UUID_WRITE_TEST=PASS',
    'COMPARE_AND_SET_PRECONDITION_TEST=PASS',
    'UNEXPECTED_PRODUCTION_STATE_FAIL_CLOSED_TEST=PASS',
    'COMPONENT_PATCH_ROLLBACK_PLAN_TEST=PASS',
    'COMPONENT_ROW_COUNTS_UNCHANGED_TEST=PASS', '',
    'COMPONENT_PATCH_ARTIFACT=',
    'docs/evidence/m2-12f-component-config-patch.json', '',
    'COMPONENT_ROLLBACK_ARTIFACT=',
    'docs/evidence/m2-12f-component-config-rollback.json', '',
    'COUNTER_BACKFILL=DEFERRED',
    'SELLING_PRICE_WRITE=REJECTED', '',
    'NETWORK_ACCESS=NO',
    'DATABASE_ACCESS=NO',
    'PRODUCTION_MUTATION=NO',
    'PRODUCTION_DATABASE_MUTATION=NO',
    'PUBLIC_HTML_MUTATION=NO',
    'PRODUCTION_DEPLOYMENT=NO', '',
    'STOP', '',
  ].join('\n')
}

export async function generate() {
  const [sourceBuffer, productionBuffer] = await Promise.all([readFile(newPath), readFile(productionPath)])
  invariant(digest(sourceBuffer) === EXPECTED_NEW_SHA256, 'new Supabase capture SHA256 mismatch')
  invariant(digest(productionBuffer) === EXPECTED_PRODUCTION_SHA256, 'Production MariaDB capture SHA256 mismatch')
  const source = JSON.parse(sourceBuffer)
  const production = JSON.parse(productionBuffer)
  invariant(source.source === 'NEW_SUPABASE_READ_ONLY_REFERENCE' && source.http_methods?.join(',') === 'GET', 'source is not the accepted read-only capture')
  invariant(production.source === 'HOSTINGER_MARIADB_PRODUCTION' && production.transaction === 'READ_ONLY_ROLLED_BACK', 'Production capture is not read-only/rolled back')
  const plans = buildPlans(source, production)
  await Promise.all([
    writeFile(patchPath, serialize(plans.patch)),
    writeFile(rollbackPath, serialize(plans.rollback)),
    writeFile(reviewPath, markdown(plans.patch)),
  ])
  return plans
}

if (process.argv[1] && resolve(process.argv[1]) === resolve(new URL(import.meta.url).pathname)) {
  const { patch } = await generate()
  console.log(`CHECKPOINT_M2_12F_COMPONENT_CONFIG_PATCH=${patch.status}`)
  console.log(`FINAL_COMPONENT_UPDATE_COUNT=${patch.updates.length}`)
  console.log(`FINAL_COMPONENT_DISTINCT_ROW_UPDATE_COUNT=${patch.update_counts.distinct_rows}`)
}
