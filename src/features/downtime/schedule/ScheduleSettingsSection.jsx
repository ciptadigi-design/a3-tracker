import { useCallback, useEffect, useMemo, useState } from 'react'
import { CheckCircle2, LoaderCircle, Plus, RefreshCcw, Trash2 } from 'lucide-react'
import { useMachines } from '../../machines/useMachines.js'
import { userErrorMessage } from '../../../lib/appErrors.js'
import {
  createScheduleException, deleteScheduleException, loadBranchProductionSchedule, loadMachineScheduleOverride, loadScheduleExceptions,
  removeMachineScheduleOverride, saveBranchProductionSchedule, saveMachineScheduleOverride,
} from '../../../services/downtime.js'
import {
  currentMonthValue, emptyWeek, exceptionToPayload, exceptionTypeLabel, exceptionTypes, NOT_CONFIGURED_LABEL, validateException, validateWeek, WEEK_DAYS, weekFromRows, weekToPayload,
} from '../downtimeModel.js'

const tabs = [['branch', 'Jadwal Cabang'], ['machine', 'Jadwal Khusus Mesin'], ['exceptions', 'Pengecualian Tanggal']]

function Feedback({ notice, error }) {
  return <>
    {notice && <div className="success-banner" role="status"><CheckCircle2 size={18} /><span>{notice}</span></div>}
    {error && <div className="form-error" role="alert">{error}</div>}
  </>
}

function WeekEditor({ week, onChange, disabled }) {
  const update = (day, patch) => onChange(week.map((row) => (row.day_of_week === day ? { ...row, ...patch } : row)))
  return (
    <ul className="schedule-week" aria-label="Jadwal mingguan">
      {WEEK_DAYS.map(({ day, label }) => {
        const row = week.find((item) => item.day_of_week === day)
        return (
          <li key={day} className={row.is_operating ? '' : 'schedule-day-off'}>
            <label className="schedule-day-toggle"><input type="checkbox" checked={row.is_operating} disabled={disabled} onChange={(event) => update(day, { is_operating: event.target.checked })} /><strong>{label}</strong><span>{row.is_operating ? 'Operasional' : 'Non-operasional'}</span></label>
            {row.is_operating && (
              <div className="schedule-day-times">
                <label className="form-field"><span>Mulai</span><input type="time" value={row.start_time} disabled={disabled} onChange={(event) => update(day, { start_time: event.target.value })} aria-label={`${label} jam mulai`} /></label>
                <label className="form-field"><span>Selesai</span><input type="time" value={row.end_time} disabled={disabled} onChange={(event) => update(day, { end_time: event.target.value })} aria-label={`${label} jam selesai`} /></label>
                <label className="schedule-check"><input type="checkbox" checked={row.crosses_midnight} disabled={disabled} onChange={(event) => update(day, { crosses_midnight: event.target.checked })} />Melewati tengah malam</label>
              </div>
            )}
          </li>
        )
      })}
    </ul>
  )
}

function BranchPicker({ branches, value, onChange }) {
  return <label className="form-field"><span>Cabang</span><select value={value} onChange={(event) => onChange(event.target.value)}>{branches.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
}

function BranchSchedulePanel({ branchId, timezone }) {
  const [week, setWeek] = useState(emptyWeek)
  const [configured, setConfigured] = useState(false)
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)

  const load = useCallback(async () => {
    setIsLoading(true); setError(null)
    try {
      const rows = await loadBranchProductionSchedule(branchId)
      setConfigured(rows.length > 0)
      setWeek(rows.length > 0 ? weekFromRows(rows) : emptyWeek())
    } catch (loadError) { setError(userErrorMessage(loadError, 'Jadwal cabang tidak dapat dimuat.')) } finally { setIsLoading(false) }
  }, [branchId])
  useEffect(() => { setNotice(null); load() }, [load])

  async function save() {
    const problem = validateWeek(week)
    if (problem) { setError(problem); return }
    setIsSaving(true); setError(null); setNotice(null)
    try {
      await saveBranchProductionSchedule(branchId, weekToPayload(week))
      setConfigured(true)
      setNotice('Jadwal cabang disimpan. Riwayat downtime tidak diubah.')
    } catch (saveError) { setError(userErrorMessage(saveError, 'Jadwal cabang tidak dapat disimpan.')) } finally { setIsSaving(false) }
  }

  if (isLoading) return <div className="settings-empty"><RefreshCcw className="spin" size={22} /><strong>Memuat jadwal cabang…</strong></div>
  return (
    <div className="schedule-panel">
      {!configured && <p className="schedule-banner">{NOT_CONFIGURED_LABEL}: Ketersediaan Mesin dan Downtime Produksi belum dapat dihitung sampai jadwal cabang disimpan.</p>}
      <p className="schedule-hint">Zona waktu: {timezone}. Satu rentang waktu per hari; shift ganda dalam satu hari belum didukung.</p>
      <WeekEditor week={week} onChange={setWeek} disabled={isSaving} />
      <Feedback notice={notice} error={error} />
      <div className="schedule-actions"><button className="primary-button" type="button" onClick={save} disabled={isSaving}>{isSaving && <LoaderCircle className="spin" size={16} />}{isSaving ? 'Menyimpan…' : 'Simpan Jadwal Cabang'}</button></div>
    </div>
  )
}

