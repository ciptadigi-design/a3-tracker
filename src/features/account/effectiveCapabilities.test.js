import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { capabilitiesForAccount, hasCapability, createTenantContextLoader } from './effectiveCapabilities.js'
const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')

test('FEC01 Laravel bootstrap consumes backend capabilities without an empty permissions placeholder', () => {
  const adapter = read('../../services/laravel/tenant.js')
  assert.match(adapter, /capabilities: me.capabilities \?\? \{\}/)
  assert.doesNotMatch(adapter, /permissions: \[\]/)
})
test('FEC02–07 presentation follows explicit capability booleans, including financial controls', () => {
  for (const key of ['components.lifecycle.initialize', 'incidents.create', 'machine_cost.selling_price.manage', 'machine_cost.operating_cost.manage']) {
    for (const value of [false, undefined, null, 1, 'true']) assert.equal(hasCapability({ [key]: value }, key), false)
    assert.equal(hasCapability({ [key]: true }, key), true)
    assert.equal(hasCapability(undefined, key), false)
  }
  const pages = {
    '../../pages/ComponentsPage.jsx': 'components.lifecycle.initialize',
    '../../pages/ErrorsPage.jsx': 'incidents.create',
    '../../pages/MachineCostPage.jsx': 'machine_cost.selling_price.manage',
    '../../pages/InventoryPage.jsx': 'inventory.receive',
  }
  for (const [path, key] of Object.entries(pages)) {
    assert.ok(read(path).includes(`can('${key}')`))
    assert.doesNotMatch(read(path), /includes\(membership\?\.role\)|operationalPermissions\?\./)
  }
})
test('FEC08–09 switching account and user never reuses another capability map', () => {
  const data = { contextUserId: 'user', capabilities: { a: { 'incidents.create': true }, b: { 'incidents.create': false } } }
  assert.equal(hasCapability(capabilitiesForAccount(data, 'user', 'a'), 'incidents.create'), true)
  for (const [user, account] of [['user', 'b'], ['user', 'missing'], ['new-user', 'a']]) assert.equal(hasCapability(capabilitiesForAccount(data, user, account), 'incidents.create'), false)
  assert.deepEqual(capabilitiesForAccount(null, 'user', 'a'), {})
  const provider = read('./TenantProvider.jsx')
  assert.match(provider, /const selectAccount[\s\S]*?setTenantData\(null\)[\s\S]*?refresh\(\)/)
})
test('late bootstrap responses cannot overwrite the current user or account refresh', async () => {
  const requests = []
  const published = []
  const loader = createTenantContextLoader(() => new Promise(resolve => requests.push(resolve)))
  const first = loader.refresh('old', data => published.push(data))
  loader.invalidate()
  const second = loader.refresh('new', data => published.push(data))
  requests[1]({ capabilities: { b: {} } })
  await second
  requests[0]({ capabilities: { a: {} } })
  await first
  assert.deepEqual(published, [{ contextUserId: 'new', capabilities: { b: {} } }])
})

test('Laravel adapter transports account-scoped capability payloads and fails closed when missing', async () => {
  const { apiClient } = await import('../../lib/api/apiClient.js')
  const { loadTenantContext } = await import('../../services/laravel/tenant.js')
  const original = apiClient.get
  try {
    for (const capabilities of [{ a: { 'incidents.create': true }, b: { 'incidents.create': false } }, undefined]) {
      const calls = []
      apiClient.get = async path => {
        calls.push(path)
        return path === '/me' ? { data: { user: { id: 'u' }, accounts: [{ id: 'a' }, { id: 'b' }], capabilities } } : { data: [] }
      }
      const context = await loadTenantContext()
      assert.deepEqual(context.capabilities, capabilities ?? {})
      assert.deepEqual(calls, ['/me', '/accounts/a/branches?per_page=50', '/accounts/b/branches?per_page=50'])
    }
  } finally { apiClient.get = original }
})

test('late failures cannot replace a successful current context with stale errors', async () => {
  const requests = []
  const loader = createTenantContextLoader(() => new Promise((resolve, reject) => requests.push({ resolve, reject })))
  const errors = []
  let settled = 0
  const first = loader.refresh('u', () => {}, e => errors.push(e), () => settled++)
  const second = loader.refresh('u', () => {}, e => errors.push(e), () => settled++)
  requests[1].resolve({})
  await second
  requests[0].reject(new Error('stale'))
  await first
  assert.deepEqual(errors, [])
  assert.equal(settled, 1)
})
