import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { applicabilityLabels, normalizeErrorCodeInput, technicalReferenceLabel } from './troubleshootingModel.js'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const section = read('./TroubleshootingSection.jsx')
const detail = read('./TroubleshootingDetailPage.jsx')
const shell = read('../../../app/AppShell.jsx')
const routes = read('../../../hooks/useAppRoute.js')
const styles = read('../../../App.css')

test('technician-safe normalization accepts harmless formatting without guessing another code', () => {
  for (const input of ['3913', 'C3913', 'C-3913', 'c3913', 'c-3913']) assert.equal(normalizeErrorCodeInput(input), 'C-3913')
  assert.equal(normalizeErrorCodeInput('C-C152'), 'C-C152')
  assert.equal(normalizeErrorCodeInput('cc152'), 'C-C152')
  assert.equal(normalizeErrorCodeInput('C-D010'), 'C-D010')
  assert.equal(normalizeErrorCodeInput('C/E012'), null)
  assert.equal(normalizeErrorCodeInput(''), null)
})

test('applicability labels preserve every server-provided variant choice', () => {
  assert.deepEqual(applicabilityLabels({ applicabilities: [{ scope_label: 'FS-531' }, { scope_label: 'FS-612' }] }), ['FS-531', 'FS-612'])
  assert.deepEqual(applicabilityLabels({ applicabilities: [], variant_key: 'FS-531_FS-612' }), ['FS-531 / FS-612'])
})

test('search has keyboard/mobile submit, bounded API loading, and clear loading/empty/error/variant states', () => {
  assert.match(section, /<form className="troubleshooting-search" role="search" onSubmit=\{submit\}>/)
  assert.match(section, /enterKeyHint="search"/)
  assert.match(section, /searchOfficialErrorEntries\(normalized, 20\)/)
  assert.match(section, /Mencari penanganan resmi/)
  assert.match(section, /Kode error tidak ditemukan di manual yang tersedia/)
  assert.match(section, /Pencarian belum dapat dimuat/)
  assert.match(section, /tersedia untuk beberapa unit/)
  assert.match(section, /Semua varian tetap ditampilkan/)
})

test('detail is stable-ID routed and renders technician content in operational order', () => {
  assert.match(routes, /maintenance\\\/troubleshooting\\\//)
  assert.match(shell, /TroubleshootingDetailPage/)
  const originalStart = detail.indexOf('function AuthoritativeSections')
  const original = detail.slice(originalStart, detail.indexOf('function AssistedSections'))
  assert.ok(original.indexOf('title="Penanganan"') < original.indexOf('title="Penyebab"'))
  assert.match(detail, /function SharedOfficialSections[\s\S]*?Part Terkait[\s\S]*?<SafetyNotice[\s\S]*?<TechnicalReferences[\s\S]*?<SourceProvenance/)
  assert.match(detail, /entry\.steps\.map/)
  assert.doesNotMatch(detail, /\.sort\(/)
})

test('valid assisted content defaults to easy Indonesian and keeps Original one tap away', () => {
  assert.match(detail, /entry\.assisted &&/)
  assert.match(detail, /selectedMode !== 'original'/)
  assert.match(detail, />Mudah Dipahami</)
  assert.match(detail, />Original</)
  assert.match(detail, /entry\.assisted\.notice/)
  assert.match(detail, /assisted\.steps\.map/)
  assert.match(detail, /official_step_id/)
  assert.match(detail, /SharedOfficialSections entry=\{entry\}/)
})

test('optional detail sections are conditional and normal UI omits internal provenance', () => {
  assert.match(detail, /entry\.cause &&/)
  assert.match(detail, /entry\.parts\?\.length > 0/)
  assert.match(detail, /if \(!warning && !alertMeasure\) return null/)
  assert.match(detail, /if \(!entry\.references\?\.length && !hasTechnicalText\) return null/)
  assert.doesNotMatch(detail, /raw_source_text|source_hash|normalized_digest|ingestion_run/)
})

test('technical reference labels remain explicit and mobile layout avoids horizontal detail scrolling', () => {
  assert.equal(technicalReferenceLabel('WIRING_DIAGRAM'), 'Wiring')
  assert.equal(technicalReferenceLabel('IO_CHECK'), 'I/O')
  assert.equal(technicalReferenceLabel('DIPSW'), 'DIPSW')
  assert.match(styles, /@media \(max-width: 520px\)[\s\S]*?\.troubleshooting-search/)
  assert.match(styles, /\.troubleshooting-result-card \{ grid-template-columns: minmax\(0,1fr\); \}/)
  assert.match(styles, /\.manufacturer-copy[^}]*white-space: pre-wrap/)
  assert.match(styles, /@media \(max-width: 520px\)[\s\S]*?\.assisted-mode-switch/)
})
