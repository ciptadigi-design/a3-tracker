import { useMemo, useState } from 'react'
import { AlertCircle, CheckCircle2, ClipboardPlus, LoaderCircle, PencilLine, RotateCcw, X } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { useAuth } from '../auth/useAuth.js'
import { createDraftKey } from '../drafts/draftKeys.js'
import { usePersistentDraft } from '../drafts/usePersistentDraft.js'
import { incidentCategories, incidentTypes } from './incidentConstants.js'
import { formatRupiah, mapIncidentError, parseLoss, toLocalDateTimeInput } from './incidentUtils.js'

const draftFields = [
  'occurredAt', 'invoiceNumber', 'customerName', 'productName', 'category',
  'incidentType', 'machineId', 'qtyAffected', 'responsibleUserId',
  'responsibleName', 'materialLoss', 'serviceLoss', 'description', 'cause',
  'prevention', 'customerResolution', 'clientRequestId',
]

const editDraftFields = [...draftFields.filter((field) => field !== 'clientRequestId'), 'changeReason', 'baseUpdatedAt']

function createInitialDraft() {
  return {
    occurredAt: toLocalDateTimeInput(),
    invoiceNumber: '',
    customerName: '',
    productName: '',
    category: '',
    incidentType: '',
    machineId: '',
    qtyAffected: '',
    responsibleUserId: '',
    responsibleName: '',
    materialLoss: '',
    serviceLoss: '',
    description: '',
    cause: '',
    prevention: '',
    customerResolution: '',
    clientRequestId: crypto.randomUUID(),
  }
}

function createEditDraft(incident) {
  return {
    occurredAt: toLocalDateTimeInput(new Date(incident.occurred_at)),
    invoiceNumber: incident.invoice_number ?? '',
    customerName: incident.customer_name_snapshot ?? '',
    productName: incident.product_name_snapshot ?? '',
    category: incident.category,
    incidentType: incident.incident_type,
    machineId: incident.machine_id ?? '',
    qtyAffected: incident.qty_affected == null ? '' : String(incident.qty_affected),
    responsibleUserId: incident.responsible_person_id ?? '',
    responsibleName: incident.responsible_name_snapshot ?? '',
    materialLoss: String(Number(incident.material_loss)),
    serviceLoss: String(Number(incident.service_loss)),
    description: incident.description,
    cause: incident.cause ?? '',
    prevention: incident.prevention ?? '',
    customerResolution: incident.customer_resolution ?? '',
    changeReason: '',
    baseUpdatedAt: incident.updated_at,
  }
}

function isIncidentDraft(value) {
  return value && draftFields.every((field) => typeof value[field] === 'string')
}

function isIncidentEditDraft(value) {
  return value && editDraftFields.every((field) => typeof value[field] === 'string')
}

function validate(values) {
  const errors = {}
  if (!values.occurredAt || Number.isNaN(new Date(values.occurredAt).getTime())) errors.occurredAt = 'Tanggal kejadian wajib diisi.'
  else if (new Date(values.occurredAt).getTime() > Date.now() + 5 * 60_000) errors.occurredAt = 'Tanggal kejadian tidak boleh berada di masa depan.'
  if (!incidentCategories.some((item) => item.value === values.category)) errors.category = 'Pilih kategori operasional.'
  if (!incidentTypes.some((item) => item.value === values.incidentType)) errors.incidentType = 'Pilih jenis kejadian.'
  if (values.qtyAffected && (!/^\d+$/.test(values.qtyAffected) || Number(values.qtyAffected) <= 0)) errors.qtyAffected = 'Qty rusak harus berupa bilangan bulat lebih dari nol.'
  if (values.materialLoss && (!/^\d+$/.test(values.materialLoss) || Number(values.materialLoss) < 0)) errors.materialLoss = 'Rugi bahan harus bernilai nol atau lebih.'
  if (values.serviceLoss && (!/^\d+$/.test(values.serviceLoss) || Number(values.serviceLoss) < 0)) errors.serviceLoss = 'Rugi jasa harus bernilai nol atau lebih.'
  if (!values.description.trim()) errors.description = 'Deskripsi kesalahan wajib diisi.'
  return errors
}

