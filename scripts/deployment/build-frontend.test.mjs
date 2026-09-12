import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, relative } from 'node:path'
import test from 'node:test'

// End-to-end: runs the real Production build wrapper (not a stub) and reads
// the artifact it produces, the same way verify-frontend-backend.sh does.
// This exists because an earlier version of build-frontend.sh had an
// off-by-one in its `node -e ... "$dist_dir" ...` argv indexing that
// silently wrote the manifest to the wrong path - a stub/mocked test would
// not have caught it; only actually running the script does.
const repoRoot = new URL('../../', import.meta.url).pathname
const script = join(repoRoot, 'scripts/deployment/build-frontend.sh')

test('build-frontend.sh produces a dist/ that verify-frontend-backend.sh accepts as laravel-backed', { timeout: 60_000 }, () => {
  const distDir = mkdtempSync(join(tmpdir(), 'build-frontend-dist-'))
  try {
    const relativeDist = relative(repoRoot, distDir)
    execFileSync(script, [relativeDist], { cwd: repoRoot, stdio: 'pipe' })

    const manifestPath = join(distDir, 'build-manifest.json')
    assert.equal(existsSync(manifestPath), true, 'build-manifest.json must be written into the requested dist dir')
    const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'))
    assert.equal(manifest.dataBackend, 'laravel')
    assert.equal(manifest.apiBaseUrl, '/api/v1')
    assert.match(manifest.gitSha, /^[0-9a-f]{40}$|^unknown$/)

    const verifyScript = join(repoRoot, 'scripts/deployment/verify-frontend-backend.sh')
    const output = execFileSync(verifyScript, [distDir], { encoding: 'utf8' })
    assert.match(output, /BACKEND_VERIFIED=laravel/)
  } finally {
    rmSync(distDir, { recursive: true, force: true })
  }
})
