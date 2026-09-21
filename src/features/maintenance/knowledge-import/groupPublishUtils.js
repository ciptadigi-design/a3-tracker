// V1.8 - Group-aware canonical review and single-code publish: pure, dependency-free logic shared by the
// group detail editor and the publish confirmation dialog. Kept free of React so it is unit-testable with
// node:test. The backend is authoritative for everything that matters (placeholder guard, collision,
// supporting pages, idempotency); this module only mirrors the checks needed to give the reviewer immediate
// feedback and to gate the buttons - a passing client check never replaces the server's own validation.

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

export const EMPTY_DRAFT = Object.freeze({ title: '', description: '', operatorGuidance: '', technicianSolution: '' })

export const DRAFT_LIMITS = { title: 200, description: 10000, operatorGuidance: 10000, technicianSolution: 10000 }

const clean = (value) => String(value ?? '').replace(/\r\n?/g, '\n').trim()

export function isDraftEmpty(draft) {
  return ![draft?.title, draft?.description, draft?.operatorGuidance, draft?.technicianSolution].some((v) => clean(v) !== '')
}

/** Client-side mirror of the server's input rules. Returns {} when the draft may be sent for a preview. */
export function validateDraft(draft, canonicalId) {
  const errors = {}
  if (!canonicalId) errors.canonical = 'Choose the canonical occurrence first.'
  const title = clean(draft?.title)
  if (isPlaceholderTitle(title)) errors.title = PLACEHOLDER_TITLE_MESSAGE
  else if (title.length > DRAFT_LIMITS.title) errors.title = `The title must be at most ${DRAFT_LIMITS.title} characters.`
  if (!clean(draft?.description) && !clean(draft?.operatorGuidance) && !clean(draft?.technicianSolution)) {
    errors.content = 'Add at least a description, operator guidance or a technician solution.'
  }
  for (const key of ['description', 'operatorGuidance', 'technicianSolution']) {
    if (clean(draft?.[key]).length > DRAFT_LIMITS[key]) errors[key] = `Too long (maximum ${DRAFT_LIMITS[key]} characters).`
  }
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
    technician_solution: orNull(draft?.technicianSolution),
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

/** Plain-language list of exactly what the server will write, from the server's own mutation plan. */
export function mutationLines(preview) {
  if (!preview?.mutation) return []
  const m = preview.mutation
  const lines = []
  if (m.error_code === 'CREATE') lines.push('Create 1 error code')
  else if (m.error_code === 'UPDATE') lines.push(`Update the existing error code (${changedFieldLabels(preview).join(', ')})`)
  else lines.push('Leave the existing error code fields unchanged')
  if (m.solution === 'CREATE') lines.push('Add 1 technician solution step')
  else if (m.solution === 'SKIP_EXISTS') lines.push('Technician solution already exists - no step added')
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
  return p.title === clean(draft?.title) && (p.description ?? null) === orNull(draft?.description)
    && (p.operator_guidance ?? null) === orNull(draft?.operatorGuidance) && (p.technician_solution ?? null) === orNull(draft?.technicianSolution)
}

export function canRequestPreview({ published, canonicalId, draft }) {
  return !published && Object.keys(validateDraft(draft, canonicalId)).length === 0
}

/** Publish is live only for a fresh, applicable preview, with the update warning acknowledged when a record already exists. */
export function canConfirmPublish({ preview, acknowledgedUpdate = false, isPublishing = false }) {
  if (isPublishing || !preview || !preview.can_publish || !preview.confirmation_token) return false
  if (preview.requires_update_confirmation && !acknowledgedUpdate) return false
  return true
}

export function blockedMessage(reason, alreadyPublished) {
  if (alreadyPublished === 'IDENTICAL') return 'This code group is already published with exactly this content. Nothing more to do.'
  if (reason === 'ALREADY_PUBLISHED_DIFFERENT') return 'This code group is already published with different content. Changing published knowledge needs a separate update review.'
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
