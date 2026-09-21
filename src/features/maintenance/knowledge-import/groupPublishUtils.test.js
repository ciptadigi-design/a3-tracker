import assert from 'node:assert/strict'
import test from 'node:test'
import {
  EMPTY_DRAFT, PLACEHOLDER_TITLE_MESSAGE, blockedMessage, buildPublishPayload, canConfirmPublish, canRequestPreview, changedFieldLabels, classifyPublishError, collisionLabel,
  fieldErrorsFromServer, isDraftEmpty, isPlaceholderTitle, mutationLines, previewIsCurrent, supportingPagesLabel, validateDraft, workflowState,
} from './groupPublishUtils.js'

// V1.8 - behavior tests for the pure canonical-review / single-code-publish logic (no rendering harness needed).

const good = { title: 'Fuser temperature sensor abnormal', description: 'The thermistor reads out of range.', operatorGuidance: '', technicianSolution: 'Replace the thermistor.' }

// --- placeholder guard (mirrors the backend rule) ---

test('the detector placeholder title is rejected for any code, in any casing or spacing', () => {
  for (const bad of ['Error Code C-3102', 'error code c-3102', 'C-3102', 'Error Code C3102', 'Error Code C - 3102', 'Error code', '  ', '', null, undefined, 'Untitled', 'TBD']) {
    assert.equal(isPlaceholderTitle(bad), true, String(bad))
  }
})

test('a human title that merely contains the code is not a placeholder', () => {
  for (const ok of ['C-3102 Fuser temperature abnormal', 'Fuser error (C-3102)', 'Paper feed misfeed', 'Error Code C-3102 fuser jam']) {
    assert.equal(isPlaceholderTitle(ok), false, ok)
  }
})

// --- draft validation ---

test('an empty draft needs a canonical occurrence, a real title and some content', () => {
  const e = validateDraft(EMPTY_DRAFT, null)
  assert.equal(e.canonical, 'Choose the canonical occurrence first.')
  assert.equal(e.title, PLACEHOLDER_TITLE_MESSAGE)
  assert.match(e.content, /at least a description/)
})

test('the placeholder message is shown for a placeholder title and clears for a real one', () => {
  assert.equal(validateDraft({ ...good, title: 'Error Code C-3102' }, 'id').title, PLACEHOLDER_TITLE_MESSAGE)
  assert.deepEqual(validateDraft(good, 'id'), {})
})

test('a technician solution alone is enough content; a title alone is not', () => {
  assert.deepEqual(validateDraft({ title: 'Fuser sensor', description: '', operatorGuidance: '', technicianSolution: 'Replace it.' }, 'id'), {})
  assert.ok(validateDraft({ title: 'Fuser sensor', description: '  ', operatorGuidance: '', technicianSolution: '' }, 'id').content)
})

test('over-long fields are caught before a request', () => {
  assert.match(validateDraft({ ...good, title: 'x'.repeat(201) }, 'id').title, /at most 200/)
  assert.match(validateDraft({ ...good, description: 'x'.repeat(10001) }, 'id').description, /Too long/)
})

// --- payload ---

test('the payload carries the canonical id and trimmed content, blank optional fields as null, and never a code', () => {
  const payload = buildPublishPayload('c-1', { title: '  Title  ', description: ' cause ', operatorGuidance: '   ', technicianSolution: '' })
  assert.deepEqual(payload, { canonical_candidate_id: 'c-1', title: 'Title', description: 'cause', operator_guidance: null, technician_solution: null })
  assert.equal('code' in payload, false, 'the code is fixed by the group in the URL')
  assert.equal('normalized_code' in payload, false)
})

// --- workflow state ---

test('the workflow state moves unreviewed -> in review -> ready to publish -> published', () => {
  assert.equal(workflowState({ published: false, canonicalId: null, draft: EMPTY_DRAFT }), 'UNREVIEWED')
  assert.equal(workflowState({ published: false, canonicalId: 'a', draft: EMPTY_DRAFT }), 'IN_REVIEW')
  assert.equal(workflowState({ published: false, canonicalId: 'a', draft: good, previewIsFresh: false }), 'IN_REVIEW')
  assert.equal(workflowState({ published: false, canonicalId: 'a', draft: good, previewIsFresh: true }), 'READY_TO_PUBLISH')
  assert.equal(workflowState({ published: false, canonicalId: 'a', draft: { ...good, title: 'Error Code C-3102' }, previewIsFresh: true }), 'IN_REVIEW', 'an invalid draft is never ready')
  assert.equal(workflowState({ published: true, canonicalId: null, draft: EMPTY_DRAFT }), 'PUBLISHED')
})

test('a published group can never request another preview (no duplicate publish)', () => {
  assert.equal(canRequestPreview({ published: true, canonicalId: 'a', draft: good }), false)
  assert.equal(canRequestPreview({ published: false, canonicalId: 'a', draft: good }), true)
  assert.equal(canRequestPreview({ published: false, canonicalId: null, draft: good }), false)
})

// --- preview freshness and confirmation gating ---

