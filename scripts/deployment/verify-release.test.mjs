import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { chmodSync, mkdirSync, mkdtempSync, rmSync, symlinkSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import test from 'node:test'

// verify-release.sh's last two checks (release identity, migrate:status)
// require a genuinely working Laravel application to execute php artisan -
// faking that in a Node fixture would be fragile and heavy for what it
// proves. Those two checks, and the full "valid release passes" path, are
// instead verified end-to-end against the real local backend build in
// scripts/deployment/verify-release.test.mjs's sibling: the M2.18.2 Phase 9
// local dry-run (see the milestone report). This file covers every check
// that happens BEFORE that point - the filesystem/manifest layer - which is
// also exactly where a real accidental regression (a broken symlink, a
// missing composer install, a Supabase-defaulted frontend build) would show
// up first.
const script = new URL('./verify-release.sh', import.meta.url).pathname
const SHA = 'a'.repeat(40)

function makeSharedDir() {
  const dir = mkdtempSync(join(tmpdir(), 'verify-release-shared-'))
  writeFileSync(join(dir, '.env'), 'APP_ENV=testing\n')
  mkdirSync(join(dir, 'storage', 'framework', 'sessions'), { recursive: true })
  return dir
}

function makeReleaseDir(shared, opts = {}) {
  const {
    withArtisan = true,
    withVendor = true,
    envLink = 'valid',
    sessionsLink = 'valid',
    withDist = true,
    manifest = { dataBackend: 'laravel', apiBaseUrl: '/api/v1' },
    withIndexHtml = true,
  } = opts

  const dir = mkdtempSync(join(tmpdir(), 'verify-release-release-'))
  mkdirSync(join(dir, 'backend', 'bootstrap', 'cache'), { recursive: true })
  mkdirSync(join(dir, 'backend', 'storage'), { recursive: true })
  if (withArtisan) writeFileSync(join(dir, 'backend', 'artisan'), '#!/usr/bin/env php\n')
  if (withVendor) {
    mkdirSync(join(dir, 'backend', 'vendor'), { recursive: true })
    writeFileSync(join(dir, 'backend', 'vendor', 'autoload.php'), '<?php\n')
  }
  if (envLink === 'valid') symlinkSync(join(shared, '.env'), join(dir, 'backend', '.env'))
  else if (envLink === 'wrong-target') {
    writeFileSync(join(dir, 'backend', '.env.decoy'), 'DECOY=1\n')
    symlinkSync(join(dir, 'backend', '.env.decoy'), join(dir, 'backend', '.env'))
  } else if (envLink === 'real-file') writeFileSync(join(dir, 'backend', '.env'), 'NOT_A_SYMLINK=1\n')
  if (sessionsLink === 'valid') {
    mkdirSync(join(dir, 'backend', 'storage', 'framework'), { recursive: true })
    symlinkSync(join(shared, 'storage', 'framework', 'sessions'), join(dir, 'backend', 'storage', 'framework', 'sessions'))
  }
  if (withDist) {
    mkdirSync(join(dir, 'dist'), { recursive: true })
    if (withIndexHtml) writeFileSync(join(dir, 'dist', 'index.html'), '<!doctype html>\n')
    if (manifest) writeFileSync(join(dir, 'dist', 'build-manifest.json'), JSON.stringify(manifest))
  }
  return dir
}

function run(args) {
  try {
    const stdout = execFileSync(script, args, { encoding: 'utf8' })
    return { code: 0, stdout, stderr: '' }
  } catch (error) {
    return { code: error.status, stdout: error.stdout ?? '', stderr: error.stderr ?? '' }
  }
}

function cleanup(...dirs) {
  for (const d of dirs) rmSync(d, { recursive: true, force: true })
}

test('a release with a missing directory is rejected', () => {
  const shared = makeSharedDir()
  try {
    const result = run(['/nonexistent/release/dir', shared, SHA])
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /release directory not found/)
  } finally { cleanup(shared) }
})

test('a release missing backend/artisan is rejected', () => {
  const shared = makeSharedDir()
  const release = makeReleaseDir(shared, { withArtisan: false })
  try {
    const result = run([release, shared, SHA])
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /backend\/artisan missing/)
  } finally { cleanup(shared, release) }
})

test('a release missing composer vendor/autoload.php is rejected', () => {
  const shared = makeSharedDir()
  const release = makeReleaseDir(shared, { withVendor: false })
  try {
    const result = run([release, shared, SHA])
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /vendor\/autoload\.php missing/)
  } finally { cleanup(shared, release) }
})

test('a backend/.env that is a real file, not a symlink, is rejected', () => {
  const shared = makeSharedDir()
  const release = makeReleaseDir(shared, { envLink: 'real-file' })
  try {
    const result = run([release, shared, SHA])
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /backend\/\.env is not a symlink/)
  } finally { cleanup(shared, release) }
})

test('a backend/.env symlink pointing at the wrong target is rejected', () => {
  const shared = makeSharedDir()
  const release = makeReleaseDir(shared, { envLink: 'wrong-target' })
  try {
    const result = run([release, shared, SHA])
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /does not resolve to the canonical shared \.env/)
  } finally { cleanup(shared, release) }
})

test('a missing shared session storage link is rejected', () => {
  const shared = makeSharedDir()
  const release = makeReleaseDir(shared, { sessionsLink: 'missing' })
  try {
    const result = run([release, shared, SHA])
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /storage\/framework\/sessions is not a symlink/)
  } finally { cleanup(shared, release) }
})

test('an unwritable backend/storage is rejected', () => {
  const shared = makeSharedDir()
  const release = makeReleaseDir(shared)
  try {
    chmodSync(join(release, 'backend', 'storage'), 0o500)
    const result = run([release, shared, SHA])
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /backend\/storage is not writable/)
  } finally {
    chmodSync(join(release, 'backend', 'storage'), 0o755)
    cleanup(shared, release)
  }
})

test('a release with no dist/ directory is rejected', () => {
  const shared = makeSharedDir()
  const release = makeReleaseDir(shared, { withDist: false })
  try {
    const result = run([release, shared, SHA])
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /no dist\/ directory/)
  } finally { cleanup(shared, release) }
})

test('a missing build-manifest.json is rejected', () => {
  const shared = makeSharedDir()
  const release = makeReleaseDir(shared, { manifest: null })
  try {
    const result = run([release, shared, SHA])
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /frontend artifact failed verify-frontend-backend\.sh/)
  } finally { cleanup(shared, release) }
})

test('a Supabase-defaulted frontend manifest is rejected - the exact M2.17.5.6 regression', () => {
  const shared = makeSharedDir()
  const release = makeReleaseDir(shared, { manifest: { dataBackend: 'supabase', apiBaseUrl: '/api/v1' } })
  try {
    const result = run([release, shared, SHA])
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /frontend artifact failed verify-frontend-backend\.sh/)
  } finally { cleanup(shared, release) }
})

test('a malformed expected_sha is rejected as a usage error', () => {
  const shared = makeSharedDir()
  const release = makeReleaseDir(shared)
  try {
    const result = run([release, shared, 'not-a-real-sha'])
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /must be an exact 40-character lowercase-hex commit SHA/)
  } finally { cleanup(shared, release) }
})

test('missing arguments print usage and exit non-zero', () => {
  const result = run([])
  assert.notEqual(result.code, 0)
  assert.match(result.stderr, /usage:/)
})
