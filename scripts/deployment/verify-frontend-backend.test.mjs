import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import test from 'node:test'

const script = new URL('./verify-frontend-backend.sh', import.meta.url).pathname

function makeDist({ manifest, withIndex = true } = {}) {
  const dir = mkdtempSync(join(tmpdir(), 'verify-frontend-backend-'))
  if (withIndex) writeFileSync(join(dir, 'index.html'), '<!doctype html><html></html>\n')
  if (manifest !== undefined) writeFileSync(join(dir, 'build-manifest.json'), typeof manifest === 'string' ? manifest : JSON.stringify(manifest))
  return dir
}

function run(dir) {
  try {
    const stdout = execFileSync(script, [dir], { encoding: 'utf8' })
    return { code: 0, stdout }
  } catch (error) {
    return { code: error.status, stdout: error.stdout, stderr: error.stderr }
  }
}

test('a laravel-backed artifact with the correct Production API base URL passes', () => {
  const dir = makeDist({ manifest: { dataBackend: 'laravel', apiBaseUrl: '/api/v1' } })
  try {
    const result = run(dir)
    assert.equal(result.code, 0)
    assert.match(result.stdout, /BACKEND_VERIFIED=laravel/)
  } finally { rmSync(dir, { recursive: true, force: true }) }
})

test('a Supabase-defaulted artifact - the exact M2.17.5.6 regression - is rejected', () => {
  const dir = makeDist({ manifest: { dataBackend: 'supabase', apiBaseUrl: '/api/v1' } })
  try {
    const result = run(dir)
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /FRONTEND_BACKEND_VERIFICATION_FAILED/)
    assert.match(result.stderr, /dataBackend='supabase'/)
  } finally { rmSync(dir, { recursive: true, force: true }) }
})

test('a missing manifest is refused rather than assumed innocent', () => {
  const dir = makeDist({})
  try {
    const result = run(dir)
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /no build-manifest\.json/)
  } finally { rmSync(dir, { recursive: true, force: true }) }
})

test('an unparseable manifest is refused, not silently treated as passing', () => {
  const dir = makeDist({ manifest: '{not valid json' })
  try {
    const result = run(dir)
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /FRONTEND_BACKEND_VERIFICATION_FAILED/)
  } finally { rmSync(dir, { recursive: true, force: true }) }
})

test('a wrong API base URL is rejected even when the backend is correctly laravel', () => {
  const dir = makeDist({ manifest: { dataBackend: 'laravel', apiBaseUrl: 'https://old-legacy-host.example.com/api' } })
  try {
    const result = run(dir)
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /apiBaseUrl=/)
  } finally { rmSync(dir, { recursive: true, force: true }) }
})

test('a dist directory without an index.html is refused as not a real build output', () => {
  const dir = makeDist({ manifest: { dataBackend: 'laravel', apiBaseUrl: '/api/v1' }, withIndex: false })
  try {
    const result = run(dir)
    assert.notEqual(result.code, 0)
    assert.match(result.stderr, /no index\.html/)
  } finally { rmSync(dir, { recursive: true, force: true }) }
})
