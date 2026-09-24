import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { applicabilityLabels, machineApplicability, normalizeErrorCodeInput, prioritizeForMachine, technicalReferenceLabel, troubleshootingDetailUrl, troubleshootingSearchUrl } from './troubleshootingModel.js'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const section = read('./TroubleshootingSection.jsx')
const detail = read('./TroubleshootingDetailPage.jsx')
const shell = read('../../../app/AppShell.jsx')
const routes = read('../../../hooks/useAppRoute.js')
const styles = read('../../../App.css')
const maintenancePage = read('../../../pages/MaintenancePage.jsx')
const machineDetail = read('../../machines/MachineDetailPage.jsx')
const machineService = read('../../../services/laravel/machines.js')

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

test('machine applicability is deterministic and insufficient accessory context remains possible', () => {
  const machine = { machine_model_id: 'model-c1070' }
  const match = { id: 'match', applicabilities: [{ scope_type: 'MAIN_BODY', machine_model_id: 'model-c1070' }] }
  const possible = { id: 'possible', applicabilities: [{ scope_type: 'ACCESSORY', scope_label: 'FS-532', machine_model_id: null }] }
  const mismatch = { id: 'mismatch', applicabilities: [{ scope_type: 'MAIN_BODY', machine_model_id: 'model-xerox' }] }

  assert.equal(machineApplicability(match, machine), 'match')
  assert.equal(machineApplicability(possible, machine), 'possible')
  assert.equal(machineApplicability(mismatch, machine), 'not_applicable')
  assert.deepEqual(prioritizeForMachine([mismatch, possible, match], machine).map((entry) => entry.id), ['match', 'possible', 'mismatch'])
})

test('machine-aware routes preserve optional machine and search context', () => {
  assert.equal(troubleshootingSearchUrl('C-3913'), '/maintenance/troubleshooting?q=C-3913')
  assert.equal(troubleshootingSearchUrl('C-1103', 'machine-1'), '/maintenance/troubleshooting?q=C-1103&machine=machine-1')
  assert.equal(troubleshootingDetailUrl('entry-1', 'machine-1'), '/maintenance/troubleshooting/entry-1?machine=machine-1')
  assert.match(maintenancePage, /searchParams\.get\('machine'\)/)
  assert.match(section, /troubleshootingDetailUrl\(entry\.id, selectedMachine\?\.id\)/)
  assert.match(detail, /troubleshootingSearchUrl\(entry\.code, machine\?\.id\)/)
})

test('machine context uses authorized existing machine APIs and never hides possible variants', () => {
  assert.match(section, /useMachine\(accountId, branchId, initialMachineId\)/)
  assert.match(section, /useMachines\(accountId, branchId\)/)
  assert.match(machineService, /apiClient\.get\(`\/machines\/\$\{machineId\}`\)/)
  assert.match(section, /Semua varian tetap ditampilkan/)
  assert.match(section, /Konteks mesin tidak tersedia/)
  assert.match(machineDetail, /maintenance\/troubleshooting\?machine=/)
})

test('machine-aware UI remains mobile responsive without changing assisted fallback', () => {
  assert.match(styles, /@media \(max-width: 520px\)[\s\S]*?\.troubleshooting-machine-picker/)
  assert.match(section, /Tanpa mesin/)
  assert.match(section, /Sesuai model/)
  assert.match(section, /Kemungkinan/)
  assert.match(section, /Tidak sesuai model/)
  assert.match(detail, /entry\.assisted &&/)
  assert.match(detail, /selectedMode !== 'original'/)
})
