import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

// V1.8 - group-aware canonical review + single-code publish frontend. Same static source-inspection convention as
// the other knowledge-import tests (no component-rendering harness exists); the behavior itself is exercised for
// real in groupPublishUtils.test.js.
const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
// Code only: block and line comments are stripped where a test asserts something must NOT exist in the behavior.
const code = (source) => source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')
const detail = read('./CodeGroupDetailDialog.jsx')
const publishDialog = read('./GroupPublishDialog.jsx')
const panel = read('./CodeGroupsPanel.jsx')
const importDetail = read('./KnowledgeImportDetail.jsx')
const triage = read('./TriageDialog.jsx')
const lib = read('../../../lib/api/maintenance.js')
const services = read('../../../services/maintenance.js')
const css = read('../../../App.css')
const routes = read('../../../../backend/routes/api.php')

// 1/3. occurrences render, with a visibly marked canonical

test('group detail renders every occurrence and marks the canonical one visibly', () => {
  assert.match(detail, /group\.occurrences\.map\(\(occurrence\) => \(/)
  assert.match(detail, /data-canonical=\{isCanonical \? 'true' : 'false'\}/)
  assert.match(detail, /\{isCanonical && <span className="incident-status-pill resolved">Canonical<\/span>\}/)
  assert.match(css, /\.maintenance-occurrence-row\.is-canonical \{ border: 2px solid/)
})

// 2. canonical selection is an explicit reviewer action; the suggestion is only a label

test('the canonical occurrence is chosen explicitly with "Use as canonical"; nothing is selected by default', () => {
  assert.match(detail, /const \[canonicalId, setCanonicalId\] = useState\(null\)/)
  assert.match(detail, /aria-pressed=\{isCanonical\}/)
  assert.match(detail, /'Use as canonical'/)
  assert.match(detail, /onUseCanonical=\{\(o\) => \{ setCanonicalId\(o\.id\); setLastPreview\(null\) \}\}/)
  assert.match(detail, /isSuggested && !isCanonical && <span[^>]*>Suggested<\/span>/)
  assert.doesNotMatch(detail, /useState\(publication\?\.suggested/, 'the suggestion never becomes the selection')
})

// 4/5. editable canonical fields, placeholder validation visible

test('the canonical editor exposes title, description/cause, operator guidance and technician solution, with a fixed code', () => {
  assert.match(detail, /aria-label="Code \(fixed by the group\)"/)
  assert.match(detail, /readOnly aria-readonly="true"/)
  for (const label of ['Canonical title', 'Canonical description or cause', 'Canonical operator guidance', 'Canonical technician solution']) assert.match(detail, new RegExp(`aria-label="${label}"`))
  assert.match(detail, /You are writing the published knowledge for \{code\}; the source PDF and its excerpts are never changed\./)
  assert.match(detail, /Based on the occurrence from/)
})

test('the placeholder-title validation is visible as the reviewer types, using the shared message', () => {
  assert.match(detail, /const titleIsPlaceholder = draft\.title\.trim\(\) !== '' && isPlaceholderTitle\(draft\.title\)/)
  assert.match(detail, /\{PLACEHOLDER_TITLE_MESSAGE\}/)
})

test('nothing is generated or copied silently: the suggested title and the stored excerpt are explicit buttons', () => {
  assert.match(detail, /Use suggested title:/)
  assert.match(detail, /Start from the stored excerpt/)
  assert.match(detail, /Nothing is generated for you: every field is written or confirmed by a reviewer\./)
})

// 6. supporting pages

test('supporting evidence lists the unique supporting pages from the server', () => {
  assert.match(detail, /aria-label="Supporting evidence"/)
  assert.match(detail, /data-testid="supporting-pages"/)
  assert.match(detail, /supportingPagesLabel\(publication\?\.supporting_pages, publication\?\.supporting_page_count \?\? 0\)/)
  assert.match(detail, /Each unique page becomes a page reference when this code is published\./)
})

test('the header shows evidence, collision, supporting page count, review and publish state', () => {
  assert.match(detail, /data-testid="group-header"/)
  assert.match(detail, /collisionLabel\(publication\?\.server_collision_status\)/)
  assert.match(detail, /data-testid="workflow-state"/)
  assert.match(detail, /WORKFLOW_LABELS\[state\]/)
})

// 7/8. Review Publish opens a preview showing create vs update

test('"Review Publish" opens the preview dialog and does not publish', () => {
  assert.match(detail, /<Rocket size=\{16\} \/> Review Publish/)
  assert.match(detail, /if \(!canRequestPreview\(\{ published, canonicalId, draft \}\)\) \{ setShowErrors\(true\); return \}\s*setShowPublish\(true\)/)
  assert.match(detail, /Opens a read-only preview first\. Nothing is published until you confirm there\./)
  assert.match(publishDialog, /previewGroupPublish\(importId, code, buildPublishPayload\(canonicalId, draft\)\)/)
})

test('the preview shows code, title, canonical source, occurrences, pages, catalog state and exactly what will be written', () => {
  for (const term of ['Code', 'Title', 'Canonical source', 'Supporting occurrences', 'Supporting pages', 'Catalog state']) assert.match(publishDialog, new RegExp(`<dt>${term}</dt>`))
  assert.match(publishDialog, /What will be written/)
  assert.match(publishDialog, /mutationLines\(preview\)\.map/)
  assert.match(publishDialog, /collisionLabel\(preview\.collision_status\)/)
})

test('an existing catalog record shows an explicit update warning and needs an acknowledgement', () => {
  assert.match(publishDialog, /Publishing will UPDATE it/)
  assert.match(publishDialog, /Existing solution steps are kept\./)
  assert.match(publishDialog, /aria-label="I understand this updates an existing record"/)
  assert.match(publishDialog, /preview\.can_publish && isUpdate && \(/)
})

// 9. publish requires confirmation

test('the final action is "Publish Knowledge", gated by canConfirmPublish, inside an alertdialog, with a server token', () => {
  assert.match(publishDialog, /Publish Knowledge/)
  assert.doesNotMatch(publishDialog, />\s*Save\s*</)
  assert.match(publishDialog, /disabled=\{!canConfirmPublish\(\{ preview, acknowledgedUpdate: acknowledged, isPublishing: busy \}\)\}/)
  assert.match(publishDialog, /if \(!canConfirmPublish\(/)
  assert.match(publishDialog, /role="alertdialog"/)
  assert.match(publishDialog, /publishCodeGroup\(importId, code, payload, \{ confirmationToken: preview\.confirmation_token, confirmUpdate: acknowledged \}\)/)
  assert.doesNotMatch(publishDialog, /window\.confirm|confirm\(/)
})

test('the dialog freezes the canonical occurrence and content it opened with', () => {
  assert.match(publishDialog, /const \[draft\] = useState\(\(\) => openedWithDraft\)/)
})

// 10. stale preview forces re-preview

test('a stale or invalid confirmation says nothing changed and offers "Preview again"', () => {
  assert.match(publishDialog, /classifyPublishError\(publishError\)/)
  assert.match(publishDialog, /error\.kind === 'STALE' \|\| error\.kind === 'INVALID'/)
  assert.match(publishDialog, /Preview again/)
  assert.match(publishDialog, /setAcknowledged\(false\)/)
})

test('editing the canonical content or choosing another occurrence invalidates the on-screen preview', () => {
  assert.match(detail, /onChange=\{\(next\) => \{ setDraft\(next\); setLastPreview\(null\) \}\}/)
  assert.match(detail, /previewIsCurrent\(lastPreview, canonicalId, draft\) && Boolean\(lastPreview\?\.can_publish\)/)
})

// 11. PUBLISHED disables duplicate publish

test('a published group shows a Published banner and offers no way to publish again', () => {
  assert.match(detail, /data-testid="published-banner"/)
  assert.match(detail, /canChooseCanonical=\{!published\}/)
  assert.match(detail, /\{canManage && !published && \(\s*<>/, 'the editor and Review Publish only exist while unpublished')
  assert.match(detail, /Find it under Maintenance → Error Codes\./)
  assert.match(detail, /Changing published knowledge needs a separate update review\./)
  assert.match(detail, /publication\.supporting_page_count/)
})

// 12. no bulk publish

test('no bulk or publish-all action exists anywhere in the review UI or API surface', () => {
  for (const source of [detail, publishDialog, panel, triage]) assert.doesNotMatch(code(source), /publish[- ]?all|bulk[- ]?publish|Publish selected|Publish \d+ /i)
  assert.doesNotMatch(code(lib), /bulk[^\n]*publish|publish[^\n]*bulk/i)
  assert.match(lib, /previewGroupPublish: \(importId, code, payload\) => apiClient\.post\(`[^`]*code-groups\/\$\{encodeURIComponent\(code\)\}\/publish-preview`/)
  assert.match(lib, /publishGroup: \(importId, code, payload\) => apiClient\.post\(`[^`]*code-groups\/\$\{encodeURIComponent\(code\)\}\/publish`/)
  assert.match(routes, /code-groups\/\{code\}\/publish-preview/)
  assert.match(services, /export const publishCodeGroup = /)
  assert.match(services, /confirm_update: Boolean\(confirmUpdate\)/)
})

// 13. responsive

test('the canonical editor, publish facts and published banner collapse cleanly on narrow screens', () => {
  assert.match(css, /\.maintenance-canonical-editor textarea, \.maintenance-canonical-editor input \{ width: 100%; min-width: 0; box-sizing: border-box; \}/)
  assert.match(css, /@media \(max-width: 680px\) \{\s*\.maintenance-publish-facts \{ grid-template-columns: 1fr; \}/)
  assert.match(css, /\.maintenance-publish-facts dd \{[^}]*overflow-wrap: anywhere/)
})

// 14/15. V1.7 triage and the ID-selection path remain

test('V1.7.2 triage and the V1.7 ID-based selection remain intact and reachable', () => {
  assert.match(panel, /<TriageDialog importId=\{importId\} action=\{triageAction\}/)
  assert.match(panel, /aria-label="Bulk triage"/)
  assert.match(importDetail, /<BulkReviewDialog/)
  assert.match(importDetail, /const BULK_ELIGIBLE_STATUSES = new Set\(\['DRAFT', 'REJECTED'\]\)/)
  assert.match(importDetail, /role="tab"[^\n]*>All candidates<\/button>/)
})

test('legacy per-entry Publish stays for manual entries and is replaced by a pointer for PDF-derived candidates', () => {
  assert.match(importDetail, /!entry\.normalized_code && <button className="secondary-button" type="button" onClick=\{\(\) => onPublish\(entry\)\}/)
  assert.match(importDetail, /Publish from its code group\./)
  assert.match(importDetail, /<PublishDialog entry=\{publishingEntry\}/)
})

test('a group publish refreshes the groups, the flat list and the import header', () => {
  assert.match(detail, /function handlePublished\(\) \{\s*detail\.refresh\(\)\s*onChanged\?\.\(\)/)
  assert.match(panel, /onChanged=\{onChanged\}/)
})
