import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { existsSync, lstatSync, mkdirSync, mkdtempSync, readFileSync, readlinkSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import test from 'node:test'

// V1.4: DocumentStorageService writes uploaded PDFs to storage/app/private/
// maintenance-documents on a fresh release's own filesystem. Every release is an
// independent `git clone`, so without this script's symlink treatment, the very
// next deploy would silently orphan every previously uploaded document - the
// database row survives, the physical file does not. These tests pin exactly
// that: both the pre-existing sessions link and the new documents link.
const script = new URL('./link-shared-storage.sh', import.meta.url).pathname

function makeReleaseDir() {
  const dir = mkdtempSync(join(tmpdir(), 'link-shared-storage-release-'))
  mkdirSync(join(dir, 'backend'), { recursive: true })
  return dir
}

function run(args) {
  try {
    const stdout = execFileSync(script, args, { encoding: 'utf8' })
    return { code: 0, stdout }
  } catch (error) {
    return { code: error.status, stdout: error.stdout ?? '', stderr: error.stderr ?? '' }
  }
}

test('links both sessions and maintenance-documents into shared, merging pre-existing files from the release', () => {
  const releaseDir = makeReleaseDir()
  const sharedDir = mkdtempSync(join(tmpdir(), 'link-shared-storage-shared-'))
  const sessionsDir = join(releaseDir, 'backend/storage/framework/sessions')
  const documentsDir = join(releaseDir, 'backend/storage/app/private/maintenance-documents')
  mkdirSync(sessionsDir, { recursive: true })
  mkdirSync(documentsDir, { recursive: true })
  writeFileSync(join(sessionsDir, 'sess_abc'), 'session-data')
  writeFileSync(join(documentsDir, 'doc1.pdf'), 'pdf-bytes')

  try {
    const result = run([releaseDir, sharedDir])
    assert.equal(result.code, 0)

    assert.equal(lstatSync(sessionsDir).isSymbolicLink(), true)
    assert.equal(readlinkSync(sessionsDir), join(sharedDir, 'storage/framework/sessions'))
    assert.equal(readFileSync(join(sharedDir, 'storage/framework/sessions/sess_abc'), 'utf8'), 'session-data')

    assert.equal(lstatSync(documentsDir).isSymbolicLink(), true)
    assert.equal(readlinkSync(documentsDir), join(sharedDir, 'storage/app/private/maintenance-documents'))
    assert.equal(readFileSync(join(sharedDir, 'storage/app/private/maintenance-documents/doc1.pdf'), 'utf8'), 'pdf-bytes')
  } finally {
    rmSync(releaseDir, { recursive: true, force: true })
    rmSync(sharedDir, { recursive: true, force: true })
  }
})

test('is idempotent - re-running against an already-linked release succeeds without touching the shared files', () => {
  const releaseDir = makeReleaseDir()
  const sharedDir = mkdtempSync(join(tmpdir(), 'link-shared-storage-shared-'))
  try {
    assert.equal(run([releaseDir, sharedDir]).code, 0)
    const second = run([releaseDir, sharedDir])
    assert.equal(second.code, 0)
    assert.match(second.stdout, /Already linked/)
  } finally {
    rmSync(releaseDir, { recursive: true, force: true })
    rmSync(sharedDir, { recursive: true, force: true })
  }
})

test('a subsequent fresh release (no pre-existing directories) sees every previously uploaded document through the shared link', () => {
  const sharedDir = mkdtempSync(join(tmpdir(), 'link-shared-storage-shared-'))
  const releaseA = makeReleaseDir()
  const releaseB = makeReleaseDir()
  try {
    run([releaseA, sharedDir])
    writeFileSync(join(sharedDir, 'storage/app/private/maintenance-documents/existing.pdf'), 'already-uploaded')

    const result = run([releaseB, sharedDir])
    assert.equal(result.code, 0)
    const releaseBDocuments = join(releaseB, 'backend/storage/app/private/maintenance-documents')
    assert.equal(existsSync(releaseBDocuments), true)
    assert.equal(readFileSync(join(releaseBDocuments, 'existing.pdf'), 'utf8'), 'already-uploaded')
  } finally {
    rmSync(releaseA, { recursive: true, force: true })
    rmSync(releaseB, { recursive: true, force: true })
    rmSync(sharedDir, { recursive: true, force: true })
  }
})