function RequiredMark() {
  return <b className="required-mark">*</b>
}

function FieldError({ message }) {
  return message ? <small className="field-error"><AlertCircle size={13} />{message}</small> : null
}

export function IncidentFormDialog({ account, branch, machines, people, incident = null, mode = 'create', onClose, onSave, onLoadLatest }) {
  const { user } = useAuth()
  const isEdit = mode === 'edit'
  const [draftContext] = useState(() => ({
    key: createDraftKey({
      userId: user.id,
      accountId: account.id,
      branchId: branch.id,
      feature: 'operational-incident',
      entityId: isEdit ? incident.id : 'new',
    }),
    initialValue: isEdit ? createEditDraft(incident) : createInitialDraft(),
  }))
  const {
    value: values,
    updateDraft,
    hasDraft,
    wasRestored,
    clearDraft,
    resetDraft,
    pendingDraft,
    restorePendingDraft,
    discardPendingDraft,
  } = usePersistentDraft({
    draftKey: draftContext.key,
    initialValue: draftContext.initialValue,
    metadata: isEdit ? { baseUpdatedAt: incident.updated_at } : {},
    validate: isEdit ? isIncidentEditDraft : isIncidentDraft,
    shouldRestore: isEdit ? (stored) => stored.value.baseUpdatedAt === incident.updated_at : undefined,
  })
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [isSaving, setIsSaving] = useState(false)
  const [runtimeConflict, setRuntimeConflict] = useState(null)
  const assessedLoss = useMemo(
    () => parseLoss(values.materialLoss) + parseLoss(values.serviceLoss),
    [values.materialLoss, values.serviceLoss],
  )

  function change(field, value) {
    updateDraft((current) => ({ ...current, [field]: value }))
    setErrors((current) => ({ ...current, [field]: undefined }))
    setFormError(null)
  }

  function changeDigits(field, value) {
    if (/^\d*$/.test(value)) change(field, value)
  }

  function changeResponsible(userId) {
    const member = people.find((item) => item.id === userId)
    updateDraft((current) => ({
      ...current,
      responsibleUserId: userId,
      responsibleName: member?.name ?? current.responsibleName,
    }))
    setFormError(null)
  }

  function handleReset() {
    resetDraft(draftContext.initialValue)
    setErrors({})
    setFormError(null)
  }

  function handleUseLatestServerData(latest = incident) {
    const nextValues = createEditDraft(latest)
    if (pendingDraft) discardPendingDraft()
    else clearDraft(nextValues)
    setRuntimeConflict(null)
    setErrors({})
    setFormError(null)
  }

  function restoreMyDraft(latest = incident) {
    if (pendingDraft) restorePendingDraft()
    updateDraft((current) => ({ ...current, baseUpdatedAt: latest.updated_at }))
    setRuntimeConflict(null)
    setFormError(null)
  }

  async function handleSubmit(event) {
    event.preventDefault()
    if (isSaving || pendingDraft || runtimeConflict) return
    const nextErrors = validate(values)
    setErrors(nextErrors)
    if (Object.keys(nextErrors).length) {
      setFormError('Periksa kembali bidang yang ditandai.')
      return
    }

    setIsSaving(true)
    setFormError(null)
    try {
      await onSave(values)
      clearDraft()
      onClose()
    } catch (error) {
      if (isEdit && error?.code === '40001' && onLoadLatest) {
        const latest = await onLoadLatest()
        setRuntimeConflict(latest)
      }
      setFormError(mapIncidentError(error))
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <BlockingDialog className="machine-dialog incident-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="incident-dialog-title" describedBy="incident-dialog-description" onClose={onClose} busy={isSaving}>
        <header className="dialog-header">
          <div className="dialog-heading"><span className="dialog-icon">{isEdit ? <PencilLine size={22} /> : <ClipboardPlus size={22} />}</span><div><span className="card-kicker">Human / Operational Error</span><h2 id="incident-dialog-title">{isEdit ? 'Edit Log' : 'Log error operasional'}</h2><p id="incident-dialog-description">{isEdit ? 'Perbarui current truth tanpa menghapus riwayat perubahan.' : `Catat kejadian produksi nyata di ${branch.name}. Bukan kode fault teknis mesin.`}</p></div></div>
          <button className="icon-button" type="button" onClick={onClose} disabled={isSaving} aria-label="Tutup form log error"><X size={19} /></button>
        </header>

        <form className="machine-form incident-form" onSubmit={handleSubmit} noValidate>
          <div className="machine-form-body">
            {wasRestored && <div className="draft-restored-status" role="status"><CheckCircle2 size={14} /><span>Unsaved draft restored</span></div>}
            {(pendingDraft || runtimeConflict) && <div className="incident-conflict-banner" role="alert"><AlertCircle size={18} /><div><strong>Incident data changed since this draft was created.</strong><span>Pilih versi terbaru server atau lanjutkan draft Anda berdasarkan versi terbaru sebelum menyimpan.</span><div><button className="secondary-button" type="button" onClick={() => handleUseLatestServerData(runtimeConflict || incident)}>Use latest server data</button><button className="primary-button" type="button" onClick={() => restoreMyDraft(runtimeConflict || incident)}>Restore my draft</button></div></div></div>}

            <div className="form-section-heading"><strong>Konteks kejadian</strong><span>Identitas produksi dan klasifikasi operasional.</span></div>
            <div className="form-grid incident-form-grid">
              <label className="form-field"><span>Tanggal Kejadian <RequiredMark /></span><input type="datetime-local" value={values.occurredAt} max={toLocalDateTimeInput(new Date(Date.now() + 5 * 60_000))} onChange={(event) => change('occurredAt', event.target.value)} aria-invalid={Boolean(errors.occurredAt)} /><FieldError message={errors.occurredAt} /></label>
              <label className="form-field"><span>No. Invoice CRM <small>Opsional</small></span><input value={values.invoiceNumber} onChange={(event) => change('invoiceNumber', event.target.value)} placeholder="Nomor invoice" autoComplete="off" /></label>
              <label className="form-field"><span>Nama Konsumen <small>Opsional</small></span><input value={values.customerName} onChange={(event) => change('customerName', event.target.value)} placeholder="Nama konsumen saat kejadian" autoComplete="off" /></label>
              <label className="form-field"><span>Nama Produk <small>Opsional</small></span><input value={values.productName} onChange={(event) => change('productName', event.target.value)} placeholder="Produk / pekerjaan" autoComplete="off" /></label>
              <label className="form-field"><span>Kategori <RequiredMark /></span><select value={values.category} onChange={(event) => change('category', event.target.value)} aria-invalid={Boolean(errors.category)}><option value="">Pilih kategori</option>{incidentCategories.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select><FieldError message={errors.category} /></label>
              <label className="form-field"><span>Jenis <RequiredMark /></span><select value={values.incidentType} onChange={(event) => change('incidentType', event.target.value)} aria-invalid={Boolean(errors.incidentType)}><option value="">Pilih jenis</option>{incidentTypes.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select><small>“Mesin” berarti kesalahan penggunaan/setting produksi, bukan fault code teknis.</small><FieldError message={errors.incidentType} /></label>
              <label className="form-field"><span>Machine</span><select value={values.machineId} onChange={(event) => change('machineId', event.target.value)}><option value="">Branch / No specific machine</option>{machines.filter((machine) => machine.is_active).map((machine) => <option key={machine.id} value={machine.id}>{machine.machine_code} · {machine.display_name}</option>)}</select><small>Select the production machine explicitly, or keep branch scope when no machine is attributable.</small></label>
              <label className="form-field"><span>Qty Rusak <small>Opsional</small></span><input value={values.qtyAffected} onChange={(event) => changeDigits('qtyAffected', event.target.value)} inputMode="numeric" pattern="[0-9]*" placeholder="0" aria-invalid={Boolean(errors.qtyAffected)} /><FieldError message={errors.qtyAffected} /></label>
              <label className="form-field"><span>PIC / Operator <small>Opsional</small></span><select value={values.responsibleUserId} onChange={(event) => changeResponsible(event.target.value)}><option value="">Nama manual / tidak ditetapkan</option>{people.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}</select></label>
              <label className="form-field"><span>PIC Terlibat <small>Snapshot nama</small></span><input value={values.responsibleName} onChange={(event) => change('responsibleName', event.target.value)} placeholder="Nama PIC saat kejadian" autoComplete="off" /></label>
            </div>

            <div className="form-section-heading"><strong>Dampak kerugian</strong><span>Multiplier V1 tetap 1×; tidak ada hukuman dinamis.</span></div>
            <div className="form-grid incident-loss-grid">
              <label className="form-field"><span>Rugi Bahan (Rp)</span><input value={values.materialLoss} onChange={(event) => changeDigits('materialLoss', event.target.value)} inputMode="numeric" pattern="[0-9]*" placeholder="0" aria-invalid={Boolean(errors.materialLoss)} /><FieldError message={errors.materialLoss} /></label>
              <label className="form-field"><span>Rugi Jasa (Rp)</span><input value={values.serviceLoss} onChange={(event) => changeDigits('serviceLoss', event.target.value)} inputMode="numeric" pattern="[0-9]*" placeholder="0" aria-invalid={Boolean(errors.serviceLoss)} /><FieldError message={errors.serviceLoss} /></label>
              <div className="incident-loss-preview form-field-wide"><span>Total assessed loss · 1×</span><strong>{formatRupiah(assessedLoss)}</strong><small>Dihitung ulang dan disimpan secara aman oleh PostgreSQL.</small></div>
            </div>

            <div className="form-section-heading"><strong>Kronologi & tindak lanjut</strong><span>Gunakan informasi faktual yang dapat ditindaklanjuti.</span></div>
            <div className="form-grid incident-narrative-grid">
              <label className="form-field form-field-wide"><span>1. Deskripsi Kesalahan <RequiredMark /></span><textarea value={values.description} onChange={(event) => change('description', event.target.value)} rows="4" placeholder="Apa yang terjadi?" aria-invalid={Boolean(errors.description)} /><FieldError message={errors.description} /></label>
              <label className="form-field form-field-wide"><span>2. Penyebab Kesalahan <small>Opsional</small></span><textarea value={values.cause} onChange={(event) => change('cause', event.target.value)} rows="3" placeholder="Penyebab yang diketahui saat ini" /></label>
              <label className="form-field form-field-wide"><span>3. Solusi & Pencegahan <small>Opsional</small></span><textarea value={values.prevention} onChange={(event) => change('prevention', event.target.value)} rows="3" placeholder="Tindakan korektif dan pencegahan" /></label>
              <label className="form-field form-field-wide"><span>4. Penyelesaian Untuk Konsumen <small>Opsional</small></span><textarea value={values.customerResolution} onChange={(event) => change('customerResolution', event.target.value)} rows="3" placeholder="Penggantian, komunikasi, atau penyelesaian lain" /></label>
              {isEdit && <label className="form-field form-field-wide"><span>Catatan perubahan <small>Opsional</small></span><textarea value={values.changeReason} onChange={(event) => change('changeReason', event.target.value)} rows="2" placeholder="Contoh: Hasil diskusi evaluasi produksi pagi." /></label>}
            </div>

            {formError && <div className="form-error" role="alert"><AlertCircle size={16} /><span>{formError}</span></div>}
          </div>
          <footer className="dialog-actions form-action-footer"><button className="draft-reset-button" type="button" onClick={handleReset} disabled={!hasDraft || isSaving} aria-label="Reset draft" title="Reset draft"><RotateCcw size={15} />Reset draft</button><button className="secondary-button" type="button" onClick={onClose} disabled={isSaving}>Batal</button><button className="primary-button" type="submit" disabled={isSaving || Boolean(pendingDraft || runtimeConflict)}>{isSaving ? <LoaderCircle className="spin" size={17} /> : isEdit ? <PencilLine size={17} /> : <ClipboardPlus size={17} />}{isSaving ? 'Menyimpan…' : isEdit ? 'Simpan Perubahan' : 'Simpan Log Error'}</button></footer>
        </form>
    </BlockingDialog>
  )
}
