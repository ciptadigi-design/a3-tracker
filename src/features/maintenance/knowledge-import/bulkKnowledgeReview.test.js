import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

// V1.7 - Bulk Knowledge Review & Triage frontend. Same static-source-
// inspection convention as knowledgeProcessing.test.js: this codebase has no
// configured component-rendering harness for these dialogs, so tests assert
// the real source contains the exact conditional/JSX wiring the UI contract
// depends on.
const importDetail = readFileSync(new URL('./KnowledgeImportDetail.jsx', import.meta.url), 'utf8')
const bulkDialog = readFileSync(new URL('./BulkReviewDialog.jsx', import.meta.url), 'utf8')
const lib = readFileSync(new URL('../../../lib/api/maintenance.js', import.meta.url), 'utf8')
const services = readFileSync(new URL('../../../services/maintenance.js', import.meta.url), 'utf8')
const controller = readFileSync(new URL('../../../../backend/app/Http/Controllers/Api/MaintenanceKnowledgeImportController.php', import.meta.url), 'utf8')

// --- 1. checkbox appears for eligible candidates ---

test('checkbox renders only for DRAFT/REJECTED candidates, never for APPROVED/published rows', () => {
  assert.match(importDetail, /const BULK_ELIGIBLE_STATUSES = new Set\(\['DRAFT', 'REJECTED'\]\)/)
  assert.match(importDetail, /const bulkEligible = canManage && BULK_ELIGIBLE_STATUSES\.has\(entry\.status\)/)
  assert.match(importDetail, /bulkEligible\s*\n?\s*\? <input type="checkbox" checked=\{selected\}/)
})

// --- 2. select current page works ---

