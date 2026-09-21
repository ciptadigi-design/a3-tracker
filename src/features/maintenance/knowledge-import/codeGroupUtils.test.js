import assert from 'node:assert/strict'
import test from 'node:test'
import {
  ACKNOWLEDGE_THRESHOLD, EMPTY_FILTERS, FILTER_PRESETS, blockedReasonMessage, buildCodeGroupQuery, canConfirmBulk, classifyBulkError, describeBulkFilters, evidenceMix,
  filterValidationError, filtersIgnoredByBulk, hasBulkCriteria, needsAcknowledgement, occurrenceLabel, occurrencePageLabel, pageRangeLabel, previewMatchesFilters, reviewProgress,
  sourcePagesLabel, toBulkFilters,
} from './codeGroupUtils.js'

// V1.7.2 - behavior tests for the pure review-consolidation logic (no rendering harness needed).

// --- code-group list query ---

test('an untouched filter set produces an empty query (defaults are never sent)', () => {
  assert.equal(buildCodeGroupQuery({ ...EMPTY_FILTERS }), '')
})

test('every filter maps to its API parameter, integers only, empties dropped', () => {
  const query = new URLSearchParams(buildCodeGroupQuery({
    ...EMPTY_FILTERS, code: ' c-31 ', evidence: 'LOW', collisionStatus: 'NEW', status: 'DRAFT', bestEvidence: 'STRONG', reviewState: 'UNREVIEWED',
    pageFrom: '30', pageTo: '38', referenceLike: 'yes', minOccurrences: '2', maxOccurrences: '9',
  }, { page: 3, perPage: 50, sort: 'page', direction: 'asc' }))
  assert.deepEqual(Object.fromEntries(query), {
    code: 'c-31', evidence: 'LOW', collision_status: 'NEW', status: 'DRAFT', best_evidence: 'STRONG', review_state: 'UNREVIEWED',
    source_page_from: '30', source_page_to: '38', reference_like: 'yes', min_occurrences: '2', max_occurrences: '9', per_page: '50', page: '3', sort: 'page', direction: 'asc',
  })
})

test('non-numeric or non-positive page and occurrence inputs are dropped rather than sent', () => {
  const query = new URLSearchParams(buildCodeGroupQuery({ ...EMPTY_FILTERS, pageFrom: 'abc', pageTo: '-4', minOccurrences: '0', maxOccurrences: '1.5' }))
  assert.equal(query.toString(), '')
})

test('the default page size and the first page are not serialised; only the supported sizes are', () => {
  assert.equal(buildCodeGroupQuery({}, { page: 1, perPage: 25 }), '')
  assert.equal(new URLSearchParams(buildCodeGroupQuery({}, { perPage: 10 })).get('per_page'), '10')
  assert.equal(new URLSearchParams(buildCodeGroupQuery({}, { perPage: 20 })).get('per_page'), null)
})

test('inverted page and occurrence ranges are caught before any request', () => {
  assert.match(filterValidationError({ pageFrom: '50', pageTo: '10' }), /last page/)
  assert.match(filterValidationError({ minOccurrences: '5', maxOccurrences: '2' }), /maximum occurrences/)
  assert.equal(filterValidationError({ pageFrom: '10', pageTo: '50' }), null)
  assert.equal(filterValidationError({ pageTo: '10' }), null)
})

// --- presets ---

test('the strong-evidence preset is a plain best-evidence filter and is never labelled verified or correct', () => {
  const strong = FILTER_PRESETS.find((p) => p.id === 'strong')
  assert.deepEqual(strong.filters, { bestEvidence: 'STRONG' })
  for (const preset of FILTER_PRESETS) assert.doesNotMatch(`${preset.label} ${preset.hint}`, /\b(verified|correct|valid)\b/i)
  assert.match(strong.hint, /not verification/i)
})

