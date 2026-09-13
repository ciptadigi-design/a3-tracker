import test from 'node:test'
import assert from 'node:assert/strict'
import { auditActionLabel, auditChanges, auditValue } from './auditPresentation.js'
test('administrative history has readable actions and compact safe changes', () => {
  assert.equal(auditActionLabel('membership.role_changed'), 'Changed member role')
  assert.equal(auditActionLabel('future.internal.action'), 'Administrative change')
  assert.deepEqual(auditChanges({ role: { before: 'operator', after: 'admin' } }), ['role: operator → admin'])
  assert.equal(auditValue({ internal: 'data' }), 'Changed')
  assert.equal(auditValue([true, false]), 'On, Off')
  assert.equal(auditValue(null), '—')
  assert.deepEqual(auditChanges({ email: { before: '[not retained]', after: '[not retained]' } }), ['email: updated (values not retained)'])
})
