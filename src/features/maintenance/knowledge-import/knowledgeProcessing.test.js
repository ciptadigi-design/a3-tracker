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
  assert.match(importDetail, /filteredEntries\.map\(\(entry\) =>/)
})

// --- 5. candidate filters (status, NEW/EXISTING, code search) ---

test('candidate list supports status filter, collision filter, and code search', () => {
  assert.match(importDetail, /function EntryFilters\(/)
  assert.match(importDetail, /onStatusFilter\(event\.target\.value\)/)
  assert.match(importDetail, /onCollisionFilter\(event\.target\.value\)/)
  assert.match(importDetail, /onCodeSearch\(event\.target\.value\)/)
  assert.match(importDetail, /entry\.status === statusFilter/)
  assert.match(importDetail, /entry\.collision_status === collisionFilter/)
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