function MachineOverridePanel({ account, branches, branchId, onBranchChange }) {
  const machinesState = useMachines(account.id, branchId)
  const machines = useMemo(() => machinesState.machines.filter((item) => item.status !== 'retired'), [machinesState.machines])
  const [machineId, setMachineId] = useState('')
  const [enabled, setEnabled] = useState(false)
  const [hasOverride, setHasOverride] = useState(false)
  const [branchRows, setBranchRows] = useState([])
  const [week, setWeek] = useState(emptyWeek)
  const [isLoading, setIsLoading] = useState(false)
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)

  useEffect(() => { setMachineId('') }, [branchId])

  const load = useCallback(async () => {
    if (!machineId) return
    setIsLoading(true); setError(null)
    try {
      const [rows, inherited] = await Promise.all([loadMachineScheduleOverride(machineId), loadBranchProductionSchedule(branchId)])
      setBranchRows(inherited)
      setHasOverride(rows.length > 0)
      setEnabled(rows.length > 0)
      setWeek(rows.length > 0 ? weekFromRows(rows) : weekFromRows(inherited))
    } catch (loadError) { setError(userErrorMessage(loadError, 'Jadwal khusus mesin tidak dapat dimuat.')) } finally { setIsLoading(false) }
  }, [machineId, branchId])
  useEffect(() => { setNotice(null); load() }, [load])

  function toggle(next) {
    setEnabled(next)
    if (next && !hasOverride) setWeek(branchRows.length > 0 ? weekFromRows(branchRows) : emptyWeek())
  }

  async function save() {
    setError(null); setNotice(null)
    if (enabled) {
      const problem = validateWeek(week)
      if (problem) { setError(problem); return }
    }
    setIsSaving(true)
    try {
      if (enabled) {
        await saveMachineScheduleOverride(machineId, weekToPayload(week))
        setHasOverride(true)
        setNotice('Jadwal khusus mesin disimpan.')
      } else if (hasOverride) {
        await removeMachineScheduleOverride(machineId)
        setHasOverride(false)
        setNotice('Jadwal khusus dinonaktifkan. Mesin kembali mengikuti jadwal cabang.')
      }
    } catch (saveError) { setError(userErrorMessage(saveError, 'Jadwal khusus mesin tidak dapat disimpan.')) } finally { setIsSaving(false) }
  }

  return (
    <div className="schedule-panel">
      <div className="form-grid">
        <BranchPicker branches={branches} value={branchId} onChange={onBranchChange} />
        <label className="form-field"><span>Mesin</span><select value={machineId} onChange={(event) => setMachineId(event.target.value)} disabled={machinesState.isLoading}><option value="">Pilih mesin…</option>{machines.map((item) => <option key={item.id} value={item.id}>{item.display_name || item.machine_code}</option>)}</select></label>
      </div>
      {!machineId ? <p className="schedule-hint">Pilih mesin untuk mengatur jadwal khusus. Tanpa jadwal khusus, mesin mengikuti jadwal cabang.</p>
        : isLoading ? <div className="settings-empty"><RefreshCcw className="spin" size={22} /><strong>Memuat jadwal mesin…</strong></div>
          : (
            <>
              <label className="schedule-check schedule-enable"><input type="checkbox" checked={enabled} disabled={isSaving} onChange={(event) => toggle(event.target.checked)} />Gunakan jadwal khusus untuk mesin ini</label>
              {enabled ? <WeekEditor week={week} onChange={setWeek} disabled={isSaving} /> : <p className="schedule-hint">Mesin ini mengikuti jadwal cabang.</p>}
              <Feedback notice={notice} error={error} />
              <div className="schedule-actions"><button className="primary-button" type="button" onClick={save} disabled={isSaving || (!enabled && !hasOverride)}>{isSaving && <LoaderCircle className="spin" size={16} />}{isSaving ? 'Menyimpan…' : enabled ? 'Simpan Jadwal Khusus' : 'Nonaktifkan Jadwal Khusus'}</button></div>
            </>
          )}
      {!machineId && <Feedback notice={null} error={error} />}
    </div>
  )
}

