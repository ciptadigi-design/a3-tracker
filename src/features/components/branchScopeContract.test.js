import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const page = readFileSync(new URL('../../pages/ComponentsPage.jsx', import.meta.url), 'utf8')
const service = readFileSync(new URL('../../services/supabase/componentLifecycles.js', import.meta.url), 'utf8')

test('Machine Components loads and renders only the global Branch machine projection', () => {
  assert.match(service, /loadMachines\(\{ accountId, branchId \}\)/)
  assert.match(service, /\.eq\('branch_id', branchId\)/)
  assert.match(page, /operational\.branchId === branch\?\.id/)
  assert.match(page, /No active machines in \{branchName/)
})

test('an invalid persisted Machine is cleared while account master tabs remain independent', () => {
  assert.match(page, /view\.machineId && !machineIdIsValid[\s\S]+machineId: null/)
  assert.match(page, /loadComponentFoundation\(\{ accountId: account\.id \}\)/)
  assert.match(page, />Model Profiles</)
  assert.match(page, />Component Catalog</)
})
