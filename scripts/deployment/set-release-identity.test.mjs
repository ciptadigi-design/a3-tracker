import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { lstatSync, mkdtempSync, readFileSync, readlinkSync, rmSync, symlinkSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import test from 'node:test'

// M2.18.2: `sed -i` replaces a symlink with a new regular file when editing
// in place, silently breaking a release's link to the shared .env - true of
// every deploy since M2.17.5.3, caught only when verify-release.sh's
// preflight gate rejected a real Production release for exactly this reason.
// These tests exist specifically to pin the fix: editing through a symlink
// must never turn it into a real file.
const script = new URL('./set-release-identity.sh', import.meta.url).pathname
const SHA_A = 'a'.repeat(40)
const SHA_B = 'b'.repeat(40)

function makeSharedEnv(contents) {
  const dir = mkdtempSync(join(tmpdir(), 'set-release-identity-shared-'))
  const envPath = join(dir, '.env')
  writeFileSync(envPath, contents)
  return { dir, envPath }
}

function run(args) {
  try {
    const stdout = execFileSync(script, args, { encoding: 'utf8' })
    return { code: 0, stdout, stderr: '' }
  } catch (error) {
    return { code: error.status, stdout: error.stdout ?? '', stderr: error.stderr ?? '' }
  }
}

test('editing through a symlink updates the shared target and never replaces the symlink itself', () => {
  const { dir, envPath } = makeSharedEnv('APP_ENV=production\nAPP_GIT_SHA="oldsha"\n')
  const releaseDir = mkdtempSync(join(tmpdir(), 'set-release-identity-release-'))
  const linkPath = join(releaseDir, '.env')
  symlinkSync(envPath, linkPath)
  try {
    const result = run([linkPath, SHA_A])
    assert.equal(result.code, 0)
    assert.match(result.stdout, new RegExp(`RELEASE_IDENTITY_SET=${SHA_A}`))
    assert.equal(lstatSync(linkPath).isSymbolicLink(), true, 'the release-side path must remain a symlink')
    assert.equal(readlinkSync(linkPath), envPath, 'the symlink must still point at the shared .env')
    assert.match(readFileSync(envPath, 'utf8'), new RegExp(`APP_GIT_SHA="${SHA_A}"`))
  } finally {
    rmSync(dir, { recursive: true, force: true })
    rmSync(releaseDir, { recursive: true, force: true })
  }
})

test('a second release editing the same shared .env through its own symlink both updates correctly and preserves both symlinks', () => {
  const { dir, envPath } = makeSharedEnv('APP_GIT_SHA="oldsha"\n')
  const releaseA = mkdtempSync(join(tmpdir(), 'set-release-identity-release-'))
  const releaseB = mkdtempSync(join(tmpdir(), 'set-release-identity-release-'))
  const linkA = join(releaseA, '.env')
  const linkB = join(releaseB, '.env')
  symlinkSync(envPath, linkA)
  symlinkSync(envPath, linkB)
  try {
    run([linkA, SHA_A])
    const second = run([linkB, SHA_B])
    assert.equal(second.code, 0)
    assert.equal(lstatSync(linkA).isSymbolicLink(), true)
    assert.equal(lstatSync(linkB).isSymbolicLink(), true)
    // Both releases share one .env, so the second deploy's SHA is what's live for both.
    assert.match(readFileSync(envPath, 'utf8'), new RegExp(`APP_GIT_SHA="${SHA_B}"`))
  } finally {
    rmSync(dir, { recursive: true, force: true })
    rmSync(releaseA, { recursive: true, force: true })
    rmSync(releaseB, { recursive: true, force: true })
  }
})

test('appending a first-ever APP_GIT_SHA line also preserves the symlink', () => {
  const { dir, envPath } = makeSharedEnv('APP_ENV=production\n')
  const releaseDir = mkdtempSync(join(tmpdir(), 'set-release-identity-release-'))
  const linkPath = join(releaseDir, '.env')
  symlinkSync(envPath, linkPath)
  try {
    const result = run([linkPath, SHA_A])
    assert.equal(result.code, 0)
    assert.equal(lstatSync(linkPath).isSymbolicLink(), true)
    assert.match(readFileSync(envPath, 'utf8'), new RegExp(`APP_GIT_SHA="${SHA_A}"`))
  } finally {
    rmSync(dir, { recursive: true, force: true })
    rmSync(releaseDir, { recursive: true, force: true })
  }
})

test('a plain (non-symlinked) .env file still works exactly as before', () => {
  const dir = mkdtempSync(join(tmpdir(), 'set-release-identity-plain-'))
  const envPath = join(dir, '.env')
  writeFileSync(envPath, 'APP_GIT_SHA="oldsha"\n')
  try {
    const result = run([envPath, SHA_A])
    assert.equal(result.code, 0)
    assert.equal(lstatSync(envPath).isSymbolicLink(), false)
    assert.match(readFileSync(envPath, 'utf8'), new RegExp(`APP_GIT_SHA="${SHA_A}"`))
  } finally {
    rmSync(dir, { recursive: true, force: true })
  }
})

test('an invalid SHA is rejected without modifying the file', () => {
  const dir = mkdtempSync(join(tmpdir(), 'set-release-identity-plain-'))
  const envPath = join(dir, '.env')
  const original = 'APP_GIT_SHA="oldsha"\n'
  writeFileSync(envPath, original)
  try {
    const result = run([envPath, 'not-a-real-sha'])
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /INVALID_SHA/)
    assert.equal(readFileSync(envPath, 'utf8'), original)
  } finally {
    rmSync(dir, { recursive: true, force: true })
  }
})

test('a missing env file is rejected', () => {
  const result = run(['/nonexistent/.env', SHA_A])
  assert.notEqual(result.code, 0)
  assert.match(result.stderr, /ENV_FILE_NOT_FOUND/)
})
