import test from 'node:test'
import assert from 'node:assert/strict'
import { resolveMemberBranchNames } from './memberBranchNames.js'

const branches = [
  { id: 'tup', name: 'Tuparev' },
  { id: 'grh', name: 'Graha' },
]

test('admin member with branch_ids resolves the matching branch names in order', () => {
  const member = { role: 'admin', branch_ids: ['tup', 'grh'] }
  assert.deepEqual(resolveMemberBranchNames(member, branches), ['Tuparev', 'Graha'])
})

test('admin member with empty branch_ids resolves to an empty list, not a crash', () => {
  const member = { role: 'admin', branch_ids: [] }
  assert.deepEqual(resolveMemberBranchNames(member, branches), [])
})

test('admin member with missing branch_ids field (undefined) resolves to an empty list', () => {
  const member = { role: 'admin' }
  assert.deepEqual(resolveMemberBranchNames(member, branches), [])
})

test('owner member is never consulted for branch_ids by callers, but the helper itself stays safe regardless', () => {
  const member = { role: 'owner', branch_ids: null }
  assert.deepEqual(resolveMemberBranchNames(member, branches), [])
})

test('unknown or stale branch id is dropped rather than throwing or rendering undefined', () => {
  const member = { role: 'admin', branch_ids: ['tup', 'deleted-branch-id'] }
  assert.deepEqual(resolveMemberBranchNames(member, branches), ['Tuparev'])
})

test('a member entirely made of stale branch ids resolves to an empty list', () => {
  const member = { role: 'admin', branch_ids: ['deleted-1', 'deleted-2'] }
  assert.deepEqual(resolveMemberBranchNames(member, branches), [])
})

test('missing branches collection does not throw', () => {
  const member = { role: 'admin', branch_ids: ['tup'] }
  assert.deepEqual(resolveMemberBranchNames(member, undefined), [])
})

test('null member does not throw', () => {
  assert.deepEqual(resolveMemberBranchNames(null, branches), [])
})