test('the low-only and reference-like presets reproduce the known clusters through ordinary filters, with no hard-coded pages', () => {
  const lowOnly = FILTER_PRESETS.find((p) => p.id === 'low_only')
  const ref = FILTER_PRESETS.find((p) => p.id === 'reference_like')
  assert.deepEqual(lowOnly.filters, { bestEvidence: 'LOW' })
  assert.deepEqual(ref.filters, { referenceLike: 'yes' })
  for (const preset of FILTER_PRESETS) assert.equal(JSON.stringify(preset.filters).includes('page'), false)
})

// --- bulk criteria ---

test('only row-level criteria and best evidence become bulk filters; states and counts never do', () => {
  const bulk = toBulkFilters({ ...EMPTY_FILTERS, code: 'c-1', evidence: 'LOW', collisionStatus: 'NEW', referenceLike: 'yes', bestEvidence: 'LOW', pageFrom: '30', pageTo: '38', status: 'DRAFT', reviewState: 'UNREVIEWED', minOccurrences: '2' })
  assert.deepEqual(bulk, { code: 'c-1', evidence: 'LOW', collision_status: 'NEW', reference_like: 'yes', best_evidence: 'LOW', source_page_from: 30, source_page_to: 38 })
  assert.equal('status' in bulk, false)
  assert.equal('review_state' in bulk, false)
  assert.equal('min_occurrences' in bulk, false)
})

test('bulk triage needs at least one narrowing criterion', () => {
  assert.equal(hasBulkCriteria({ ...EMPTY_FILTERS }), false)
  assert.equal(hasBulkCriteria({ ...EMPTY_FILTERS, status: 'DRAFT', reviewState: 'UNREVIEWED' }), false)
  assert.equal(hasBulkCriteria({ ...EMPTY_FILTERS, evidence: 'LOW' }), true)
  assert.equal(hasBulkCriteria({ ...EMPTY_FILTERS, pageFrom: '30' }), true)
  assert.equal(hasBulkCriteria({ ...EMPTY_FILTERS, bestEvidence: 'LOW' }), true)
})

test('active filters that do not define a bulk selection are named so the reviewer is not misled', () => {
  assert.deepEqual(filtersIgnoredByBulk({ ...EMPTY_FILTERS, status: 'DRAFT', reviewState: 'PARTIALLY_REVIEWED', maxOccurrences: '3' }), ['Review status', 'Group review state', 'Occurrence count'])
  assert.deepEqual(filtersIgnoredByBulk({ ...EMPTY_FILTERS, evidence: 'LOW' }), [])
})

test('bulk filter chips describe exactly what will be acted on', () => {
  assert.deepEqual(describeBulkFilters({ evidence: 'LOW', source_page_from: 30, source_page_to: 38, reference_like: 'yes' }), ['Evidence: Low', 'Pages 30–38', 'Reference-like only'])
  assert.deepEqual(describeBulkFilters({ best_evidence: 'STRONG', source_page_from: 900 }), ['Best evidence: High or medium', 'From page 900'])
  assert.deepEqual(describeBulkFilters({}), [])
})

// --- group display helpers ---

test('evidence mix, occurrences and page range come straight from backend values', () => {
  const group = { evidence_counts: { HIGH: 3, MEDIUM: 4, LOW: 1 }, source_page_min: 1320, source_page_max: 1328, source_pages: [1320, 1324, 1328], source_pages_truncated: false }
  assert.equal(evidenceMix(group), 'H 3 · M 4 · L 1')
  assert.equal(pageRangeLabel(group), 'p1320–1328')
  assert.equal(pageRangeLabel({ source_page_min: 12, source_page_max: 12 }), 'p12')
  assert.equal(pageRangeLabel({ source_page_min: null }), '—')
  assert.equal(sourcePagesLabel(group), 'p1320, p1324, p1328')
  assert.equal(sourcePagesLabel({ ...group, source_pages_truncated: true }), 'p1320, p1324, p1328, …')
  assert.equal(occurrenceLabel(1), '1 occurrence')
  assert.equal(occurrenceLabel(8), '8 occurrences')
})

