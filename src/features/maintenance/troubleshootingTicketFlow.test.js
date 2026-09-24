import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const detail = read('./troubleshooting/TroubleshootingDetailPage.jsx')
const createDialog = read('./CreateTicketDialog.jsx')
const ticketDetail = read('./MaintenanceTicketDetailPage.jsx')
const maintenancePage = read('../../pages/MaintenancePage.jsx')
const styles = read('../../App.css')

test('ticket creation remains an explicit action after troubleshooting content', () => {
  const content = detail.indexOf("assistedMode ? <AssistedSections")
  const cta = detail.indexOf('Buat Ticket Maintenance')

  assert.ok(content >= 0 && cta > content)
  assert.match(detail, /onClick=\{\(\) => setShowCreateTicket\(true\)\}/)
  assert.doesNotMatch(detail, /useEffect\([^)]*createMaintenanceTicket/)
})

test('official prefill links stable knowledge and leaves incident description technician-controlled', () => {
  assert.match(createDialog, /official_error_entry_id: officialKnowledge\?\.id \|\| null/)
  assert.match(createDialog, /const \[description, setDescription\] = useState\(''\)/)
  assert.match(createDialog, /Konten manual tidak disalin ke deskripsi ticket/)
  assert.match(createDialog, /Informasi referensi/)
  assert.match(createDialog, /Informasi insiden/)
  assert.doesNotMatch(createDialog, /officialKnowledge\.(steps|cause|warning|parts|references)/)
})

test('possible applicability is informational and deterministic mismatch requires acknowledgement', () => {
  assert.match(createDialog, /knowledgeMatch === 'possible'/)
  assert.match(createDialog, /bukan sebagai kecocokan aksesori yang terbukti/)
  assert.match(createDialog, /knowledgeMatch === 'not_applicable' && !confirmNotApplicable/)
  assert.match(createDialog, /confirm_not_applicable: knowledgeMatch === 'not_applicable'/)
})

test('direct ticket creation remains available without an official reference', () => {
  assert.match(maintenancePage, /CreateTicketDialog/)
  assert.match(createDialog, /officialKnowledge = null/)
  assert.match(createDialog, /!officialKnowledge && <label className="form-field"/)
})

test('ticket detail shows a compact reference and preserves machine context on backlink', () => {
  assert.match(ticketDetail, /ticket\.official_error_entry &&/)
  assert.match(ticketDetail, /Troubleshooting Reference/)
  assert.match(ticketDetail, /troubleshootingDetailUrl\(ticket\.official_error_entry\.id, ticket\.machine\?\.id\)/)
  assert.doesNotMatch(ticketDetail, /official_error_entry\.(steps|raw_source_text|assisted)/)
})

test('ticket handoff remains usable at mobile width', () => {
  assert.match(styles, /@media \(max-width: 520px\)[\s\S]*?\.troubleshooting-ticket-cta/)
  assert.match(styles, /@media \(max-width: 520px\)[\s\S]*?\.ticket-knowledge-reference/)
})
