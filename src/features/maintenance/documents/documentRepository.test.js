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

test('document detail shows related error-code references and gates linking behind canManage', () => {
  assert.match(detail, /Related error knowledge/)
  assert.match(detail, /canManage && \(showReferenceForm/)
  assert.match(detail, /addDocumentReference/)
  assert.match(detail, /deleteDocumentReference/)
})

test('document form dialog is metadata-only - a file_path reference field, no upload input', () => {
  assert.match(formDialog, /File reference \(path or URL\)/)
  assert.doesNotMatch(formDialog, /type="file"/)
  assert.match(formDialog, /No file is uploaded here\./)
})

test('error code knowledge section renders related documents from document_references', () => {
  assert.match(errorCodeSection, /code\.document_references/)
  assert.match(errorCodeSection, /Related documents:/)
})
