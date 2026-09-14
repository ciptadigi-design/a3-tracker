import test from 'node:test'
import assert from 'node:assert/strict'
import { auditActionLabel, auditActorLabel, auditChanges, auditTarget, auditValue, shortenAuditIdentifier } from './auditPresentation.js'
test('administrative history has readable actions and compact safe changes', () => {
  assert.equal(auditActionLabel('membership.role_changed'), 'Changed Member Role')
  assert.equal(auditActionLabel('supplier.archived'), 'Archived Supplier')
  assert.equal(auditActionLabel('future.internal.action'), 'Administrative Change')
  assert.equal(auditActorLabel('Platform staff'), 'Platform Staff')
  assert.deepEqual(auditChanges({ role: { before: 'operator', after: 'admin' } }), [{ field: 'Role', value: 'Operator → Admin' }])
  assert.equal(auditValue({ internal: 'data' }), 'Changed')
  assert.equal(auditValue([true, false]), 'Yes, No')
  assert.equal(auditValue(null), '—')
  assert.deepEqual(auditChanges({ email: { before: '[not retained]', after: '[not retained]' } }), [{ field: 'Email', value: 'Updated (values not retained)' }])
})

test('supported targets prefer human-readable labels and unknown targets shorten identifiers', () => {
  assert.deepEqual(auditTarget({ type: 'supplier', id: '076d37ba-1111-2222-3333-44444444be05', label: 'Cipta Toner' }), {
    typeLabel: 'Supplier', label: 'Cipta Toner', hasHumanLabel: true, fullIdentifier: '076d37ba-1111-2222-3333-44444444be05',
  })
  assert.equal(auditTarget({ type: 'branch', id: 'b', label: 'Tuparev' }).label, 'Tuparev')
  assert.equal(auditTarget({ type: 'account_membership', id: 'm', label: 'Siti Aminah' }).label, 'Siti Aminah')
  assert.equal(shortenAuditIdentifier('076d37ba-1111-2222-3333-44444444be05'), '076d37ba…be05')
  assert.equal(auditTarget({ type: 'future_record', id: '076d37ba-1111-2222-3333-44444444be05' }).label, '076d37ba…be05')
})

test('supplier activity uses domain-readable status instead of raw booleans', () => {
  assert.deepEqual(auditChanges({ is_active: { before: true, after: false } }, 'supplier'), [
    { field: 'Status', value: 'Active → Archived' },
  ])
  assert.deepEqual(auditChanges({ is_active: { before: 'on', after: 0 } }, 'supplier'), [
    { field: 'Status', value: 'Active → Archived' },
  ])
  assert.equal(auditValue('076d37ba-1111-4222-8333-44444444be05', { field: 'branch_id' }), '076d37ba…be05')
})
