import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import test from 'node:test'

// rollback-release.sh's core swap path delegates to verify-release.sh, which
// (like verify-release.test.mjs explains) needs a genuinely working Laravel
// app to prove release identity - not something worth faking in a Node
// fixture. This file covers everything reachable without one: the
// request-level validation that runs BEFORE verify-release.sh is ever
// called, plus a static proof that the script contains no database-mutating
// or public_html-replacing command anywhere in its source. The full
// dry-run-against-a-real-release path is verified in the M2.18.2 Phase 13
// read-only precheck against actual retained Production releases - the most
// convincing evidence available, and more meaningful than a synthetic one.
const script = new URL('./rollback-release.sh', import.meta.url).pathname
const scriptSource = readFileSync(new URL('./rollback-release.sh', import.meta.url), 'utf8')
const SHA = 'b'.repeat(40)

function run(args) {
  try {
    const stdout = execFileSync(script, args, { encoding: 'utf8' })
    return { code: 0, stdout, stderr: '' }
  } catch (error) {
    return { code: error.status, stdout: error.stdout ?? '', stderr: error.stderr ?? '' }
  }
}

test('the script never runs a database restore, dump, or migration command', () => {
  assert.doesNotMatch(scriptSource, /mysqldump|mysql\s+-|DROP\s+DATABASE|DELETE\s+FROM|artisan\s+migrate(?!:status)/i)
})

test('the script never removes or recreates the public_html directory itself', () => {
  assert.doesNotMatch(scriptSource, /rm\s+-rf\s+"?\$public_html_dir"?/)
  assert.doesNotMatch(scriptSource, /mkdir\s+.*\$public_html_dir/)
})

test('the script delegates frontend sync to sync-public-html.sh rather than reimplementing it', () => {
  assert.match(scriptSource, /sync-public-html\.sh/)
})

test('the script requires an explicit confirmation gate before an unproven DB-compatible swap', () => {
  assert.match(scriptSource, /--confirm-db-compatible/)
  assert.match(scriptSource, /exit 3/)
})

test('missing arguments print usage and exit non-zero', () => {
  const result = run([])
  assert.notEqual(result.code, 0)
  assert.match(result.stderr, /usage:/)
})

test('a malformed target SHA is rejected before touching the filesystem', () => {
  const result = run(['not-a-real-sha', '/tmp/does-not-matter', '/tmp/does-not-matter'])
  assert.notEqual(result.code, 0)
  assert.match(result.stderr, /not an exact 40-character lowercase-hex commit SHA/)
})

test('an unknown target release is rejected', () => {
  const appRoot = mkdtempSync(join(tmpdir(), 'rollback-app-root-'))
  const publicHtml = mkdtempSync(join(tmpdir(), 'rollback-public-html-'))
  try {
    mkdirSync(join(appRoot, 'releases'), { recursive: true })
    const result = run([SHA, appRoot, publicHtml])
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /target release not found/)
  } finally {
    rmSync(appRoot, { recursive: true, force: true })
    rmSync(publicHtml, { recursive: true, force: true })
  }
})

test('a missing public_html directory is rejected', () => {
  const appRoot = mkdtempSync(join(tmpdir(), 'rollback-app-root-'))
  try {
    mkdirSync(join(appRoot, 'releases', SHA, 'backend'), { recursive: true })
    const result = run([SHA, appRoot, '/nonexistent/public_html'])
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /public_html directory not found/)
  } finally {
    rmSync(appRoot, { recursive: true, force: true })
  }
})
