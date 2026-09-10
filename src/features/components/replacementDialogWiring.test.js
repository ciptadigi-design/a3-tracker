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
