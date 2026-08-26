import { useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { AlertCircle, CheckCircle2, LoaderCircle, LockKeyhole, Printer, RotateCcw, TriangleAlert, X } from 'lucide-react'
import { useAuth } from '../auth/useAuth.js'
import { createDraftKey } from '../drafts/draftKeys.js'
import { usePersistentDraft } from '../drafts/usePersistentDraft.js'
import { createMachineFormValues, mapMachineMutationError, operationalStatuses, validateMachineForm } from './machineForm.js'

function FieldError({ message }) {
  return message ? <span className="field-error"><AlertCircle size={13} /> {message}</span> : null
}

function RequiredMark() {
  return <span className="required-mark" aria-hidden="true">*</span>
}

function initialValues({ machine, branchId, manufacturers, models }) {
  const values = createMachineFormValues({ branchId, machine })
  if (machine) return values
  const manufacturer = manufacturers.length === 1 ? manufacturers[0] : null
  const matchingModels = manufacturer ? models.filter((model) => model.manufacturer_id === manufacturer.id) : []
  return {
    ...values,
    manufacturerId: manufacturer?.id ?? '',
    machineModelId: matchingModels.length === 1 ? matchingModels[0].id : '',
  }
}

const machineDraftFields = ['branchId', 'manufacturerId', 'machineModelId', 'machineCode', 'displayName', 'serialNumber', 'installedOn', 'status', 'timezone', 'notes']

function isMachineDraft(value) {
  return value && machineDraftFields.every((field) => typeof value[field] === 'string')
}

export function MachineFormDialog({ mode, machine, account, branches, branchId, manufacturers = [], models = [], onClose, onSave }) {
  const { user } = useAuth()
  const isEdit = mode === 'edit'
  const serverValues = initialValues({ machine, branchId, manufacturers, models })
  const draftKey = createDraftKey({
    userId: user.id,
    accountId: account.id,
    branchId,
    feature: 'machine',
    entityId: isEdit ? machine.id : 'new',
  })
  const draftBaseUpdatedAt = machine?.updated_at ?? null
  const {
    value: values,
    updateDraft,
    hasDraft,
    wasRestored,
    pendingDraft,
    clearDraft,
    resetDraft,
    restorePendingDraft,
    discardPendingDraft,
  } = usePersistentDraft({
    draftKey,
    initialValue: serverValues,
    metadata: { baseUpdatedAt: draftBaseUpdatedAt },
    validate: isMachineDraft,
    shouldRestore: (storedDraft) => !isEdit || !storedDraft.metadata?.baseUpdatedAt || !draftBaseUpdatedAt || storedDraft.metadata.baseUpdatedAt === draftBaseUpdatedAt,
  })
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [isSaving, setIsSaving] = useState(false)
  const dialogRef = useRef(null)
  const closeButtonRef = useRef(null)
  const isSavingRef = useRef(false)
  const onCloseRef = useRef(onClose)
  const filteredModels = useMemo(
    () => models.filter((model) => model.manufacturer_id === values.manufacturerId),
    [models, values.manufacturerId],
  )

  useEffect(() => { isSavingRef.current = isSaving }, [isSaving])
  useEffect(() => { onCloseRef.current = onClose }, [onClose])

  useEffect(() => {
    const previouslyFocused = document.activeElement
    const previousRootOverflow = document.documentElement.style.overflow
    const previousOverflow = document.body.style.overflow
    const previousPaddingRight = document.body.style.paddingRight
    const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth
    const bodyPaddingRight = Number.parseFloat(window.getComputedStyle(document.body).paddingRight) || 0

    document.documentElement.style.overflow = 'hidden'
    document.body.style.overflow = 'hidden'
    if (scrollbarWidth > 0) document.body.style.paddingRight = `${bodyPaddingRight + scrollbarWidth}px`
    closeButtonRef.current?.focus()

    function handleKeyDown(event) {
      if (event.key === 'Escape' && !isSavingRef.current) {
        event.preventDefault()
        onCloseRef.current()
        return
      }
      if (event.key !== 'Tab') return

      const focusableElements = [...(dialogRef.current?.querySelectorAll('button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])') ?? [])]
      if (!focusableElements.length) return
      const firstElement = focusableElements[0]
      const lastElement = focusableElements[focusableElements.length - 1]
      if (event.shiftKey && document.activeElement === firstElement) {
        event.preventDefault()
        lastElement.focus()
      } else if (!event.shiftKey && document.activeElement === lastElement) {
        event.preventDefault()
        firstElement.focus()
      }
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => {
      window.removeEventListener('keydown', handleKeyDown)
      document.documentElement.style.overflow = previousRootOverflow
      document.body.style.overflow = previousOverflow
      document.body.style.paddingRight = previousPaddingRight
      previouslyFocused?.focus()
    }
  }, [])

  function change(field, value) {
    updateDraft((current) => ({ ...current, [field]: value }))
    setErrors((current) => ({ ...current, [field]: undefined }))
    setFormError(null)
  }

  function changeManufacturer(manufacturerId) {
    const manufacturerModels = models.filter((model) => model.manufacturer_id === manufacturerId)
    updateDraft((current) => ({
      ...current,
      manufacturerId,
      machineModelId: manufacturerModels.length === 1 ? manufacturerModels[0].id : '',
    }))
    setErrors((current) => ({ ...current, manufacturerId: undefined, machineModelId: undefined }))
    setFormError(null)
  }

  function handleResetDraft() {
    resetDraft(serverValues)
    setErrors({})
    setFormError(null)
  }

  async function handleSubmit(event) {
    event.preventDefault()
    if (isSaving) return
    const nextErrors = validateMachineForm(values, { branches, models, mode })
    if (Object.keys(nextErrors).length) {
      setErrors(nextErrors)
      setFormError('Review the highlighted fields and try again.')
      return
    }

    setIsSaving(true)
    setFormError(null)
    try {
      await onSave(values)
      clearDraft()
      onClose()
    } catch (error) {
      const mapped = mapMachineMutationError(error)
      if (mapped.field) setErrors((current) => ({ ...current, [mapped.field]: mapped.message }))
      setFormError(mapped.message)
    } finally {
      setIsSaving(false)
    }
  }

  const selectedManufacturer = manufacturers.find((item) => item.id === values.manufacturerId)
  const selectedModel = machine?.machine_models

  return createPortal(
    <div className="dialog-backdrop machine-dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget && !isSaving) onClose() }}>
      <section ref={dialogRef} className="machine-dialog glass-surface" role="dialog" aria-modal="true" aria-labelledby="machine-dialog-title" aria-describedby="machine-dialog-description">
        <header className="dialog-header">
          <div className="dialog-heading"><span className="dialog-icon"><Printer size={22} /></span><div><span className="card-kicker">Machine master</span><h2 id="machine-dialog-title">{isEdit ? 'Edit machine' : 'Add machine'}</h2><p id="machine-dialog-description">{isEdit ? 'Update operational details without changing the machine model.' : `Register a physical machine in ${account?.name}.`}</p></div></div>
          <button ref={closeButtonRef} className="icon-button" type="button" onClick={onClose} disabled={isSaving} aria-label="Close machine form"><X size={19} /></button>
        </header>

        <form className="machine-form" onSubmit={handleSubmit} noValidate>
          <div className="machine-form-body">
            {pendingDraft && <div className="draft-conflict-banner" role="alert"><TriangleAlert size={18} /><div><strong>This machine changed after your draft was started.</strong><span>Choose the latest server data or restore your saved draft. Values will not be merged automatically.</span><div className="draft-conflict-actions"><button className="secondary-button" type="button" onClick={discardPendingDraft}>Use latest server data</button><button className="primary-button" type="button" onClick={restorePendingDraft}>Restore my draft</button></div></div></div>}
            {wasRestored && <div className="draft-restored-status" role="status"><CheckCircle2 size={14} /><span>Unsaved draft restored</span></div>}
            <div className="form-section-heading"><strong>Assignment</strong><span>Account is set securely from your active workspace.</span></div>
            <div className="form-grid">
              <label className="form-field"><span>Branch <RequiredMark /></span><select value={values.branchId} onChange={(event) => change('branchId', event.target.value)} aria-invalid={Boolean(errors.branchId)}><option value="">Choose branch</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select><FieldError message={errors.branchId} /></label>
              {isEdit ? <label className="form-field"><span>Manufacturer & model</span><div className="locked-field"><LockKeyhole size={15} /><span>{selectedModel?.manufacturers?.name} · {selectedModel?.name}</span></div><small>Model assignment is fixed in M1.2.</small></label>
                : <>
                  <label className="form-field"><span>Manufacturer <RequiredMark /></span><select value={values.manufacturerId} onChange={(event) => changeManufacturer(event.target.value)} aria-invalid={Boolean(errors.manufacturerId)}><option value="">Choose manufacturer</option>{manufacturers.map((manufacturer) => <option key={manufacturer.id} value={manufacturer.id}>{manufacturer.name}</option>)}</select><FieldError message={errors.manufacturerId} /></label>
                  <label className="form-field"><span>Machine model <RequiredMark /></span><select value={values.machineModelId} onChange={(event) => change('machineModelId', event.target.value)} disabled={!values.manufacturerId} aria-invalid={Boolean(errors.machineModelId)}><option value="">Choose model</option>{filteredModels.map((model) => <option key={model.id} value={model.id}>{model.name}</option>)}</select><FieldError message={errors.machineModelId} /></label>
                </>}
            </div>

            <div className="form-section-heading"><strong>Identity</strong><span>Use stable identifiers from the physical machine or operating convention.</span></div>
            <div className="form-grid">
              <label className="form-field"><span>Machine code <RequiredMark /></span><input value={values.machineCode} onChange={(event) => change('machineCode', event.target.value)} placeholder="CG-TUP-A3-01" autoComplete="off" aria-invalid={Boolean(errors.machineCode)} /><FieldError message={errors.machineCode} />{isEdit && <small>Operational identifier: changing it may affect how your team references this machine.</small>}</label>
              <label className="form-field"><span>Display name <RequiredMark /></span><input value={values.displayName} onChange={(event) => change('displayName', event.target.value)} placeholder="Konica C1070 #1" autoComplete="off" aria-invalid={Boolean(errors.displayName)} /><FieldError message={errors.displayName} /></label>
              <label className="form-field"><span>Serial number <small>Optional</small></span><input value={values.serialNumber} onChange={(event) => change('serialNumber', event.target.value)} placeholder="Manufacturer serial" autoComplete="off" aria-invalid={Boolean(errors.serialNumber)} /><FieldError message={errors.serialNumber} /></label>
              <label className="form-field"><span>Installed date <small>Optional</small></span><input type="date" value={values.installedOn} onChange={(event) => change('installedOn', event.target.value)} /></label>
            </div>

            <div className="form-section-heading"><strong>Operations</strong><span>Timezone inherits from the branch or account when left blank.</span></div>
            <div className="form-grid">
              <label className="form-field"><span>Status <RequiredMark /></span><select value={values.status} onChange={(event) => change('status', event.target.value)} aria-invalid={Boolean(errors.status)}>{operationalStatuses.map((status) => <option key={status.value} value={status.value}>{status.label}</option>)}</select><FieldError message={errors.status} /></label>
              <label className="form-field"><span>Timezone <small>Optional</small></span><input value={values.timezone} onChange={(event) => change('timezone', event.target.value)} placeholder="Inherit workspace timezone" autoComplete="off" aria-invalid={Boolean(errors.timezone)} /><FieldError message={errors.timezone} /></label>
              <label className="form-field form-field-wide"><span>Notes <small>Optional</small></span><textarea value={values.notes} onChange={(event) => change('notes', event.target.value)} placeholder="Physical location, ownership note, or setup context" rows="4" /></label>
            </div>

            {formError && <div className="form-error" role="alert"><AlertCircle size={16} /><span>{formError}</span></div>}
            {!isEdit && selectedManufacturer && <p className="form-context-note">Creating a {selectedManufacturer.name} machine inside {account?.name}. Database constraints and tenant access remain authoritative.</p>}
          </div>
          <footer className="dialog-actions"><button className="draft-reset-button" type="button" onClick={handleResetDraft} disabled={!hasDraft || isSaving}><RotateCcw size={15} />Reset draft</button><button className="secondary-button" type="button" onClick={onClose} disabled={isSaving}>Cancel</button><button className="primary-button" type="submit" disabled={isSaving || Boolean(pendingDraft)}>{isSaving && <LoaderCircle className="spin" size={17} />}{isSaving ? 'Saving machine…' : isEdit ? 'Save changes' : 'Create machine'}</button></footer>
        </form>
      </section>
    </div>,
    document.body,
  )
}
