import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { groupActivities, ticketErrorCodeDisplay } from './maintenanceUtils.js'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const detail = read('./MaintenanceTicketDetailPage.jsx')
const responsible = read('./ResponsibleSection.jsx')
const condition = read('../downtime/MachineConditionSection.jsx')
const css = read('../../App.css')
const ruleFor = (selector) => css.split('\n').filter((line) => line.startsWith(`${selector} {`)).join('\n')

test('error code: ticket master link wins; downtime code is labelled as downtime-only; never invented', () => {
  assert.deepEqual(ticketErrorCodeDisplay({ error_code: { code: 'C-2113', title: 'Fuser' }, downtime_event: { error_code_text: 'X-1' } }), { value: 'C-2113 · Fuser', hint: null })
  assert.deepEqual(ticketErrorCodeDisplay({ error_code: null, downtime_event: { error_code_text: 'C-0213' } }), { value: 'C-0213', hint: 'Dari downtime terkait' })
  assert.deepEqual(ticketErrorCodeDisplay({ error_code: null, downtime_event: { error_code_text: null } }), { value: 'Not specified', hint: null })
  assert.deepEqual(ticketErrorCodeDisplay({ error_code: null }), { value: 'Not specified', hint: null })
  assert.match(detail, /ticketErrorCodeDisplay\(ticket\)/)
})

test('ticket page has four primary sections and no oversized shared cards', () => {
  for (const label of ['Ringkasan ticket', 'Downtime terkait', 'Informasi ticket', 'Penanganan dan penyelesaian']) assert.match(detail, new RegExp(`aria-label="${label}"`))
  assert.doesNotMatch(detail, /incident-detail-hero|detail-grid|incident-narrative|machine-list-card|machine-empty-state|ticket-knowledge-reference/)
  assert.doesNotMatch(responsible, /machine-list-card/)
  assert.doesNotMatch(ruleFor('.ticket-card') + ruleFor('.ticket-activity') + ruleFor('.ticket-subsection') + ruleFor('.ticket-fields'), /min-height|\bheight:/)
})

test('Penanganan & Penyelesaian combines three subsections and hides empty responsible fields', () => {
  assert.match(detail, /Penanganan &amp; Penyelesaian/)
  for (const heading of ['Penanggung Jawab', 'Riwayat Penanganan', 'Hasil Penyelesaian']) assert.match(detail + responsible, new RegExp(`<h3>${heading}</h3>`))
  assert.match(responsible, /ticket\.internal_pic_name && \['PIC Internal'/)
  assert.match(responsible, /ticket\.external_technician_name && \['Teknisi Eksternal'/)
  assert.match(responsible, /ticket\.external_vendor_name && \['Vendor \/ Perusahaan'/)
  assert.match(responsible, /\.filter\(Boolean\)/)
  assert.match(detail, /Belum ada aktivitas penanganan yang dicatat\./)
})

test('resolution result is structured, wraps, and uses the existing status pill palette', () => {
  for (const label of ['Penyebab', 'Tindakan', 'Hasil Pengujian', 'Catatan', 'Waktu Penyelesaian', 'Diselesaikan Oleh']) assert.match(detail, new RegExp(`<Field label="${label}"`))
  assert.match(detail, /testResultTone = \{ normal: 'done', monitoring: 'in_progress', not_normal: 'voided' \}/)
  assert.match(ruleFor('.ticket-fields dd'), /overflow-wrap: anywhere/)
  assert.match(ruleFor('.ticket-fields dd'), /white-space: pre-wrap/)
  for (const tone of ['done', 'in_progress', 'voided']) assert.match(css, new RegExp(`\\.incident-status-pill\\.${tone}\\b`))
})

test('activity history keeps chronological groups, corrections and the correction workflow', () => {
  const rows = [
    { id: 'c', corrects_action_id: 'a', performed_at: '2026-10-19T16:00:00Z' },
    { id: 'b', performed_at: '2026-10-19T15:00:00Z' },
    { id: 'a', performed_at: '2026-10-19T14:00:00Z' },
  ]
  assert.deepEqual(groupActivities(rows).map((g) => [g.original.id, g.corrections.length]), [['a', 1], ['b', 0]])
  assert.deepEqual(groupActivities([]), [])
  assert.match(detail, /setCorrecting\(\{ original, current \}\)/)
  assert.match(detail, /canRecordAction && isOpenTicket && <button/)
  assert.match(detail, /Temuan: \{original\.findings\}/)
})

test('downtime history rows use one aligned grid with status and ticket grouped, and responsive fallbacks', () => {
  assert.match(condition, /className="downtime-history-meta"/)
  assert.match(condition, /className="downtime-history-ticket"/)
  assert.match(ruleFor('.downtime-history-list li'), /align-items: start/)
  assert.match(ruleFor('.downtime-history-meta'), /repeat\(4, minmax\(0, 1fr\)\)/)
  assert.doesNotMatch(ruleFor('.downtime-history-list li') + ruleFor('.downtime-history-meta') + ruleFor('.downtime-history-ticket'), /min-height|\bheight:/)
  assert.match(css, /@media \(max-width: 760px\) \{\n {2}\.downtime-history-meta \{ grid-template-columns: repeat\(2/)
  assert.match(css, /\.downtime-history-ticket button \{ width: 100%; min-height: 44px; \}/)
})

test('UI refinement: timeline sits in one card and the PIC form has no detached action strip', () => {
  const css = read('../../App.css')
  const history = read('./MachineMaintenanceHistory.jsx')
  assert.match(history, /className="machine-maintenance-history glass-surface"/)
  assert.doesNotMatch(history, /maintenance-history-(item|empty) glass-surface/, 'no nested glass cards')
  assert.doesNotMatch(responsible, /machine-form|dialog-actions/, 'the shared dialog footer strip is not used inline')
  assert.match(responsible, /form-field form-field-wide"><span>Vendor/)
  assert.match(css, /\.ticket-form-actions \{ display: flex; justify-content: flex-end; \}/)
  assert.match(css, /\.ticket-responsible-form \.form-grid \{ grid-template-columns: minmax\(0, 1fr\); \}/)
})

test('ticket detail retouch: bounded PIC form, balanced info grid, compact header', () => {
  const css = read('../../App.css')
  assert.match(css, /\.ticket-responsible-form \{[^}]*max-width: 640px/)
  assert.match(css, /\.ticket-fields-info \{ grid-template-columns: repeat\(3, minmax\(0, 1fr\)\)/)
  assert.match(css, /\.ticket-page \.page-header h1 \{[^}]*font-size: clamp\(22px/)
  assert.match(css, /\.ticket-resolution \{ grid-template-columns: repeat\(2, minmax\(0, 1fr\)\)/)
  assert.match(read('./MaintenanceTicketDetailPage.jsx'), /ticket-fields ticket-fields-info/)
})
