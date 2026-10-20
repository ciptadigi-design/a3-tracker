import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { fieldTestResultLabels, fieldTestResultTone } from './troubleshootingModel.js'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const section = read('./TroubleshootingSection.jsx')
const styles = read('../../../App.css')
const service = read('../../../services/maintenance.js')
const api = read('../../../lib/api/maintenance.js')
const controller = read('./troubleshootingSearch.js')
const rule = (selector) => styles.split('\n').filter((line) => line.startsWith(`${selector} {`)).join('\n')

test('one search input feeds both sources; no second form, route or detail page is introduced', () => {
  assert.equal(section.match(/<form /g).length, 1)
  assert.equal(section.match(/role="search"/g).length, 1)
  assert.equal(section.match(/<input /g).length, 1)
  assert.match(section, /placeholder="C-0213, J-2113, hasil kotor, paper exit"/)
  // Phase 3.3D: navigation is decided by the pure fieldExperienceTarget(), never by hard-coded ticket URLs here.
  assert.doesNotMatch(section, /\/maintenance\/tickets\//)
  assert.doesNotMatch(section, /can_open/)
})

test('both groups are labelled and clearly separated; experiences are never presented as official', () => {
  assert.match(section, /aria-label="Pengetahuan Resmi"/)
  assert.match(section, /aria-label="Pengalaman Lapangan"/)
  assert.match(section, /<span className="card-kicker">Pengetahuan Resmi<\/span>/)
  assert.match(section, /<span className="card-kicker">Pengalaman Lapangan<\/span>/)
  assert.match(section, /<span className="field-experience-source">Pengalaman Lapangan<\/span>/)
  assert.match(section, /Berdasarkan penanganan sebelumnya, bukan prosedur resmi pabrikan\./)
  assert.match(section, /Lihat Penanganan/)
  const field = section.slice(section.indexOf('function FieldExperienceResult'), section.indexOf('function GroupState'))
  assert.doesNotMatch(field, /troubleshooting-result-card|Official|resmi/i, 'experience cards use their own card and never official wording')
  assert.match(styles, /\.field-experience-card \{[^}]*border-left: 3px solid var\(--warning\)/)
})

test('numeric searches explain that C and J are separate and may differ', () => {
  assert.match(section, /search\.kind === 'numeric'/)
  assert.match(section, /bisa berarti kode C atau J/)
  assert.match(section, /secara terpisah/)
  assert.match(section, /Hasilnya bisa berbeda/)
  assert.match(section, /search\.codes\.join\(' dan '\)/)
})

test('descriptive queries get a clear official note and a too-short hint, never a fake code', () => {
  assert.match(section, /Pengetahuan resmi dicari berdasarkan kode error/)
  assert.match(section, /Kata kunci terlalu pendek/)
  assert.doesNotMatch(section, /normalizeErrorCodeInput/, 'routing lives in classifyTroubleshootingQuery')
})

test('independent states per group: loading, empty and error each have their own message and retry', () => {
  for (const text of ['Mencari penanganan resmi', 'Mencari pengalaman lapangan', 'Kode error tidak ditemukan di manual yang tersedia', 'Belum ada pengalaman lapangan untuk pencarian ini', 'Pencarian belum dapat dimuat']) assert.match(section, new RegExp(text))
  assert.match(section, /official\.status === 'error'/)
  assert.match(section, /field\.status === 'error'/)
  assert.match(section, /controller\.retry\('official'\)/)
  assert.match(section, /controller\.retry\('field'\)/)
  assert.match(section, /Pengalaman lapangan tetap ditampilkan/)
  assert.match(section, /Pengetahuan resmi tetap ditampilkan/)
  // The failure UI is a different branch from the empty UI, so an outage cannot read as "no results".
  assert.notEqual(section.indexOf("official.status === 'error'"), section.indexOf("official.status === 'empty'"))
})

test('both groups paginate with load-more; page sizes are bounded and requested from the backend', () => {
  assert.equal(section.match(/<LoadMore /g).length, 2)
  assert.match(section, /Muat lebih banyak/)
  assert.match(section, /Menampilkan \{group\.items\.length\} dari \{group\.total\}/)
  assert.match(section, /controller\.loadMore\('official'\)/)
  assert.match(section, /controller\.loadMore\('field'\)/)
  assert.match(service, /searchOfficialErrorEntryPage[\s\S]*per_page: perPage, page/)
  assert.match(service, /searchFieldExperiences[\s\S]*account_id: accountId, q: query, machine_id: machineId, page, per_page: perPage/)
  assert.match(api, /fieldExperiences: \(params = ''\) => apiClient\.get\(`\/maintenance\/field-experiences/)
  assert.match(service, /export const searchOfficialErrorEntries = /, 'legacy official helper is left intact')
})

test('field cards render only safe server fields and the required result labels', () => {
  const field = section.slice(section.indexOf('function FieldExperienceResult'), section.indexOf('function GroupState'))
  for (const used of ['item.error_code', 'item.problem', 'item.machine_name', 'item.model_name', 'item.branch_name', 'item.incident_at', 'item.cause', 'item.action', 'item.test_result', 'item.title']) assert.match(field, new RegExp(used.replace('.', '\\.')))
  for (const forbidden of ['internal_pic_name', 'external_technician_name', 'external_vendor_name', 'reported_by', 'assigned_to', 'email', 'account_id', 'performed_by', 'resolved_by', 'user_id', 'operator_name']) assert.doesNotMatch(section, new RegExp(forbidden), `${forbidden} must never be rendered`)
  for (const label of ['Penyebab', 'Tindakan', 'Cabang', 'Perlu Monitoring']) assert.match(section + read('./troubleshootingModel.js'), new RegExp(label))
  assert.equal(fieldTestResultLabels.monitoring, 'Perlu Monitoring')
  assert.equal(fieldTestResultLabels.normal, 'Normal')
  assert.deepEqual(fieldTestResultTone, { normal: 'done', monitoring: 'in_progress' })
  assert.match(section, /incident-status-pill \$\{tone\}/)
  // Not-normal results are excluded by the backend, so no tone exists for them.
  assert.equal(fieldTestResultTone.not_normal, undefined)
})

test('relevance: official keeps its three classes and nothing is hidden; field uses only server flags', () => {
  for (const label of ['Sesuai model', 'Kemungkinan', 'Tidak sesuai model']) assert.match(section, new RegExp(label))
  assert.match(section, /prioritizeForMachine\(search\.official\.items, selectedMachine\)/)
  assert.doesNotMatch(section + controller, /machine_model_id|model_filter/, 'no naive backend model filter')
  assert.match(section, /item\.model_match &&/)
  assert.match(section, /item\.same_machine &&/)
  assert.match(controller, /machineId: context\.machineId \|\| undefined/)
  assert.match(section, /Semua varian tetap ditampilkan/)
})

test('machine context is sent to field search only once resolved and only when valid', () => {
  assert.match(section, /machineIdPattern\.test\(initialMachineId\)/)
  assert.match(section, /const machineReady = !machineId \|\| !selectedMachineState\.isLoading/)
  assert.match(section, /const contextMachineId = machineId && !selectedMachineState\.error \? machineId : ''/)
  assert.match(section, /controller\.run\(initialQuery, \{ machineId: contextMachineId \}\)/)
})

test('compact, mobile-first layout: no oversized states, stacked cards, 44px touch targets, no horizontal overflow', () => {
  assert.doesNotMatch(rule('.troubleshooting-state-compact'), /min-height: [1-9]\d+/)
  assert.match(rule('.troubleshooting-state-compact'), /min-height: 0/)
  assert.doesNotMatch(rule('.field-experience-card') + rule('.troubleshooting-group'), /min-height|\bheight:/)
  for (const selector of ['.field-experience-card', '.field-experience-head', '.troubleshooting-group']) assert.match(rule(selector), /min-width: 0/)
  assert.match(rule('.field-experience-problem'), /overflow-wrap: anywhere/)
  assert.match(rule('.field-experience-resolution dd'), /overflow-wrap: anywhere/)
  assert.match(styles, /@media \(max-width: 760px\) \{\n {2}\.field-experience-resolution \{ grid-template-columns: minmax\(0, 1fr\); \}/)
  assert.match(styles, /\.troubleshooting-more \.secondary-button \{ width: 100%; \}/)
  assert.match(styles, /\.troubleshooting-more \.secondary-button, \.troubleshooting-state-compact \.secondary-button \{ min-height: 44px; \}/)
  assert.match(rule('.troubleshooting-search input'), /text-transform: none/)
})
