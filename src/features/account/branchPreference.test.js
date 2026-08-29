import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import {
  createBranchPreferenceKey, readBranchPreference, resolveAuthorizedBranchId, writeBranchPreference,
} from './branchPreference.js'

class MemoryStorage {
  constructor() { this.values = new Map() }
  getItem(key) { return this.values.get(key) ?? null }
  setItem(key, value) { this.values.set(key, value) }
}

const tuparev = { id: 'tuparev', account_id: 'cipta', name: 'Tuparev', is_active: true }
const graha = { id: 'graha', account_id: 'cipta', name: 'Graha', is_active: true }
const provider = readFileSync(new URL('./TenantProvider.jsx', import.meta.url), 'utf8')
const authProvider = readFileSync(new URL('../auth/AuthProvider.jsx', import.meta.url), 'utf8')
const routes = [
  '../../pages/OverviewPage.jsx', '../../pages/MachinesPage.jsx', '../../pages/DailyPage.jsx', '../../pages/ComponentsPage.jsx',
  '../../pages/InventoryPage.jsx', '../../pages/MachineCostPage.jsx', '../../pages/ErrorsPage.jsx', '../../pages/ReportsPage.jsx',
].map((path) => readFileSync(new URL(path, import.meta.url), 'utf8')).join('\n')

test('selected Branch survives refresh, remount, and logout/login after authorization', () => {
  const storage = new MemoryStorage()
  writeBranchPreference({ userId: 'user-a', accountId: 'cipta', branchId: tuparev.id, storage })
  for (let lifecycle = 0; lifecycle < 3; lifecycle += 1) {
    const preferredBranchId = readBranchPreference({ userId: 'user-a', accountId: 'cipta', storage })
    assert.equal(resolveAuthorizedBranchId({ currentBranchId: null, preferredBranchId, branches: [graha, tuparev] }), tuparev.id)
  }
  assert.doesNotMatch(authProvider, /clearBranchPreference|branch-preference/)
})

test('creating or reordering a Branch does not steal an active valid selection', () => {
  assert.equal(resolveAuthorizedBranchId({ currentBranchId: tuparev.id, preferredBranchId: tuparev.id, branches: [graha, tuparev] }), tuparev.id)
  const newBranch = { id: 'newest', account_id: 'cipta', is_active: true }
  assert.equal(resolveAuthorizedBranchId({ currentBranchId: tuparev.id, preferredBranchId: tuparev.id, branches: [newBranch, graha, tuparev] }), tuparev.id)
})

test('preferences are isolated by user and Account', () => {
  const storage = new MemoryStorage()
  writeBranchPreference({ userId: 'user-a', accountId: 'cipta', branchId: tuparev.id, storage })
  writeBranchPreference({ userId: 'user-b', accountId: 'cipta', branchId: graha.id, storage })
  writeBranchPreference({ userId: 'user-a', accountId: 'other', branchId: 'other-branch', storage })
  assert.notEqual(createBranchPreferenceKey({ userId: 'user-a', accountId: 'cipta' }), createBranchPreferenceKey({ userId: 'user-b', accountId: 'cipta' }))
  assert.equal(readBranchPreference({ userId: 'user-a', accountId: 'cipta', storage }), tuparev.id)
  assert.equal(readBranchPreference({ userId: 'user-b', accountId: 'cipta', storage }), graha.id)
  assert.equal(readBranchPreference({ userId: 'user-a', accountId: 'other', storage }), 'other-branch')
})

test('removed, archived, or cross-Account preferences fall back to the first stable authorized Branch', () => {
  assert.equal(resolveAuthorizedBranchId({ currentBranchId: null, preferredBranchId: 'removed', branches: [tuparev, graha] }), tuparev.id)
  assert.equal(resolveAuthorizedBranchId({ currentBranchId: null, preferredBranchId: graha.id, branches: [tuparev, { ...graha, is_active: false }] }), tuparev.id)
  assert.equal(resolveAuthorizedBranchId({ currentBranchId: null, preferredBranchId: 'other-account-branch', branches: [graha, tuparev] }), graha.id)
})

test('a one-Branch user always resolves to the authorized Branch', () => {
  assert.equal(resolveAuthorizedBranchId({ currentBranchId: null, preferredBranchId: 'unknown', branches: [tuparev] }), tuparev.id)
})

test('rapid explicit switching persists the final accepted choice', () => {
  const storage = new MemoryStorage()
  for (const branchId of [tuparev.id, graha.id, tuparev.id]) writeBranchPreference({ userId: 'user-a', accountId: 'cipta', branchId, storage })
  assert.equal(readBranchPreference({ userId: 'user-a', accountId: 'cipta', storage }), tuparev.id)
})

test('Tenant restoration validates authorization before exposing the preference', () => {
  assert.match(provider, /resolveAuthorizedBranchId/)
  assert.match(provider, /availableBranches\.some\(\(branch\) => branch\.id === branchId/)
  assert.doesNotMatch(provider, /availableBranches\[availableBranches\.length - 1\]/)
})

test('operational routes keep the canonical Branch scope and never hard-code Graha or Tuparev', () => {
  assert.match(routes, /branch\.id|branch\?\.id|activeBranch\.id/)
  assert.doesNotMatch(routes, /Graha|Tuparev/)
})
