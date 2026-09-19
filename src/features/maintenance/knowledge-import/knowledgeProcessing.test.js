import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

// V1.6 - Extracted Knowledge Processing frontend. Same static-source-inspection
// convention as knowledgeImport.test.js: this codebase has no configured
// component-rendering harness for these dialogs, so tests assert the real
// source contains the exact conditional/JSX wiring the UI contract depends on.
const documentDetail = readFileSync(new URL('../documents/DocumentDetail.jsx', import.meta.url), 'utf8')
const importDetail = readFileSync(new URL('./KnowledgeImportDetail.jsx', import.meta.url), 'utf8')
const importList = readFileSync(new URL('./KnowledgeImportList.jsx', import.meta.url), 'utf8')
const useKnowledgeImport = readFileSync(new URL('./useKnowledgeImport.js', import.meta.url), 'utf8')
const useKnowledgeImports = readFileSync(new URL('./useKnowledgeImports.js', import.meta.url), 'utf8')
const maintenanceUtils = readFileSync(new URL('../maintenanceUtils.js', import.meta.url), 'utf8')
const lib = readFileSync(new URL('../../../lib/api/maintenance.js', import.meta.url), 'utf8')
const services = readFileSync(new URL('../../../services/maintenance.js', import.meta.url), 'utf8')
const useKnowledgeEntries = readFileSync(new URL('./useKnowledgeEntries.js', import.meta.url), 'utf8')

// --- 1. a COMPLETED extraction exposes the Process Knowledge CTA ---

