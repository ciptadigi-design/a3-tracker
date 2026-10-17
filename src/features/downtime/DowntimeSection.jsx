import { useCallback, useEffect, useState } from 'react'
import { AlertTriangle, CheckCircle2, ClipboardPlus, LoaderCircle, PowerOff, RefreshCcw, Timer } from 'lucide-react'
import { userErrorMessage } from '../../lib/appErrors.js'
import { addDowntimeNote, endDowntime, loadActiveDowntime, startDowntime } from '../../services/downtime.js'
import { causeLabel, elapsedMinutesBetween, formatDowntimeDateTime, formatElapsedMinutes, mapDowntimeError, newClientRequestId } from './downtimeModel.js'
import { EndDowntimeDialog } from './EndDowntimeDialog.jsx'
import { StartDowntimeDialog } from './StartDowntimeDialog.jsx'

function LiveElapsed({ startAt }) {
  const [minutes, setMinutes] = useState(() => elapsedMinutesBetween(startAt))
  useEffect(() => {
    const interval = setInterval(() => setMinutes(elapsedMinutesBetween(startAt)), 30000)
    return () => clearInterval(interval)
  }, [startAt])
  return <strong>{formatElapsedMinutes(minutes)}</strong>
}

export function DowntimeSection({ machine, timezone, canManage, canBackdate }) {
  const [active, setActive] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const [showStart, setShowStart] = useState(false)
  const [showEnd, setShowEnd] = useState(false)
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
    } catch (submitError) {
      setNoteError(mapDowntimeError(submitError))
    } finally {
      setIsSubmittingNote(false)
    }
  }

  async function handleEnd(payload) {
    const closed = await endDowntime(active.id, payload)
    setActive(closed)
  }

  return (
    <section className="downtime-section glass-surface" aria-labelledby="downtime-section-title">
      <header className="maintenance-history-header">
        <div><span className="card-kicker">Operational state</span><h2 id="downtime-section-title">Downtime & Maintenance</h2><p>Status operasional mesin dan log maintenance terkait downtime.</p></div>
        <button className="icon-button" type="button" onClick={refresh} disabled={isLoading} aria-label="Refresh downtime status"><RefreshCcw size={17} className={isLoading ? 'spin' : ''} /></button>
      </header>

      {isLoading && !active ? <div className="maintenance-history-state"><LoaderCircle className="spin" size={25} /><strong>Memuat status downtime…</strong></div>
        : error ? <div className="embedded-error" role="alert"><strong>Status downtime tidak dapat dimuat.</strong><span>{userErrorMessage(error, 'Coba lagi beberapa saat.')}</span><button className="secondary-button" type="button" onClick={refresh}>Coba lagi</button></div>
          : active ? (
            <div className="downtime-active-card">
              <div className="downtime-active-status"><span className="downtime-status-pill downtime-status-active"><Timer size={16} /> Downtime Active</span><LiveElapsed startAt={active.actual_start_at} /></div>
              <dl className="downtime-active-meta">
                <div><dt>Start</dt><dd>{formatDowntimeDateTime(active.actual_start_at, timezone)}</dd></div>
                <div><dt>Cause</dt><dd>{causeLabel(active.cause)}</dd></div>
                {active.error_code_text && <div><dt>Error code</dt><dd>{active.error_code_text}</dd></div>}
                {active.description && <div><dt>Description</dt><dd>{active.description}</dd></div>}
                {active.is_backdated && <div><dt /><dd><span className="downtime-backdated-flag"><AlertTriangle size={14} /> Waktu mulai dicatat historis (backdated)</span></dd></div>}
              </dl>

              <div className="downtime-notes">
                <h3>Maintenance Notes</h3>
                {(active.notes || []).length === 0 ? <p className="downtime-notes-empty">Belum ada maintenance note.</p> : (
                  <ul>{(active.notes || []).map((note) => <li key={note.id}><time>{formatDowntimeDateTime(note.occurred_at, timezone)}</time><span>{note.note}</span>{note.performer?.name && <small>oleh {note.performer.name}</small>}</li>)}</ul>
                )}
                {canManage && (
                  <form className="downtime-note-form" onSubmit={handleAddNote}>
                    <textarea value={noteText} onChange={(event) => setNoteText(event.target.value)} placeholder="Tambahkan maintenance note…" rows={2} disabled={isSubmittingNote} />
                    {noteError && <div className="form-error" role="alert"><AlertTriangle size={16} />{noteError}</div>}
                    <button className="secondary-button" type="submit" disabled={isSubmittingNote || !noteText.trim()}><ClipboardPlus size={16} /> {isSubmittingNote ? 'Menyimpan…' : 'Add Maintenance Note'}</button>
                  </form>
                )}
              </div>

              {canManage && <button className="primary-button" type="button" onClick={() => setShowEnd(true)}><CheckCircle2 size={17} /> End Downtime</button>}
            </div>
          ) : (
            <div className="downtime-idle-card">
              <span className="downtime-status-pill downtime-status-operational"><CheckCircle2 size={16} /> Operational</span>
              {canManage && <button className="danger-outline-button" type="button" onClick={() => setShowStart(true)}><PowerOff size={17} /> Start Downtime</button>}
            </div>
          )}

      {showStart && <StartDowntimeDialog machine={machine} canBackdate={canBackdate} onClose={() => setShowStart(false)} onStart={handleStart} />}
      {showEnd && active && <EndDowntimeDialog canBackdate={canBackdate} onClose={() => setShowEnd(false)} onEnd={handleEnd} />}
    </section>
  )
}
