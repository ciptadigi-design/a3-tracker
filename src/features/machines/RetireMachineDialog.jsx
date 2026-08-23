import { AlertTriangle, LoaderCircle, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { mapMachineMutationError } from './machineForm.js'

export function RetireMachineDialog({ machine, onClose, onConfirm }) {
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState(null)

  useEffect(() => {
    function handleKeyDown(event) {
      if (event.key === 'Escape' && !isSaving) onClose()
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [isSaving, onClose])

  async function handleConfirm() {
    if (isSaving) return
    setIsSaving(true)
    setError(null)
    try {
      await onConfirm()
      onClose()
    } catch (retireError) {
      setError(mapMachineMutationError(retireError).message)
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget && !isSaving) onClose() }}>
      <section className="confirm-dialog glass-surface" role="alertdialog" aria-modal="true" aria-labelledby="retire-dialog-title" aria-describedby="retire-dialog-description">
        <header className="dialog-header"><span className="danger-dialog-icon"><AlertTriangle size={23} /></span><button className="icon-button" type="button" onClick={onClose} disabled={isSaving} aria-label="Close retirement confirmation"><X size={19} /></button></header>
        <h2 id="retire-dialog-title">Retire {machine.display_name}?</h2>
        <p id="retire-dialog-description">This is not a deletion. Historical records will be preserved, and the machine will no longer appear as active.</p>
        <div className="retire-summary"><span>{machine.machine_code}</span><strong>{machine.machine_models?.manufacturers?.name} · {machine.machine_models?.name}</strong></div>
        {error && <div className="form-error" role="alert"><AlertTriangle size={16} />{error}</div>}
        <footer className="dialog-actions"><button className="secondary-button" type="button" onClick={onClose} disabled={isSaving}>Keep machine</button><button className="danger-button" type="button" onClick={handleConfirm} disabled={isSaving}>{isSaving && <LoaderCircle className="spin" size={17} />}{isSaving ? 'Retiring…' : 'Retire machine'}</button></footer>
      </section>
    </div>
  )
}
