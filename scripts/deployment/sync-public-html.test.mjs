import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { cpSync, existsSync, lstatSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, readlinkSync, realpathSync, rmSync, symlinkSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import test from 'node:test'

const script = new URL('./sync-public-html.sh', import.meta.url).pathname
const builtIndex = '<!doctype html><html><head><script type="module" src="/assets/index-XYZ.js"></script><link rel="stylesheet" href="/assets/index-ABC.css"></head><body><div id="root"></div></body></html>'
const infra = ['.htaccess', 'index.php', '.a3-active']
const hash = value => createHash('sha256').update(value).digest('hex')
function put(root, path, value) {
  mkdirSync(dirname(join(root, path)), { recursive: true })
  writeFileSync(join(root, path), value)
}
function build(root) {
  put(root, 'index.html', builtIndex)
  put(root, 'assets/index-XYZ.js', 'console.log("built")')
  put(root, 'assets/index-ABC.css', 'body { color: blue; }')
  put(root, 'build-manifest.json', JSON.stringify({ dataBackend: 'laravel', apiBaseUrl: '/api/v1', gitSha: 'fixture', builtAt: 'fixture' }))
  put(root, 'a3-tracker.svg', '<svg/>')
}
function fixture(t) {
  const root = realpathSync(mkdtempSync(join(tmpdir(), 'sync guardrail ')))
  t.after(() => rmSync(root, { recursive: true, force: true }))
  const source = join(root, 'artifact with spaces')
  const dest = join(root, 'public with spaces')
  build(source)
  for (const name of infra) put(dest, name, `protected ${name}`)
  put(dest, 'index.html', 'old frontend')
  put(dest, 'sentinel', 'must survive failure')
  put(dest, 'old/nested.txt', 'old asset')
  return { root, source, dest }
}
// Include content, topology, inode, permissions, mtime and ctime. Access time
// is deliberately excluded: merely reading a file can update it on some OSes.
function snapshot(root) {
  const entries = {}
  function visit(path, relative) {
    const s = lstatSync(path, { bigint: true })
    entries[relative] = {
      metadata: ['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtimeNs', 'ctimeNs'].map(k => String(s[k])),
      content: s.isSymbolicLink() ? readlinkSync(path) : s.isFile() ? hash(readFileSync(path)) : null,
    }
    if (s.isDirectory()) for (const name of readdirSync(path).sort()) visit(join(path, name), `${relative}/${name}`)
  }
  visit(root, '.')
  return entries
}
function run(source, dest, options = {}) {
  return spawnSync('bash', [script, source, dest], { encoding: 'utf8', ...options })
}
function rejected(source, dest, options) {
  const before = snapshot(dest)
  const r = run(source, dest, options)
  assert.notEqual(r.status, 0, r.stdout)
  assert.match(r.stderr, /SYNC_FAIL|VERIFICATION_FAILED/)
  assert.doesNotMatch(r.stdout, /SYNC_OK/)
  assert.deepEqual(snapshot(dest), before)
}
function accepted(source, dest) {
  const before = snapshot(dest)
  const r = run(source, dest)
  assert.equal(r.status, 0, r.stderr)
  assert.match(r.stdout, /SYNC_OK/)
  assert.equal(readFileSync(join(dest, 'index.html'), 'utf8'), builtIndex)
  for (const path of ['assets/index-XYZ.js', 'assets/index-ABC.css', 'build-manifest.json', 'a3-tracker.svg']) {
    assert.deepEqual(readFileSync(join(dest, path)), readFileSync(join(source, path)))
  }
  assert.equal(existsSync(join(dest, 'sentinel')), false)
  const after = snapshot(dest)
  assert.equal(after['.'].metadata[1], before['.'].metadata[1])
  assert.equal(after['.'].metadata[2], before['.'].metadata[2])
  for (const name of infra) assert.deepEqual(after[`./${name}`], before[`./${name}`])
}

test('A/O/Q: valid artifact with spaces preserves infrastructure and destination inode', t => {
  const { source, dest } = fixture(t)
  accepted(source, dest)
})
test('M2.19.1 exact incident: release root rejected untouched; release/dist succeeds', t => {
  const { root, source, dest } = fixture(t)
  const release = join(root, 'release')
  put(release, 'index.html', '<html><script type="module" src="/src/main.jsx"></script></html>')
  put(release, 'src/main.jsx', '<App />')
  put(release, 'backend/artisan', '<?php')
  put(release, 'package.json', '{}')
  cpSync(source, join(release, 'dist'), { recursive: true })
  const indexHash = hash(readFileSync(join(dest, 'index.html')))
  rejected(release, dest)
  assert.equal(hash(readFileSync(join(dest, 'index.html'))), indexHash)
  assert.equal(readFileSync(join(dest, 'sentinel'), 'utf8'), 'must survive failure')
  for (const name of ['src', 'backend', 'package.json', 'dist']) assert.equal(existsSync(join(dest, name)), false)
  accepted(join(release, 'dist'), dest)
})

const invalid = {
  'C: source index /src/main.jsx': (s) => put(s, 'index.html', '<script src="/src/main.jsx"></script>'),
  'D: missing manifest': s => rmSync(join(s, 'build-manifest.json')),
  'E: missing assets': s => rmSync(join(s, 'assets'), { recursive: true }),
  'F: missing JS': s => rmSync(join(s, 'assets/index-XYZ.js')),
  'G: missing CSS': s => rmSync(join(s, 'assets/index-ABC.css')),
  'H: Supabase': s => put(s, 'build-manifest.json', '{"dataBackend":"supabase","apiBaseUrl":"/api/v1"}'),
  'I: wrong API base': s => put(s, 'build-manifest.json', '{"dataBackend":"laravel","apiBaseUrl":"/api"}'),
  'malformed manifest': s => put(s, 'build-manifest.json', '{'),
  'manifest directory': s => { rmSync(join(s, 'build-manifest.json')); mkdirSync(join(s, 'build-manifest.json')) },
  'index directory': s => { rmSync(join(s, 'index.html')); mkdirSync(join(s, 'index.html')) },
  'S: nested dist even with a valid parent index': s => build(join(s, 'dist')),
  'T: no assets': s => put(s, 'index.html', '<html><body>empty</body></html>'),
  'T: un-hashed JS': s => { put(s, 'assets/index.js', 'built'); put(s, 'index.html', builtIndex.replace('index-XYZ.js', 'index.js')) },
  'commented-out entry': s => put(s, 'index.html', `<!-- ${builtIndex} -->`),
  'non-module entry': s => put(s, 'index.html', builtIndex.replace('type="module"', '')),
  'base override': s => put(s, 'index.html', builtIndex.replace('<head>', '<head><base href="https://example.com">')),
  'inline script': s => put(s, 'index.html', builtIndex + '<script>bad()</script>'),
}
for (const value of ['/src/other.js', '/@vite/client', 'localhost', '127.0.0.1']) {
  invalid[`J/K: development reference ${value}`] = s => put(s, 'index.html', builtIndex + `<a href="${value}">dev</a>`)
}
for (const value of ['/assets/../index-XYZ.js', '/assets/%2e%2e/index-XYZ.js', '/assets/%252e%252e/index-XYZ.js', '/assets/&#46;&#46;/index-XYZ.js', '/assets/..\\index-XYZ.js', '//example.com/index-XYZ.js', 'https://example.com/index-XYZ.js', '/assets/index-XYZ.js?x', '/assets//index-XYZ.js']) {
  invalid[`L: traversal or noncanonical URL ${value}`] = s => put(s, 'index.html', builtIndex.replace('/assets/index-XYZ.js', value))
}
for (const name of infra) invalid[`source infrastructure collision ${name}`] = s => put(s, name, 'overwrite')
for (const [name, alter] of Object.entries(invalid)) {
  test(`${name}: P destination byte-for-byte and metadata unchanged`, t => {
    const { source, dest } = fixture(t)
    alter(source)
    rejected(source, dest)
  })
}
for (const [name, args] of Object.entries({
  'empty source': (s, d) => ['', d],
  'empty destination': s => [s, ''],
  'root source': (s, d) => ['/', d],
  'root destination': s => [s, '/'],
  'same directory': (s, d) => [d, d],
  'source inside destination': (s, d) => { build(join(d, 'child')); return [join(d, 'child'), d] },
  'destination inside source': (s, d) => { cpSync(d, join(s, 'child'), { recursive: true }); return [s, join(s, 'child')] },
  'missing source': (s, d) => [`${s}-missing`, d],
})) {
  test(`M/N: ${name}`, t => {
    const { root, source, dest } = fixture(t)
    const call = args(source, dest)
    const before = snapshot(root)
    const r = run(...call)
    assert.notEqual(r.status, 0)
    assert.match(r.stderr, /SYNC_FAIL/)
    assert.deepEqual(snapshot(root), before)
  })
}
for (const suffix of ['/', '///', '/.', '/./']) {
  test(`R: trailing slash/dot ${suffix}`, t => {
    const { source, dest } = fixture(t)
    accepted(source + suffix, dest + suffix)
  })
  for (const which of ['source', 'destination']) test(`reject symlink ${which} ${suffix}`, t => {
    const { root, source, dest } = fixture(t)
    const alias = join(root, 'alias')
    symlinkSync(which === 'source' ? source : dest, alias)
    rejected(which === 'source' ? alias + suffix : source, which === 'destination' ? alias + suffix : dest)
  })
}
test('source asset symlink to external file is rejected', t => {
  const { root, source, dest } = fixture(t)
  put(root, 'external.js', 'outside')
  rmSync(join(source, 'assets/index-XYZ.js'))
  symlinkSync(join(root, 'external.js'), join(source, 'assets/index-XYZ.js'))
  rejected(source, dest)
})
test('destination asset symlink is removed without following or modifying its target', t => {
  const { root, source, dest } = fixture(t)
  const outside = join(root, 'outside')
  put(outside, 'sentinel', 'outside')
  symlinkSync(outside, join(dest, 'assets'))
  const before = snapshot(outside)
  accepted(source, dest)
  assert.deepEqual(snapshot(outside), before)
})
test('invalid directory named dist is rejected', t => {
  const { root, dest } = fixture(t)
  const dist = join(root, 'dist')
  put(dist, 'index.html', '<script src="/src/main.jsx"></script>')
  rejected(dist, dest)
})
for (const name of infra) test(`missing infrastructure ${name} is rejected before mutation`, t => {
  const { source, dest } = fixture(t)
  rmSync(join(dest, name))
  rejected(source, dest)
})
test('copy failure reports PARTIAL_SYNC_FAILURE and never SYNC_OK', t => {
  const { root, source, dest } = fixture(t)
  put(root, 'bin/cp', '#!/bin/sh\nexit 42\n')
  const fake = join(root, 'bin/cp')
  spawnSync('chmod', ['+x', fake])
  const r = run(source, dest, { env: { ...process.env, PATH: `${join(root, 'bin')}:${process.env.PATH}` } })
  assert.notEqual(r.status, 0)
  assert.match(r.stderr, /PARTIAL_SYNC_FAILURE/)
  assert.doesNotMatch(r.stdout, /SYNC_OK/)
})
test('post-sync missing asset reports PARTIAL_SYNC_FAILURE', t => {
  const { root, source, dest } = fixture(t)
  // Simulate a copy that claims success while omitting the entire assets entry.
  const realCp = spawnSync('which', ['cp'], { encoding: 'utf8' }).stdout.trim()
  put(root, 'bin/cp', `#!/bin/sh\ncase "$3" in */assets) exit 0;; esac\nexec "${realCp}" "$@"\n`)
  spawnSync('chmod', ['+x', join(root, 'bin/cp')])
  const r = run(source, dest, { env: { ...process.env, PATH: `${join(root, 'bin')}:${process.env.PATH}` } })
  assert.notEqual(r.status, 0)
  assert.match(r.stderr, /PARTIAL_SYNC_FAILURE/)
  assert.doesNotMatch(r.stdout, /SYNC_OK/)
})
for (const name of ['index.html', 'build-manifest.json', 'assets']) test(`symlink artifact member ${name} rejected`, t => {
  const { root, source, dest } = fixture(t)
  const outside = join(root, 'outside')
  cpSync(join(source, name), outside, { recursive: true })
  rmSync(join(source, name), { recursive: true })
  symlinkSync(outside, join(source, name))
  rejected(source, dest)
})
test('unreferenced special file rejected before mutation', t => {
  const { source, dest } = fixture(t)
  const r = spawnSync('mkfifo', [join(source, 'pipe')])
  assert.equal(r.status, 0)
  rejected(source, dest)
})
test('canonical backend verifier remains a mandatory pre-mutation gate', t => {
  const { root, source, dest } = fixture(t)
  const tools = join(root, 'tools')
  put(tools, 'sync-public-html.sh', readFileSync(script))
  put(tools, 'lib/frontend-sync-contract.php', readFileSync(new URL('./lib/frontend-sync-contract.php', import.meta.url)))
  put(tools, 'verify-frontend-backend.sh', '#!/bin/sh\necho CANONICAL_GATE_FAILED >&2\nexit 9\n')
  const before = snapshot(dest)
  const r = spawnSync('bash', [join(tools, 'sync-public-html.sh'), source, dest], { encoding: 'utf8' })
  assert.equal(r.status, 9)
  assert.match(r.stderr, /CANONICAL_GATE_FAILED/)
  assert.deepEqual(snapshot(dest), before)
})
