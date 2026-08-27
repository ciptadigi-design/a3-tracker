import { useCallback, useEffect, useMemo, useState } from 'react'
import { Archive, Boxes, Edit3, Link2, Plus, RefreshCcw, ShieldCheck, Trash2 } from 'lucide-react'
import { PageHeader } from '../components/ui/PageHeader.jsx'
import { useAuth } from '../features/auth/useAuth.js'
import { useTenant } from '../features/account/useTenant.js'
import { ComponentDialog } from '../features/components/ComponentDialog.jsx'
import { ProfileDialog } from '../features/components/ProfileDialog.jsx'
import { useComponentWorkflowState } from '../features/components/useComponentWorkflowState.js'
import { createUIStateKey } from '../features/uiState/uiStateKeys.js'
import { usePersistentUIState } from '../features/uiState/usePersistentUIState.js'
import { deleteComponent, effectiveProfiles, loadComponentFoundation, removeProfile, saveComponent, saveProfile } from '../services/supabase/components.js'

const trackingLabels = {
  counter_based: 'Counter based',
  consumption_based: 'Consumption based',
  inspection_based: 'Inspection based',
}

function validView(value) {
  return value && ['profiles', 'catalog'].includes(value.tab)
    && (value.modelId == null || typeof value.modelId === 'string')
    && typeof value.showArchived === 'boolean'
}

function assignmentMap({ profiles, models, accountId }) {
  const result = new Map()
  for (const model of models) {
    for (const profile of effectiveProfiles(profiles, accountId, model.id).filter((item) => item.is_active)) {
      const assignedModels = result.get(profile.component_id) ?? []
      if (!assignedModels.some((item) => item.id === model.id)) assignedModels.push(model)
      result.set(profile.component_id, assignedModels)
    }
  }
  return result
}

function ConfirmDialog({ title, message, confirmLabel, onCancel, onConfirm, busy }) {
  return <div className="dialog-backdrop"><section className="confirm-dialog glass-surface" role="alertdialog" aria-modal="true"><span className="danger-dialog-icon"><Trash2 size={22} /></span><h2>{title}</h2><p>{message}</p><div className="dialog-actions"><button className="secondary-button" onClick={onCancel} disabled={busy}>Cancel</button><button className="danger-button" onClick={onConfirm} disabled={busy}>{busy ? 'Working…' : confirmLabel}</button></div></section></div>
}

