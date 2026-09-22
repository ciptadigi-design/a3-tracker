// V1.8 / V1.8.1 - Group-aware canonical review, single-code publish, and technician SOLUTION VARIANTS:
// pure, dependency-free logic shared by the group detail editor and the publish confirmation dialog.
// Kept free of React so it is unit-testable with node:test. The backend is authoritative for
// everything that matters (placeholder guard, collision, supporting pages, solution-variant
// identity, idempotency); this module only mirrors the checks needed to give the reviewer immediate
// feedback and to gate the buttons - a passing client check never replaces the server's own
// validation.
//
// V1.8.1: a single error code can have more than one technician procedure, each applying to a
// different accessory/hardware/model context (a real, recurring pattern in the source manuals, not
// a one-off). A draft's `solutions` is a small ordered list of { applicabilityLabel, instruction }
// - applicabilityLabel is optional ("General" when blank) - rather than one fixed textarea.

export const PLACEHOLDER_TITLE_MESSAGE = 'Review and replace the placeholder title before publishing.'

const EMPTY_TITLE_WORDS = ['', 'errorcode', 'errorcodes', 'error', 'code', 'codes', 'untitled', 'title', 'todo', 'tbd', 'na']

/**
 * Mirrors backend PlaceholderTitle: strip every error-code token (C-3102, C3102, "C - 3102"), then everything that
 * is not a letter or digit; a title is a placeholder only when nothing informative is left. A human title that
 * merely CONTAINS the code ("C-3102 Fuser temperature abnormal") is not a placeholder.
 */
export function isPlaceholderTitle(title) {
  const t = String(title ?? '').trim().toLowerCase()
  if (!t) return true
  const stripped = t.replace(/\bc[\s-]*\d{4}\b/gu, ' ').replace(/[^a-z0-9]+/gu, '')
  return EMPTY_TITLE_WORDS.includes(stripped)
}

/** One repeatable solution-list row. applicabilityLabel blank means "General" (unlabelled, the pre-variant default). */
export const EMPTY_SOLUTION = Object.freeze({ applicabilityLabel: '', instruction: '' })

export const EMPTY_DRAFT = Object.freeze({ title: '', description: '', operatorGuidance: '', solutions: [] })

export const DRAFT_LIMITS = { title: 200, description: 10000, operatorGuidance: 10000, applicabilityLabel: 160, instruction: 10000 }

export const MAX_SOLUTIONS = 20

const clean = (value) => String(value ?? '').replace(/\r\n?/g, '\n').trim()

/** Non-blank solution rows only - a trailing empty "Add another" row is never a real proposal. */
function realSolutions(draft) {
  return (draft?.solutions ?? []).filter((s) => clean(s?.instruction) !== '')
}

export function isDraftEmpty(draft) {
  const hasSharedContent = [draft?.title, draft?.description, draft?.operatorGuidance].some((v) => clean(v) !== '')

  return !hasSharedContent && realSolutions(draft).length === 0
}

/** Client-side mirror of the server's input rules. Returns {} when the draft may be sent for a preview. */
export function validateDraft(draft, canonicalId) {
  const errors = {}
  if (!canonicalId) errors.canonical = 'Choose the canonical occurrence first.'
  const title = clean(draft?.title)
  if (isPlaceholderTitle(title)) errors.title = PLACEHOLDER_TITLE_MESSAGE
  else if (title.length > DRAFT_LIMITS.title) errors.title = `The title must be at most ${DRAFT_LIMITS.title} characters.`
  const solutions = realSolutions(draft)
  if (!clean(draft?.description) && !clean(draft?.operatorGuidance) && solutions.length === 0) {
    errors.content = 'Add at least a description, operator guidance or a technician solution.'
  }
  for (const key of ['description', 'operatorGuidance']) {
    if (clean(draft?.[key]).length > DRAFT_LIMITS[key]) errors[key] = `Too long (maximum ${DRAFT_LIMITS[key]} characters).`
  }
  if (solutions.length > MAX_SOLUTIONS) errors.solutions = `At most ${MAX_SOLUTIONS} technician solutions are allowed.`
  const seenLabels = new Set()
  const solutionErrors = solutions.map((s) => {
    const rowErrors = {}
    const label = clean(s.applicabilityLabel)
    if (label.length > DRAFT_LIMITS.applicabilityLabel) rowErrors.applicabilityLabel = `Too long (maximum ${DRAFT_LIMITS.applicabilityLabel} characters).`
    if (clean(s.instruction).length > DRAFT_LIMITS.instruction) rowErrors.instruction = `Too long (maximum ${DRAFT_LIMITS.instruction} characters).`
    const key = label === '' ? '\u0000general' : label.toLowerCase()
    if (seenLabels.has(key)) rowErrors.applicabilityLabel = label === '' ? 'Only one general (unlabelled) solution is allowed - give the others an applicability.' : 'Each technician solution needs a distinct applicability.'
    seenLabels.add(key)
    return rowErrors
  })
  if (solutionErrors.some((e) => Object.keys(e).length > 0)) errors.solutionRows = solutionErrors
  return errors
}

