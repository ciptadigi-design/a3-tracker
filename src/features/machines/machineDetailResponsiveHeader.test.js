import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const css = readFileSync(new URL('../../App.css', import.meta.url), 'utf8')
const pageHeader = readFileSync(new URL('../../components/ui/PageHeader.jsx', import.meta.url), 'utf8')
const machineDetail = readFileSync(new URL('./MachineDetailPage.jsx', import.meta.url), 'utf8')

test('page header wraps instead of squeezing action buttons when the title is long', () => {
  assert.match(css, /\.page-header\s*\{[^}]*flex-wrap:\s*wrap/)
  assert.match(css, /\.page-header-copy\s*\{[^}]*flex:\s*1 1 320px/)
  assert.match(pageHeader, /className="page-header-copy"/)
})

test('detail action buttons keep their readable size and wrap instead of compressing', () => {
  assert.match(css, /\.detail-actions\s*\{[^}]*flex-wrap:\s*wrap/)
  assert.match(css, /\.primary-button,\s*\.secondary-button\s*\{[^}]*flex-shrink:\s*0[^}]*white-space:\s*nowrap/)
  assert.match(css, /\.danger-button,\s*\.danger-outline-button\s*\{[^}]*flex-shrink:\s*0[^}]*white-space:\s*nowrap/)
})

test('machine detail header still renders Troubleshooting, Maintenance History, Edit and Retire actions', () => {
  assert.match(machineDetail, /className="detail-actions"/)
  assert.match(machineDetail, /Troubleshooting<\/button>/)
  assert.match(machineDetail, /Maintenance History<\/button>/)
  assert.match(machineDetail, /Edit machine<\/button>/)
  assert.match(machineDetail, /Retire machine<\/button>/)
})
