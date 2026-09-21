// V1.7.2 - Knowledge Review Consolidation: pure, dependency-free logic shared by the
// code-group panel, the group detail dialog and the bulk triage flow. Kept free of
// React/JSX so it is unit-testable with node:test (this codebase has no component
// rendering harness) and so the criteria vocabulary lives in exactly one place.
//
// Evidence here is DETECTOR evidence (HIGH/MEDIUM/LOW), never "verified" or "correct",
// and a code group's review state is DERIVED by the backend from candidate rows - the
// frontend never invents a state and never sends one.

export const EVIDENCE_LABELS = { HIGH: 'High', MEDIUM: 'Medium', LOW: 'Low' }

export const BEST_EVIDENCE_LABELS = { HIGH: 'High', MEDIUM: 'Medium', LOW: 'Low', STRONG: 'High or medium' }

export const REVIEW_STATE_LABELS = {
  UNREVIEWED: 'Unreviewed',
  PARTIALLY_REVIEWED: 'Partially reviewed',
  REJECTED: 'Rejected',
  APPROVED: 'Approved',
  PUBLISHED: 'Published',
}

export const REVIEW_STATE_PILL_CLASS = { UNREVIEWED: '', PARTIALLY_REVIEWED: '', REJECTED: 'voided', APPROVED: 'resolved', PUBLISHED: 'resolved' }

export const REFERENCE_LIKE_LABELS = { yes: 'Reference-like only', no: 'Not reference-like' }

export const PER_PAGE_OPTIONS = [10, 25, 50]
export const DEFAULT_PER_PAGE = 25

// Above this many eligible candidates the confirmation additionally needs an explicit
// acknowledgement, so one accidental click can never reject hundreds of rows.
export const ACKNOWLEDGE_THRESHOLD = 100

export const EMPTY_FILTERS = Object.freeze({
  code: '', evidence: '', collisionStatus: '', status: '', bestEvidence: '', reviewState: '',
  pageFrom: '', pageTo: '', referenceLike: '', minOccurrences: '', maxOccurrences: '',
})

// Named, transparent presets - each is just a set of ordinary filters the reviewer can see and edit.
export const FILTER_PRESETS = [
  { id: 'all', label: 'All codes', hint: 'Every distinct code in this import.', filters: {} },
  { id: 'strong', label: 'Strong evidence', hint: 'Best evidence is High or Medium. Detector evidence, not verification.', filters: { bestEvidence: 'STRONG' } },
  { id: 'low_only', label: 'Low-only', hint: 'Codes whose strongest evidence is Low. Not necessarily invalid.', filters: { bestEvidence: 'LOW' } },
  { id: 'reference_like', label: 'Reference-like', hint: 'Occurrences with a dotted-leader (table-of-contents / index style) signal.', filters: { referenceLike: 'yes' } },
  { id: 'remaining', label: 'Not yet reviewed', hint: 'Every occurrence is still a draft.', filters: { reviewState: 'UNREVIEWED' } },
]

function toInt(value) {
  if (value === '' || value === null || value === undefined) return null
  const n = Number(value)
  return Number.isInteger(n) && n >= 1 ? n : null
}

