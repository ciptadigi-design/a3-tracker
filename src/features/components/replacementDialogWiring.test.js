import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const dialog = readFileSync(new URL('./ReplaceComponentDialog.jsx', import.meta.url), 'utf8')
const componentsPage = readFileSync(new URL('../../pages/ComponentsPage.jsx', import.meta.url), 'utf8')

test('Replace / Refill PIC options are the same canonical Operator population as Daily Click, not a bare is_active filter', () => {
  assert.match(dialog, /counterOperatorsForBranch\(operationalPeople, machine\.branch_id\)/)
  assert.doesNotMatch(dialog, /operationalPeople\.filter\(\(person\) => person\.is_active\)/)
})

test('replaceLifecycle sends the machine-component assignment id, not just the lifecycle id, to the API', () => {
  const call = componentsPage.match(/await replaceComponentLifecycle\([^)]+\)/)?.[0] ?? ''
  assert.match(call, /assignmentId:\s*initializingLifecycle\.assignment_id/)
})

// Phase 2: compatibility is explicit domain data (inventory_component_compatibilities),
// never a component_id equality fallback - this must never silently reappear.
test('eligible item filtering never falls back to item.component_id === lifecycle.component_id', () => {
  assert.doesNotMatch(dialog, /item\.component_id\s*===\s*lifecycle\.component_id/)
  assert.match(dialog, /eligibleCompatibleInventoryItems\(inventoryItems, compatibilities, lifecycle\.component_id\)/)
})

test('ComponentsPage passes the fetched compatibilities down to the Replace dialog', () => {
  assert.match(componentsPage, /compatibilities=\{scopedOperational\.compatibilities\}/)
})