test('select-all-on-page selects exactly the eligible entries on the current page, not every filtered result', () => {
  assert.match(importDetail, /function toggleSelectPage\(\)/)
  assert.match(importDetail, /new Set\(eligibleOnPage\.map\(\(e\) => e\.id\)\)/)
  assert.match(importDetail, /const eligibleOnPage = useMemo\(\(\) => entriesState\.entries\.filter/)
})

// --- 3. clear selection works ---

test('a clear action resets the selection to empty', () => {
  assert.match(importDetail, /onClearSelection=\{\(\) => setSelectedIds\(new Set\(\)\)\}/)
})

// --- 4. selected count truthful ---

test('bulk action bar shows the real selection size, not a fabricated count', () => {
  assert.match(importDetail, /\{selectedIds\.size\} selected/)
})

// --- 5. bulk action bar hidden when nothing selected ---

test('bulk action bar renders nothing when selection is empty', () => {
  assert.match(importDetail, /function BulkActionBar\(\{ selectedIds, entries, onClearSelection, onRequestAction \}\) \{\s*if \(selectedIds\.size === 0\) return null/)
})

// --- 6. Reject selected opens confirmation dialog (not a browser confirm()) ---

test('reject/restore buttons request a confirmation dialog rather than mutating directly', () => {
  assert.match(importDetail, /onClick=\{\(\) => onRequestAction\('reject'\)\}/)
  assert.match(importDetail, /onClick=\{\(\) => onRequestAction\('restore'\)\}/)
  assert.match(importDetail, /pendingBulkAction && \(/)
  assert.doesNotMatch(bulkDialog, /window\.confirm/)
  assert.doesNotMatch(importDetail, /window\.confirm/)
})

test('confirmation dialog reuses BlockingDialog, not an ad-hoc modal', () => {
  assert.match(bulkDialog, /import \{ BlockingDialog \} from '\.\.\/\.\.\/\.\.\/components\/ui\/BlockingDialog\.jsx'/)
  assert.match(bulkDialog, /<BlockingDialog className="confirm-dialog glass-surface" role="alertdialog"/)
})

// --- 7. cancelling does not mutate ---

test('cancel closes the dialog without calling the bulk review service', () => {
  assert.match(bulkDialog, /<button className="secondary-button" type="button" onClick=\{onClose\} disabled=\{isSaving\}>Cancel<\/button>/)
})

// --- 8. confirmation sends only selected IDs (never full candidate objects) ---

test('bulk review request sends only entry_ids and action, never full candidate objects', () => {
  assert.match(bulkDialog, /bulkReviewEntries\(importId, \{ entryIds, action \}\)/)
  assert.match(lib, /bulkReviewEntries: \(importId, payload\) => apiClient\.post\(`\/maintenance\/document-imports\/\$\{importId\}\/entries\/bulk-review`, payload\)/)
  assert.match(services, /export const bulkReviewEntries = async \(importId, \{ entryIds, action \}\) => unwrapData\(await laravelMaintenance\.bulkReviewEntries\(importId, \{ entry_ids: entryIds, action \}\)\)/)
})

// --- 9/10. successful bulk reject refreshes the list and clears selection ---

test('a successful bulk review clears selection and refreshes the entries list', () => {
  assert.match(importDetail, /function handleBulkReviewed\(\) \{\s*setSelectedIds\(new Set\(\)\)\s*entriesState\.refresh\(\)/)
  assert.match(bulkDialog, /onReviewed\(result\)\s*\n\s*onClose\(\)/)
})

// --- 11. API failure preserves understandable state ---

test('a failed bulk review surfaces a real error message inside the dialog, not a silent failure', () => {
  assert.match(bulkDialog, /catch \(submitError\) \{\s*setError\(mapMaintenanceError\(submitError\)\)/)
  assert.match(bulkDialog, /\{error && <div className="form-error" role="alert">/)
})

// --- 12. filter change clears hidden selection ---

test('selection is cleared whenever any filter or the page changes', () => {
  // React's recommended "adjust state during render" pattern (not
  // useEffect+setState, which the project's lint config flags as an
  // avoidable cascading render for state fully derivable from props).
  assert.match(importDetail, /const selectionContextKey = `\$\{statusFilter\}\|\$\{collisionFilter\}\|\$\{evidenceFilter\}\|\$\{codeSearch\}\|\$\{entriesState\.currentPage\}`/)
  assert.match(importDetail, /if \(selectionContextKey !== lastSelectionContextKey\) \{\s*setLastSelectionContextKey\(selectionContextKey\)\s*setSelectedIds\(new Set\(\)\)/)
})

// --- 13. pagination change does not accidentally mutate hidden rows ---
// (covered by the same selectionContextKey above - entriesState.currentPage is part of it)

test('pagination change is included in the same selection-clearing key as filters', () => {
  assert.match(importDetail, /selectionContextKey = `[^`]*entriesState\.currentPage/)
})

// --- 14. REJECTED candidates cannot be bulk-rejected again (only "restore" is offered) ---

test('a uniform REJECTED selection only offers restore, never reject again', () => {
  assert.match(importDetail, /uniformStatus === 'REJECTED' && <button className="secondary-button" type="button" onClick=\{\(\) => onRequestAction\('restore'\)\}/)
  assert.doesNotMatch(importDetail, /uniformStatus === 'REJECTED'[^\n]*onRequestAction\('reject'\)/)
})

test('a mixed-status selection offers neither action rather than guessing intent', () => {
  assert.match(importDetail, /!uniformStatus && <small>Select candidates with the same review status/)
})

// --- 15. published candidates never expose bulk reject ---

test('an APPROVED (potentially published) entry never renders a bulk checkbox', () => {
  assert.doesNotMatch(importDetail, /BULK_ELIGIBLE_STATUSES = new Set\(\['DRAFT', 'REJECTED', 'APPROVED'\]\)/)
})

// --- 16. existing individual review still works ---

test('individual approve/reject/publish actions are unchanged', () => {
  assert.match(importDetail, /onClick=\{\(\) => onTransition\(entry\.id, 'APPROVED'\)\}/)
  assert.match(importDetail, /onClick=\{\(\) => onTransition\(entry\.id, 'REJECTED'\)\}/)
  assert.match(importDetail, /onClick=\{\(\) => onPublish\(entry\)\}/)
})

// --- 17. evidence filter still works ---

test('evidence filter remains present alongside the new selection UI', () => {
  assert.match(importDetail, /aria-label="Filter by evidence"/)
})

// --- 18. responsive narrow layout does not overflow ---

test('bulk UI reuses flexible layout primitives, no fixed-width elements', () => {
  assert.doesNotMatch(importDetail, /width:\s*\d+px/)
  assert.doesNotMatch(bulkDialog, /width:\s*\d+px/)
})

// --- backend contract sanity: batch limit and no-publish guarantee are visible in source ---

test('backend enforces a documented batch limit and never references the publish service', () => {
  assert.match(controller, /private const BULK_BATCH_LIMIT = 100;/)
  const bulkReviewBody = controller.slice(controller.indexOf('function bulkReview'));
  assert.doesNotMatch(bulkReviewBody, /MaintenanceKnowledgePublishService/)
})
