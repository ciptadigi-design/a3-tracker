import assert from 'node:assert/strict'
import test from 'node:test'
import { callSelectedBackend, resolveDataBackend, selectDataBackend } from './dataBackend.js'

test('an explicit backend is always honored, in dev or production builds', () => {
  assert.equal(selectDataBackend('laravel', false), 'laravel')
  assert.equal(selectDataBackend('laravel', true), 'laravel')
  assert.equal(selectDataBackend('supabase', false), 'supabase')
})

test('an explicit supabase value survives a production build - the DEV/staging behavioral oracle stays available when intentionally configured', () => {
  assert.equal(selectDataBackend('supabase', true), 'supabase')
})

test('a missing backend in a non-production build keeps the Supabase dev default', () => {
  assert.equal(selectDataBackend(undefined, false), 'supabase')
  assert.equal(selectDataBackend('', false), 'supabase')
})

test('a missing backend in a production build fails closed instead of silently becoming Supabase (M2.17.5.6)', () => {
  assert.throws(() => selectDataBackend(undefined, true), /VITE_DATA_BACKEND is required in a production build/)
  assert.throws(() => selectDataBackend('', true), /VITE_DATA_BACKEND is required in a production build/)
})

test('an invalid backend value is rejected regardless of build mode', () => {
  assert.throws(() => selectDataBackend('firebase', false), /Unsupported VITE_DATA_BACKEND/)
  assert.throws(() => selectDataBackend('firebase', true), /Unsupported VITE_DATA_BACKEND/)
  assert.throws(() => resolveDataBackend('firebase'), /Unsupported VITE_DATA_BACKEND/)
})

test('login under an explicit laravel selection resolves through the Laravel auth adapter only', async () => {
  let supabaseCalls = 0
  let laravelCalls = 0
  const supabase = async () => { supabaseCalls += 1; return { signIn: async () => { throw new Error('Supabase auth adapter must not run under VITE_DATA_BACKEND=laravel') } } }
  const laravel = async () => { laravelCalls += 1; return { signIn: async (identifier, password) => ({ user: { id: 'u1', email: identifier }, password: typeof password } ) } }

  const session = await callSelectedBackend({ backend: 'laravel', domain: 'auth', operation: 'signIn', args: ['user@example.com', 'secret'], supabase, laravel })

  assert.equal(laravelCalls, 1)
  assert.equal(supabaseCalls, 0)
  assert.equal(session.user.id, 'u1')
})

test('login under an explicit supabase selection resolves through the Supabase auth adapter only', async () => {
  let supabaseCalls = 0
  let laravelCalls = 0
  const supabase = async () => { supabaseCalls += 1; return { signIn: async () => ({ session: 'supabase-session' }) } }
  const laravel = async () => { laravelCalls += 1; return { signIn: async () => { throw new Error('Laravel auth adapter must not run under VITE_DATA_BACKEND=supabase') } } }

  const session = await callSelectedBackend({ backend: 'supabase', domain: 'auth', operation: 'signIn', args: ['user@example.com', 'secret'], supabase, laravel })

  assert.equal(supabaseCalls, 1)
  assert.equal(laravelCalls, 0)
  assert.deepEqual(session, { session: 'supabase-session' })
})
