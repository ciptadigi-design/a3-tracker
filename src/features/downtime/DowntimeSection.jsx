import { useCallback, useEffect, useState } from 'react'
import { AlertTriangle, CheckCircle2, ClipboardPlus, LoaderCircle, PowerOff, RefreshCcw } from 'lucide-react'
import { userErrorMessage } from '../../lib/appErrors.js'
import { addDowntimeNote, endDowntime, loadActiveDowntime, startDowntime } from '../../services/downtime.js'
import { describeMachineStatus, elapsedMinutesBetween, formatDowntimeDateTime, formatElapsedMinutes, mapDowntimeError, newClientRequestId, UNAVAILABLE_LABEL } from './downtimeModel.js'
import { EndDowntimeDialog } from './EndDowntimeDialog.jsx'
import { StartDowntimeDialog } from './StartDowntimeDialog.jsx'

function LiveElapsed({ startAt }) {
  const [minutes, setMinutes] = useState(() => elapsedMinutesBetween(startAt))
  useEffect(() => {
    const interval = setInterval(() => setMinutes(elapsedMinutesBetween(startAt)), 30000)
    return () => clearInterval(interval)
  }, [startAt])
  return <>{minutes == null ? UNAVAILABLE_LABEL : formatElapsedMinutes(minutes)}</>
}

export function DowntimeSection({ machine, timezone, canManage, canBackdate }) {
  const [active, setActive] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const [showStart, setShowStart] = useState(false)
  const [showEnd, setShowEnd] = useState(false)
  const [showNoteForm, setShowNoteForm] = useState(false)
  const [noteText, setNoteText] = useState('')
  const [noteError, setNoteError] = useState(null)
  const [isSubmittingNote, setIsSubmittingNote] = useState(false)

  const refresh = useCallback(async () => {
    setIsLoading(true)
    setError(null)
    try {
      setActive(await loadActiveDowntime(machine.id))
    } catch (loadError) {
      setError(loadError)
    } finally {
      setIsLoading(false)
    }
  }, [machine.id])

  useEffect(() => { refresh() }, [refresh])

  async function handleStart(payload) {
    const event = await startDowntime(machine.id, payload)
    setActive(event)
  }

  async function handleAddNote(submitEvent) {
    submitEvent.preventDefault()
    if (!noteText.trim() || isSubmittingNote) return
    setIsSubmittingNote(true)
    setNoteError(null)
    try {
      const note = await addDowntimeNote(active.id, { note: noteText.trim(), client_request_id: newClientRequestId() })
      setActive((current) => ({ ...current, notes: [...(current.notes || []), note] }))
      setNoteText('')
      setShowNoteForm(false)
    } catch (submitError) {
      setNoteError(mapDowntimeError(submitError))
    } finally {
      setIsSubmittingNote(false)
    }
  }

  async function handleEnd(payload) {
    await endDowntime(active.id, payload)
    setActive(null)
  }

  const status = describeMachineStatus(active, timezone)
  const isStopped = status.state === 'stopped'

  return (
    <section className="downtime-section glass-surface" aria-labelledby="downtime-section-title">
      <header className="maintenance-history-header">
        <div>
          <span className="card-kicker" id="downtime-section-title">Status Mesin</span>
          <div className="downtime-status-line">
            {isLoading && !active ? <span className="downtime-status-loading">Memuat…</span> : error ? null : <span className={`machine-status ${isStopped ? 'machine-status-down' : ''}`}>{status.label}</span>}
          </div>
        </div>
        <div className="maintenance-history-header-actions"><button className="icon-button" type="button" onClick={refresh} disabled={isLoading} aria-label="Muat ulang status mesin"><RefreshCcw size={17} className={isLoading ? 'spin' : ''} /></button></div>
      </header>

      {isLoading && !active ? <div className="downtime-status-state"><LoaderCircle className="spin" size={20} /><span>Memuat status mesin…</span></div>
        : error ? <div className="embedded-error" role="alert"><strong>Status mesin tidak dapat dimuat.</strong><span>{userErrorMessage(error, 'Coba lagi beberapa saat.')}</span><button className="secondary-button" type="button" onClick={refresh}>Coba lagi</button></div>
          : isStopped ? (
            <>
              <dl className="maintenance-history-summary downtime-status-summary">
                <div><dt>Mulai</dt><dd>{status.startText}</dd></div>
                <div><dt>Penyebab</dt><dd>{status.causeText}</dd></div>
                <div><dt>Durasi</dt><dd>{status.canComplete ? <LiveElapsed startAt={active.actual_start_at} /> : status.durationText}</dd></div>
                {status.errorCode && <div><dt>Kode error</dt><dd>{status.errorCode}</dd></div>}
                {active.description && <div><dt>Deskripsi</dt><dd>{active.description}</dd></div>}
              </dl>
              {!status.canComplete && <div className="downtime-status-notice" role="alert"><AlertTriangle size={16} /><span>Waktu mulai downtime ini tidak valid, sehingga tidak dapat diselesaikan dari sini. Hubungi admin untuk memperbaiki data.</span></div>}
              {active.is_backdated && <span className="downtime-backdated-flag"><AlertTriangle size={14} /> Waktu mulai dicatat historis (backdated)</span>}

              {(active.notes || []).length > 0 && (
                <div className="downtime-notes">
                  <ul>{(active.notes || []).map((note) => <li key={note.id}><time>{formatDowntimeDateTime(note.occurred_at, timezone)}</time><span>{note.note}</span>{note.performer?.name && <small>oleh {note.performer.name}</small>}</li>)}</ul>
                </div>
              )}

              {canManage && (
                showNoteForm ? (
                  <form className="downtime-note-form" onSubmit={handleAddNote}>
                    <textarea value={noteText} onChange={(event) => setNoteText(event.target.value)} placeholder="Tambahkan catatan…" rows={2} disabled={isSubmittingNote} autoFocus />
                    {noteError && <div className="form-error" role="alert"><AlertTriangle size={16} />{noteError}</div>}
                    <div className="downtime-note-form-actions">
                      <button className="secondary-button" type="button" onClick={() => { setShowNoteForm(false); setNoteText(''); setNoteError(null) }} disabled={isSubmittingNote}>Batalkan</button>
                      <button className="primary-button" type="submit" disabled={isSubmittingNote || !noteText.trim()}>{isSubmittingNote ? 'Menyimpan…' : 'Simpan Catatan'}</button>
                    </div>
                  </form>
                ) : (
                  <div className="downtime-status-card-actions">
                    <button className="secondary-button" type="button" onClick={() => setShowNoteForm(true)}><ClipboardPlus size={16} /> Tambah Catatan</button>
                    <button className="primary-button" type="button" onClick={() => setShowEnd(true)} disabled={!status.canComplete}><CheckCircle2 size={17} /> Mesin Sudah Normal</button>
                  </div>
                )
              )}
            </>
          ) : (
            <div className="downtime-status-row">
              <p className="downtime-status-empty">Tidak ada downtime aktif.</p>
              {canManage && <button className="danger-outline-button" type="button" onClick={() => setShowStart(true)}><PowerOff size={17} /> Laporkan Mesin Berhenti</button>}
            </div>
          )}

      {showStart && <StartDowntimeDialog machine={machine} canBackdate={canBackdate} onClose={() => setShowStart(false)} onStart={handleStart} />}
      {showEnd && active && status.canComplete && <EndDowntimeDialog startAt={active.actual_start_at} canBackdate={canBackdate} onClose={() => setShowEnd(false)} onEnd={handleEnd} />}
    </section>
  )
}
