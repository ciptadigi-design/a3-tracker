import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const page = readFileSync(new URL('../../pages/ComponentsPage.jsx', import.meta.url), 'utf8')
const adapter = readFileSync(new URL('../../services/supabase/components.js', import.meta.url), 'utf8')
const lifecycle = readFileSync(new URL('../../services/supabase/componentLifecycles.js', import.meta.url), 'utf8')

test('reconciliation preview uses server candidate and BlockingDialog', () => {
  assert.match(page, /Reconcile with Model Profile/)
  assert.match(page, /Machine Component identity and existing lifecycle\/replacement history will be preserved/)
  assert.match(page, /does not create a new lifecycle and does not change historical evidence/)
  assert.match(page, /<BlockingDialog[^>]+busy=\{busy\}/)
  assert.match(adapter, /reconcile_manual_component_assignment/)
  assert.match(lifecycle, /getReconciliationCandidate/)
})

test('preview confirms explicitly and preserves busy/error behavior', () => {
  assert.match(page, /onConfirm=\{runReconcile\}/)
  assert.match(page, /disabled=\{busy\}/)
  assert.match(page, /setReconcileError\(mutationError\)/)
  assert.match(page, /await refresh\(\)/)
})

test('ordinary sync remains a separate action', () => {
  assert.match(page, /Sync Model Profile/)
  const syncBody = page.match(/async function syncSelectedMachine\(\)[\s\S]*?\n\s*\}/)?.[0] ?? ''
  assert.doesNotMatch(syncBody, /reconcileManualComponent/)
})
