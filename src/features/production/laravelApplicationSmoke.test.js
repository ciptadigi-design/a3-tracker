import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import test from 'node:test'
import { callSelectedBackend } from '../../services/dataBackend.js'

const root = new URL('../../', import.meta.url)
const read = (path) => readFileSync(new URL(path, root), 'utf8')

const routes = ['/login', '/', '/my-account', '/machines', '/daily', '/components', '/inventory', '/machine-cost', '/errors', '/reports', '/settings']
const domains = ['auth', 'tenant', 'account', 'machines', 'counters', 'components', 'componentLifecycles', 'inventory', 'machineCost', 'incidents', 'reports', 'settings', 'operationalMasters']

test('Laravel application smoke has a canonical route and adapter seam for every launch-critical module', () => {
  const shell = read('app/AppShell.jsx')
  assert.match(read('features/auth/LoginPage.jsx'), /export function LoginPage/)
  for (const route of routes.filter((item) => item !== '/login')) assert.ok(shell.includes(`'${route}'`), `missing route ${route}`)
  for (const domain of domains) {
    const service = domain === 'componentLifecycles' ? 'componentLifecycles' : domain
    assert.equal(existsSync(new URL(`services/${service}.js`, root)), true, `missing canonical service ${service}`)
    const adapterPath = domain === 'reports' ? 'lib/api/reports.js' : `services/laravel/${service}.js`
    assert.equal(existsSync(new URL(adapterPath, root)), true, `missing Laravel adapter ${domain}`)
  }
})

test('Laravel backend selection fails closed and never silently falls back', () => {
  const selector = read('services/dataBackend.js')
  assert.match(selector, /Unsupported VITE_DATA_BACKEND/)
  assert.match(selector, /No backend fallback was attempted/)
  assert.match(selector, /selected === 'laravel' \? laravel : supabase/)
})

test('Laravel execution never invokes the Supabase operational loader, including on API failure', async () => {
  let supabaseCalls = 0
  const supabase = async () => { supabaseCalls += 1; throw new Error('Supabase operational loader must not run') }
  const laravel = async () => ({ load: async () => { throw new Error('Laravel API unavailable') } })
  await assert.rejects(callSelectedBackend({ backend: 'laravel', domain: 'smoke', operation: 'load', args: [], supabase, laravel }), /Laravel API unavailable/)
  assert.equal(supabaseCalls, 0)
  await assert.rejects(callSelectedBackend({ backend: 'invalid-value', domain: 'smoke', operation: 'load', args: [], supabase, laravel }), /Unsupported VITE_DATA_BACKEND/)
  assert.equal(supabaseCalls, 0)
})
