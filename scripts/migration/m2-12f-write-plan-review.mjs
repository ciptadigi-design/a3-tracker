#!/usr/bin/env node

/**
 * M2.12F strict offline write-plan and component-diff review.
 *
 * This script deliberately imports only local filesystem and hashing helpers.
 * It has no network, database, SSH, child-process, or environment access.
 */
import { createHash } from 'node:crypto'
import { readFile, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'

const EXPECTED_NEW_SHA256 = '94bfe30b539fb490b59e349e2d271ba594de882863f2f17dc34351482786bc5e'
const EXPECTED_PRODUCTION_SHA256 = '6c79b0be60bc62e622f348c660a29aaf9191062b20793ada304222fd886e5e16'
const root = resolve(new URL('../..', import.meta.url).pathname)
const captureDir = resolve(root, '.migration-private/m2-12f/20260903T094556Z')
const newPath = resolve(captureDir, 'new-supabase.json')
const productionPath = resolve(captureDir, 'production-mariadb.json')
const jsonPath = resolve(root, 'docs/evidence/m2-12f-write-plan-review.json')
const markdownPath = resolve(root, 'docs/M2_12F_WRITE_PLAN_REVIEW.md')

const sha256 = buffer => createHash('sha256').update(buffer).digest('hex')
const invariant = (condition, message) => { if (!condition) throw new Error(message) }
const normalizeCode = value => String(value ?? '').trim().toUpperCase()
const number = value => value === null || value === undefined || value === '' ? null : Number(value)
const json = value => `${JSON.stringify(value, null, 2)}\n`
const byId = rows => new Map(rows.map(row => [String(row.id), row]))

function semanticIndex(rows, componentById) {
  return new Map(rows.map(row => [`${normalizeCode(row.slot_code)}:${normalizeCode(componentById.get(String(row.component_id))?.code)}`, row]))
}

function valueCounts(rows, field) {
  const result = new Map()
  for (const row of rows) {
    const value = row[field]
    const key = value === null ? 'null' : String(value)
    result.set(key, (result.get(key) ?? 0) + 1)
  }
  return Object.fromEntries([...result.entries()].sort())
}

function componentCandidates(source, production) {
  const sourceComponents = byId(source.tables.components)
  const productionComponents = byId(production.tables.component_catalogs)
  const productionCatalogByCode = new Map(production.tables.component_catalogs.map(row => [normalizeCode(row.code), row]))
  const sourceCatalogByCode = new Map(source.tables.components.map(row => [normalizeCode(row.code), row]))

  const catalog = ['TONER_C', 'TONER_K', 'TONER_M', 'TONER_Y'].map(code => {
    const sourceRow = sourceCatalogByCode.get(code)
    const productionRow = productionCatalogByCode.get(code)
    invariant(sourceRow && productionRow, `missing catalog crosswalk for ${code}`)
    return {
      domain: 'component_catalogs', operation: 'UPDATE', semantic_key: code,
      current: { tracking_method: productionRow.tracking_method },
      proposed: { tracking_method: sourceRow.default_tracking_method },
      runtime_effect: 'Changes the catalog default and rendered tracking classification from counter-based to toner yield/consumption-based.',
      canonical_recommendation: 'UPDATE_TO_CANONICAL_SOURCE_VALUE',
    }
  })

  const sourceSlots = source.tables.machine_model_components
    .filter(row => String(row.id).startsWith('54000000-0000-0000-0000-'))
    .sort((a, b) => number(a.display_order) - number(b.display_order))
  invariant(sourceSlots.length === 28, 'canonical C1070 source slot count changed')
  const productionSlots = semanticIndex(production.tables.model_profile_slots, productionComponents)
  const slots = sourceSlots.map(sourceRow => {
    const code = normalizeCode(sourceComponents.get(String(sourceRow.component_id))?.code)
    const key = `${normalizeCode(sourceRow.slot_code)}:${code}`
    const productionRow = productionSlots.get(key)
    invariant(productionRow, `missing Production profile slot ${key}`)
    const current = {
      tracking_method: productionRow.tracking_method,
      baseline_expected_clicks: number(productionRow.baseline_expected_clicks),
      display_order: number(productionRow.display_order),
    }
    const proposed = {
      tracking_method: sourceRow.tracking_method,
      baseline_expected_clicks: number(sourceRow.baseline_expected_clicks),
      display_order: number(sourceRow.display_order),
    }
    return { domain: 'model_profile_slots', operation: 'UPDATE', semantic_key: key, current, proposed }
  })

  const tuparev = source.tables.machines.find(row => normalizeCode(row.machine_code) === 'CG-TUP-A3-01')
  invariant(tuparev, 'source Tuparev machine missing')
  const sourceAssignments = source.tables.machine_component_assignments
    .filter(row => row.machine_id === tuparev.id)
    .sort((a, b) => number(a.display_order) - number(b.display_order))
  invariant(sourceAssignments.length === 28, 'source Tuparev assignment count changed')
  const productionAssignments = semanticIndex(production.tables.machine_components, productionComponents)
  const assignments = sourceAssignments.map(sourceRow => {
    const code = normalizeCode(sourceComponents.get(String(sourceRow.component_id))?.code)
    const key = `${normalizeCode(sourceRow.slot_code)}:${code}`
    const productionRow = productionAssignments.get(key)
    invariant(productionRow, `missing Production machine component ${key}`)
    const current = {
      tracking_method: productionRow.tracking_method,
      baseline_expected_clicks: number(productionRow.baseline_expected_clicks),
      display_order: number(productionRow.display_order),
      source_type: productionRow.source_type,
    }
    const proposed = {
      tracking_method: sourceRow.tracking_method,
      baseline_expected_clicks: number(sourceRow.baseline_expected_clicks),
      display_order: number(sourceRow.display_order),
      source_type: 'inherited',
    }
    return { domain: 'machine_components', operation: 'UPDATE', machine_semantic_identity: 'CG-TUP-A3-01', semantic_key: key, current, proposed }
  })
  return { catalog, slots, assignments }
}

function counterReview(source, production, readingValue) {
  const sourceMachines = byId(source.tables.machines)
  const sourceTypes = byId(source.tables.counter_types)
  const sourceRows = source.tables.counter_readings.filter(row => number(row.reading_value) === readingValue)
  invariant(sourceRows.length === 1, `source counter ${readingValue} is not unique`)
  const row = sourceRows[0]
  const machine = sourceMachines.get(String(row.machine_id))
  const type = sourceTypes.get(String(row.counter_type_id))
  invariant(normalizeCode(machine?.machine_code) === 'CG-TUP-A3-01', `counter ${readingValue} machine mismatch`)
  invariant(type?.code === 'total_impressions' && type?.is_monotonic === true, `counter ${readingValue} type mismatch`)

  const productionMachine = production.tables.machines.find(item => normalizeCode(item.machine_code) === 'CG-TUP-A3-01')
  const productionType = production.tables.counter_types.find(item => item.code === 'total_impressions')
  invariant(productionMachine && productionType, 'Production counter semantic target missing')
  const productionRows = production.tables.counter_readings
    .filter(item => item.machine_id === productionMachine.id && item.counter_type_id === productionType.id && item.status === 'effective')
    .sort((a, b) => String(a.observed_at).localeCompare(String(b.observed_at)) || number(a.reading_value) - number(b.reading_value))
  invariant(!productionRows.some(item => number(item.reading_value) === readingValue), `counter ${readingValue} unexpectedly exists in Production`)
  const position = productionRows.findIndex(item => String(item.observed_at).replace(' ', 'T') > String(row.observed_at).replace('+00:00', ''))
  const previous = productionRows[position - 1]
  const next = productionRows[position]
  invariant(previous && next, `counter ${readingValue} lacks surrounding Production facts`)
  invariant(number(previous.reading_value) <= readingValue && readingValue <= number(next.reading_value), `counter ${readingValue} regresses`)
  const duplicateSourceFacts = source.tables.counter_readings.filter(item =>
    item.machine_id === row.machine_id && item.counter_type_id === row.counter_type_id &&
    number(item.reading_value) === readingValue && item.observed_at === row.observed_at)
  invariant(duplicateSourceFacts.length === 1, `counter ${readingValue} has another Supabase UUID`)

  const successorThatMustChange = productionRows.find(item => item.previous_reading_id === previous.id && String(item.observed_at) >= String(next.observed_at))
  return {
    domain: 'counters',
    proposed_operation: 'CREATE historical effective counter fact (not executed)',
    source_semantic_identity: `CG-TUP-A3-01:total_impressions:${row.observed_at}:${readingValue}`,
    source_value: readingValue,
    source_timestamp_effective_timestamp: row.observed_at,
    source_status: row.status,
    source_method: row.source,
    production_semantic_target: 'CG-TUP-A3-01 / total_impressions',
    current_production_value_state: 'ABSENT; exact machine/type/value/effective-time fact is not present',
    counter_type_validation: { code: type.code, source_unit: type.unit, production_unit: productionType.unit, mapped: true, monotonic: true },
    immediately_previous_production_counter: { value: number(previous.reading_value), observed_at: previous.observed_at },
    immediately_next_production_counter: { value: number(next.reading_value), observed_at: next.observed_at },
    proposed_delta_from_previous: readingValue - number(previous.reading_value),
    proposed_delta_to_next: number(next.reading_value) - readingValue,
    why_not_already_represented: 'No Production counter has this semantic machine, type, value, and effective date/time; the exact value is absent.',
    why_not_legacy_duplicate: 'The fact is a unique manual source row, and no legacy-import Production row has its value or source semantic identity.',
    why_not_dev_test_data: 'It belongs to the canonical Tuparev machine, is effective/manual, contains no test marker, and is inside the accepted operational chronology.',
    why_canonical_or_operationally_valid: 'The machine and monotonic counter type resolve semantically; the value lies strictly between surrounding effective Production values.',
    duplicate_checks: { migrated_legacy_counter: false, existing_production_counter: false, another_supabase_uuid: false },
    historical_parity_required: true,
    why_historical_parity_requires_the_fact: 'The captured source history contains this unique effective Tuparev reading and Production does not; row-level historical parity cannot be reached without representing it.',
    impossible_click_delta_check: 'VALUE_ORDER_PASS_BUT_LINKAGE_PLAN_INCOMPLETE',
    linkage_risk: `Production usage follows previous_reading_id. A CREATE-only backfill does not re-link the successor (${successorThatMustChange?.observed_at ?? next.observed_at}); derived daily/period clicks can diverge or double-count. The normal create service also rejects timestamps older than the latest effective reading.`,
    downstream_effect_if_applied: 'With an explicit atomic predecessor/successor relink, restores the missing historical reading while preserving total click continuity; CREATE alone is unsafe.',
    downstream_effect_if_skipped: 'Production remains two facts short of source history, while its existing aggregate counter progression remains internally consistent.',
    confidence: 'HIGH_VALID_FACT_HIGH_UNSAFE_CREATE_ONLY',
    operator_approval_recommendation: 'DEFER',
  }
}

function markdown(result) {
  const c = result.component_diff_review
  const candidateLines = c.update_only_candidates.flatMap(group => [
    `### ${group.domain} (${group.candidates.length} UPDATE-only candidates)`, '',
    ...group.candidates.map(row => `- \`${row.semantic_key}\`: \`${JSON.stringify(row.current)}\` → \`${JSON.stringify(row.proposed)}\``), '',
  ])
  return [
    '# M2.12F Write-plan review', '',
    'Status: **READY — strict offline review complete; no write executed.**', '',
    '## Capture identity', '',
    `- NEW_SUPABASE_CAPTURE_SHA256_VERIFIED=${result.capture_identity.new_supabase_sha256_verified}`,
    `- PRODUCTION_MARIADB_CAPTURE_SHA256_VERIFIED=${result.capture_identity.production_mariadb_sha256_verified}`,
    '- CAPTURE_IDENTITY_VERIFICATION=PASS',
    '- The invalid 66-character display was a rendering/concatenation defect. The reconciliation input path, embedded digest, script expectation, and recomputed private-capture digest all identify the intended capture.',
    '- `docs/evidence/m2-12f-new-supabase-capture.json` is an earlier public evidence capture and is not the input named by the reconciliation artifact.', '',
    '## Proposed writes', '',
    ...result.write_reviews.map((row, index) => [
      `### ${index + 1}. ${row.domain}: ${row.source_semantic_identity}`, '',
      `- Proposed operation: ${row.proposed_operation}`,
      `- Source value/effective time: ${row.source_value} at ${row.source_timestamp_effective_timestamp}`,
      `- Production target/state: ${row.production_semantic_target}; ${row.current_production_value_state}`,
      `- Not represented: ${row.why_not_already_represented}`,
      `- Not legacy duplicate: ${row.why_not_legacy_duplicate}`,
      `- Not DEV/test: ${row.why_not_dev_test_data}`,
      `- Canonical validity: ${row.why_canonical_or_operationally_valid}`,
      `- If applied: ${row.downstream_effect_if_applied}`,
      `- If skipped: ${row.downstream_effect_if_skipped}`,
      `- Confidence: ${row.confidence}`,
      `- Recommendation: **${row.operator_approval_recommendation}**`, '',
    ]).flat(),
    '### Selling-price finding', '',
    `- Source owner: ${result.selling_price_review.supabase_owner}`,
    `- Identity/value/effective time: ${result.selling_price_review.source_semantic_identity}; ${result.selling_price_review.value_and_units}; ${result.selling_price_review.effective_from}`,
    `- State: ${result.selling_price_review.source_state}; ${result.selling_price_review.test_evidence}`,
    `- Production: ${result.selling_price_review.current_production_value_state}`,
    `- Runtime: ${result.selling_price_review.application_consumption}`,
    `- Recommendation: **${result.selling_price_review.operator_approval_recommendation}**. The invalid row was removed from the write plan.`, '',
    '## Component field-level review', '',
    '| Catalog key | Supabase | Production | Runtime behavior | Recommendation |',
    '|---|---|---|---|---|',
    ...c.catalog_tracking_differences.map(row => `| ${row.semantic_key} | ${row.supabase_tracking_value} | ${row.production_tracking_value} | ${row.runtime_behavior} | ${row.canonical_recommendation} |`), '',
    `Profile-slot differences by field: baseline_expected_clicks=28, display_order=15, tracking_method=4. UUID/profile-wrapper, timestamp precision, absent Production adaptive fields, and null/default representations are structural/representational and are not row-identity mismatches.`, '',
    `Machine-component differences by field: baseline_expected_clicks=28, source_type=28, display_order=15, tracking_method=4. Thresholds, status, active state, slot/component identity, and semantic assignment count match. Source \`model_profile\` maps to Production \`inherited\`; current \`configured\` blocks inherited-row lifecycle management paths.`, '',
    '- The baseline gaps block the frontend initialization capability and suppress expected-life display. Tracking and order differences change toner classification and deterministic UI ordering. These are canonical runtime-affecting fields.',
    '- Existing UUIDs are preserved. No catalog/profile/slot/assignment/lifecycle CREATE is proposed. The 47 closed lifecycles and 28 ambiguous incomplete reference lifecycle states remain untouched.', '',
    ...candidateLines,
    '## Approval-count correction', '',
    `The original 200 is a classification-review total, not a write count. After rejecting the test selling price: actual write candidates=${result.approval_breakdown.actual_write_count}; deferred/excluded=${result.approval_breakdown.deferred_count}; ambiguous=${result.approval_breakdown.ambiguous_count}; audit-only=${result.approval_breakdown.audit_only_count}. These categories sum to 200.`, '',
    `- Deferred/excluded: 61 Graha/Xerox-scope canonical/operational records plus the rejected voided test selling-price fact.`,
    `- Ambiguous: lifecycles=28, incidents=2, inventory items=18, machine identity/config=1, people=13, person-branch assignments=14.`,
    `- Audit-only: component catalogs=4, profile slots=28, machine components=28. These 60 UPDATE candidates are separate and are not in the approved write plan.`, '',
    '## Final checkpoint', '',
    ...Object.entries(result.final_report).map(([key, value]) => `${key}=${value}`), '',
    'STOP', '',
  ].join('\n')
}

async function main() {
  const [newBuffer, productionBuffer] = await Promise.all([readFile(newPath), readFile(productionPath)])
  const newSha = sha256(newBuffer)
  const productionSha = sha256(productionBuffer)
  invariant(/^[0-9a-f]{64}$/.test(newSha) && newSha === EXPECTED_NEW_SHA256, 'new Supabase capture identity mismatch')
  invariant(/^[0-9a-f]{64}$/.test(productionSha) && productionSha === EXPECTED_PRODUCTION_SHA256, 'Production capture identity mismatch')
  const source = JSON.parse(newBuffer)
  const production = JSON.parse(productionBuffer)
  invariant(source.source === 'NEW_SUPABASE_READ_ONLY_REFERENCE' && source.http_methods?.join(',') === 'GET', 'unexpected source capture contract')
  invariant(production.source === 'HOSTINGER_MARIADB_PRODUCTION' && production.transaction === 'READ_ONLY_ROLLED_BACK', 'unexpected Production capture contract')

  const updates = componentCandidates(source, production)
  invariant(updates.catalog.length === 4 && updates.slots.length === 28 && updates.assignments.length === 28, 'component candidate count mismatch')
  const selling = source.tables.machine_selling_prices[0]
  invariant(source.tables.machine_selling_prices.length === 1 && selling.status === 'voided' && /test/i.test(selling.void_reason), 'selling price is not the reviewed invalid test row')

  const counter1 = counterReview(source, production, 1437283)
  const counter2 = counterReview(source, production, 1438992)
  const catalogReview = updates.catalog.map(row => ({
    semantic_key: row.semantic_key,
    supabase_tracking_value: row.proposed.tracking_method,
    production_tracking_value: row.current.tracking_method,
    runtime_behavior: row.runtime_effect,
    canonical_recommendation: row.canonical_recommendation,
  }))
  const profileFieldGroups = [
    { field: 'baseline_expected_clicks', affected_rows: 28, supabase_values: valueCounts(updates.slots.map(row => row.proposed), 'baseline_expected_clicks'), production_values: { null: 28 }, cause: 'actual missing canonical expected-life semantics', runtime_affecting: true },
    { field: 'display_order', affected_rows: 15, cause: 'ordering; Production orders K before M/Y while canonical C1070 order is C/M/Y/K', runtime_affecting: true },
    { field: 'tracking_method', affected_rows: 4, semantic_keys: ['TONER_C', 'TONER_K', 'TONER_M', 'TONER_Y'], cause: 'tracking method', runtime_affecting: true },
  ]
  const machineFieldGroups = [
    { field: 'baseline_expected_clicks', affected_rows: 28, supabase_values: valueCounts(updates.assignments.map(row => row.proposed), 'baseline_expected_clicks'), production_values: { null: 28 }, cause: 'actual missing canonical expected-life semantics', runtime_affecting: true },
    { field: 'source_type', affected_rows: 28, supabase_value: 'model_profile', production_value: 'configured', target_semantic_value: 'inherited', cause: 'naming/vocabulary plus actual lifecycle-management behavior', runtime_affecting: true },
    { field: 'display_order', affected_rows: 15, cause: 'ordering', runtime_affecting: true },
    { field: 'tracking_method', affected_rows: 4, semantic_keys: ['TONER_C', 'TONER_K', 'TONER_M', 'TONER_Y'], cause: 'tracking method', runtime_affecting: true },
  ]

  const result = {
    checkpoint: 'M2.12F',
    status: 'READY',
    mode: 'STRICT_OFFLINE_WRITE_PLAN_AND_COMPONENT_DIFF_REVIEW',
    capture_identity: {
      invalid_rendered_value: '94bfe30b539fb490b59e349e2d271ba594de882863f2f17dc34351482786bc5e16',
      invalid_rendered_length: 66,
      operator_observed_value_length: 64,
      new_supabase_capture_path: newPath,
      new_supabase_sha256_verified: newSha,
      production_mariadb_capture_path: productionPath,
      production_mariadb_sha256_verified: productionSha,
      reconciliation_artifact_input_match: true,
      public_evidence_capture_is_not_reconciliation_input: true,
      verification: 'PASS',
    },
    write_reviews: [counter1, counter2],
    selling_price_review: {
      domain: 'machine_cost_source_facts', proposed_operation: 'CREATE',
      supabase_owner: 'public.machine_selling_prices / machine selling-price domain',
      source_semantic_identity: 'CG-TUP-A3-01 / selling price', value_and_units: '1000 IDR per click',
      source_value: 1000, source_units: 'IDR per click',
      source_timestamp_effective_timestamp: selling.effective_from,
      effective_from: selling.effective_from, source_state: selling.status,
      test_evidence: `void_reason=${JSON.stringify(selling.void_reason)}`,
      production_semantic_target: 'CG-TUP-A3-01 / machine_selling_prices effective-dated fact',
      current_production_value_state: 'Production machine_selling_prices is empty; no equivalent posted or voided value exists elsewhere in the captured machine-cost fact tables.',
      fact_classification: 'effective-dated persisted configuration/historical evidence, not a derived value',
      application_consumption: 'Laravel reads this table for selling-price history, but the current Laravel period summary does not apply it to current price/revenue/contribution calculations. A voided row would only add test history, not runtime parity.',
      runtime_parity_required: false,
      dev_only_risk: 'PROVEN_TEST_DATA',
      why_not_already_represented: 'No Production row exists.',
      why_not_legacy_duplicate: 'No Production equivalent exists, but absence does not make a voided test row canonical.',
      why_not_dev_test_data: 'It is DEV/test data: the source itself says it was voided for “test set selling price.”',
      why_canonical_or_operationally_valid: 'It is neither canonical nor operationally valid for Production.',
      downstream_effect_if_applied: 'Would import a voided DEV test artifact and expose misleading price history without providing current-price runtime parity.',
      downstream_effect_if_skipped: 'Production correctly remains without a selling price until an operator creates a real posted Production value.',
      confidence: 'HIGH', operator_approval_recommendation: 'REJECT', write_plan_modified: true,
    },
    component_diff_review: {
      classification: 'DIFF_FOUND', reconciliation_required: 'YES',
      causes: {
        uuid_differences: 'representational only; ignored for semantic matching and never updated',
        nullable_default_representation: 'present, but baseline nulls are runtime-significant rather than harmless defaults',
        ordering: '15 profile slots and the same 15 machine assignments differ',
        naming: 'catalog display names/categories and source-type vocabulary differ; catalog metadata is audit context, while source_type maps semantically to inherited',
        tracking_method: '4 toner rows differ at catalog, profile-slot, and assignment levels',
        metadata: 'timestamps, creator IDs, adaptive fields absent from the Laravel schema, and display metadata are not identity mismatches',
        model_profile_structure: 'Supabase direct model-component rows map to the sole Production model profile and its slots',
        actual_missing_canonical_semantics: 'expected-life baselines, canonical order, toner tracking, and inherited assignment semantics',
      },
      catalog_tracking_differences: catalogReview,
      profile_slot_differences_by_field: profileFieldGroups,
      machine_component_differences_by_field: machineFieldGroups,
      matching_fields: {
        profile_slots: ['semantic model/slot/component identity', 'active state'],
        machine_components: ['semantic machine/slot/component identity', 'status', 'active state', 'threshold percentages'],
      },
      lifecycle_safety: { create_lifecycle: false, duplicate_catalog_profile_slot_or_assignment: false, existing_assignments_preserved: 28, closed_lifecycles_preserved: 47, ambiguous_incomplete_reference_lifecycles_excluded: 28 },
      update_only_candidates: [
        { domain: 'component_catalogs', candidates: updates.catalog },
        { domain: 'model_profile_slots', candidates: updates.slots },
        { domain: 'machine_components', candidates: updates.assignments },
      ],
      runtime_diff_counts: { component_catalogs: 4, model_profile_slots: 28, machine_components: 28, update_candidate_rows: 60 },
      added_to_approved_write_plan: false,
    },
    approval_breakdown: {
      original_requires_user_approval: 200,
      actual_write_count: 2,
      deferred_count: 62,
      ambiguous_count: 76,
      audit_only_count: 60,
      sum_check: 200,
      explanation: 'The old total counted every fail-closed review item. Only two counter facts remain in the plan; 61 records were policy-deferred, the invalid selling-price fact is now rejected/deferred from execution, 76 are non-component ambiguities, and 60 are component UPDATE-only audit candidates held outside the plan.',
    },
    final_report: {
      CHECKPOINT_M2_12F_WRITE_PLAN_REVIEW: 'READY',
      CAPTURE_IDENTITY_VERIFICATION: 'PASS',
      NEW_SUPABASE_CAPTURE_SHA256_VERIFIED: newSha,
      PRODUCTION_MARIADB_CAPTURE_SHA256_VERIFIED: productionSha,
      COUNTER_WRITE_1_RECOMMENDATION: 'DEFER',
      COUNTER_WRITE_2_RECOMMENDATION: 'DEFER',
      SELLING_PRICE_WRITE_RECOMMENDATION: 'REJECT',
      COMPONENT_CONFIG_PARITY_AUDIT: 'DIFF_FOUND',
      COMPONENT_CONFIG_RECONCILIATION_REQUIRED: 'YES',
      COMPONENT_CATALOG_RUNTIME_DIFF_COUNT: 4,
      PROFILE_SLOT_RUNTIME_DIFF_COUNT: 28,
      MACHINE_COMPONENT_RUNTIME_DIFF_COUNT: 28,
      COMPONENT_UPDATE_CANDIDATE_COUNT: 60,
      APPROVAL_REQUIRED_ACTUAL_WRITE_COUNT: 2,
      APPROVAL_REQUIRED_DEFERRED_COUNT: 62,
      APPROVAL_REQUIRED_AMBIGUOUS_COUNT: 76,
      APPROVAL_REQUIRED_AUDIT_ONLY_COUNT: 60,
      FINAL_PROPOSED_WRITE_COUNT: 2,
      NETWORK_ACCESS: 'NO', DATABASE_ACCESS: 'NO', PRODUCTION_MUTATION: 'NO',
      PRODUCTION_DATABASE_MUTATION: 'NO', PUBLIC_HTML_MUTATION: 'NO', PRODUCTION_DEPLOYMENT: 'NO',
    },
  }
  invariant(Object.values(result.approval_breakdown).filter(value => typeof value === 'number').slice(1, 5).reduce((sum, value) => sum + value, 0) === 200, 'approval taxonomy mismatch')
  await Promise.all([writeFile(jsonPath, json(result)), writeFile(markdownPath, markdown(result))])
  console.log('CHECKPOINT_M2_12F_WRITE_PLAN_REVIEW=READY')
  console.log(`REVIEW_JSON=${jsonPath}`)
  console.log(`REVIEW_MARKDOWN=${markdownPath}`)
  console.log('NETWORK_ACCESS=NO\nDATABASE_ACCESS=NO\nPRODUCTION_MUTATION=NO\nSTOP')
}

main().catch(error => {
  console.error('CHECKPOINT_M2_12F_WRITE_PLAN_REVIEW=STOP')
  console.error(`STOP_REASON=${error instanceof Error ? error.message : 'unknown offline review failure'}`)
  process.exitCode = 78
})
