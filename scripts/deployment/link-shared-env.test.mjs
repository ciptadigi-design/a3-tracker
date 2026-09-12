import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { lstatSync, mkdirSync, mkdtempSync, readlinkSync, rmSync, symlinkSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import test from 'node:test'

const script = new URL('./link-shared-env.sh', import.meta.url).pathname

// A fake, obviously-not-real secret so a test failure could never leak a
// real credential, and so we can assert this string never appears in
// stdout/stderr (the script must never print .env contents).
const FAKE_ENV_CONTENTS = 'APP_ENV=testing\nFAKE_SECRET_MARKER=not-a-real-value-do-not-leak\n'

function makeSharedDir({ withEnv = true } = {}) {
  const dir = mkdtempSync(join(tmpdir(), 'link-shared-env-shared-'))
  if (withEnv) writeFileSync(join(dir, '.env'), FAKE_ENV_CONTENTS)
  return dir
}

function makeReleaseDir({ withBackend = true, existingEnv = null } = {}) {
  const dir = mkdtempSync(join(tmpdir(), 'link-shared-env-release-'))
  if (withBackend) {
    mkdirSync(join(dir, 'backend'), { recursive: true })
    if (existingEnv === 'real-file') writeFileSync(join(dir, 'backend', '.env'), 'PRE_EXISTING=yes\n')
    if (existingEnv === 'stale-symlink') symlinkSync('/nonexistent/path/.env', join(dir, 'backend', '.env'))
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

test('a valid shared .env is linked successfully', () => {
  const shared = makeSharedDir()
  const release = makeReleaseDir()
  try {
    const result = run([release, shared])
    assert.equal(result.code, 0)
    assert.match(result.stdout, /ENV_LINK_OK=/)
    assert.equal(readlinkSync(resolve(release, 'backend', '.env')), join(shared, '.env'))
  } finally {
    rmSync(shared, { recursive: true, force: true })
    rmSync(release, { recursive: true, force: true })
  }
})

test('the script never prints the .env contents, in success or failure', () => {
  const shared = makeSharedDir()
  const release = makeReleaseDir()
  try {
    const result = run([release, shared])
    assert.doesNotMatch(result.stdout + result.stderr, /FAKE_SECRET_MARKER/)
    assert.doesNotMatch(result.stdout + result.stderr, /not-a-real-value-do-not-leak/)
  } finally {
    rmSync(shared, { recursive: true, force: true })
    rmSync(release, { recursive: true, force: true })
  }
})

test('rerunning against an already-linked release is idempotent', () => {
  const shared = makeSharedDir()
  const release = makeReleaseDir()
  try {
    const first = run([release, shared])
    const second = run([release, shared])
    assert.equal(first.code, 0)
    assert.equal(second.code, 0)
    assert.equal(readlinkSync(resolve(release, 'backend', '.env')), join(shared, '.env'))
  } finally {
    rmSync(shared, { recursive: true, force: true })
    rmSync(release, { recursive: true, force: true })
  }
})

test('a missing shared .env is rejected, not silently linked to nothing', () => {
  const shared = makeSharedDir({ withEnv: false })
  const release = makeReleaseDir()
  try {
    const result = run([release, shared])
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /ENV_LINK_FAIL/)
    assert.match(result.stderr, /shared \.env not found/)
  } finally {
    rmSync(shared, { recursive: true, force: true })
    rmSync(release, { recursive: true, force: true })
  }
})

test('a release-local .env that is a real file (not a symlink) is never clobbered', () => {
  const shared = makeSharedDir()
  const release = makeReleaseDir({ existingEnv: 'real-file' })
  try {
    const result = run([release, shared])
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /refusing to overwrite/)
    assert.equal(lstatSync(resolve(release, 'backend', '.env')).isSymbolicLink(), false)
  } finally {
    rmSync(shared, { recursive: true, force: true })
    rmSync(release, { recursive: true, force: true })
  }
})

test('a stale symlink from a previous release layout is safely replaced', () => {
  const shared = makeSharedDir()
  const release = makeReleaseDir({ existingEnv: 'stale-symlink' })
  try {
    const result = run([release, shared])
    assert.equal(result.code, 0)
    assert.equal(readlinkSync(resolve(release, 'backend', '.env')), join(shared, '.env'))
  } finally {
    rmSync(shared, { recursive: true, force: true })
    rmSync(release, { recursive: true, force: true })
  }
})

test('an unsafe release_dir ("/" or empty) is rejected', () => {
  const shared = makeSharedDir()
  try {
    const result = run(['/', shared])
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /unsafe release_dir/)
  } finally {
    rmSync(shared, { recursive: true, force: true })
  }
})

test('a release with no backend directory is rejected', () => {
  const shared = makeSharedDir()
  const release = makeReleaseDir({ withBackend: false })
  try {
    const result = run([release, shared])
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /no backend directory/)
  } finally {
    rmSync(shared, { recursive: true, force: true })
    rmSync(release, { recursive: true, force: true })
  }
})

test('missing arguments print usage and exit non-zero', () => {
  const result = run([])
  assert.notEqual(result.code, 0)
  assert.match(result.stderr, /usage:/)
})