export function ComponentsPage() {
  const { user } = useAuth()
  const { account, membership } = useTenant()
  const canManage = ['owner', 'admin'].includes(membership?.role)
  const [data, setData] = useState({ manufacturers: [], models: [], components: [], profiles: [] })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)
  const [confirm, setConfirm] = useState(null)
  const [busy, setBusy] = useState(false)
  const viewKey = createUIStateKey({ userId: user.id, accountId: account.id, feature: 'components-view', entityId: 'active' })
  const { value: view, setUIState: setView } = usePersistentUIState({ uiStateKey: viewKey, initialValue: { tab: 'profiles', modelId: null, showArchived: false }, validate: validView })
  const { workflow, open, close } = useComponentWorkflowState({ userId: user.id, accountId: account.id })

  const refresh = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      setData(await loadComponentFoundation())
    } catch (loadError) {
      setError(loadError)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    let active = true
    loadComponentFoundation()
      .then((result) => { if (active) setData(result) })
      .catch((loadError) => { if (active) setError(loadError) })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [])

  const selectedModel = data.models.find((model) => model.id === view.modelId) ?? data.models[0] ?? null
  useEffect(() => {
    if (!view.modelId && data.models[0]) setView((current) => ({ ...current, modelId: data.models[0].id }))
  }, [data.models, setView, view.modelId])

  const effective = useMemo(() => effectiveProfiles(data.profiles, account.id, selectedModel?.id), [account.id, data.profiles, selectedModel?.id])
  const allEffectiveProfiles = useMemo(() => data.models.flatMap((model) => effectiveProfiles(data.profiles, account.id, model.id)), [account.id, data.models, data.profiles])
  const assignments = useMemo(() => assignmentMap({ profiles: data.profiles, models: data.models, accountId: account.id }), [account.id, data.models, data.profiles])
  const visibleProfiles = effective.filter((profile) => view.showArchived ? !profile.is_active : profile.is_active)
  const visibleComponents = data.components.filter((component) => view.showArchived ? !component.is_active : component.is_active)
  const editingComponent = data.components.find((component) => component.id === workflow.entityId)
  const editingProfile = allEffectiveProfiles.find((profile) => profile.id === workflow.entityId)
  const assigningComponent = data.components.find((component) => component.id === workflow.entityId)
  const profileModel = data.models.find((model) => model.id === editingProfile?.machine_model_id) ?? selectedModel

  async function savedComponent(values) {
    await saveComponent({ accountId: account.id, component: editingComponent, values })
    await refresh()
    setNotice('Component definition saved. Machine-model assignments were not changed.')
  }

  async function savedProfile(values) {
    await saveProfile({ accountId: account.id, modelId: values.machineModelId, profile: editingProfile, values })
    setView((current) => ({ ...current, modelId: values.machineModelId }))
    await refresh()
    setNotice(workflow.type === 'profile-assign'
      ? `${assigningComponent.name} was assigned to the selected machine model.`
      : 'Model profile saved. Future lifecycle installs will use the updated baseline.')
  }

  async function runConfirm() {
    setBusy(true)
    try {
      const result = confirm.kind === 'component'
        ? await deleteComponent({ accountId: account.id, componentId: confirm.item.id })
        : await removeProfile({ accountId: account.id, modelId: selectedModel.id, profile: confirm.item })
      setNotice(result.mode === 'deleted' ? 'Unused record permanently deleted.' : 'Historical safety required archiving; the record remains readable.')
      setConfirm(null)
      await refresh()
    } catch (mutationError) {
      setError(mutationError)
    } finally {
      setBusy(false)
    }
  }

  const pageAction = canManage ? <div className="page-header-actions"><button className="secondary-button" onClick={() => open('component-create')}><Plus size={17} />Add Component</button>{selectedModel && <button className="primary-button" onClick={() => open('profile-create')}><Plus size={17} />Add Profile</button>}</div> : null

  return <div className="page-stack">
    <PageHeader eyebrow={`${account.name} · Component intelligence`} title="Components" description="Catalog definitions are reusable. Machine-model profiles explicitly assign them to compatible models." action={pageAction} />
    {notice && <div className="success-banner"><span>{notice}</span><button onClick={() => setNotice(null)}>Dismiss</button></div>}
    {!canManage && <div className="permission-banner"><ShieldCheck size={18} /><span>Your {membership?.role} role has read-only access. Owners and admins manage catalog and profiles.</span></div>}
    {error && <div className="inline-error catalog-error"><span>{error.message}</span><button className="secondary-button" onClick={refresh}>Try again</button></div>}

    <section className="component-shell glass-surface">
      <div className="component-toolbar"><div className="machine-view-tabs"><button className={view.tab === 'profiles' ? 'selected' : ''} onClick={() => setView((current) => ({ ...current, tab: 'profiles' }))}>Model Profiles</button><button className={view.tab === 'catalog' ? 'selected' : ''} onClick={() => setView((current) => ({ ...current, tab: 'catalog' }))}>Component Catalog</button></div><button className="icon-button" onClick={refresh} aria-label="Refresh"><RefreshCcw size={17} className={loading ? 'spin' : ''} /></button></div>

      {view.tab === 'profiles' && <>
        <div className="model-selector-row"><label><span>Machine model</span><select value={selectedModel?.id ?? ''} onChange={(event) => setView((current) => ({ ...current, modelId: event.target.value }))}>{data.models.map((model) => <option key={model.id} value={model.id}>{model.manufacturers?.name} · {model.name}</option>)}</select></label><div><strong>{effective.filter((profile) => profile.is_active).length}</strong><span>active profiles</span></div></div>
        <div className="profile-list-tools"><div className="machine-view-tabs record-tabs"><button className={!view.showArchived ? 'selected' : ''} onClick={() => setView((current) => ({ ...current, showArchived: false }))}>Active <span>{effective.filter((profile) => profile.is_active).length}</span></button><button className={view.showArchived ? 'selected' : ''} onClick={() => setView((current) => ({ ...current, showArchived: true }))}>Archived <span>{effective.filter((profile) => !profile.is_active).length}</span></button></div>{canManage && !view.showArchived && <button className="secondary-button compact-button" onClick={() => open('profile-create')}><Plus size={15} />Add Profile</button>}</div>
        <div className="component-table-head"><span>Component / slot</span><span>Tracking</span><span>Baseline</span><span>Adaptive</span><span>Actions</span></div>
        <div className="component-list">{visibleProfiles.map((profile) => <article className="component-row" key={profile.id}><div className="component-identity"><span className="component-icon"><Boxes size={18} /></span><div><strong>{profile.components?.name}</strong><code>{profile.slot_code}</code></div></div><span className="tracking-pill">{trackingLabels[profile.tracking_method]}</span><div className="component-baseline"><strong>{profile.baseline_expected_clicks?.toLocaleString() ?? 'Reference only'}</strong><span>expected clicks</span></div><span className={profile.adaptive_enabled ? 'adaptive-on' : 'adaptive-off'}>{profile.adaptive_enabled ? 'Enabled' : 'Disabled'}</span><div className="row-actions">{canManage && <><button onClick={() => open('profile-edit', profile.id)} aria-label={`Edit ${profile.components?.name}`}><Edit3 size={16} /></button><button onClick={() => setConfirm({ kind: 'profile', item: profile })} aria-label={`Remove ${profile.components?.name}`}>{profile.is_active ? <Archive size={16} /> : <Trash2 size={16} />}</button></>}</div></article>)}</div>
      </>}

      {view.tab === 'catalog' && <>
        <div className="catalog-summary"><div><span className="card-kicker">Reusable definitions</span><h2>{visibleComponents.length} {view.showArchived ? 'archived' : 'active'} components</h2></div><div className="machine-view-tabs"><button className={!view.showArchived ? 'selected' : ''} onClick={() => setView((current) => ({ ...current, showArchived: false }))}>Active</button><button className={view.showArchived ? 'selected' : ''} onClick={() => setView((current) => ({ ...current, showArchived: true }))}>Archived</button></div></div>
        <div className="catalog-grid">{visibleComponents.map((component) => {
          const manufacturer = data.manufacturers.find((item) => item.id === component.manufacturer_id)
          const assignedModels = assignments.get(component.id) ?? []
          return <article className="catalog-card" key={component.id}><div><span className="component-icon"><Boxes size={18} /></span><span className={component.account_id ? 'scope-pill custom' : 'scope-pill'}>{component.account_id ? 'Workspace' : 'Shared'}</span></div><h3>{component.name}</h3><code>{component.code}</code><p>{manufacturer?.name ?? 'Any manufacturer'} · {trackingLabels[component.default_tracking_method]}</p><span className="catalog-category">{component.category ?? 'Uncategorized'}</span><div className={assignedModels.length ? 'assignment-context assigned' : 'assignment-context'}><Link2 size={14} /><span>{assignedModels.length ? `Assigned: ${assignedModels.map((model) => `${model.manufacturers?.name} · ${model.name}`).join(', ')}` : 'Not assigned to any model'}</span></div>{canManage && component.is_active && <div className="catalog-actions"><button className="primary-button assign-model-button" onClick={() => open('profile-assign', component.id)}><Link2 size={15} />Assign to Model</button>{component.account_id === account.id && <><button className="secondary-button" onClick={() => open('component-edit', component.id)}><Edit3 size={15} />Edit</button><button className="secondary-button icon-only-action" onClick={() => setConfirm({ kind: 'component', item: component })} aria-label={`Delete ${component.name}`}><Trash2 size={15} /></button></>}</div>}</article>
        })}</div>
      </>}

      {!loading && ((view.tab === 'profiles' && !visibleProfiles.length) || (view.tab === 'catalog' && !visibleComponents.length)) && <div className="component-empty">No records in this view.</div>}
      {loading && <div className="machine-loading-state"><RefreshCcw className="spin" size={24} /><strong>Loading component intelligence…</strong></div>}
    </section>

    {canManage && (workflow.type === 'component-create' || (workflow.type === 'component-edit' && editingComponent)) && <ComponentDialog account={account} component={editingComponent} manufacturers={data.manufacturers} onClose={close} onSave={savedComponent} />}
    {canManage && selectedModel && (workflow.type === 'profile-create' || (workflow.type === 'profile-edit' && editingProfile) || (workflow.type === 'profile-assign' && assigningComponent)) && <ProfileDialog account={account} model={profileModel} models={data.models} profile={editingProfile} components={data.components} initialComponent={workflow.type === 'profile-assign' ? assigningComponent : null} draftEntityId={workflow.type === 'profile-assign' ? `assign-${assigningComponent.id}` : undefined} onClose={close} onSave={savedProfile} />}
    {confirm && <ConfirmDialog title={confirm.kind === 'component' ? 'Delete Component' : 'Remove Model Profile'} message={confirm.kind === 'component' ? 'If this component has never been referenced, it will be permanently deleted. Historical usage forces a safe archive instead.' : 'Unused profiles are deleted. Any historically referenced profile is archived so operational records remain intact.'} confirmLabel={confirm.kind === 'component' ? 'Delete / archive' : 'Remove / archive'} onCancel={() => setConfirm(null)} onConfirm={runConfirm} busy={busy} />}
  </div>
}
