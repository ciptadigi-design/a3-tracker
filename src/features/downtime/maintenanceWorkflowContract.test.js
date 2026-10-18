import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import {
  describeActiveEntry, describeEventDurations, describeEventStatus, emptyWeek, exceptionToPayload, exceptionTypeLabel, hasActiveDowntime, machineConditionPath,
  monthToPeriod, validateException, validateWeek, weekFromRows, weekToPayload, WEEK_DAYS,
} from './downtimeModel.js'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const condition = read('./MachineConditionSection.jsx')
const detail = read('./DowntimeSection.jsx')
const detailPage = read('../machines/MachineDetailPage.jsx')
const startDialog = read('./StartDowntimeDialog.jsx')
const schedule = read('./schedule/ScheduleSettingsSection.jsx')
const settings = read('../../pages/SettingsPage.jsx')
const maintenance = read('../../pages/MaintenancePage.jsx')
const overview = read('../../pages/OverviewPage.jsx')
const service = read('../../services/downtime.js')

// --- Maintenance > Kondisi Mesin workflow ----------------------------------------

test('Maintenance can report downtime: machine selector, default-now start, duplicate guard, Indonesian labels', () => {
  assert.match(condition, /Laporkan Mesin Berhenti/)
  assert.match(condition, /await startDowntime\(machineId, payload\)/)
  assert.match(startDialog, /<span>Mesin<\/span>/)
  assert.match(startDialog, /activeMachineIds\.includes\(item\.id\)/)
  assert.match(startDialog, /Mesin ini sudah memiliki downtime aktif/)
  assert.match(startDialog, /canBackdate && startAt && startAt !== now/, 'start time is only sent when an authorized role changed it')
  for (const label of ['Waktu mulai', 'Penyebab', 'Kode error', 'Deskripsi']) assert.match(startDialog, new RegExp(label))
  assert.doesNotMatch(startDialog, /<span>(Cause|Description|Error code)/)
})

test('the report action is gated by downtime.manage and appears once, not duplicated per card', () => {
  assert.match(condition, /canManage && <button className="danger-outline-button"[^>]*>[^<]*<PowerOff size=\{17\} \/> Laporkan Mesin Berhenti/)
  assert.equal(condition.split('Laporkan Mesin Berhenti').length - 1, 1)
})

test('active downtime shows the required actions and the latest maintenance note', () => {
  for (const label of ['Tambah Catatan', 'Mesin Sudah Normal', 'Lihat Detail', 'Catatan terakhir']) assert.match(condition, new RegExp(label))
  assert.match(condition, /await addDowntimeNote\(entry\.downtime_event_id, payload\)/)
  assert.match(condition, /await endDowntime\(entry\.downtime_event_id, payload\)/)
  assert.match(condition, /disabled=\{!status\.canComplete\}/, 'an event with an invalid start can not be completed from the UI')
})

test('downtime history lists completed and active events with period, machine and status filters', () => {
  assert.match(condition, /Riwayat Downtime/)
  assert.match(condition, /loadDowntimeEvents\(\{ branchId[^}]*machineId[^}]*status[^}]*periodStart[^}]*periodEnd/)
  for (const label of ['Mulai', 'Selesai', 'Durasi', 'Downtime Produksi']) assert.match(condition, new RegExp(`<dt>${label}</dt>`))
  assert.match(condition, /Muat lebih banyak/)
  assert.match(service, /period_start: periodStart, period_end: periodEnd/)
})

test('empty states are explicit: no active downtime, no history', () => {
  assert.match(condition, /Tidak ada mesin yang sedang berhenti\./)
  assert.match(condition, /Belum ada downtime pada periode ini\./)
})

test('Maintenance reads the ?machine=&action= deep link, ignoring a malformed id', () => {
  assert.match(maintenance, /initialMachineId=\{initialMachineId\} initialAction=/)
  assert.match(condition, /machineIdPattern\.test\(requestedMachineId\)/)
  assert.match(condition, /initialAction === 'report' && canManage/)
})

// --- Machine Detail --------------------------------------------------------------

test('Machine Detail keeps a compact Status Mesin card and only navigates to Maintenance', () => {
  assert.match(detail, /Kelola Downtime/)
  assert.match(detail, /Lapor Gangguan/)
  assert.match(detail, /navigate\(machineConditionPath\(machine\.id, action\)\)/)
  assert.doesNotMatch(detail, /<form|<textarea|BlockingDialog|useState\(false\)/, 'no downtime forms or dialogs in Machine Detail')
  assert.match(detailPage, /canOpenMaintenance=\{can\('maintenance\.view'\)\} navigate=\{navigate\}/)
})

