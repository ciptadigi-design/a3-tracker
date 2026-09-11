import assert from 'node:assert/strict'
import test from 'node:test'
import { optional, optionalEmail } from './supplierFields.js'

test('optional trims and converts empty string to null, but leaves non-strings alone', () => {
  assert.equal(optional('  hello  '), 'hello')
  assert.equal(optional('   '), null)
  assert.equal(optional(''), null)
  assert.equal(optional(undefined), null)
  assert.equal(optional(null), null)
})

// M2.17.4 Scope A8: legacy Supabase/AI-assisted migration rows used "-" as a
// placeholder for "no email on file"; the backend's email validator rejects that
// literally, so the frontend must normalize it to null before sending, matching the
// backend's own normalizeSupplierEmailPlaceholder().
test('optionalEmail normalizes common legacy placeholders to null, case-insensitively', () => {
  for (const placeholder of ['-', '--', 'n/a', 'N/A', 'NA', 'none', 'None', ' - ']) {
    assert.equal(optionalEmail(placeholder), null, `expected "${placeholder}" to normalize to null`)
  }
})

test('optionalEmail leaves a real email address untouched', () => {
  assert.equal(optionalEmail('dzikri@example.com'), 'dzikri@example.com')
})

test('optionalEmail treats empty/omitted the same as optional()', () => {
  assert.equal(optionalEmail(''), null)
  assert.equal(optionalEmail(undefined), null)
})

// Field-aware: this helper must only ever be applied to the email field. Notes/Address
// legitimately use "-" as real free text, so they must keep using plain optional().
test('optional (used for Notes/Address) does not treat "-" as a placeholder', () => {
  assert.equal(optional('-'), '-')
})