/** The request body. The code is NOT part of it: it is fixed by the group in the URL and cannot be changed here. */
export function buildPublishPayload(canonicalId, draft) {
  const orNull = (v) => (clean(v) === '' ? null : clean(v))

  return {
    canonical_candidate_id: canonicalId,
    title: clean(draft?.title),
    description: orNull(draft?.description),
    operator_guidance: orNull(draft?.operatorGuidance),
    technician_solutions: realSolutions(draft).map((s) => ({ applicability_label: orNull(s.applicabilityLabel), instruction: clean(s.instruction) })),
  }
}

/**
 * Client-visible workflow state. Only PUBLISHED is server-derived (the group already has a published
 * occurrence); the others describe the reviewer's local progress - nothing is persisted for them.
 */
export function workflowState({ published, canonicalId, draft, previewIsFresh = false }) {
  if (published) return 'PUBLISHED'
  if (!canonicalId && isDraftEmpty(draft)) return 'UNREVIEWED'
  if (canonicalId && Object.keys(validateDraft(draft, canonicalId)).length === 0 && previewIsFresh) return 'READY_TO_PUBLISH'
  return 'IN_REVIEW'
}

export const WORKFLOW_LABELS = { UNREVIEWED: 'Unreviewed', IN_REVIEW: 'In review', READY_TO_PUBLISH: 'Ready to publish', PUBLISHED: 'Published' }

export function collisionLabel(collision) {
  if (collision === 'EXISTING') return 'Existing record - update'
  if (collision === 'POTENTIAL_UPDATE') return 'Existing empty record - update'
  return 'New record'
}

const FIELD_LABELS = { title: 'Title', manufacturer_description: 'Description / cause', operator_description: 'Operator guidance' }

export function changedFieldLabels(preview) {
  return (preview?.existing?.changed_fields ?? []).map((f) => FIELD_LABELS[f] ?? f)
}

export function applicabilityDisplayLabel(label) {
  return label ? label : 'General'
}

export const SOLUTION_STATUS_LABELS = { NEW_VARIANT: 'New variant', IDENTICAL: 'Already exists', CONFLICT: 'Conflict' }

/** Plain-language list of exactly what the server will write, from the server's own mutation plan. */
export function mutationLines(preview) {
  if (!preview?.mutation) return []
  const m = preview.mutation
  const lines = []
  if (m.error_code === 'CREATE') lines.push('Create 1 error code')
  else if (m.error_code === 'UPDATE') lines.push(`Update the existing error code (${changedFieldLabels(preview).join(', ')})`)
  else lines.push('Leave the existing error code fields unchanged')
  if (m.solutions_new > 0) lines.push(`Add ${m.solutions_new} technician solution${m.solutions_new === 1 ? '' : 's'}`)
  if (m.solutions_identical > 0) lines.push(`${m.solutions_identical} technician solution${m.solutions_identical === 1 ? '' : 's'} already exist${m.solutions_identical === 1 ? 's' : ''} - no step added`)
  const created = m.references_to_create ?? 0
  const existing = m.references_existing ?? 0
  lines.push(`Add ${created} page reference${created === 1 ? '' : 's'}${existing > 0 ? ` (${existing} already exist)` : ''}`)
  return lines
}

