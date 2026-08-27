import { useState } from 'react'
import { Boxes, LoaderCircle, RotateCcw, X } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { useAuth } from '../auth/useAuth.js'
import { createDraftKey } from '../drafts/draftKeys.js'
import { usePersistentDraft } from '../drafts/usePersistentDraft.js'

const componentCategories = [
  'Imaging', 'Developer', 'Transfer', 'Fusing', 'Cleaning',
  'Mechanical', 'Electrical / Sensor', 'Consumable',
]

function categoryChoice(category) {
  if (!category) return ''
  return componentCategories.includes(category) ? category : 'Other'
}

const blank = {
  code: '', name: '', category: '', categoryChoice: '', description: '',
  manufacturerId: '', partNumber: '', trackingMethod: 'counter_based',
}

function initialValues(component) {
  if (!component) return blank
  return {
    code: component.code,
    name: component.name,
    category: component.category ?? '',
    categoryChoice: categoryChoice(component.category),
    description: component.description ?? '',
    manufacturerId: component.manufacturer_id ?? '',
    partNumber: component.part_number ?? '',
    trackingMethod: component.default_tracking_method,
  }
}

function isDraft(value) {
  return value && typeof value.code === 'string' && typeof value.name === 'string'
    && typeof value.category === 'string' && typeof value.trackingMethod === 'string'
}

export function ComponentDialog({ account, component, manufacturers, onClose, onSave }) {
  const { user } = useAuth()
  const initial = initialValues(component)
  const { value, updateDraft, clearDraft, resetDraft, hasDraft, wasRestored } = usePersistentDraft({
    draftKey: createDraftKey({ userId: user.id, accountId: account.id, feature: 'component-catalog', entityId: component?.id ?? 'new' }),
    initialValue: initial,
    metadata: { baseUpdatedAt: component?.updated_at ?? null },
    validate: isDraft,
  })
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const selectedCategory = value.categoryChoice ?? categoryChoice(value.category)

  function change(field, next) {
    updateDraft((current) => ({ ...current, [field]: next }))
    setError(null)
  }

  function changeCategory(nextChoice) {
    updateDraft((current) => ({
      ...current,
      categoryChoice: nextChoice,
      category: nextChoice === 'Other' ? (categoryChoice(current.category) === 'Other' ? current.category : '') : nextChoice,
    }))
    setError(null)
  }

  async function submit(event) {
    event.preventDefault()
    if (!value.name.trim() || !value.code.trim()) return setError('Code and name are required.')
    if (selectedCategory === 'Other' && !value.category.trim()) return setError('Enter a custom category or choose a controlled category.')
    setSaving(true)
    setError(null)
    try {
      await onSave({ ...value, category: value.category.trim().replace(/\s+/g, ' ') })
      clearDraft()
      onClose()
    } catch (saveError) {
      setError(saveError.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <BlockingDialog className="machine-dialog component-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="component-dialog-title" onClose={onClose} busy={saving}>
        <header className="dialog-header">
          <div className="dialog-heading"><span className="dialog-icon"><Boxes size={22} /></span><div><span className="card-kicker">Component catalog</span><h2 id="component-dialog-title">{component ? 'Edit component' : 'Add component'}</h2><p>Reusable definition only. Manufacturer does not assign a machine model.</p></div></div>
          <button className="icon-button" type="button" onClick={onClose} disabled={saving} aria-label="Close component form"><X size={19} /></button>
        </header>
        <form className="machine-form" onSubmit={submit}>
          <div className="machine-form-body">
            {wasRestored && <div className="draft-restored-status">Unsaved draft restored</div>}
            <div className="form-grid">
              <label className="form-field"><span>Code *</span><input value={value.code} onChange={(event) => change('code', event.target.value)} placeholder="FUSER_BELT" /></label>
              <label className="form-field"><span>Name *</span><input value={value.name} onChange={(event) => change('name', event.target.value)} placeholder="Fuser Belt" /></label>
              <label className="form-field"><span>Category</span><select value={selectedCategory} onChange={(event) => changeCategory(event.target.value)}><option value="">Choose category</option>{componentCategories.map((category) => <option key={category} value={category}>{category}</option>)}<option value="Other">Custom / Other</option></select></label>
              <label className="form-field"><span>Manufacturer</span><select value={value.manufacturerId} onChange={(event) => change('manufacturerId', event.target.value)}><option value="">Any manufacturer</option>{manufacturers.map((manufacturer) => <option key={manufacturer.id} value={manufacturer.id}>{manufacturer.name}</option>)}</select><small>Manufacturer describes the definition; model assignment is configured separately.</small></label>
              {selectedCategory === 'Other' && <label className="form-field"><span>Custom category *</span><input value={value.category} onChange={(event) => change('category', event.target.value)} placeholder="Enter a concise category" /></label>}
              <label className="form-field"><span>Part number</span><input value={value.partNumber} onChange={(event) => change('partNumber', event.target.value)} /></label>
              <label className="form-field"><span>Default tracking</span><select value={value.trackingMethod} onChange={(event) => change('trackingMethod', event.target.value)}><option value="counter_based">Counter based</option><option value="consumption_based">Consumption based</option><option value="inspection_based">Inspection based</option></select></label>
              <label className="form-field form-field-wide"><span>Description</span><textarea value={value.description} onChange={(event) => change('description', event.target.value)} rows="3" /></label>
            </div>
            {error && <div className="form-error">{error}</div>}
          </div>
          <footer className="dialog-actions"><button className="draft-reset-button" type="button" onClick={() => resetDraft(initial)} disabled={!hasDraft || saving}><RotateCcw size={15} />Reset draft</button><button className="secondary-button" type="button" onClick={onClose} disabled={saving}>Cancel</button><button className="primary-button" disabled={saving}>{saving && <LoaderCircle className="spin" size={16} />}Save component</button></footer>
        </form>
    </BlockingDialog>
  )
}