test('Process Knowledge action only renders once extraction is COMPLETED, and is capability-gated', () => {
  assert.match(documentDetail, /extractionState\.extraction\?\.status === 'COMPLETED' && \(/)
  assert.match(documentDetail, /onClick=\{handleProcessKnowledge\}/)
  assert.match(documentDetail, /canManage && \(\s*<div className="dialog-actions"/)
})

test('handleProcessKnowledge calls the real processDocumentKnowledge endpoint, not a stub', () => {
  assert.match(documentDetail, /const created = await processDocumentKnowledge\(documentId\)/)
  assert.match(lib, /processDocumentKnowledge: \(documentId, payload = \{\}\) => apiClient\.post\(`\/maintenance\/documents\/\$\{documentId\}\/process-knowledge`, payload\)/)
  assert.match(services, /export const processDocumentKnowledge = async \(documentId, payload = \{\}\) => unwrapData/)
})

// --- 2/3. processing state and real progress display (never a fabricated percentage) ---

test('processing progress shows real pages_processed/candidate_count, never a fabricated percentage', () => {
  assert.match(importDetail, /Processing page \{state\.documentImport\.pages_processed/)
  assert.match(importDetail, /state\.documentImport\.candidate_count \?\? 0\} candidate/)
  assert.doesNotMatch(importDetail, /%/)
  assert.match(importList, /item\.pages_processed \?\? 0/)
})

test('import list and detail both poll while a PDF_EXTRACTION import is PROCESSING, and stop on a terminal status', () => {
  assert.match(useKnowledgeImport, /documentImport\?\.import_type === 'PDF_EXTRACTION' && documentImport\?\.status === 'PROCESSING'/)
  assert.match(useKnowledgeImports, /item\.import_type === 'PDF_EXTRACTION' && item\.status === 'PROCESSING'/)
})

// --- 4. REVIEW candidate list ---

test('import detail lists candidates once loaded, independent of any specific status label', () => {
  // V1.6.1: candidates are fetched server-side-paginated (useKnowledgeEntries),
  // not client-filtered from an embedded array - see the evidence-filter tests below.
  assert.match(importDetail, /entriesState\.entries\.map\(\(entry\) =>/)
})

// --- 5. candidate filters (status, NEW/EXISTING, code search) ---

test('candidate list supports status filter, collision filter, and code search, applied server-side', () => {
  assert.match(importDetail, /function EntryFilters\(/)
  assert.match(importDetail, /onStatusFilter\(event\.target\.value\)/)
  assert.match(importDetail, /onCollisionFilter\(event\.target\.value\)/)
  assert.match(importDetail, /onCodeSearch\(event\.target\.value\)/)
  // V1.6.1: filters are passed into useKnowledgeEntries (server-side), not
  // compared against entry fields in the frontend itself.
  assert.match(importDetail, /useKnowledgeEntries\(\{ importId, status: statusFilter \|\| undefined, collisionStatus: collisionFilter \|\| undefined, evidence: evidenceFilter \|\| undefined, code: codeSearch\.trim\(\) \|\| undefined \}\)/)
  assert.doesNotMatch(importDetail, /entry\.status === statusFilter/, 'status filtering must not happen client-side against an already-paginated page')
})

// --- 6/9. candidate detail shows source page and evidence ---

test('candidate row shows source page range and evidence, falling back to page_reference for manual entries', () => {
  assert.match(importDetail, /entry\.source_page_start === entry\.source_page_end/)
  assert.match(importDetail, /entry\.page_reference \? `Page \$\{entry\.page_reference\}` : null/)
  assert.match(importDetail, /evidenceLabels\[entry\.evidence\]/)
})

// --- 7/8. NEW / EXISTING status badges ---

test('NEW/EXISTING/POTENTIAL_UPDATE collision status renders from the shared label map, not ad-hoc strings', () => {
  assert.match(importDetail, /collisionStatusLabels\[entry\.collision_status\]/)
  assert.match(maintenanceUtils, /export const collisionStatusLabels = \{\s*NEW: 'New',\s*EXISTING: 'Existing',\s*POTENTIAL_UPDATE: 'Potential Update',/)
})

// --- 10. publish action respects capability (unchanged from V1.3 - still gated) ---

test('publish action remains canManage-gated for PDF-derived candidates exactly as for manual entries', () => {
  assert.match(importDetail, /entry\.status === 'APPROVED' && !entry\.published_at && <button className="secondary-button" type="button" onClick=\{\(\) => onPublish\(entry\)\}/)
  assert.match(importDetail, /canManage && \(\s*<div className="dialog-actions">/)
})

// --- 11. reject action ---

test('reject action is offered whenever the entry status allows it, via the same nextEntryStatuses contract', () => {
  assert.match(importDetail, /availableTransitions\.includes\('REJECTED'\)/)
})

// --- 12. empty candidate result is a truthful message, not a spinner or fabricated content ---

test('zero candidates renders an honest empty state distinguishing "still processing" from "nothing found"', () => {
  assert.match(importDetail, /isProcessing \? 'No candidates detected yet\.' : 'No knowledge entries recorded yet\.'/)
  assert.match(importDetail, /No entries match the current filters\./)
})

// --- 13. API failure state ---

test('a failed Process Knowledge request surfaces a real error message, not a silent failure', () => {
  assert.match(documentDetail, /catch \(error\) \{\s*setProcessingError\(mapMaintenanceError\(error\)\)/)
  assert.match(documentDetail, /processingError && <small className="field-error">/)
  assert.match(importDetail, /state\.error \? <ErrorState/)
})

// --- 14. mobile/narrow layout: reuses existing responsive primitives, no ad-hoc fixed widths ---

test('new V1.6 UI reuses existing flexible layout primitives instead of introducing fixed-width elements', () => {
  assert.doesNotMatch(importDetail, /width:\s*\d+px/)
  assert.doesNotMatch(importList, /width:\s*\d+px/)
})

// ==================================================
// V1.6.1 - Evidence filter + server-side pagination
// ==================================================
// Real Production evidence (1285 candidates in one import, dense code-index/
// glossary sections producing 99.8% LOW evidence) proved candidates must be
// server-paginated and evidence-filterable, not loaded/filtered client-side.

const controller = readFileSync(new URL('../../../../backend/app/Http/Controllers/Api/MaintenanceKnowledgeImportController.php', import.meta.url), 'utf8')

test('evidence filter renders with All/HIGH/MEDIUM/LOW options from the shared label map', () => {
  assert.match(importDetail, /aria-label="Filter by evidence"/)
  assert.match(importDetail, /Object\.entries\(evidenceLabels\)\.map\(\(\[value, label\]\) => <option key=\{value\} value=\{value\}>\{label\}<\/option>\)/)
  assert.match(importDetail, /<option value=\{ALL_FILTER\}>All evidence<\/option>/)
})

test('changing any filter resets pagination to page 1', () => {
  assert.match(useKnowledgeEntries, /useEffect\(\(\) => \{ setPageNumber\(1\) \}, \[status, collisionStatus, evidence, code\]\)/)
})

test('evidence filter composes with status and collision filters and code search in the same request', () => {
  assert.match(useKnowledgeEntries, /loadDocumentImportEntries\(importId, \{ status, collisionStatus, evidence, code, page: pageNumber, perPage: PER_PAGE \}\)/)
})

test('candidates are always fetched via the paginated backend endpoint, never loaded as one embedded array', () => {
  assert.match(lib, /documentImportEntries: \(importId, params = ''\) => apiClient\.get\(`\/maintenance\/document-imports\/\$\{importId\}\/entries/)
  assert.match(services, /export const loadDocumentImportEntries = async/)
  assert.doesNotMatch(importDetail, /entries:\s*\[\.\.\.\(prev\.entries/, 'must not still be mutating an embedded entries array')
})

test('backend applies evidence/status/collision/code filters in SQL, not after fetching all rows', () => {
  assert.match(controller, /function listEntries\(Request \$r, string \$importId\)/)
  assert.match(controller, /'evidence' => 'nullable\|in:HIGH,MEDIUM,LOW'/)
  assert.match(controller, /\$q->where\('evidence', \$d\['evidence'\]\)/)
  assert.match(controller, /\$q->orderBy\('created_at'\)->paginate\(\$perPage\)/)
})

test('backend rejects an invalid evidence value via normal validation, not a silent no-op filter', () => {
  assert.match(controller, /'evidence' => 'nullable\|in:HIGH,MEDIUM,LOW'/)
})

test('listEntries enforces the same tenant/account visibility check as show()', () => {
  const listEntriesBody = controller.slice(controller.indexOf('function listEntries'), controller.indexOf('function listEntries') + 800)
  assert.match(listEntriesBody, /abort_unless\(\$import->document->account_id === null \|\| \$ids->contains\(\$import->document->account_id\), 404\)/)
})

test('show() no longer embeds every entry, and instead exposes a real evidence_summary aggregate', () => {
  assert.doesNotMatch(controller, /'entries' => fn \(\$q\) => \$q->orderBy\('created_at'\)/)
  assert.match(controller, /function evidenceSummary\(string \$importId\): array/)
  assert.match(controller, /groupBy\('evidence'\)/)
})

test('evidence summary in the UI shows real counts, never a fabricated percentage', () => {
  assert.match(importDetail, /function EvidenceSummary\(\{ summary \}\)/)
  assert.match(importDetail, /HIGH \{summary\.HIGH\} · MEDIUM \{summary\.MEDIUM\} · LOW \{summary\.LOW\}/)
  assert.doesNotMatch(importDetail, /%/)
})

test('pagination controls appear only when more than one page exists, matching the extracted-pages viewer convention', () => {
  assert.match(importDetail, /entriesState\.lastPage > 1 && \(/)
  assert.match(importDetail, /entriesState\.goToPage\(entriesState\.currentPage - 1\)/)
  assert.match(importDetail, /entriesState\.goToPage\(entriesState\.currentPage \+ 1\)/)
})

test('candidate detail rendering (EntryRow) is unchanged by the pagination refactor', () => {
  assert.match(importDetail, /function EntryRow\(\{ entry, canManage, onEdit, onTransition, onPublish \}\)/)
  assert.match(importDetail, /entry\.source_page_start === entry\.source_page_end/)
})