test('machineConditionPath preselects the machine and encodes the action', () => {
  assert.equal(machineConditionPath('abc', 'report'), '/maintenance/machine-condition?machine=abc&action=report')
  assert.equal(machineConditionPath('abc', 'manage'), '/maintenance/machine-condition?machine=abc&action=manage')
  assert.equal(machineConditionPath('abc', 'anything-else'), '/maintenance/machine-condition?machine=abc&action=manage')
})

// --- Overview ----------------------------------------------------------------------

test('Overview Machine Health refreshes when the selected machine or period changes and stays last', () => {
  assert.match(overview, /<MachineHealthSection key=\{`\$\{selectedMachine\.id\}:\$\{period\.start\}:\$\{period\.end\}`\}/)
  assert.ok(overview.indexOf('<DailyClickPerformanceChart') < overview.indexOf('<MachineHealthSection key='))
})

// --- Model helpers -----------------------------------------------------------------

test('history rows show server elapsed/production minutes and never fabricate production downtime', () => {
  const closed = { actual_start_at: '2026-10-12T09:00:00Z', actual_end_at: '2026-10-13T03:00:00Z', elapsed_minutes: 1080, production_downtime_minutes: 180 }
  assert.deepEqual(describeEventDurations(closed), { elapsedText: '18j 0m', productionText: '3j 0m' })
  assert.equal(describeEventDurations({ ...closed, production_downtime_minutes: null }).productionText, 'Belum dikonfigurasi')
  assert.equal(describeEventDurations({ ...closed, production_downtime_minutes: 0 }).productionText, '0m', 'a configured zero stays zero')
  assert.equal(describeEventDurations({ actual_start_at: null, actual_end_at: null }).elapsedText, 'Tidak tersedia')
  assert.deepEqual(describeEventStatus({ actual_end_at: null }), { state: 'active', label: 'Berhenti' })
  assert.deepEqual(describeEventStatus({ actual_end_at: '2026-10-13T03:00:00Z' }), { state: 'closed', label: 'Selesai' })
})

test('active entries expose the latest maintenance note and block completion on an invalid start', () => {
  const entry = describeActiveEntry({ actual_start_at: '2026-10-12T09:00:00Z', cause: 'mechanical', latest_note: { note: 'Ganti roller', occurred_at: '2026-10-12T10:00:00Z' } }, 'Asia/Jakarta')
  assert.equal(entry.latestNote, 'Ganti roller')
  assert.equal(entry.canComplete, true)
  assert.equal(describeActiveEntry({ actual_start_at: null, cause: 'mechanical' }, 'UTC').canComplete, false)
  assert.equal(describeActiveEntry({ actual_start_at: '2026-10-12T09:00:00Z' }, 'UTC').latestNote, null)
})

test('duplicate-report guard recognises a machine that is already stopped', () => {
  assert.equal(hasActiveDowntime([{ machine_id: 'a' }, { machine_id: 'b' }], 'b'), true)
  assert.equal(hasActiveDowntime([{ machine_id: 'a' }], 'c'), false)
  assert.equal(hasActiveDowntime(undefined, 'c'), false)
})

test('monthToPeriod yields inclusive local month bounds, including leap-year February', () => {
  assert.deepEqual(monthToPeriod('2026-10'), { periodStart: '2026-10-01', periodEnd: '2026-10-31' })
  assert.deepEqual(monthToPeriod('2028-02'), { periodStart: '2028-02-01', periodEnd: '2028-02-29' })
  assert.equal(monthToPeriod(''), null)
  assert.equal(monthToPeriod('2026-1'), null)
})

// --- Settings > Jadwal Operasional ---------------------------------------------------

test('Settings gets a Jadwal Operasional section behind downtime.schedule.manage with the three configuration areas', () => {
  assert.match(settings, /\['schedule', 'Jadwal Operasional', CalendarClock, 'downtime\.schedule\.manage'\]/)
  assert.match(settings, /section === 'schedule' && <ScheduleSettingsSection/)
  for (const label of ['Jadwal Cabang', 'Jadwal Khusus Mesin', 'Pengecualian Tanggal']) assert.match(schedule, new RegExp(label))
})

