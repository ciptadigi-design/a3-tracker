import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { AlertCircle, CheckCircle2, LoaderCircle, X } from 'lucide-react'
import { formatRupiah, mapIncidentError } from './incidentUtils.js'

function ReadinessItem({ label, value }) {
  return <div><span>{label}</span><strong>{value || 'Belum dicatat'}</strong></div>
}

export function SolveIncidentDialog({ incident, onClose, onConfirm }) {
  const [resolutionNote, setResolutionNote] = useState('')
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState(null)
  const closeRef = useRef(null)

  useEffect(() => {
    const previousRootOverflow = document.documentElement.style.overflow
    const previousBodyOverflow = document.body.style.overflow
    document.documentElement.style.overflow = 'hidden'
    document.body.style.overflow = 'hidden'
    closeRef.current?.focus()
    const onKeyDown = (event) => { if (event.key === 'Escape' && !isSaving) onClose() }
    window.addEventListener('keydown', onKeyDown)
    return () => {
      window.removeEventListener('keydown', onKeyDown)
      document.documentElement.style.overflow = previousRootOverflow
      document.body.style.overflow = previousBodyOverflow
    }
  }, [isSaving, onClose])

  async function submit(event) {
    event.preventDefault()
    setIsSaving(true)
    setError(null)
    try {
      await onConfirm(resolutionNote)
      onClose()
    } catch (saveError) {
      setError(mapIncidentError(saveError))
    } finally {
      setIsSaving(false)
    }
  }

  return createPortal(
    <div className="dialog-backdrop" role="presentation">
      <section className="confirm-dialog solve-dialog glass-surface" role="dialog" aria-modal="true" aria-labelledby="solve-dialog-title">
        <header className="dialog-header"><span className="dialog-icon"><CheckCircle2 size={22} /></span><button ref={closeRef} className="icon-button" type="button" onClick={onClose} disabled={isSaving} aria-label="Tutup konfirmasi solve"><X size={18} /></button></header>
        <h2 id="solve-dialog-title">Mark Incident as Solved</h2>
        <p>Konfirmasi bahwa tim telah meninjau current truth incident ini. Status database tetap memakai kode stabil <strong>resolved</strong>.</p>
        <form className="incident-solve-form" onSubmit={submit}>
          <div className="solve-readiness" aria-label="Ringkasan kesiapan solve">
            <ReadinessItem label="Penyebab Kesalahan" value={incident.cause} />
            <ReadinessItem label="Solusi & Pencegahan" value={incident.prevention} />
            <ReadinessItem label="Penyelesaian Konsumen" value={incident.customer_resolution} />
            <ReadinessItem label="Nilai Kerugian" value={formatRupiah(incident.assessed_loss)} />
          </div>
          <label className="form-field"><span>Resolution Note <small>Opsional / direkomendasikan</small></span><textarea rows="3" value={resolutionNote} onChange={(event) => setResolutionNote(event.target.value)} placeholder="Contoh: Disepakati pada briefing produksi. SOP akan diperbarui." /></label>
          {error && <div className="form-error" role="alert"><AlertCircle size={16} /><span>{error}</span></div>}
          <div className="dialog-actions"><button className="secondary-button" type="button" onClick={onClose} disabled={isSaving}>Batal</button><button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? <LoaderCircle className="spin" size={17} /> : <CheckCircle2 size={17} />}{isSaving ? 'Menyelesaikan…' : 'Confirm Solved'}</button></div>
        </form>
      </section>
    </div>,
    document.body,
  )
}
