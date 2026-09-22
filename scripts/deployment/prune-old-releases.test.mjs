import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, readFileSync, rmSync, symlinkSync, utimesSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import test from 'node:test'

// V1.10 - actually executes the script against a real temp-directory fixture shaped like
// <account>/a3-production-app/releases, the same convention rollback-release.test.mjs uses for
// scripts whose safety behavior is worth proving by running, not just reading.
const script = new URL('./prune-old-releases.sh', import.meta.url).pathname
const scriptSource = readFileSync(new URL('./prune-old-releases.sh', import.meta.url), 'utf8')

function run(args) {
  try {
    const stdout = execFileSync(script, args, { encoding: 'utf8' })
    return { code: 0, stdout, stderr: '' }
  } catch (error) {
    return { code: error.status, stdout: error.stdout ?? '', stderr: error.stderr ?? '' }
  }
}

/** Builds <tmp>/a3-production-app/releases/<names...> with distinct, ordered mtimes, oldest first. */
function fixture(names) {
  const root = mkdtempSync(join(tmpdir(), 'prune-releases-'))
  const releasesRoot = join(root, 'a3-production-app', 'releases')
  mkdirSync(releasesRoot, { recursive: true })
  names.forEach((name, index) => {
    const dir = join(releasesRoot, name)
    mkdirSync(dir)
    mkdirSync(join(dir, 'backend'))
    const t = new Date(Date.now() - (names.length - index) * 60_000)
    utimesSync(dir, t, t)
  })
  return { root, releasesRoot }
}

function withCurrent(releasesRoot, targetName) {
  const current = join(releasesRoot, '..', 'current')
  symlinkSync(join(releasesRoot, targetName), current);
  return current
}

test('usage error on missing arguments', () => {
  const result = run([])
  assert.notEqual(result.code, 0)
  assert.match(result.stderr, /usage: prune-old-releases\.sh/)
})

test('refuses a non-integer or zero keep_count', () => {
  const { releasesRoot } = fixture(['a'])
  const current = withCurrent(releasesRoot, 'a')
  assert.notEqual(run([releasesRoot, current, '0']).code, 0)
  assert.notEqual(run([releasesRoot, current, 'ten']).code, 0)
})

test('fails closed on a releases root that does not exist', () => {
  const result = run(['/nonexistent/a3-production-app/releases', '/nonexistent/current', '5'])
  assert.notEqual(result.code, 0)
  assert.match(result.stderr, /PRUNE_FAIL/)
})

test('fails closed on a releases root that does not have the expected a3-production-app/releases shape', () => {
  const root = mkdtempSync(join(tmpdir(), 'prune-wrong-shape-'))
  const notReleases = join(root, 'some', 'other', 'path')
  mkdirSync(notReleases, { recursive: true })
  const current = join(root, 'current')
  mkdirSync(join(notReleases, 'x'))
  symlinkSync(join(notReleases, 'x'), current)
  const result = run([notReleases, current, '5'])
  assert.notEqual(result.code, 0)
  assert.match(result.stderr, /does not look like an a3-production-app releases directory/)
})

test('fails closed when current_symlink is not actually a symlink', () => {
  const { releasesRoot } = fixture(['a'])
  const notASymlink = join(releasesRoot, 'a'); // a real directory, not a symlink
  const result = run([releasesRoot, notASymlink, '5'])
  assert.notEqual(result.code, 0)
  assert.match(result.stderr, /current_symlink is not a symlink/)
})

test('dry run by default: reports what would be removed but removes nothing', () => {
  const { releasesRoot } = fixture(['r1', 'r2', 'r3', 'r4', 'r5']);
  const current = withCurrent(releasesRoot, 'r5')

  const result = run([releasesRoot, current, '2'])

  assert.equal(result.code, 0)
  assert.match(result.stdout, /PRUNE_MODE=DRY_RUN/)
  assert.match(result.stdout, /PRUNE_RESULT=DRY_RUN_OK/)
  assert.match(result.stdout, /KEEP: r5/)
  assert.match(result.stdout, /KEEP: r4/)
  assert.match(result.stdout, /REMOVE: r3/)
  assert.match(result.stdout, /REMOVE: r2/)
  assert.match(result.stdout, /REMOVE: r1/)
  // nothing was actually removed
  assert.equal(run([releasesRoot, current, '2']).stdout, result.stdout)
})