test('a new week is unconfigured: every day off and no invented operating hours', () => {
  const week = emptyWeek()
  assert.equal(week.length, 7)
  assert.ok(week.every((row) => row.is_operating === false && row.start_time === '' && row.end_time === ''))
  assert.deepEqual(week.map((row) => row.day_of_week), WEEK_DAYS.map((item) => item.day))
  assert.match(validateWeek(week), /minimal satu hari operasional/)
})

test('schedule validation: operating days need ordered times unless they cross midnight', () => {
  const base = emptyWeek().map((row) => (row.day_of_week === 1 ? { ...row, is_operating: true, start_time: '08:00', end_time: '17:00' } : row))
  assert.equal(validateWeek(base), null)
  const set = (patch) => base.map((row) => (row.day_of_week === 1 ? { ...row, ...patch } : row))
  assert.match(validateWeek(set({ start_time: '' })), /Senin: isi jam mulai/)
  assert.match(validateWeek(set({ start_time: '17:00', end_time: '08:00' })), /jam selesai harus setelah jam mulai/)
  assert.match(validateWeek(set({ start_time: '08:00', end_time: '08:00', crosses_midnight: true })), /jam selesai harus setelah jam mulai/)
  assert.equal(validateWeek(set({ start_time: '22:00', end_time: '06:00', crosses_midnight: true })), null)
  assert.match(validateWeek(base.slice(0, 6)), /tujuh hari/)
})

test('weekToPayload sends null times for non-operating days and weekFromRows round-trips saved rows', () => {
  const rows = [{ day_of_week: 1, is_operating: true, start_time: '08:00:00', end_time: '17:00:00', crosses_midnight: false }]
  const week = weekFromRows(rows)
  assert.equal(week.length, 7)
  assert.equal(week[0].start_time, '08:00')
  const payload = weekToPayload(week)
  assert.deepEqual(payload[0], { day_of_week: 1, is_operating: true, start_time: '08:00', end_time: '17:00', crosses_midnight: false })
  assert.deepEqual(payload[1], { day_of_week: 2, is_operating: false, start_time: null, end_time: null, crosses_midnight: false })
})

test('exceptions: only supported types, times required for operating types, machine scope never broadened', () => {
  assert.match(validateException({ exception_date: '', type: 'holiday' }), /tanggal/)
  assert.match(validateException({ exception_date: '2026-10-20', type: 'invented' }), /jenis/i)
  assert.equal(validateException({ exception_date: '2026-10-20', type: 'holiday' }), null)
  assert.match(validateException({ exception_date: '2026-10-20', type: 'overtime', override_start_time: '', override_end_time: '' }), /jam mulai dan jam selesai/)
  assert.match(validateException({ exception_date: '2026-10-20', type: 'overtime', override_start_time: '20:00', override_end_time: '18:00' }), /Jam selesai harus setelah/)
  assert.equal(validateException({ exception_date: '2026-10-20', type: 'overtime', override_start_time: '18:00', override_end_time: '22:00' }), null)

  const branchWide = exceptionToPayload({ machine_id: '', exception_date: '2026-10-20', type: 'holiday', note: '  Libur  ' })
  assert.equal(branchWide.machine_id, null)
  assert.deepEqual([branchWide.is_operating, branchWide.override_start_time, branchWide.note], [false, null, 'Libur'])
  assert.equal(exceptionToPayload({ machine_id: 'm1', exception_date: '2026-10-20', type: 'overtime', override_start_time: '18:00', override_end_time: '22:00' }).machine_id, 'm1')
  assert.equal(exceptionTypeLabel('overtime'), 'Lembur')
})

test('schedule panels use the existing API contract and never touch downtime events', () => {
  assert.match(schedule, /saveBranchProductionSchedule\(branchId, weekToPayload\(week\)\)/)
  assert.match(schedule, /saveMachineScheduleOverride\(machineId, weekToPayload\(week\)\)/)
  assert.match(schedule, /removeMachineScheduleOverride\(machineId\)/)
  assert.match(schedule, /createScheduleException\(branchId, exceptionToPayload\(values\)\)/)
  assert.doesNotMatch(schedule, /startDowntime|endDowntime|addDowntimeNote/)
  assert.match(schedule, /Belum dikonfigurasi|NOT_CONFIGURED_LABEL/)
})
