import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

// V1.7.2 - Knowledge Review Consolidation frontend. Same static source-inspection convention as
// knowledgeProcessing.test.js / bulkKnowledgeReview.test.js: there is no component-rendering harness
// for these dialogs, so these assert the real source carries the wiring the UI contract depends on.
// The pure behavior (queries, gating, error classification) is exercised for real in codeGroupUtils.test.js.
const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const panel = read('./CodeGroupsPanel.jsx')
const detailDialog = read('./CodeGroupDetailDialog.jsx')
const triage = read('./TriageDialog.jsx')
const importDetail = read('./KnowledgeImportDetail.jsx')
const groupsHook = read('./useCodeGroups.js')
const detailHook = read('./useCodeGroupDetail.js')
const lib = read('../../../lib/api/maintenance.js')
const services = read('../../../services/maintenance.js')
const css = read('../../../App.css')
const controller = read('../../../../backend/app/Http/Controllers/Api/MaintenanceKnowledgeReviewController.php')
const routes = read('../../../../backend/routes/api.php')

// --- 1/2/3/4/5. consolidated list, distinct-code summary, evidence mix, best-evidence badge, review state ---

test('the code-group list renders one row per backend group with code, best-evidence badge, evidence mix, page range and review state', () => {
  assert.match(panel, /groupsState\.groups\.map\(\(group\) => <GroupRow key=\{group\.normalized_code\}/)
  assert.match(panel, /Best: \{EVIDENCE_LABELS\[group\.best_evidence\]/)
  assert.match(panel, /evidenceMix\(group\)/)
  assert.match(panel, /pageRangeLabel\(group\)/)
  assert.match(panel, /REVIEW_STATE_LABELS\[group\.review_state\]/)
  assert.match(panel, /occurrenceLabel\(group\.occurrence_count\)/)
})

test('the header shows pages, candidates and distinct codes from backend values, and the summary cards are backend aggregates', () => {
  assert.match(panel, /data-testid="import-progress"/)
  assert.match(panel, /summary\?\.distinct_codes/)
  assert.match(panel, /summary\.best_evidence_codes\?\.HIGH/)
  assert.match(panel, /summary\.best_evidence_codes\?\.MEDIUM/)
  assert.match(panel, /summary\.best_evidence_codes\?\.LOW/)
  assert.match(panel, /Reviewed \/ remaining/)
  assert.doesNotMatch(panel, /\.reduce\(/, 'no client-side aggregation of the candidate set')
})

test('grouping and pagination are server-side: the hook requests one page and never groups rows in the browser', () => {
  assert.match(groupsHook, /loadCodeGroups\(importId, query\)/)
  assert.match(groupsHook, /buildCodeGroupQuery\(JSON\.parse\(filterKey\), \{ page: pageNumber, perPage, sort, direction \}\)/)
  assert.doesNotMatch(groupsHook, /groupBy|reduce\(/)
  assert.match(groupsHook, /groups: result\?\.data \?\? \[\]/)
})

// --- 6/7/8. search, evidence filters, page-range filters ---

test('the toolbar exposes code search, evidence, best evidence, review state, collision, page range and reference-like filters', () => {
  assert.match(panel, /aria-label="Search by code"/)
  assert.match(panel, /aria-label="Filter by best evidence"/)
  assert.match(panel, /aria-label="Filter by occurrence evidence"/)
  assert.match(panel, /aria-label="Filter by review state"/)
  assert.match(panel, /aria-label="Filter by collision status"/)
  assert.match(panel, /aria-label="From page"/)
  assert.match(panel, /aria-label="To page"/)
  assert.match(panel, /aria-label="Filter by reference-like signal"/)
})

test('presets are transparent filters and the product never hard-codes Konica page ranges', () => {
  assert.match(panel, /FILTER_PRESETS\.map\(\(preset\) => \(/)
  assert.match(panel, /setFilters\(\{ \.\.\.EMPTY_FILTERS, \.\.\.preset\.filters \}\)/)
  for (const source of [panel, detailDialog, triage, read('./codeGroupUtils.js')]) {
    assert.doesNotMatch(source, /\b(30\s*[–-]\s*38|945\s*[–-]\s*956)\b/, 'no real-manual page ranges in product code')
  }
})

// --- 9/10. group detail + occurrence provenance ---

test('opening a group loads every underlying occurrence in the backend order with page provenance and per-occurrence status', () => {
  assert.match(detailHook, /loadCodeGroup\(importId, code\)/)
  assert.match(detailDialog, /group\.occurrences\.map\(\(occurrence\) => \(/)
  assert.match(detailDialog, /occurrencePageLabel\(occurrence\)/)
  assert.match(detailDialog, /entryStatusLabels\[occurrence\.status\]/)
  assert.match(detailDialog, /occurrence\.reference_like/)
  assert.match(detailDialog, /Stored excerpt/)
  assert.doesNotMatch(detailDialog, /\.sort\(/, 'evidence order is the backend order, never re-sorted or collapsed here')
  assert.match(detailDialog, /Several can support the same machine error code/)
})

// --- 11/12/13. bulk preview, confirmation dialog, affected-count result ---

test('triage always previews first, shows matching/eligible/excluded and a bounded sample, and reports the server-returned count', () => {
  assert.match(triage, /previewFilterBulkReview\(importId, \{ action, filters: bulkFilters \}\)/)
  assert.match(triage, /<dt>Matching<\/dt>/)
  assert.match(triage, /<dt>Eligible<\/dt>/)
  assert.match(triage, /<dt>Excluded<\/dt>/)
  assert.match(triage, /Sample of \{preview\.sample\.length\} of \{eligible\}/)
  assert.match(triage, /\{result\.affected\} candidate/)
  assert.match(triage, /Rejected|Restored/)
})

test('apply sends the server-canonical filters and the server-issued token, and the confirm button is gated by canConfirmBulk', () => {
  assert.match(triage, /applyFilterBulkReview\(importId, \{ action, filters: preview\.filters, confirmationToken: preview\.confirmation_token \}\)/)
  assert.match(triage, /disabled=\{!canConfirmBulk\(\{ preview, acknowledged, isApplying: busy \}\)\}/)
  assert.match(triage, /if \(!canConfirmBulk\(/)
  assert.match(triage, /role="alertdialog"/)
  assert.match(triage, /BlockingDialog/)
  assert.doesNotMatch(triage, /window\.confirm|confirm\(/)
})

test('a large set needs an explicit acknowledgement checkbox before the button enables', () => {
  assert.match(triage, /needsAcknowledgement\(eligible\)/)
  assert.match(triage, /I understand \{eligible\} candidates will be/)
  assert.match(triage, /type="checkbox" checked=\{acknowledged\}/)
})

test('the dialog operates on the filters frozen when it opened, so a re-render can never change what is acted on', () => {
  assert.match(triage, /const \[bulkFilters\] = useState\(\(\) => openedWithFilters\)/)
})

// --- 14. restore flow ---

test('restore uses the same controlled preview-confirm flow, and a single rejected occurrence can be restored from group detail', () => {
  assert.match(panel, /setTriageAction\('restore'\)/)
  assert.match(panel, /setTriageAction\('reject'\)/)
  assert.match(triage, /Restore \{eligible\}|`\$\{verb\} \$\{eligible\} eligible candidate/)
  assert.match(detailDialog, /bulkReviewEntries\(importId, \{ entryIds: \[o\.id\], action: 'restore' \}\)/)
})

// --- 15. stale-preview conflict UX ---

test('a stale or invalid confirmation shows a no-change message with a Preview again action', () => {
  assert.match(triage, /classifyBulkError\(applyError\)/)
  assert.match(triage, /error\.kind === 'STALE' \|\| error\.kind === 'INVALID'/)
  assert.match(triage, /Preview again/)
  assert.match(triage, /setPhase\('failed'\)/)
})

// --- 16. authorization / capability gating ---

test('bulk triage controls and per-occurrence review actions render only for managers', () => {
  assert.match(panel, /\{canManage && \(\s*<div className="maintenance-bulk-action-bar" role="toolbar" aria-label="Bulk triage">/)
  assert.match(detailDialog, /\{canManage && \(\s*<div className="dialog-actions">/)
  assert.match(importDetail, /canManage=\{canManage\}/)
  assert.match(controller, /canManageCatalogScope/)
  assert.match(controller, /manageableImport\(\$r, \$importId\)/)
})

test('bulk triage is disabled until the filters actually narrow the selection', () => {
  assert.match(panel, /const bulkReady = hasBulkCriteria\(filters\) && !validationError/)
  assert.match(panel, /disabled=\{!bulkReady\}/)
})

// --- 17. pagination ---

test('groups paginate server-side with 10/25/50 per page and Previous/Next controls', () => {
  assert.match(panel, /PER_PAGE_OPTIONS\.map\(\(n\) => <option key=\{n\} value=\{n\}>\{n\} per page<\/option>\)/)
  assert.match(panel, /groupsState\.goToPage\(groupsState\.currentPage - 1\)/)
  assert.match(panel, /groupsState\.goToPage\(groupsState\.currentPage \+ 1\)/)
  assert.match(panel, /Page \{groupsState\.currentPage\} of \{groupsState\.lastPage\}/)
  assert.match(groupsHook, /useEffect\(\(\) => \{ setPageNumber\(1\) \}, \[filterKey, perPage, sort, direction\]\)/)
})

// --- 18. responsive / narrow layout ---

test('the code-group layout collapses to one column and the summary to two cards on narrow screens', () => {
  assert.match(css, /\.maintenance-code-group-row \{ min-width: 0; display: grid; grid-template-columns: minmax\(0, 1\.4fr\) minmax\(0, 2fr\) auto/)
  assert.match(css, /@media \(max-width: 860px\) \{\s*\.maintenance-summary-cards \{ grid-template-columns: repeat\(2, minmax\(0, 1fr\)\); \}\s*\.maintenance-code-group-row \{ grid-template-columns: minmax\(0, 1fr\); \}/)
  assert.match(css, /@media \(max-width: 430px\) \{\s*\.maintenance-triage-counts \{ grid-template-columns: 1fr; \}/)
  assert.match(css, /\.maintenance-occurrence-title \{[^}]*overflow-wrap: anywhere/)
})

// --- 19. no automatic publish ---

test('consolidation never publishes or approves in bulk: no publish call anywhere in the group panel or triage flow', () => {
  for (const source of [panel, triage, groupsHook]) {
    assert.doesNotMatch(source, /publishKnowledgeEntry|PublishDialog|onPublish\(/)
  }
  assert.doesNotMatch(triage, /action: 'approve'|action: 'publish'|'APPROVED'/)
  // V1.8: an OCCURRENCE is never published on its own. Publishing is the explicit single-code group flow (GroupPublishDialog).
  assert.doesNotMatch(detailDialog, /\bonPublish\b|publishKnowledgeEntry|from '\.\/PublishDialog\.jsx'/)
  assert.doesNotMatch(detailDialog, /<Rocket size=\{14\} \/> Publish/)
  assert.match(detailDialog, /<GroupPublishDialog importId=\{importId\} code=\{code\}/)
  assert.doesNotMatch(importDetail, /<CodeGroupsPanel[^\n]*onPublish/)
  assert.match(routes, /bulk-review\/preview/)
  assert.doesNotMatch(controller, /MaintenanceKnowledgePublishService/)
})

test('the copy never calls detector evidence verified or correct', () => {
  for (const source of [panel, detailDialog, triage]) assert.doesNotMatch(source, /\b(verified|correct answer|guaranteed)\b/i)
  assert.match(panel, /Evidence is detector evidence, not verification/)
})

// --- 20. existing candidate review remains reachable ---

test('the flat candidate list and its V1.7 ID-based bulk selection remain one tab away, unchanged', () => {
  assert.match(importDetail, /role="tab"[^\n]*>All candidates<\/button>/)
  assert.match(importDetail, /const BULK_ELIGIBLE_STATUSES = new Set\(\['DRAFT', 'REJECTED'\]\)/)
  assert.match(importDetail, /<BulkReviewDialog/)
  assert.match(importDetail, /<EntryFilters /)
  assert.match(importDetail, /activeView === 'groups' && isPdfImport/)
  assert.match(importDetail, /const activeView = viewMode \?\? \(isPdfImport \? 'groups' : 'flat'\)/)
})

test('a review change made anywhere refreshes the groups, the flat list and the import header', () => {
  assert.match(importDetail, /function handleGroupsChanged\(\) \{\s*entriesState\.refresh\(\)\s*state\.refresh\(\)\s*bumpGroups\(\)/)
  assert.match(importDetail, /version=\{groupsVersion\}/)
  assert.match(groupsHook, /\[refresh, version\]/)
})

// --- API plumbing ---

test('the four V1.7.2 endpoints are wired through the API client and services', () => {
  assert.match(lib, /codeGroups: \(importId, params = ''\) => apiClient\.get\(`\/maintenance\/document-imports\/\$\{importId\}\/code-groups/)
  assert.match(lib, /codeGroup: \(importId, code\) => apiClient\.get\(`[^`]*code-groups\/\$\{encodeURIComponent\(code\)\}`\)/)
  assert.match(lib, /previewFilterBulkReview: [^\n]*entries\/bulk-review\/preview/)
  assert.match(lib, /applyFilterBulkReview: [^\n]*entries\/bulk-review\/apply/)
  assert.match(services, /export const loadCodeGroups = /)
  assert.match(services, /export const previewFilterBulkReview = /)
  assert.match(services, /confirmation_token: confirmationToken/)
})

test('backend routes for the consolidated view and the two-step triage exist and stay separate from the V1.7 ID endpoint', () => {
  assert.match(routes, /document-imports\/\{id\}\/code-groups', \[MaintenanceKnowledgeReviewController::class, 'codeGroups'\]/)
  assert.match(routes, /document-imports\/\{id\}\/code-groups\/\{code\}/)
  assert.match(routes, /entries\/bulk-review\/apply/)
  assert.match(routes, /entries\/bulk-review', \[MaintenanceKnowledgeImportController::class, 'bulkReview'\]/)
})
