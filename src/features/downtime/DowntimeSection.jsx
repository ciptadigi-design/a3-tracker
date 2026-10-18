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
    const closed = await endDowntime(active.id, payload)
    setActive(closed)
  }

  return (
    <section className="downtime-section glass-surface" aria-labelledby="downtime-section-title">
      <header className="downtime-status-card-header">
        <div><span className="card-kicker">Status mesin</span><h2 id="downtime-section-title">{isLoading && !active ? 'Memuat…' : active ? 'Berhenti' : 'Normal'}</h2></div>
        <button className="icon-button" type="button" onClick={refresh} disabled={isLoading} aria-label="Refresh status mesin"><RefreshCcw size={17} className={isLoading ? 'spin' : ''} /></button>
      </header>

      {isLoading && !active ? <div className="maintenance-history-state"><LoaderCircle className="spin" size={25} /><strong>Memuat status mesin…</strong></div>
        : error ? <div className="embedded-error" role="alert"><strong>Status mesin tidak dapat dimuat.</strong><span>{userErrorMessage(error, 'Coba lagi beberapa saat.')}</span><button className="secondary-button" type="button" onClick={refresh}>Coba lagi</button></div>
          : active ? (
            <div className="downtime-active-card downtime-active-card-compact">
              <div className="downtime-active-status"><span className="downtime-status-pill downtime-status-active"><Timer size={16} /> Berhenti</span><LiveElapsed startAt={active.actual_start_at} /></div>
              <dl className="downtime-active-meta">
                <div><dt>Waktu mulai</dt><dd>{formatDowntimeDateTime(active.actual_start_at, timezone)}</dd></div>
                <div><dt>Penyebab</dt><dd>{causeLabel(active.cause)}</dd></div>
                {active.error_code_text && <div><dt>Kode error</dt><dd>{active.error_code_text}</dd></div>}
                {active.description && <div><dt>Deskripsi</dt><dd>{active.description}</dd></div>}
                {active.is_backdated && <div><dt /><dd><span className="downtime-backdated-flag"><AlertTriangle size={14} /> Waktu mulai dicatat historis (backdated)</span></dd></div>}
              </dl>

              {(active.notes || []).length > 0 && (
                <div className="downtime-notes">
                  <ul>{(active.notes || []).map((note) => <li key={note.id}><time>{formatDowntimeDateTime(note.occurred_at, timezone)}</time><span>{note.note}</span>{note.performer?.name && <small>oleh {note.performer.name}</small>}</li>)}</ul>
                </div>
              )}

              {canManage && (
                <div className="downtime-status-card-actions">
                  {showNoteForm ? (
                    <form className="downtime-note-form" onSubmit={handleAddNote}>
                      <textarea value={noteText} onChange={(event) => setNoteText(event.target.value)} placeholder="Tambahkan catatan…" rows={2} disabled={isSubmittingNote} autoFocus />
                      {noteError && <div className="form-error" role="alert"><AlertTriangle size={16} />{noteError}</div>}
                      <div className="downtime-note-form-actions">
                        <button className="secondary-button" type="button" onClick={() => { setShowNoteForm(false); setNoteText(''); setNoteError(null) }} disabled={isSubmittingNote}>Batalkan</button>
                        <button className="secondary-button" type="submit" disabled={isSubmittingNote || !noteText.trim()}>{isSubmittingNote ? 'Menyimpan…' : 'Simpan Catatan'}</button>
                      </div>
                    </form>
                  ) : (
                    <button className="secondary-button" type="button" onClick={() => setShowNoteForm(true)}><ClipboardPlus size={16} /> Tambah Catatan</button>
                  )}
                  <button className="primary-button" type="button" onClick={() => setShowEnd(true)}><CheckCircle2 size={17} /> Mesin Sudah Normal</button>
                </div>
              )}
            </div>
          ) : (
            <div className="downtime-idle-card">
              <span className="downtime-status-pill downtime-status-operational"><CheckCircle2 size={16} /> Normal</span>
              {canManage && <button className="danger-outline-button" type="button" onClick={() => setShowStart(true)}><PowerOff size={17} /> Laporkan Mesin Berhenti</button>}
            </div>
          )}

      {showStart && <StartDowntimeDialog machine={machine} canBackdate={canBackdate} onClose={() => setShowStart(false)} onStart={handleStart} />}
      {showEnd && active && <EndDowntimeDialog startAt={active.actual_start_at} canBackdate={canBackdate} onClose={() => setShowEnd(false)} onEnd={handleEnd} />}
    </section>
  )
}