/** The query string for GET .../code-groups. Empty/invalid criteria are dropped, never sent. */
export function buildCodeGroupQuery(filters = {}, { page = 1, perPage = DEFAULT_PER_PAGE, sort, direction } = {}) {
  const f = { ...EMPTY_FILTERS, ...filters }
  const params = new URLSearchParams()
  const code = String(f.code ?? '').trim()
  if (code) params.set('code', code)
  if (f.evidence) params.set('evidence', f.evidence)
  if (f.collisionStatus) params.set('collision_status', f.collisionStatus)
  if (f.status) params.set('status', f.status)
  if (f.bestEvidence) params.set('best_evidence', f.bestEvidence)
  if (f.reviewState) params.set('review_state', f.reviewState)
  if (f.referenceLike) params.set('reference_like', f.referenceLike)
  const pageFrom = toInt(f.pageFrom)
  const pageTo = toInt(f.pageTo)
  const minOcc = toInt(f.minOccurrences)
  const maxOcc = toInt(f.maxOccurrences)
  if (pageFrom) params.set('source_page_from', pageFrom)
  if (pageTo) params.set('source_page_to', pageTo)
  if (minOcc) params.set('min_occurrences', minOcc)
  if (maxOcc) params.set('max_occurrences', maxOcc)
  if (PER_PAGE_OPTIONS.includes(Number(perPage)) && Number(perPage) !== DEFAULT_PER_PAGE) params.set('per_page', perPage)
  if (page > 1) params.set('page', page)
  if (sort) params.set('sort', sort)
  if (direction) params.set('direction', direction)
  return params.toString()
}

/** Inverted page or occurrence ranges are caught before a request is made. */
export function filterValidationError(filters = {}) {
  const f = { ...EMPTY_FILTERS, ...filters }
  const from = toInt(f.pageFrom); const to = toInt(f.pageTo)
  if (from && to && to < from) return 'The last page must not be before the first page.'
  const min = toInt(f.minOccurrences); const max = toInt(f.maxOccurrences)
  if (min && max && max < min) return 'The maximum occurrences must not be below the minimum.'
  return null
}

/**
 * The criteria a bulk triage request may carry (API vocabulary). Only row-level criteria and
 * best_evidence exist for bulk: review state, occurrence counts and the status filter describe
 * groups or the current review state, so they are never sent as a bulk criterion.
 */
export function toBulkFilters(filters = {}) {
  const f = { ...EMPTY_FILTERS, ...filters }
  const out = {}
  const code = String(f.code ?? '').trim()
  if (code) out.code = code
  if (f.evidence) out.evidence = f.evidence
  if (f.collisionStatus) out.collision_status = f.collisionStatus
  if (f.referenceLike) out.reference_like = f.referenceLike
  if (f.bestEvidence) out.best_evidence = f.bestEvidence
  const from = toInt(f.pageFrom); const to = toInt(f.pageTo)
  if (from) out.source_page_from = from
  if (to) out.source_page_to = to
  return out
}

/** Bulk triage needs at least one narrowing criterion - the server refuses "the whole import". */
export function hasBulkCriteria(filters = {}) {
  return Object.keys(toBulkFilters(filters)).length > 0
}

/** Active filters that describe groups / current state and therefore do NOT define a bulk selection. */
export function filtersIgnoredByBulk(filters = {}) {
  const f = { ...EMPTY_FILTERS, ...filters }
  const ignored = []
  if (f.status) ignored.push('Review status')
  if (f.reviewState) ignored.push('Group review state')
  if (toInt(f.minOccurrences) || toInt(f.maxOccurrences)) ignored.push('Occurrence count')
  return ignored
}

/** Human-readable chips for an API-vocabulary bulk filter object. */
export function describeBulkFilters(bulk = {}) {
  const chips = []
  if (bulk.code) chips.push(`Code contains ${bulk.code}`)
  if (bulk.evidence) chips.push(`Evidence: ${EVIDENCE_LABELS[bulk.evidence] ?? bulk.evidence}`)
  if (bulk.best_evidence) chips.push(`Best evidence: ${BEST_EVIDENCE_LABELS[bulk.best_evidence] ?? bulk.best_evidence}`)
  if (bulk.collision_status) chips.push(`Collision: ${bulk.collision_status}`)
  if (bulk.source_page_from && bulk.source_page_to) chips.push(`Pages ${bulk.source_page_from}–${bulk.source_page_to}`)
  else if (bulk.source_page_from) chips.push(`From page ${bulk.source_page_from}`)
  else if (bulk.source_page_to) chips.push(`Up to page ${bulk.source_page_to}`)
  if (bulk.reference_like) chips.push(REFERENCE_LIKE_LABELS[bulk.reference_like] ?? `Reference-like: ${bulk.reference_like}`)
  return chips
}

