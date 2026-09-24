import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const page = readFileSync(new URL('../../../pages/MaintenancePage.jsx', import.meta.url), 'utf8')
const section = readFileSync(new URL('./DocumentRepositorySection.jsx', import.meta.url), 'utf8')
const list = readFileSync(new URL('./DocumentList.jsx', import.meta.url), 'utf8')
const detail = readFileSync(new URL('./DocumentDetail.jsx', import.meta.url), 'utf8')
const formDialog = readFileSync(new URL('./DocumentFormDialog.jsx', import.meta.url), 'utf8')
const errorCodeSection = readFileSync(new URL('../ErrorCodeKnowledgeSection.jsx', import.meta.url), 'utf8')

test('Maintenance page renders the real Documents tab (not the old coming-soon placeholder)', () => {
  assert.match(page, /tab === 'documents'[\s\S]*?<DocumentRepositorySection \/>/)
  assert.doesNotMatch(page, /Documents is coming soon/)
  assert.doesNotMatch(page, /Documents<span>Soon<\/span>/)
})

test('permission visibility: document mutation is gated behind machines.manage, the list itself is not', () => {
  assert.match(section, /const canManage = can\('machines\.manage'\)/)
  assert.match(section, /canManage && <button className="primary-button" type="button" onClick=\{\(\) => setWorkflow\(\{ mode: 'create' \}\)\}>/)
  assert.doesNotMatch(section, /canManage &&[\s\S]{0,60}<DocumentList/)
  assert.match(section, /Read-only access\. Only account owners\/admins/)
})

test('global vs owned documents resolve edit authority the same way the backend does', () => {
  assert.match(section, /doc\.account_id === null \? isPlatformSuperuser : doc\.account_id === account\?\.id/)
})

test('document search is wired to the documents list hook, not a client-side filter', () => {
  assert.match(section, /useMaintenanceDocuments\(\{ search \}\)/)
  assert.match(section, /setSearch\(searchInput\.trim\(\)\)/)
  assert.match(section, /placeholder="Search by title…"/)
})

test('document row edit action is also gated per-row, matching the section-level gate', () => {
  assert.match(list, /canManageRow\(doc\) && <button className="icon-button"/)
})

// Maintenance Clean Slate Phase 1: document references are UI-retired (the
// maintenance_document_references table/data and backend routes are untouched -
// see docs/M2 Maintenance Clean Slate Phase 1 report), so DocumentDetail no longer
// renders a references section at all.
test('document detail no longer exposes document references UI', () => {
  assert.doesNotMatch(detail, /Related error knowledge/)
  assert.doesNotMatch(detail, /addDocumentReference/)
  assert.doesNotMatch(detail, /deleteDocumentReference/)
})

test('document detail no longer exposes extraction, process knowledge, or knowledge imports/review/benchmark UI', () => {
  assert.doesNotMatch(detail, /ExtractedPagesViewer/)
  assert.doesNotMatch(detail, /ExtractionModal/)
  assert.doesNotMatch(detail, /ExtractionProgress/)
  assert.doesNotMatch(detail, /useDocumentExtraction/)
  assert.doesNotMatch(detail, /Process Knowledge/)
  assert.doesNotMatch(detail, /processDocumentKnowledge/)
  assert.doesNotMatch(detail, /Knowledge imports/)
  assert.doesNotMatch(detail, /KnowledgeImportList/)
  assert.doesNotMatch(detail, /KnowledgeImportDetail/)
  assert.doesNotMatch(detail, /useKnowledgeImports/)
  assert.doesNotMatch(detail, /createDocumentImport/)
})

test('maintenance navigation shows Troubleshooting, Tickets, and Documents - no Knowledge Base or Error Codes tab', () => {
  assert.match(page, /Troubleshooting<\/button>/)
  assert.match(page, /Tickets<\/button>/)
  assert.match(page, /Documents<\/button>/)
  assert.doesNotMatch(page, /Knowledge base<\/button>/)
  assert.doesNotMatch(page, /Error Codes<\/button>/)
  assert.doesNotMatch(page, /ErrorCodeKnowledgeSection/)
})

test('document form dialog offers an upload-PDF mode alongside the external-reference mode (superseded by V1.4 document storage)', () => {
  assert.match(formDialog, /File reference \(path or URL\)/)
  assert.match(formDialog, /<PdfUploadField file=\{selectedFile\} onFileSelected=\{setSelectedFile\} disabled=\{isSaving\} \/>/)
})

test('error code knowledge section renders related documents from document_references', () => {
  assert.match(errorCodeSection, /code\.document_references/)
  assert.match(errorCodeSection, /Related documents:/)
})