const blankException = { machine_id: '', exception_date: '', type: 'holiday', override_start_time: '', override_end_time: '', crosses_midnight: false, note: '' }

function ExceptionsPanel({ account, branches, branchId, onBranchChange }) {
  const machinesState = useMachines(account.id, branchId)
  const machines = useMemo(() => machinesState.machines.filter((item) => item.status !== 'retired'), [machinesState.machines])
  const [month, setMonth] = useState(() => currentMonthValue('UTC'))
  const [rows, setRows] = useState([])
  const [values, setValues] = useState(blankException)
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)
  const [confirmId, setConfirmId] = useState(null)
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)
  const type = exceptionTypes.find((item) => item.value === values.type)

  const load = useCallback(async () => {
    const [year, monthNumber] = month.split('-').map(Number)
    setIsLoading(true)
    try {
      setRows(await loadScheduleExceptions(branchId, year, monthNumber))
    } catch (loadError) { setError(userErrorMessage(loadError, 'Pengecualian tidak dapat dimuat.')) } finally { setIsLoading(false) }
  }, [branchId, month])
  useEffect(() => { load() }, [load])
  useEffect(() => { setValues((current) => ({ ...current, machine_id: '' })) }, [branchId])

  const change = (patch) => setValues((current) => ({ ...current, ...patch }))
  const machineName = (id) => machines.find((item) => item.id === id)?.display_name || 'Mesin tertentu'

  async function add(event) {
    event.preventDefault()
    setError(null); setNotice(null)
    const problem = validateException(values)
    if (problem) { setError(problem); return }
    setIsSaving(true)
    try {
      await createScheduleException(branchId, exceptionToPayload(values))
      setValues(blankException)
      setNotice('Pengecualian ditambahkan.')
      await load()
    } catch (saveError) { setError(saveError?.status === 409 ? 'Sudah ada pengecualian untuk tanggal dan cakupan ini.' : userErrorMessage(saveError, 'Pengecualian tidak dapat disimpan.')) } finally { setIsSaving(false) }
  }

  async function remove(id) {
    if (confirmId !== id) { setConfirmId(id); return }
    setError(null); setNotice(null)
    try {
      await deleteScheduleException(id)
      setConfirmId(null)
      setNotice('Pengecualian dihapus.')
      await load()
    } catch (deleteError) { setError(userErrorMessage(deleteError, 'Pengecualian tidak dapat dihapus.')) }
  }

  return (
    <div className="schedule-panel">
      <form className="schedule-exception-form" onSubmit={add}>
        <div className="form-grid">
          <BranchPicker branches={branches} value={branchId} onChange={onBranchChange} />
          <label className="form-field"><span>Cakupan</span><select value={values.machine_id} onChange={(event) => change({ machine_id: event.target.value })}><option value="">Seluruh cabang</option>{machines.map((item) => <option key={item.id} value={item.id}>{item.display_name || item.machine_code}</option>)}</select></label>
          <label className="form-field"><span>Tanggal</span><input type="date" value={values.exception_date} onChange={(event) => change({ exception_date: event.target.value })} required /></label>
          <label className="form-field"><span>Jenis</span><select value={values.type} onChange={(event) => change({ type: event.target.value })}>{exceptionTypes.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select></label>
          {type?.operating && <>
            <label className="form-field"><span>Jam mulai</span><input type="time" value={values.override_start_time} onChange={(event) => change({ override_start_time: event.target.value })} /></label>
            <label className="form-field"><span>Jam selesai</span><input type="time" value={values.override_end_time} onChange={(event) => change({ override_end_time: event.target.value })} /></label>
            <label className="schedule-check form-field-wide"><input type="checkbox" checked={values.crosses_midnight} onChange={(event) => change({ crosses_midnight: event.target.checked })} />Melewati tengah malam</label>
          </>}
          <label className="form-field form-field-wide"><span>Alasan <small>Opsional</small></span><input value={values.note} onChange={(event) => change({ note: event.target.value })} maxLength={200} /></label>
        </div>
        <div className="schedule-actions"><button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? <LoaderCircle className="spin" size={16} /> : <Plus size={16} />}Tambah Pengecualian</button></div>
      </form>
      <Feedback notice={notice} error={error} />

      <div className="schedule-list-head"><h3>Pengecualian</h3><label className="schedule-month"><span>Bulan</span><input type="month" value={month} onChange={(event) => event.target.value && setMonth(event.target.value)} /></label></div>
      {isLoading ? <div className="settings-empty"><RefreshCcw className="spin" size={22} /><strong>Memuat pengecualian…</strong></div>
        : rows.length === 0 ? <p className="schedule-hint">Tidak ada pengecualian pada bulan ini.</p>
          : (
            <ul className="schedule-exception-list">
              {rows.map((row) => (
                <li key={row.id}>
                  <div><strong>{String(row.exception_date).slice(0, 10)} · {exceptionTypeLabel(row.type)}</strong><span>{row.machine_id ? machineName(row.machine_id) : 'Seluruh cabang'}{row.note ? ` · ${row.note}` : ''}</span></div>
                  <span className="schedule-exception-window">{row.is_operating ? `${String(row.override_start_time).slice(0, 5)}–${String(row.override_end_time).slice(0, 5)}${row.crosses_midnight ? ' (+1)' : ''}` : 'Tidak beroperasi'}</span>
                  <button className="secondary-button compact-button" type="button" onClick={() => remove(row.id)} aria-label={`Hapus pengecualian ${String(row.exception_date).slice(0, 10)}`}><Trash2 size={15} />{confirmId === row.id ? 'Konfirmasi hapus' : 'Hapus'}</button>
                </li>
              ))}
            </ul>
          )}
    </div>
  )
}

