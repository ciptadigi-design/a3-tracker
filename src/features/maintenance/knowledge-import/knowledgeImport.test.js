import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const documentDetail = readFileSync(new URL('../documents/DocumentDetail.jsx', import.meta.url), 'utf8')
const importDetail = readFileSync(new URL('./KnowledgeImportDetail.jsx', import.meta.url), 'utf8')
const entryForm = readFileSync(new URL('./KnowledgeEntryForm.jsx', import.meta.url), 'utf8')
const publishDialog = readFileSync(new URL('./PublishDialog.jsx', import.meta.url), 'utf8')
const importList = readFileSync(new URL('./KnowledgeImportList.jsx', import.meta.url), 'utf8')

test('Document detail renders the Knowledge imports section with an "Import Knowledge" action', () => {
  assert.match(documentDetail, /Knowledge imports/)
  assert.match(documentDetail, /Import Knowledge/)
  assert.match(documentDetail, /<KnowledgeImportList imports=\{importsState\.imports\}/)
})

test('capability gate: only canManage can create an import session or manage entries, read access is unconditional', () => {
  assert.match(documentDetail, /canManage && \(showCreateImport \?/)
  assert.match(importDetail, /canManage && \(editingEntry \?/)
  assert.match(importDetail, /const availableTransitions = canManage \? \(nextEntryStatuses\[entry\.status\] \?\? \[\]\) : \[\]/)
  // The entries list itself renders regardless of canManage.
  assert.doesNotMatch(importDetail, /canManage &&[\s\S]{0,60}<ul className="incident-narrative-list">/)
})

test('knowledge entry form covers all documented fields and requires a title', () => {
  for (const field of ['knowledge_type', 'code', 'title', 'category', 'severity', 'description', 'operator_solution', 'technician_solution', 'page_reference']) {
    assert.match(entryForm, new RegExp(`values\\.${field}`), `missing field ${field}`)
  }
  assert.match(entryForm, /Title is required\./)
})

test('publish dialog calls publishKnowledgeEntry and previews what will be created', () => {
  assert.match(publishDialog, /import \{ publishKnowledgeEntry \} from/)
  assert.match(publishDialog, /await publishKnowledgeEntry\(entry\.id\)/)
  assert.match(publishDialog, /Create or update machine error code/)
  assert.match(publishDialog, /no downstream table for uncoded knowledge yet/)
})

test('import list renders status and type labels from the shared maintenance utils, not ad-hoc strings', () => {
  assert.match(importList, /importStatusLabels\[item\.status\]/)
  assert.match(importList, /importTypeLabels\[item\.import_type\]/)
})
