import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { fieldExperienceFailure, fieldExperienceTarget, fieldExperienceUrl } from './troubleshootingModel.js'
import { getFieldExperienceIdFromPath } from '../../../hooks/useAppRoute.js'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const page = read('./FieldExperienceDetailPage.jsx')
const hook = read('./useFieldExperience.js')
const section = read('./TroubleshootingSection.jsx')
const shell = read('../../../app/AppShell.jsx')
const routes = read('../../../hooks/useAppRoute.js')
const styles = read('../../../App.css')
const id = '0b5c6f0e-3c1d-4e53-9a53-6f7a0d0c9e11'

test('authorized results open the existing ticket detail; others open the read-only experience with the search preserved', () => {
  assert.equal(fieldExperienceTarget({ id, can_open: true }, { query: 'C-0213' }), `/maintenance/tickets/${id}`)
  assert.equal(fieldExperienceTarget({ id, can_open: false }, { query: 'C-0213', machineId: 'm1' }), `/maintenance/troubleshooting/experience/${id}?q=C-0213&machine=m1`)
  assert.equal(fieldExperienceTarget({ id }, {}), `/maintenance/troubleshooting/experience/${id}`, 'missing can_open is treated as restricted')
  assert.equal(fieldExperienceTarget({ id, can_open: 'true' }, {}), `/maintenance/troubleshooting/experience/${id}`)
  assert.match(section, /Lihat Penanganan <ArrowRight/)
  assert.equal(section.match(/Lihat Penanganan <ArrowRight/g).length, 2, 'official and experience cards both carry the action')
})

test('the experience route accepts only a UUID and is registered before ticket access checks', () => {
  assert.equal(getFieldExperienceIdFromPath(`/maintenance/troubleshooting/experience/${id}`), id)
  assert.equal(getFieldExperienceIdFromPath('/maintenance/troubleshooting/experience/not-a-uuid'), null)
  assert.equal(getFieldExperienceIdFromPath(`/maintenance/troubleshooting/${id}`), null)
  assert.match(routes, /fieldExperienceDetailPattern\.test\(path\)/)
  assert.match(shell, /getFieldExperienceIdFromPath\(path\) && tenant\.can\('maintenance\.view'\)/)
  assert.match(shell, /key=\{`\$\{tenant\.account\.id\}:/, 'remounts on account switch')
})

test('failures map to clear Indonesian messages without authorization internals', () => {
  assert.equal(fieldExperienceFailure({ status: 404 }).kind, 'not_found')
  assert.equal(fieldExperienceFailure({ status: 422 }).kind, 'not_found', 'invalid id')
  assert.equal(fieldExperienceFailure({ status: 403 }).kind, 'denied')
  assert.equal(fieldExperienceFailure({ message: 'Failed to fetch' }).retry, true)
  assert.equal(fieldExperienceFailure({ status: 500 }).retry, true)
  assert.equal(fieldExperienceFailure({ status: 404 }).retry, false)
  for (const status of [403, 404, 500]) {
    const { title, detail } = fieldExperienceFailure({ status, message: 'This action is unauthorized for membership xyz' })
    assert.doesNotMatch(`${title} ${detail}`, /membership|unauthorized|branch|permission|izin/i)
  }
  assert.equal(fieldExperienceUrl(id), `/maintenance/troubleshooting/experience/${id}`)
})

test('the hook keys state by account and id and clears data on every failure', () => {
  assert.match(hook, /const key = `\$\{accountId\}:\$\{ticketId\}`/)
  assert.match(hook, /experience: current \? state\.data : null/)
  assert.match(hook, /setState\(\{ key, data: null, error \}\)/)
  assert.match(hook, /if \(!cancelled\)/)
  assert.match(hook, /refresh = useCallback\(\(\) => \{ setState\(\{ key: null/, 'retry drops previous data before refetching')
})

test('the read-only page shows the permitted fields and never identities or audit data', () => {
  for (const text of ['Pengalaman Lapangan', 'Berdasarkan pengalaman penanganan sebelumnya, bukan prosedur resmi pabrikan.', 'Perlu Monitoring', 'Kembali ke pencarian', 'Penyebab', 'Tindakan', 'Kegiatan penanganan']) assert.ok(page.includes(text), text)
  for (const field of ['internal_pic_name', 'external_technician_name', 'external_vendor_name', 'operator_name', 'vendor_name', 'email', 'user_id', 'metadata', 'corrects_action_id', 'correction_reason', 'corrected']) assert.ok(!page.includes(field), `page must not reference ${field}`)
  assert.match(page, /item\.test_result === 'monitoring'/)
  assert.match(page, /fieldTestResultLabels\[item\.test_result\]/)
})

test('activities render the effective list as returned, one step each', () => {
  assert.match(page, /activities\.map\(\(activity, index\)/)
  assert.match(page, /activity\.description/)
  assert.doesNotMatch(page, /superseded|history|riwayat koreksi/i)
})

test('official reference link appears only for an explicit official_reference and reuses the official detail route', () => {
  assert.match(page, /item\.official_reference && \(/)
  assert.match(page, /troubleshootingDetailUrl\(item\.official_reference\.entry_id, machineId\)/)
  assert.doesNotMatch(page, /error_code[^\n]*troubleshootingDetailUrl|troubleshootingDetailUrl\([^)]*error_code/, 'never inferred from the code text')
})

test('ticket shortcut is shown only when the server says can_open', () => {
  assert.match(page, /item\.can_open && <button/)
})

test('layout is compact and collapses on phones', () => {
  assert.match(styles, /\.field-experience-detail-card \{/)
  assert.match(styles, /@media[^{]*\{[^}]*\.field-experience-resolution \{ grid-template-columns: minmax\(0, 1fr\)/s)
  assert.match(styles, /\.field-experience-card \{ min-width: 0;/)
  assert.match(styles, /overflow-wrap: anywhere/)
})
