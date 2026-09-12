import assert from 'node:assert/strict'
import test from 'node:test'
import { resolveApiBaseUrl } from './apiClient.js'

test('the Production API base URL contract resolves to /api/v1', () => {
  assert.equal(resolveApiBaseUrl('/api/v1'), '/api/v1')
})

test('a trailing slash on the configured base URL is normalized away', () => {
  assert.equal(resolveApiBaseUrl('/api/v1/'), '/api/v1')
})

test('a missing VITE_API_BASE_URL still defaults to the Production contract path', () => {
  assert.equal(resolveApiBaseUrl(undefined), '/api/v1')
  assert.equal(resolveApiBaseUrl(''), '/api/v1')
})
