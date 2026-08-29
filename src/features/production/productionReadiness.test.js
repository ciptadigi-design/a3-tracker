import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { failureCategory, userErrorMessage } from '../../lib/appErrors.js'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')

test('recoverable render boundary contains unexpected route crashes', () => {
  assert.match(read('../../App.jsx'), /<AppErrorBoundary>/)
  assert.match(read('../../components/ui/AppErrorBoundary.jsx'), /Reload A3 Tracker/)
})

test('global Branch changes remount operational route state without a page reload', () => {
  const shell = read('../../app/AppShell.jsx')
  assert.match(shell, /key={`\$\{tenant\.account\?\.id/)
  assert.match(shell, /tenant\.branch\?\.id/)
  assert.doesNotMatch(shell, /location\.reload\(\).*branch|branch.*location\.reload\(\)/is)
})

test('account transitions never render operational routes with an invalid Branch', () => {
  const tenant = read('../account/TenantProvider.jsx')
  assert.match(tenant, /No active Branch access/)
  assert.match(tenant, /if \(!branch\) return <LoadingScreen label="Selecting your active Branch"/)
})

test('operational detail reads require the selected Branch', () => {
  const machines = read('../../services/supabase/machines.js')
  const incidents = read('../../services/supabase/operationalIncidents.js')
  assert.match(machines.slice(machines.indexOf('loadMachine('), machines.indexOf('loadMachineCatalog')), /\.eq\('branch_id', branchId\)/)
  assert.match(incidents.slice(incidents.indexOf('loadOperationalIncident('), incidents.indexOf('incidentMutationPayload')), /\.eq\('branch_id', branchId\)/)
})

test('late async responses are ignored by shared operational hooks', () => {
  for (const path of ['../machines/useMachines.js', '../machines/useMachine.js', '../counters/useCounterHistory.js', '../operationalPeople/useOperationalPeople.js', '../incidents/useOperationalIncidents.js']) {
    const source = read(path)
    assert.match(source, /requestId\.current/)
  }
})

test('production error messages classify failures without exposing raw database detail', () => {
  const duplicate = { code: '23505', message: 'duplicate key value violates unique constraint secret_internal_key' }
  assert.equal(failureCategory(duplicate), 'conflict')
  assert.equal(userErrorMessage(duplicate), 'That record already exists or this request was already completed.')
  assert.doesNotMatch(userErrorMessage(duplicate), /secret_internal_key|duplicate key value/)
})
