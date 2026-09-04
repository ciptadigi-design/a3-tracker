import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const authProvider = readFileSync(new URL('./AuthProvider.jsx', import.meta.url), 'utf8')
const loginPage = readFileSync(new URL('./LoginPage.jsx', import.meta.url), 'utf8')
const postLoginNotice = readFileSync(new URL('./postLoginNotice.js', import.meta.url), 'utf8')
const myAccountPage = readFileSync(new URL('../../pages/MyAccountPage.jsx', import.meta.url), 'utf8')
const settingsPage = readFileSync(new URL('../../pages/SettingsPage.jsx', import.meta.url), 'utf8')
const supabaseAuth = readFileSync(new URL('../../services/supabase/auth.js', import.meta.url), 'utf8')
const laravelAuth = readFileSync(new URL('../../services/laravel/auth.js', import.meta.url), 'utf8')
const authService = readFileSync(new URL('../../services/auth.js', import.meta.url), 'utf8')

// Bug: after a superuser changed their OWN password (via My Account, or via the
// Members & Roles admin panel targeting their own member row), Settings kept
// running with the now-invalid session, surfaced "Settings could not be loaded",
// and left a stale authenticated shell until a manual browser refresh. The fix is
// to end the session locally and hand off to Login immediately on success, without
// attempting any further authenticated request (e.g. a Settings/tenant refetch)
// first — and to never do this for an admin resetting ANOTHER member's password.

test('AuthProvider exposes a local-only session teardown for self credential changes', () => {
  assert.match(authProvider, /async endSessionForOwnCredentialChange\(/)
  // Must use the local-scope sign-out (no network revoke call against a token that
  // may already be invalid server-side), not the default signOut() used for a
  // deliberate user-initiated logout.
  const method = authProvider.match(/async endSessionForOwnCredentialChange\([\s\S]*?\n {6}\},/)?.[0] ?? ''
  assert.match(method, /signOutLocal\(\)/)
  assert.doesNotMatch(method, /await signOut\(\)/)
  assert.match(method, /setSession\(null\)/)
  assert.match(method, /clearDraftsForUser/)
  assert.match(method, /clearUIStateForUser/)
})

test('local-only sign-out never throws on an already-invalid session and never logs credentials or tokens', () => {
  assert.match(supabaseAuth, /export async function signOutLocal/)
  assert.match(supabaseAuth, /scope: 'local'/)
  assert.match(laravelAuth, /export async function signOutLocal/)
  assert.match(authService, /export const signOutLocal = /)
  const combined = authProvider + supabaseAuth + laravelAuth + authService
  assert.doesNotMatch(combined, /console\.(log|error|warn)\([^)]*\b(password|token|access_token|refresh_token)\b/i)
})

test('My Account self password change ends the session locally instead of reloading authenticated data', () => {
  const fn = myAccountPage.match(/async function savePassword[\s\S]*?\n {2}\}/)?.[0] ?? ''
  assert.match(fn, /updateMyPassword\(/)
  assert.match(fn, /endSessionForOwnCredentialChange\(/)
  // No success banner is set on this page after a self password change: the app
  // must transition straight to Login, not keep this authenticated view mounted.
  assert.doesNotMatch(fn, /setMessage\(\{ notice:/)
  // Must not attempt any further authenticated refetch (profile/tenant/settings)
  // after the password call succeeds and before ending the session.
  assert.doesNotMatch(fn, /tenant\.refresh\(\)|loadSettings\(/)
})

test('admin Reset password for another member keeps refreshing Settings and preserves the admin session', () => {
  const fn = settingsPage.match(/async function resetMemberPassword[\s\S]*?\n {2}\}/)?.[0] ?? ''
  assert.match(fn, /resetManagedMemberPassword\(/)
  // Self-target branches into the same local session teardown used by My Account...
  assert.match(fn, /member\.user_id === user\.id/)
  assert.match(fn, /endSessionForOwnCredentialChange\(/)
  // ...but resetting ANOTHER member's password must still fall through to done(),
  // which reloads Settings using the admin's own (untouched) session.
  assert.match(fn, /await done\('Member password reset\. Existing credentials were not revealed\.'\)/)
})

test('the self-target branch returns before reaching the Settings refetch, avoiding the reported race', () => {
  const fn = settingsPage.match(/async function resetMemberPassword[\s\S]*?\n {2}\}/)?.[0] ?? ''
  const selfBranch = fn.match(/if \(member\.user_id === user\.id\) \{[\s\S]*?\}/)?.[0] ?? ''
  assert.match(selfBranch, /return/)
})

test('Login can show a one-time notice handed off after a self credential change, without persisting it beyond one read', () => {
  assert.match(postLoginNotice, /export function setPostLoginNotice/)
  assert.match(postLoginNotice, /export function consumePostLoginNotice/)
  assert.match(postLoginNotice, /removeItem/)
  assert.match(loginPage, /consumePostLoginNotice/)
  // The notice channel only ever carries the fixed, hardcoded copy the callers pass
  // in (e.g. "Password changed. Please sign in again.") — it must never be wired to
  // carry an actual credential or session token value.
  assert.doesNotMatch(postLoginNotice, /currentPassword|access_token|refresh_token/i)
})