export function evidenceMix(group) {
  const c = group?.evidence_counts ?? {}
  return `H ${c.HIGH ?? 0} · M ${c.MEDIUM ?? 0} · L ${c.LOW ?? 0}`
}

export function occurrenceLabel(count) {
  return `${count} occurrence${count === 1 ? '' : 's'}`
}

export function pageRangeLabel(group) {
  const min = group?.source_page_min
  const max = group?.source_page_max
  if (min == null) return '—'
  return min === max ? `p${min}` : `p${min}–${max}`
}

/** The bounded page list the API returns; an ellipsis marks that more pages exist in the group detail. */
export function sourcePagesLabel(group) {
  const pages = group?.source_pages ?? []
  if (pages.length === 0) return '—'
  return `${pages.map((p) => `p${p}`).join(', ')}${group.source_pages_truncated ? ', …' : ''}`
}

export function occurrencePageLabel(occurrence) {
  const start = occurrence?.source_page_start
  const end = occurrence?.source_page_end
  if (start == null) return occurrence?.page_reference ? `Page ${occurrence.page_reference}` : 'Page unknown'
  return start === end || end == null ? `Page ${start}` : `Pages ${start}–${end}`
}

/** Reviewed / total distinct codes as shown in the summary; both figures come straight from the backend. */
export function reviewProgress(summary) {
  if (!summary) return null
  const total = summary.distinct_codes ?? 0
  return { reviewed: summary.codes_reviewed ?? 0, remaining: summary.codes_remaining ?? 0, total }
}

/** Distinguishes the failures the triage UI must word differently. Keyed off status/field, never message text. */
export function classifyBulkError(error) {
  if (error?.status === 409) {
    return { kind: 'STALE', message: 'The candidates changed since the preview, so nothing was changed. Preview again to see the current numbers.' }
  }
  if (error?.status === 403) {
    return { kind: 'FORBIDDEN', message: 'Your role is not authorized to review candidates in bulk.' }
  }
  if (error?.status === 422) {
    if (error.errors?.confirmation_token) return { kind: 'INVALID', message: 'The confirmation is no longer valid. Preview again before applying.' }
    if (error.errors?.filters || Object.keys(error.errors ?? {}).some((k) => k.startsWith('filters.'))) {
      return { kind: 'FILTERS', message: 'Choose at least one valid filter before previewing.' }
    }
    return { kind: 'FILTERS', message: 'Check the filters and try again.' }
  }
  return { kind: 'OTHER', message: 'The request could not be completed. Please try again.' }
}

export function needsAcknowledgement(eligibleCount) {
  return Number(eligibleCount) > ACKNOWLEDGE_THRESHOLD
}

/** The apply button is only ever live for a fresh, applicable preview - and, for a large set, after an explicit acknowledgement. */
export function canConfirmBulk({ preview, acknowledged = false, isApplying = false }) {
  if (isApplying || !preview || !preview.can_apply || !preview.confirmation_token) return false
  if (!(preview.eligible_count > 0)) return false
  if (needsAcknowledgement(preview.eligible_count) && !acknowledged) return false
  return true
}

/** A preview describes exactly the criteria it was made for; any change to them makes it stale on screen too. */
export function previewMatchesFilters(preview, bulkFilters, action) {
  if (!preview || preview.action !== action) return false
  const a = preview.filters ?? {}
  const b = bulkFilters ?? {}
  const norm = (o) => Object.keys(o).sort().map((k) => `${k}=${String(o[k]).toUpperCase()}`).join('&')
  return norm(a) === norm(b)
}

export function blockedReasonMessage(reason) {
  if (reason === 'NOTHING_ELIGIBLE') return 'No candidates match these filters in the state this action needs, so there is nothing to change.'
  if (reason === 'TOO_MANY_CANDIDATES') return 'Too many candidates match for one action. Narrow the filters (for example a smaller page range) and preview again.'
  return null
}