const preview = { canonical_candidate_id: 'a', proposed: { title: good.title, description: good.description, operator_guidance: null, technician_solution: good.technicianSolution }, can_publish: true, confirmation_token: 'x.y', requires_update_confirmation: false }

test('a preview is current only for the exact canonical occurrence and content it was made for', () => {
  assert.equal(previewIsCurrent(preview, 'a', good), true)
  assert.equal(previewIsCurrent(preview, 'b', good), false)
  assert.equal(previewIsCurrent(preview, 'a', { ...good, title: 'Edited' }), false)
  assert.equal(previewIsCurrent(preview, 'a', { ...good, description: 'Edited' }), false)
  assert.equal(previewIsCurrent(null, 'a', good), false)
})

test('publish needs a fresh applicable preview, and an explicit acknowledgement when a record already exists', () => {
  assert.equal(canConfirmPublish({ preview }), true)
  assert.equal(canConfirmPublish({ preview: null }), false)
  assert.equal(canConfirmPublish({ preview: { ...preview, can_publish: false } }), false)
  assert.equal(canConfirmPublish({ preview: { ...preview, confirmation_token: null } }), false)
  assert.equal(canConfirmPublish({ preview, isPublishing: true }), false)
  const update = { ...preview, requires_update_confirmation: true }
  assert.equal(canConfirmPublish({ preview: update }), false)
  assert.equal(canConfirmPublish({ preview: update, acknowledgedUpdate: true }), true)
})

// --- preview presentation ---

test('the mutation lines state create vs update and the fields an update would change', () => {
  const create = { mutation: { error_code: 'CREATE', solution: 'CREATE', references_to_create: 6, references_existing: 0 } }
  assert.deepEqual(mutationLines(create), ['Create 1 error code', 'Add 1 technician solution step', 'Add 6 page references'])
  const update = { mutation: { error_code: 'UPDATE', solution: 'SKIP_EXISTS', references_to_create: 5, references_existing: 1 }, existing: { changed_fields: ['title', 'manufacturer_description'] } }
  assert.deepEqual(mutationLines(update), ['Update the existing error code (Title, Description / cause)', 'Technician solution already exists - no step added', 'Add 5 page references (1 already exist)'])
  assert.deepEqual(changedFieldLabels(update), ['Title', 'Description / cause'])
  assert.deepEqual(mutationLines({ mutation: { error_code: 'CREATE', solution: 'NONE', references_to_create: 1, references_existing: 0 } }), ['Create 1 error code', 'Add 1 page reference'])
})

test('collision states read as create vs update', () => {
  assert.equal(collisionLabel('NEW'), 'New record')
  assert.match(collisionLabel('EXISTING'), /update/)
  assert.match(collisionLabel('POTENTIAL_UPDATE'), /update/)
})

test('supporting pages are listed compactly with an ellipsis when the list is truncated', () => {
  assert.equal(supportingPagesLabel([33, 100, 900], 3), 'p33, p100, p900')
  assert.equal(supportingPagesLabel([33, 100], 40), 'p33, p100, …')
  assert.equal(supportingPagesLabel([], 0), 'No page provenance')
})

test('blocked states are explained', () => {
  assert.match(blockedMessage(null, 'IDENTICAL'), /already published with exactly this content/)
  assert.match(blockedMessage('ALREADY_PUBLISHED_DIFFERENT', 'DIFFERENT'), /different content/)
  assert.match(blockedMessage('CANONICAL_REJECTED', null), /rejected/)
  assert.equal(blockedMessage(null, null), null)
})

test('the draft emptiness check ignores whitespace', () => {
  assert.equal(isDraftEmpty({ title: ' ', description: '', operatorGuidance: '\n', technicianSolution: '' }), true)
  assert.equal(isDraftEmpty(good), false)
})

// --- server errors: keyed off status and field names, never message text ---

test('a 409 is a stale-preview conflict that says nothing was changed', () => {
  const e = classifyPublishError({ status: 409, errors: {} })
  assert.equal(e.kind, 'STALE')
  assert.match(e.message, /Nothing was changed/)
  assert.match(e.message, /Preview again/)
})

test('an invalid confirmation, a missing update confirmation and field errors are worded differently', () => {
  assert.equal(classifyPublishError({ status: 422, errors: { confirmation_token: ['x'] } }).kind, 'INVALID')
  assert.equal(classifyPublishError({ status: 422, errors: { confirm_update: ['x'] } }).kind, 'CONFIRM_UPDATE')
  assert.equal(classifyPublishError({ status: 422, errors: { title: ['x'] } }).kind, 'FIELDS')
  assert.equal(classifyPublishError({ status: 403 }).kind, 'FORBIDDEN')
  assert.equal(classifyPublishError({ status: 500 }).kind, 'OTHER')
  assert.equal(classifyPublishError(undefined).kind, 'OTHER')
})

test('server field errors map onto the editor fields', () => {
  assert.deepEqual(fieldErrorsFromServer({ errors: { title: ['bad'], description: ['need content'], canonical_candidate_id: ['no'] } }), {
    title: 'bad', content: 'need content', canonical: 'The canonical occurrence must be one of this code group\'s occurrences.',
  })
  assert.deepEqual(fieldErrorsFromServer({}), {})
})
