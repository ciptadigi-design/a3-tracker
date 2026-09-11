import assert from 'node:assert/strict'
import test from 'node:test'
import { describeApiError, isReferenceConflict } from './apiErrors.js'

test('describeApiError prefers structured field messages over the truncated summary', () => {
  const error = { message: 'The code field is required. (and 1 more error)', errors: { code: ['The code field is required.'], email: ['The email must be a valid email address.'] } }
  assert.equal(describeApiError(error), 'The code field is required. The email must be a valid email address.')
})

test('describeApiError falls back to the message when there are no field errors', () => {
  assert.equal(describeApiError({ message: 'Conflict.', errors: {} }), 'Conflict.')
})

// M2.17.4: a CSRF/session mismatch (419) is shaped by apiClient.apiRequest into a plain
// Error with no `errors` map and an actionable `message` - describeApiError must surface
// that message as-is rather than falling back to a generic string.
test('describeApiError surfaces the actionable CSRF/session-expiry message', () => {
  const error = new Error('Your session security token expired. Refresh or sign in again, then retry.')
  error.status = 419
  error.isCsrfMismatch = true
  error.errors = {}
  assert.equal(describeApiError(error), 'Your session security token expired. Refresh or sign in again, then retry.')
})

test('isReferenceConflict recognizes Laravel 409s and Postgres FK codes, not CSRF 419s', () => {
  assert.equal(isReferenceConflict({ status: 409 }), true)
  assert.equal(isReferenceConflict({ code: '23503' }), true)
  assert.equal(isReferenceConflict({ status: 419, isCsrfMismatch: true }), false)
})