test('occurrence provenance keeps single-page, two-page and unknown pages distinct', () => {
  assert.equal(occurrencePageLabel({ source_page_start: 33, source_page_end: 33 }), 'Page 33')
  assert.equal(occurrencePageLabel({ source_page_start: 1541, source_page_end: 1542 }), 'Pages 1541–1542')
  assert.equal(occurrencePageLabel({ source_page_start: null, page_reference: '12-13' }), 'Page 12-13')
  assert.equal(occurrencePageLabel({ source_page_start: null }), 'Page unknown')
})

test('review progress reports backend figures and never invents a number', () => {
  assert.deepEqual(reviewProgress({ distinct_codes: 705, codes_reviewed: 5, codes_remaining: 700 }), { reviewed: 5, remaining: 700, total: 705 })
  assert.equal(reviewProgress(null), null)
})

// --- triage confirmation gating ---

const readyPreview = { action: 'reject', can_apply: true, confirmation_token: 'a.b', eligible_count: 40, filters: { evidence: 'LOW' } }

test('the apply button is live only for a fresh, applicable preview', () => {
  assert.equal(canConfirmBulk({ preview: readyPreview }), true)
  assert.equal(canConfirmBulk({ preview: null }), false)
  assert.equal(canConfirmBulk({ preview: { ...readyPreview, can_apply: false } }), false)
  assert.equal(canConfirmBulk({ preview: { ...readyPreview, confirmation_token: null } }), false)
  assert.equal(canConfirmBulk({ preview: { ...readyPreview, eligible_count: 0 } }), false)
  assert.equal(canConfirmBulk({ preview: readyPreview, isApplying: true }), false)
})

test('a large set additionally needs an explicit acknowledgement', () => {
  const big = { ...readyPreview, eligible_count: ACKNOWLEDGE_THRESHOLD + 1 }
  assert.equal(needsAcknowledgement(ACKNOWLEDGE_THRESHOLD), false)
  assert.equal(needsAcknowledgement(ACKNOWLEDGE_THRESHOLD + 1), true)
  assert.equal(canConfirmBulk({ preview: big }), false)
  assert.equal(canConfirmBulk({ preview: big, acknowledged: true }), true)
  assert.equal(canConfirmBulk({ preview: { ...readyPreview, eligible_count: ACKNOWLEDGE_THRESHOLD } }), true)
})

test('a preview only counts for the exact action and criteria it was made for', () => {
  assert.equal(previewMatchesFilters(readyPreview, { evidence: 'low' }, 'reject'), true)
  assert.equal(previewMatchesFilters(readyPreview, { evidence: 'LOW', source_page_from: 30 }, 'reject'), false)
  assert.equal(previewMatchesFilters(readyPreview, { evidence: 'LOW' }, 'restore'), false)
  assert.equal(previewMatchesFilters(null, {}, 'reject'), false)
})

test('blocked previews explain themselves without blaming the reviewer', () => {
  assert.match(blockedReasonMessage('NOTHING_ELIGIBLE'), /nothing to change/)
  assert.match(blockedReasonMessage('TOO_MANY_CANDIDATES'), /Narrow the filters/)
  assert.equal(blockedReasonMessage(null), null)
})

// --- error classification (stale-preview UX) ---

test('a 409 on apply is a stale preview with a clear no-change message', () => {
  const e = classifyBulkError({ status: 409, errors: {} })
  assert.equal(e.kind, 'STALE')
  assert.match(e.message, /nothing was changed/i)
  assert.match(e.message, /Preview again/)
})

test('an invalid confirmation, a missing filter and a forbidden role are each worded differently', () => {
  assert.equal(classifyBulkError({ status: 422, errors: { confirmation_token: ['x'] } }).kind, 'INVALID')
  assert.equal(classifyBulkError({ status: 422, errors: { filters: ['x'] } }).kind, 'FILTERS')
  assert.equal(classifyBulkError({ status: 422, errors: { 'filters.source_page_to': ['x'] } }).kind, 'FILTERS')
  assert.equal(classifyBulkError({ status: 403 }).kind, 'FORBIDDEN')
  assert.equal(classifyBulkError({ status: 500 }).kind, 'OTHER')
  assert.equal(classifyBulkError(undefined).kind, 'OTHER')
})