export function supportingPagesLabel(pages, count) {
  if (!pages || pages.length === 0) return 'No page provenance'
  const shown = pages.map((p) => `p${p}`).join(', ')
  return count > pages.length ? `${shown}, …` : shown
}

/** A preview only describes the exact canonical occurrence and content it was made for; any edit makes it stale on screen. */
export function previewIsCurrent(preview, canonicalId, draft) {
  if (!preview || !canonicalId || preview.canonical_candidate_id !== canonicalId) return false
  const p = preview.proposed ?? {}
  const orNull = (v) => (clean(v) === '' ? null : clean(v))
  const sharedMatches = p.title === clean(draft?.title) && (p.description ?? null) === orNull(draft?.description) && (p.operator_guidance ?? null) === orNull(draft?.operatorGuidance)
  if (!sharedMatches) return false
  const proposedSolutions = p.solutions ?? []
  const draftSolutions = realSolutions(draft).map((s) => ({ applicability_label: orNull(s.applicabilityLabel), instruction: clean(s.instruction) }))
  if (proposedSolutions.length !== draftSolutions.length) return false

  return proposedSolutions.every((ps, i) => ps.applicability_label === draftSolutions[i].applicability_label && ps.instruction === draftSolutions[i].instruction)
}

export function canRequestPreview({ published, canonicalId, draft }) {
  return !published && Object.keys(validateDraft(draft, canonicalId)).length === 0
}

/**
 * Publish is live only for a fresh, applicable preview: no solution may be a CONFLICT (that always
 * needs a fresh review, never just an acknowledgement), and the shared-field update warning must be
 * acknowledged when the server requires it.
 */
export function canConfirmPublish({ preview, acknowledgedUpdate = false, isPublishing = false }) {
  if (isPublishing || !preview || !preview.can_publish || !preview.confirmation_token) return false
  if (preview.requires_update_confirmation && !acknowledgedUpdate) return false
  return true
}

export function blockedMessage(reason, alreadyPublished) {
  if (alreadyPublished === 'IDENTICAL') return 'This code group is already published with exactly this content. Nothing more to do.'
  if (reason === 'SOLUTION_CONFLICT') return 'One or more technician solutions conflict with what is already published under the same applicability. Resolve the conflict below before publishing.'
  if (reason === 'CANONICAL_MISMATCH') return 'This group was already published from a different occurrence. The canonical occurrence cannot be changed after publishing.'
  if (reason === 'CANONICAL_REJECTED') return 'The chosen canonical occurrence is rejected. Restore it or choose another occurrence.'
  return null
}

/** Field messages from a 422, keyed the way the editor shows them. Keys come from the server; message text is never parsed. */
export function fieldErrorsFromServer(error) {
  const e = error?.errors ?? {}
  const first = (k) => (Array.isArray(e[k]) ? e[k][0] : e[k])
  const out = {}
  if (e.title) out.title = first('title')
  if (e.description) out.content = first('description')
  if (e.canonical_candidate_id) out.canonical = 'The canonical occurrence must be one of this code group\'s occurrences.'
  if (e.technician_solutions) out.solutions = first('technician_solutions')
  return out
}

/** Distinguishes the failures the publish UI words differently. Keyed off status and field names, never message text. */
export function classifyPublishError(error) {
  if (error?.status === 409) {
    return { kind: 'STALE', message: 'The group or the existing catalog record changed since the preview (or it was published meanwhile). Nothing was changed. Preview again to see the current state.' }
  }
  if (error?.status === 403) return { kind: 'FORBIDDEN', message: 'Your role is not authorized to publish knowledge.' }
  if (error?.status === 422) {
    if (error.errors?.confirmation_token) return { kind: 'INVALID', message: 'The confirmation is no longer valid. Preview again before publishing.' }
    if (error.errors?.confirm_update) return { kind: 'CONFIRM_UPDATE', message: 'A catalog record for this code already exists. Confirm the update explicitly to publish.' }
    return { kind: 'FIELDS', message: 'Check the canonical knowledge fields and try again.' }
  }
  return { kind: 'OTHER', message: 'The request could not be completed. Please try again.' }
}
