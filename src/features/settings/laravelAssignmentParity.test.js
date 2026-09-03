import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const service = readFileSync(new URL('../../services/laravel/settings.js', import.meta.url), 'utf8')

test('Laravel operational-person assignment save is one full-set replacement request', () => {
  const implementation = service.match(/export async function updateOperationalPersonBranches[^\n]+/)?.[0] ?? ''
  assert.match(implementation, /apiClient\.put\(`\/operational-people\/\$\{personId\}\/branches`/)
  assert.match(implementation, /assignments:/)
  assert.doesNotMatch(implementation, /Promise\.all|apiClient\.post/)
})
