import test from 'node:test'
import assert from 'node:assert/strict'
import { mkdtempSync, mkdirSync, writeFileSync, rmSync, readFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { spawnSync } from 'node:child_process'
import { randomBytes, createHash } from 'node:crypto'

const validator = new URL('./validate-frontend-backup.sh', import.meta.url).pathname
function fixture(t, { large = false, sourceTree = false, missingIndex = false, corrupt = false } = {}) {
  const dir = mkdtempSync(join(tmpdir(), 'a3-backup-validator-'))
  t.after(() => rmSync(dir, { recursive: true, force: true }))
  const tree = join(dir, 'tree')
  mkdirSync(tree)
  const files = []
  function add(name, data) {
    const path = join(tree, name)
    mkdirSync(join(path, '..'), { recursive: true })
    writeFileSync(path, data)
    files.push(name)
  }
  if (!missingIndex) add('index.html', sourceTree
    ? '<html><body><script type="module" src="/src/main.jsx"></script></body></html>'
    : '<html><script src="/assets/index-abc.js"></script></html>')
  add('.htaccess', 'Options -Indexes')
  const prefix = sourceTree ? 'dist/' : ''
  add(prefix + 'assets/index-abc.js', randomBytes(4096))
  add(prefix + 'assets/index-abc.css', 'body { color: black; }')
  add(prefix + 'build-manifest.json', '{"dataBackend":"laravel","apiBaseUrl":"/api/v1"}')
  if (sourceTree) {
    add('dist/index.html', '<html><script src="/assets/index-abc.js"></script></html>')
    add('src/main.jsx', '<App />')
  }
  if (large) for (let i = 0; i < 3000; i++) add(`docs/${'x'.repeat(120)}-${i}.txt`, 'fixture')
  const archive = join(dir, 'backup.tar.gz')
  const names = join(dir, 'files.txt')
  writeFileSync(names, files.join('\n') + '\n')
  const tar = spawnSync('tar', ['-czf', archive, '-C', tree, '-T', names], { encoding: 'utf8' })
  assert.equal(tar.status, 0, tar.stderr)
  if (corrupt) writeFileSync(archive, randomBytes(2048))
  return archive
}
function run(archive) {
  return spawnSync('bash', [validator, archive], { encoding: 'utf8' })
}
for (const [name, options] of [
  ['normal valid frontend archive', {}],
  ['large valid archive with index early', { large: true }],
  ['large accidentally published repository tree', { large: true, sourceTree: true }],
]) test(name, t => {
  const archive = fixture(t, options)
  if (options.large) {
    const listing = spawnSync('tar', ['-tzf', archive], { encoding: 'utf8' }).stdout
    assert.ok(listing.length > 300000)
    assert.equal(listing.split('\n')[0], 'index.html')
  }
  const result = run(archive)
  assert.equal(result.status, 0, result.stdout + result.stderr)
  assert.match(result.stdout, /CLASSIFICATION=VALID/)
  assert.match(result.stdout, /HAS_HTACCESS=YES/)
  assert.match(result.stdout, /HAS_CSS=YES/)
  const hash = createHash('sha256').update(readFileSync(archive)).digest('hex')
  assert.ok(result.stdout.includes(`SHA256=${hash}`))
})
test('missing index is rejected', t => {
  const result = run(fixture(t, { large: true, missingIndex: true }))
  assert.equal(result.status, 1)
  assert.match(result.stdout, /archive has no index.html/)
})
test('corrupted archive is rejected', t => {
  const result = run(fixture(t, { corrupt: true }))
  assert.equal(result.status, 1)
  assert.match(result.stdout, /corrupt\/truncated/)
})
test('large listing reproduces original SIGPIPE and direct search avoids it', () => {
  const result = spawnSync('bash', ['-c', `
set -o pipefail
listing=$(printf 'index.html\\n'; printf 'assets/file-%s.js\\n' {1..30000})
echo "$listing" | grep -qE '(^|/)index\\.html$'
printf 'ORIGINAL=%s\\n' "\${PIPESTATUS[*]}"
grep -qE '(^|/)index\\.html$' <<< "$listing"
printf 'FIXED=%s\\n' "$?"
`], { encoding: 'utf8' })
  assert.equal(result.status, 0, result.stderr)
  assert.match(result.stdout, /ORIGINAL=141 0/)
  assert.match(result.stdout, /FIXED=0/)
})
