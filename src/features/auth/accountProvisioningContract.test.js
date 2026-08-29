import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const auth = readFileSync(new URL('./AuthProvider.jsx', import.meta.url), 'utf8')
const login = readFileSync(new URL('./LoginPage.jsx', import.meta.url), 'utf8')
const serverLogin = readFileSync(new URL('../../../supabase/functions/auth-login/index.ts', import.meta.url), 'utf8')
const provision = readFileSync(new URL('../../../supabase/functions/provision-member/index.ts', import.meta.url), 'utf8')
const migration = readFileSync(new URL('../../../supabase/migrations/20260829000200_account_provisioning_branch_scope.sql', import.meta.url), 'utf8')

test('email-or-username login remains one Supabase password authority with generic failures', () => {
  assert.match(login, /Email or username/)
  assert.match(auth, /functions\.invoke\('auth-login'/)
  assert.match(serverLogin, /signInWithPassword/)
  assert.match(serverLogin, /Invalid username\/email or password\./)
  assert.doesNotMatch(serverLogin, /return json\([^\n]+email/)
})

test('privileged onboarding stays server-side and is invite-first', () => {
  assert.match(provision, /SUPABASE_SERVICE_ROLE_KEY/)
  assert.match(provision, /inviteUserByEmail/)
  assert.match(provision, /prepare_member_provisioning/)
  assert.match(provision, /finalize_member_provisioning/)
  assert.doesNotMatch(auth + login, /SERVICE_ROLE/)
})

test('database owns normalized username and many-to-many branch scope', () => {
  assert.match(migration, /username_normalized/)
  assert.match(migration, /profiles_username_normalized_key/)
  assert.match(migration, /account_membership_branches/)
  assert.match(migration, /operational_person_branches/)
  assert.match(migration, /can_access_branch/)
})
