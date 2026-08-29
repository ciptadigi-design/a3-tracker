import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const page = readFileSync(new URL('../../pages/MyAccountPage.jsx', import.meta.url), 'utf8')
const manage = readFileSync(new URL('../../../supabase/functions/manage-account/index.ts', import.meta.url), 'utf8')
const provision = readFileSync(new URL('../../../supabase/functions/provision-member/index.ts', import.meta.url), 'utf8')
const migration = readFileSync(new URL('../../../supabase/migrations/20260829000400_superuser_direct_provisioning_my_account.sql', import.meta.url), 'utf8')

test('password fields are ephemeral and never use persistent draft storage', () => {
  assert.match(page, /useState\(\{ current: '', next: '', confirm: '' \}\)/)
  assert.doesNotMatch(page, /usePersistentDraft|localStorage|sessionStorage/)
  assert.doesNotMatch(manage + provision + migration, /console\.(log|error)|password_hash|encrypted_password/)
  assert.match(migration, /Passwords, hashes, tokens, and session credentials are never stored/)
})

test('self identity cannot change role, Branches, membership, or platform privilege', () => {
  assert.match(manage, /manage_my_profile/)
  assert.doesNotMatch(manage, /account_memberships|account_membership_branches|platform_user_privileges/)
  assert.doesNotMatch(page, /targetRole|branchIds|membership status/)
})

test('password change and managed reset never return an existing credential', () => {
  assert.match(manage, /Current password is incorrect/)
  assert.match(provision, /reset_password/)
  assert.doesNotMatch(manage + provision, /existingPassword|oldPassword\s*:/)
})