/** Settings > Jadwal Operasional: the configuration UI for the production-schedule backend. */
export function ScheduleSettingsSection({ account, branches }) {
  const activeBranches = useMemo(() => branches.filter((item) => item.is_active !== false), [branches])
  const [tab, setTab] = useState('branch')
  const [branchId, setBranchId] = useState(() => activeBranches[0]?.id ?? '')
  const branch = activeBranches.find((item) => item.id === branchId)
  const timezone = branch?.timezone || account.default_timezone || 'UTC'

  return (
    <section className="settings-card glass-surface schedule-settings">
      <header><div><span className="card-kicker">Produksi</span><h2>Jadwal Operasional</h2><p>Jam produksi dipakai untuk menghitung Ketersediaan Mesin dan Downtime Produksi. Mengubah jadwal tidak mengubah catatan downtime yang sudah ada.</p></div></header>
      {activeBranches.length === 0 ? <p className="schedule-hint schedule-pad">Belum ada cabang aktif.</p> : (
        <>
          <nav className="machine-view-tabs schedule-tabs" role="tablist" aria-label="Jadwal operasional">
            {tabs.map(([id, label]) => <button key={id} type="button" role="tab" aria-selected={tab === id} className={tab === id ? 'selected' : ''} onClick={() => setTab(id)}>{label}</button>)}
          </nav>
          <div className="schedule-body">
            {tab === 'branch' && <><BranchPicker branches={activeBranches} value={branchId} onChange={setBranchId} /><BranchSchedulePanel branchId={branchId} timezone={timezone} /></>}
            {tab === 'machine' && <MachineOverridePanel account={account} branches={activeBranches} branchId={branchId} onBranchChange={setBranchId} />}
            {tab === 'exceptions' && <ExceptionsPanel account={account} branches={activeBranches} branchId={branchId} onBranchChange={setBranchId} />}
          </div>
        </>
      )}
    </section>
  )
}