test('the active release is always preserved even when it would otherwise fall outside the keep window', () => {
  const { releasesRoot } = fixture(['old1', 'old2', 'old3', 'old4', 'active_but_old']);
  // active_but_old is the OLDEST by mtime (last in the fixture array = newest normally; here we
  // point current at the very first/oldest one to prove ordering never overrides active-release safety).
  const current = withCurrent(releasesRoot, 'old1')

  const result = run([releasesRoot, current, '2']);

  assert.equal(result.code, 0)
  assert.match(result.stdout, /KEEP: old1/)
  assert.doesNotMatch(result.stdout, /REMOVE: old1/)
})

test('--apply actually removes exactly the reported release directories and nothing else', () => {
  const { releasesRoot } = fixture(['r1', 'r2', 'r3', 'r4', 'r5']);
  const current = withCurrent(releasesRoot, 'r5')

  const result = run([releasesRoot, current, '2', '--apply'])

  assert.equal(result.code, 0)
  assert.match(result.stdout, /PRUNE_MODE=APPLY/)
  assert.match(result.stdout, /PRUNE_RESULT=PASS/)
  assert.match(result.stdout, /REMOVED: r1/)
  assert.match(result.stdout, /REMOVED: r2/)
  assert.match(result.stdout, /REMOVED: r3/)

  const after = run([releasesRoot, current, '2']) // dry run again to inspect final state
  assert.match(after.stdout, /KEEP: r5/)
  assert.match(after.stdout, /KEEP: r4/)
  assert.match(after.stdout, /REMOVE_COUNT=0/)
})

test('symlinked and non-directory entries under releases_root are skipped, never removed as releases', () => {
  const { releasesRoot } = fixture(['r1', 'r2', 'r3']);
  const current = withCurrent(releasesRoot, 'r3')
  // A stray symlink and a stray file sitting directly under releases_root.
  symlinkSync(join(releasesRoot, 'r1'), join(releasesRoot, 'sneaky-link'))
  const strayFile = join(releasesRoot, 'not-a-dir.txt')
  execFileSync('touch', [strayFile])

  const result = run([releasesRoot, current, '1'])

  assert.equal(result.code, 0)
  // execFileSync only attaches stderr to the returned/thrown result on a non-zero exit (this
  // script exits 0 here - skipping a malformed entry is a warning, not a failure) - so the
  // property that actually matters for safety is checked directly: neither malformed entry is
  // ever treated as a release to keep or remove.
  assert.doesNotMatch(result.stdout, /REMOVE: sneaky-link/)
  assert.doesNotMatch(result.stdout, /REMOVE: not-a-dir\.txt/)
  assert.doesNotMatch(result.stdout, /KEEP: sneaky-link/)
  assert.doesNotMatch(result.stdout, /KEEP: not-a-dir\.txt/)
})

test('output is deterministic across repeated dry runs', () => {
  const { releasesRoot } = fixture(['r1', 'r2', 'r3', 'r4']);
  const current = withCurrent(releasesRoot, 'r4')

  const first = run([releasesRoot, current, '2'])
  const second = run([releasesRoot, current, '2'])

  assert.equal(first.stdout, second.stdout)
})

test('the script never runs outside releases_root: every rm target is validated against it', () => {
  assert.match(scriptSource, /case "\$full" in\s*\n\s*"\$releases_root"\/\*\) ;;/)
  assert.doesNotMatch(scriptSource, /rm -rf --?\s*"\$releases_root"\s*$/m)
})

test('the script never removes anything without --apply, by construction', () => {
  const rmCount = (scriptSource.match(/rm -rf/g) || []).length
  assert.equal(rmCount, 1, 'exactly one rm -rf call in the whole script, gated behind the apply branch')
  const applyBranchIndex = scriptSource.indexOf('if [ "$apply" = false ]')
  const rmIndex = scriptSource.indexOf('rm -rf --')
  assert.ok(rmIndex > applyBranchIndex, 'the rm -rf call is textually after the dry-run early-exit')
})

test('the script checks for a symlink with -L before ever treating a path as a plain release directory', () => {
  assert.match(scriptSource, /\[ -L "\$full" \] \|\| \[ ! -d "\$full" \]/)
})
