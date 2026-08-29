import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const auth = readFileSync(new URL('./AuthProvider.jsx', import.meta.url), 'utf8')
const login = readFileSync(new URL('./LoginPage.jsx', import.meta.url), 'utf8')
const serverLogin = readFileSync(new URL('../../../supabase/functions/auth-login/index.ts', import.meta.url), 'utf8')
const provision = readFileSync(new URL('../../../supabase/functions/provision-member/index.ts', import.meta.url), 'utf8')
const manageAccount = readFileSync(new URL('../../../supabase/functions/manage-account/index.ts', import.meta.url), 'utf8')
const bootstrap = readFileSync(new URL('../../../supabase/functions/bootstrap-platform-superuser/index.ts', import.meta.url), 'utf8')
const migration = readFileSync(new URL('../../../supabase/migrations/20260829000200_account_provisioning_branch_scope.sql', import.meta.url), 'utf8')
const directMigration = readFileSync(new URL('../../../supabase/migrations/20260829000400_superuser_direct_provisioning_my_account.sql', import.meta.url), 'utf8')

test('email-or-username login remains one Supabase password authority with generic failures', () => {
  assert.match(login, /Email or username/)
  assert.match(auth, /functions\.invoke\('auth-login'/)
  assert.match(serverLogin, /signInWithPassword/)
  assert.match(serverLogin, /rpc\('resolve_login_username'/)
  assert.match(serverLogin, /Invalid username\/email or password\./)
  assert.doesNotMatch(serverLogin, /return json\([^\n]+email/)
})

test('privileged onboarding stays server-side and creates immediately active Auth identities', () => {
  assert.match(provision, /SUPABASE_SERVICE_ROLE_KEY/)
  assert.match(provision, /auth\.admin\.createUser/)
  assert.match(provision, /email_confirm: true/)
  assert.match(provision, /prepare_direct_member_provisioning/)
  assert.match(provision, /finalize_direct_member_provisioning/)
  assert.match(provision, /action === 'activate'/)
  assert.doesNotMatch(provision, /inviteUserByEmail|invitation sent/i)
  assert.doesNotMatch(auth + login, /SERVICE_ROLE/)
})

test('database owns normalized username and many-to-many branch scope', () => {
  assert.match(migration, /username_normalized/)
  assert.match(migration, /profiles_username_normalized_key/)
  assert.match(migration, /grant execute on function public\.resolve_login_username\(text\) to service_role/)
  assert.match(migration, /account_membership_branches/)
  assert.match(migration, /operational_person_branches/)
  assert.match(migration, /can_access_branch/)
})

test('My Account keeps Supabase Auth authoritative and verifies current password', () => {
  assert.match(manageAccount, /signInWithPassword/)
  assert.match(manageAccount, /auth\.admin\.updateUserById/)
  assert.match(manageAccount, /manage_my_profile/)
  assert.match(manageAccount, /record_identity_auth_change/)
  assert.doesNotMatch(manageAccount, /console\.(log|error)|encrypted_password/)
})

test('platform bootstrap is explicit UUID-bound and cannot infer from mutable identity', () => {
  assert.match(bootstrap, /PLATFORM_BOOTSTRAP_USER_ID/)
  assert.match(bootstrap, /PLATFORM_BOOTSTRAP_TOKEN/)
  assert.match(bootstrap, /bootstrap_platform_superuser/)
  assert.doesNotMatch(bootstrap + directMigration, /admin@test\.com|username\s*=\s*['"]admin|membership\.role\s*=\s*['"]owner/)
  assert.match(directMigration, /public\.bootstrap_platform_superuser\(uuid,uuid,text\)[\s\S]+to service_role/)
  assert.match(directMigration, /Platform Superuser required/)
})
