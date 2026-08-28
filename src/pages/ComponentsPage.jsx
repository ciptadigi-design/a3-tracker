import { useCallback, useEffect, useMemo, useState } from 'react'
import { Activity, Archive, BarChart3, Boxes, Edit3, Eye, EyeOff, Gauge, Link2, Plus, RefreshCcw, ShieldCheck, Trash2 } from 'lucide-react'
import { BlockingDialog } from '../components/ui/BlockingDialog.jsx'
import { PageHeader } from '../components/ui/PageHeader.jsx'
import { useAuth } from '../features/auth/useAuth.js'
import { useTenant } from '../features/account/useTenant.js'
import { ComponentDialog } from '../features/components/ComponentDialog.jsx'
import { ProfileDialog } from '../features/components/ProfileDialog.jsx'
import { InitializeLifecycleDialog } from '../features/components/InitializeLifecycleDialog.jsx'
import { ReplaceComponentDialog } from '../features/components/ReplaceComponentDialog.jsx'
import { ReplacementHistory } from '../features/components/ReplacementHistory.jsx'
import { ComponentIntelligenceDialog } from '../features/components/ComponentIntelligenceDialog.jsx'
import { ComponentChannelMarker } from '../features/components/ComponentChannelMarker.jsx'
import { lifecycleActionFor } from '../features/components/lifecycleActions.js'
import { useComponentWorkflowState } from '../features/components/useComponentWorkflowState.js'
import { createUIStateKey } from '../features/uiState/uiStateKeys.js'
import { usePersistentUIState } from '../features/uiState/usePersistentUIState.js'
import { adoptIntelligenceRecommendation, deleteComponent, effectiveProfiles, loadComponentFoundation, removeProfile, saveComponent, saveProfile } from '../services/supabase/components.js'
import { initializeComponentLifecycle, loadMachineComponentLifecycles, replaceComponentLifecycle } from '../services/supabase/componentLifecycles.js'

const trackingLabels = {
  counter_based: 'Counter based',
  consumption_based: 'Consumption based',
  inspection_based: 'Inspection based',
}

function validView(value) {
  return value && ['machine', 'profiles', 'catalog'].includes(value.tab)
    && (value.modelId == null || typeof value.modelId === 'string')
    && Object.hasOwn(value, 'machineId')
    && (value.machineId == null || typeof value.machineId === 'string')
    && typeof value.showArchived === 'boolean'
}

function number(value) {
  return value == null ? '—' : Number(value).toLocaleString('en-US', { maximumFractionDigits: 0 })
}

function validDensity(value) {
  return value && ['compact', 'detailed'].includes(value.mode)
}

function DensityControl({ density, onChange }) {
  const detailed = density === 'detailed'
  const nextDensity = detailed ? 'compact' : 'detailed'
  const actionLabel = `Switch to ${detailed ? 'Compact' : 'Detailed'} view`

  return <button type="button" className={`component-density-toggle density-${density}`} aria-label={actionLabel} aria-pressed={detailed} title={actionLabel} onClick={() => onChange(nextDensity)}>
    <span className="component-density-icon" key={density} aria-hidden="true">{detailed ? <Eye size={17} /> : <EyeOff size={17} />}</span>
  </button>
}

function MachineComponentsPanel({ machines, lifecycles, replacementHistory, selectedMachine, onMachineChange, canManage, canReplace, onInitialize, onReplace, density }) {
  const rows = lifecycles.filter((row) => row.machine_id === selectedMachine?.id)
  const initialized = rows.filter((row) => row.lifecycle_status === 'active').length
  const currentCounter = rows.find((row) => row.latest_effective_counter != null)?.latest_effective_counter

  return <>
    <div className="model-selector-row lifecycle-machine-selector"><label><span>Physical machine</span><select value={selectedMachine?.id ?? ''} onChange={(event) => onMachineChange(event.target.value)}>{machines.map((machine) => <option key={machine.id} value={machine.id}>{machine.machine_code} · {machine.display_name}</option>)}</select></label><div><strong>{rows.length}</strong><span>component slots · {initialized} initialized</span></div></div>
    {selectedMachine && density === 'detailed' && <div className="lifecycle-counter-banner"><span><Gauge size={17} />Latest effective Total Impressions</span><strong>{number(currentCounter)}</strong><small>Daily Counter source of truth</small></div>}
    <div className="lifecycle-grid">{rows.map((row) => {
      const unknown = row.lifecycle_status === 'unknown'
      const consumption = row.tracking_method === 'consumption_based'
      const visualPercent = Math.max(0, Math.min(100, Number(row.remaining_percent ?? 0)))
      const lifecycleAction = lifecycleActionFor({ lifecycleStatus: row.lifecycle_status, canInitialize: canManage, canReplace })
      return <article className={`lifecycle-card ${density === 'compact' ? 'lifecycle-card-compact' : ''} ${unknown ? 'lifecycle-unknown' : `health-${row.health_status}`}`} key={row.lifecycle_id}>
        {density === 'compact' ? <>
          <header><h3><ComponentChannelMarker slotCode={row.slot_code} name={row.component_name} />{row.component_name}</h3><span className={`health-badge ${unknown ? 'health-unknown' : `health-${row.health_status}`}`}>{unknown ? 'Unknown' : row.health_status}</span></header>
          {unknown ? <div className="compact-unknown-state"><span>Lifecycle not initialized</span>{lifecycleAction === 'initialize' && <button type="button" onClick={() => onInitialize(row.lifecycle_id)} aria-label={`Initialize ${row.component_name} lifecycle`} title="Start tracking the component already installed">Initialize</button>}</div> : <div className="compact-life-row"><div className="lifecycle-progress" role="progressbar" aria-label={`${row.component_name} remaining life: ${Number(row.remaining_percent).toFixed(1)} percent, ${row.health_status}`} aria-valuemin="0" aria-valuemax="100" aria-valuenow={visualPercent}><span style={{ width: `${visualPercent}%` }} /></div><strong>{Number(row.remaining_percent).toFixed(1)}%</strong>{lifecycleAction === 'replace' && <button className="compact-replace-button" type="button" onClick={() => onReplace(row.lifecycle_id)} aria-label={`Replace ${row.component_name}`}><RefreshCcw size={13} />Replace</button>}</div>}
        </> : <>
        <header><div><span className="component-icon"><Activity size={18} /></span><div><h3><ComponentChannelMarker slotCode={row.slot_code} name={row.component_name} />{row.component_name}</h3><span>{consumption ? 'Yield-based tracking' : 'Counter-based lifecycle'}</span></div></div>{!unknown && <span className={`health-badge health-${row.health_status}`}>{row.health_status}</span>}</header>
        {unknown ? <div className="unknown-lifecycle-body"><div><span>Installation history</span><strong>Unknown</strong></div><div><span>Tracking status</span><strong>Not initialized</strong></div><div><span>Expected baseline</span><strong>{number(row.current_profile_baseline)} clicks</strong></div><p>Start tracking a component that is already installed. Inventory will not change.</p>{lifecycleAction === 'initialize' && <button className="primary-button" onClick={() => onInitialize(row.lifecycle_id)} aria-label={`Initialize ${row.component_name} lifecycle`}>Initialize Lifecycle</button>}</div> : <>
          <div className="lifecycle-metrics"><div><span>{consumption ? 'Used Yield' : 'Current Usage'}</span><strong>{number(row.current_usage)}</strong></div><div><span>{consumption ? 'Expected Yield' : 'Expected Life'}</span><strong>{number(row.effective_expected)}</strong></div><div><span>{consumption ? 'Estimated Remaining' : 'Remaining Clicks'}</span><strong>{number(row.remaining_clicks)}</strong></div><div><span>Remaining</span><strong>{Number(row.remaining_percent).toFixed(1)}%</strong></div><div className="replace-counter"><span>Estimated Replacement Counter</span><strong>{number(row.estimated_replacement_counter)}</strong></div></div>
          <div className="lifecycle-progress" aria-label={`${Math.max(0, Number(row.remaining_percent)).toFixed(1)} percent remaining`}><span style={{ width: `${visualPercent}%` }} /></div>
          <footer><div className="lifecycle-snapshot-context"><span>{row.expected_source}</span>{Number(row.current_profile_baseline) !== Number(row.expected_at_install) && <span>Current profile: {number(row.current_profile_baseline)} · install snapshot: {number(row.expected_at_install)}</span>}</div>{lifecycleAction === 'replace' && <button className="secondary-button lifecycle-replace-button" type="button" onClick={() => onReplace(row.lifecycle_id)} aria-label={`Replace ${row.component_name}`}><RefreshCcw size={14} />{consumption ? 'Replace / Refill Toner' : 'Replace Component'}</button>}</footer>
        </>}</>}
      </article>
    })}</div>
    {!rows.length && <div className="component-empty">No component lifecycles are available for this machine.</div>}
    {selectedMachine && density === 'detailed' && <ReplacementHistory history={replacementHistory} machine={selectedMachine} />}
  </>
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
  return <BlockingDialog className="confirm-dialog glass-surface" role="alertdialog" labelledBy="components-confirm-title" describedBy="components-confirm-description" onClose={onCancel} busy={busy}><span className="danger-dialog-icon"><Trash2 size={22} /></span><h2 id="components-confirm-title">{title}</h2><p id="components-confirm-description">{message}</p><div className="dialog-actions"><button className="secondary-button" onClick={onCancel} disabled={busy}>Cancel</button><button className="danger-button" onClick={onConfirm} disabled={busy}>{busy ? 'Working…' : confirmLabel}</button></div></BlockingDialog>
}

export function ComponentsPage() {
  const { user } = useAuth()
  const { account, membership, profile } = useTenant()
  const canManage = ['owner', 'admin'].includes(membership?.role)
  const canReplace = ['owner', 'admin', 'technician', 'operator'].includes(membership?.role)
  const [data, setData] = useState({ manufacturers: [], models: [], components: [], profiles: [], intelligence: [], intelligenceSamples: [] })
  const [operational, setOperational] = useState({ machines: [], lifecycles: [], replacementHistory: [], members: [], inventoryItems: [], inventoryLocations: [], inventoryBalances: [] })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)
  const [confirm, setConfirm] = useState(null)
  const [busy, setBusy] = useState(false)
  const viewKey = createUIStateKey({ userId: user.id, accountId: account.id, feature: 'components-view', entityId: 'active' })
  const { value: view, setUIState: setView } = usePersistentUIState({ uiStateKey: viewKey, initialValue: { tab: 'machine', modelId: null, machineId: null, showArchived: false }, validate: validView })
  const densityKey = createUIStateKey({ userId: user.id, accountId: account.id, feature: 'components-density', entityId: 'workspace' })
  const { value: densityState, setUIState: setDensityState } = usePersistentUIState({ uiStateKey: densityKey, initialValue: { mode: 'detailed' }, validate: validDensity })
  const density = densityState.mode
  const { workflow, open, close } = useComponentWorkflowState({ userId: user.id, accountId: account.id })

  const refresh = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const [foundation, lifecycleData] = await Promise.all([loadComponentFoundation({ accountId: account.id }), loadMachineComponentLifecycles({ accountId: account.id })])
      setData(foundation)
      setOperational(lifecycleData)
    } catch (loadError) {
      setError(loadError)
    } finally {
      setLoading(false)
    }
  }, [account.id])

  useEffect(() => {
    let active = true
    Promise.all([loadComponentFoundation({ accountId: account.id }), loadMachineComponentLifecycles({ accountId: account.id })])
      .then(([foundation, lifecycleData]) => { if (active) { setData(foundation); setOperational(lifecycleData) } })
      .catch((loadError) => { if (active) setError(loadError) })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [account.id])

  const selectedModel = data.models.find((model) => model.id === view.modelId) ?? data.models[0] ?? null
  useEffect(() => {
    if (!view.modelId && data.models[0]) setView((current) => ({ ...current, modelId: data.models[0].id }))
  }, [data.models, setView, view.modelId])
  const selectedMachine = operational.machines.find((machine) => machine.id === view.machineId) ?? operational.machines[0] ?? null
  useEffect(() => {
    if (!view.machineId && operational.machines[0]) setView((current) => ({ ...current, machineId: operational.machines[0].id }))
  }, [operational.machines, setView, view.machineId])

  const effective = useMemo(() => effectiveProfiles(data.profiles, account.id, selectedModel?.id), [account.id, data.profiles, selectedModel?.id])
  const allEffectiveProfiles = useMemo(() => data.models.flatMap((model) => effectiveProfiles(data.profiles, account.id, model.id)), [account.id, data.models, data.profiles])
  const assignments = useMemo(() => assignmentMap({ profiles: data.profiles, models: data.models, accountId: account.id }), [account.id, data.models, data.profiles])
  const visibleProfiles = effective.filter((profile) => view.showArchived ? !profile.is_active : profile.is_active)
  const visibleComponents = data.components.filter((component) => view.showArchived ? !component.is_active : component.is_active)
  const editingComponent = data.components.find((component) => component.id === workflow.entityId)
  const editingProfile = allEffectiveProfiles.find((profile) => profile.id === workflow.entityId)
  const intelligenceProfile = allEffectiveProfiles.find((item) => item.id === workflow.entityId)
  const activeIntelligence = data.intelligence.find((item) => item.effective_profile_id === intelligenceProfile?.id)
  const assigningComponent = data.components.find((component) => component.id === workflow.entityId)
  const profileModel = data.models.find((model) => model.id === editingProfile?.machine_model_id) ?? selectedModel
  const initializingLifecycle = operational.lifecycles.find((row) => row.lifecycle_id === workflow.entityId)
  const lifecycleMachine = operational.machines.find((machine) => machine.id === initializingLifecycle?.machine_id)

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

  async function initializeLifecycle(values) {
    await initializeComponentLifecycle({ accountId: account.id, machineId: lifecycleMachine.id, profileId: initializingLifecycle.model_component_profile_id, ...values })
    await refresh()
    setNotice(`${initializingLifecycle.component_name} lifecycle initialized. Usage now follows Daily Counter.`)
  }

  async function replaceLifecycle(values) {
    await replaceComponentLifecycle({ accountId: account.id, machineId: lifecycleMachine.id, lifecycleId: initializingLifecycle.lifecycle_id, ...values })
    await refresh()
    setNotice(`${initializingLifecycle.component_name} replacement recorded. The previous lifecycle is closed and new tracking starts at the replacement counter.`)
  }

  async function adoptRecommendation({ intelligence, reason, clientRequestId }) {
    await adoptIntelligenceRecommendation({
      accountId: account.id,
      profileId: intelligence.effective_profile_id,
      baseline: intelligence.current_baseline,
      sampleFingerprint: intelligence.sample_fingerprint,
      algorithmVersion: intelligence.algorithm_version,
      clientRequestId,
      reason,
    })
    await refresh()
    close()
    setNotice(`Adaptive recommendation adopted for ${intelligence.component_name}. Existing lifecycle snapshots were not changed.`)
  }

  const pageAction = canManage && view.tab !== 'machine' ? <div className="page-header-actions"><button className="secondary-button" onClick={() => open('component-create')}><Plus size={17} />Add Component</button>{selectedModel && <button className="primary-button" onClick={() => open('profile-create')}><Plus size={17} />Add Profile</button>}</div> : null

  return <div className="page-stack">
    <PageHeader eyebrow={`${account.name} · Component intelligence`} title="Components" description="Machine lifecycles turn Daily Counter readings into current usage, remaining clicks, replacement targets, and health." action={pageAction} />
    {notice && <div className="success-banner"><span>{notice}</span><button onClick={() => setNotice(null)}>Dismiss</button></div>}
    {!canManage && <div className="permission-banner"><ShieldCheck size={18} /><span>Your {membership?.role} role can record operational component replacements. Catalog and model-profile management remain owner/admin only.</span></div>}
    {error && <div className="inline-error catalog-error"><span>{error.message}</span><button className="secondary-button" onClick={refresh}>Try again</button></div>}

    <section className={`component-shell component-density-${density} glass-surface`}>
      <div className="component-toolbar"><div className="machine-view-tabs"><button className={view.tab === 'machine' ? 'selected' : ''} onClick={() => setView((current) => ({ ...current, tab: 'machine' }))}>Machine Components</button><button className={view.tab === 'profiles' ? 'selected' : ''} onClick={() => setView((current) => ({ ...current, tab: 'profiles' }))}>Model Profiles</button><button className={view.tab === 'catalog' ? 'selected' : ''} onClick={() => setView((current) => ({ ...current, tab: 'catalog' }))}>Component Catalog</button></div><div className="component-toolbar-actions"><DensityControl density={density} onChange={(mode) => setDensityState({ mode })} /><button className="icon-button" type="button" onClick={refresh} aria-label="Refresh Components" title="Refresh Components"><RefreshCcw size={17} className={loading ? 'spin' : ''} /></button></div></div>

      {view.tab === 'machine' && <MachineComponentsPanel machines={operational.machines} lifecycles={operational.lifecycles} replacementHistory={operational.replacementHistory} selectedMachine={selectedMachine} onMachineChange={(machineId) => setView((current) => ({ ...current, machineId }))} canManage={canManage} canReplace={canReplace} onInitialize={(lifecycleId) => open('lifecycle-initialize', lifecycleId)} onReplace={(lifecycleId) => open('lifecycle-replace', lifecycleId)} density={density} />}

      {view.tab === 'profiles' && <>
        <div className="model-selector-row"><label><span>Machine model</span><select value={selectedModel?.id ?? ''} onChange={(event) => setView((current) => ({ ...current, modelId: event.target.value }))}>{data.models.map((model) => <option key={model.id} value={model.id}>{model.manufacturers?.name} · {model.name}</option>)}</select></label><div><strong>{effective.filter((profile) => profile.is_active).length}</strong><span>active profiles</span></div></div>
        <div className="profile-list-tools"><div className="machine-view-tabs record-tabs"><button className={!view.showArchived ? 'selected' : ''} onClick={() => setView((current) => ({ ...current, showArchived: false }))}>Active <span>{effective.filter((profile) => profile.is_active).length}</span></button><button className={view.showArchived ? 'selected' : ''} onClick={() => setView((current) => ({ ...current, showArchived: true }))}>Archived <span>{effective.filter((profile) => !profile.is_active).length}</span></button></div></div>
        {density === 'detailed' && <div className="component-table-head"><span>Component / slot</span><span>Tracking</span><span>Baseline</span><span>Adaptive</span><span>Actions</span></div>}
        <div className={`component-list ${density === 'compact' ? 'compact-profile-grid' : ''}`}>{visibleProfiles.map((profile) => {
          const intelligence = data.intelligence.find((item) => item.effective_profile_id === profile.id)
          return <article className={density === 'compact' ? 'compact-profile-item' : 'component-row'} key={profile.id}>{density === 'compact' ? <><div className="compact-item-heading"><strong><ComponentChannelMarker slotCode={profile.slot_code} code={profile.components?.code} name={profile.components?.name} />{profile.components?.name}</strong>{canManage && <div className="compact-context-actions"><button type="button" onClick={() => open('profile-edit', profile.id)} aria-label={`Edit ${profile.components?.name}`} title={`Edit ${profile.components?.name}`}><Edit3 size={14} /></button><button type="button" onClick={() => setConfirm({ kind: 'profile', item: profile })} aria-label={`Remove ${profile.components?.name}`} title={`Remove ${profile.components?.name}`}>{profile.is_active ? <Archive size={14} /> : <Trash2 size={14} />}</button></div>}</div><strong className="compact-baseline">{profile.baseline_expected_clicks?.toLocaleString() ?? 'Reference only'}<small> expected clicks</small></strong><div className="compact-profile-meta"><span className="tracking-pill">{trackingLabels[profile.tracking_method]}</span>{intelligence && <button className={profile.adaptive_enabled ? 'adaptive-intelligence-badge adaptive-on' : 'adaptive-intelligence-badge adaptive-off'} type="button" onClick={() => open('intelligence-view', profile.id)} aria-label={`View intelligence for ${profile.components?.name}`}><BarChart3 size={12} />Adaptive · {intelligence.usable_samples}</button>}</div></> : <><div className="component-identity"><span className="component-icon"><Boxes size={18} /></span><div><strong><ComponentChannelMarker slotCode={profile.slot_code} code={profile.components?.code} name={profile.components?.name} />{profile.components?.name}</strong><code>{profile.slot_code}</code></div></div><span className="tracking-pill">{trackingLabels[profile.tracking_method]}</span><div className="component-baseline"><strong>{profile.baseline_expected_clicks?.toLocaleString() ?? 'Reference only'}</strong><span>expected clicks</span></div><div className="adaptive-intelligence-cell"><span className={profile.adaptive_enabled ? 'adaptive-on' : 'adaptive-off'}>{profile.adaptive_enabled ? `${intelligence?.confidence_label === 'no_data' ? 'No Data' : `${intelligence?.confidence_label ?? '—'} · ${intelligence?.usable_samples ?? 0}`}` : 'Disabled'}</span>{intelligence && <button type="button" onClick={() => open('intelligence-view', profile.id)}><BarChart3 size={13} />View Intelligence</button>}</div><div className="row-actions">{canManage && <><button onClick={() => open('profile-edit', profile.id)} aria-label={`Edit ${profile.components?.name}`}><Edit3 size={16} /></button><button onClick={() => setConfirm({ kind: 'profile', item: profile })} aria-label={`Remove ${profile.components?.name}`}>{profile.is_active ? <Archive size={16} /> : <Trash2 size={16} />}</button></>}</div></>}</article>
        })}</div>
      </>}

      {view.tab === 'catalog' && <>
        <div className="catalog-summary"><div><span className="card-kicker">Reusable definitions</span><h2>{visibleComponents.length} {view.showArchived ? 'archived' : 'active'} components</h2></div><div className="machine-view-tabs"><button className={!view.showArchived ? 'selected' : ''} onClick={() => setView((current) => ({ ...current, showArchived: false }))}>Active</button><button className={view.showArchived ? 'selected' : ''} onClick={() => setView((current) => ({ ...current, showArchived: true }))}>Archived</button></div></div>
        <div className={`catalog-grid ${density === 'compact' ? 'compact-catalog-grid' : ''}`}>{visibleComponents.map((component) => {
          const manufacturer = data.manufacturers.find((item) => item.id === component.manufacturer_id)
          const assignedModels = assignments.get(component.id) ?? []
          return <article className={`catalog-card ${density === 'compact' ? 'catalog-card-compact' : ''}`} key={component.id}>{density === 'compact' ? <><div className="compact-item-heading"><h3><ComponentChannelMarker code={component.code} name={component.name} />{component.name}</h3><span className={component.account_id ? 'scope-pill custom' : 'scope-pill'}>{component.account_id ? 'Workspace' : 'Shared'}</span></div><div className={assignedModels.length ? 'compact-assignment-status assigned' : 'compact-assignment-status'}><Link2 size={13} /><span>{assignedModels.length ? `${assignedModels.length} model${assignedModels.length === 1 ? '' : 's'} assigned` : 'Not assigned'}</span></div>{canManage && component.is_active && <div className="compact-catalog-actions"><button className="primary-button" type="button" onClick={() => open('profile-assign', component.id)}><Link2 size={14} />Assign to Model</button>{component.account_id === account.id && <div className="compact-context-actions"><button type="button" onClick={() => open('component-edit', component.id)} aria-label={`Edit ${component.name}`} title={`Edit ${component.name}`}><Edit3 size={14} /></button><button type="button" onClick={() => setConfirm({ kind: 'component', item: component })} aria-label={`Delete ${component.name}`} title={`Delete ${component.name}`}><Trash2 size={14} /></button></div>}</div>}</> : <><div><span className="component-icon"><Boxes size={18} /></span><span className={component.account_id ? 'scope-pill custom' : 'scope-pill'}>{component.account_id ? 'Workspace' : 'Shared'}</span></div><h3><ComponentChannelMarker code={component.code} name={component.name} />{component.name}</h3><code>{component.code}</code><p>{manufacturer?.name ?? 'Any manufacturer'} · {trackingLabels[component.default_tracking_method]}</p><span className="catalog-category">{component.category ?? 'Uncategorized'}</span><div className={assignedModels.length ? 'assignment-context assigned' : 'assignment-context'}><Link2 size={14} /><span>{assignedModels.length ? `Assigned: ${assignedModels.map((model) => `${model.manufacturers?.name} · ${model.name}`).join(', ')}` : 'Not assigned to any model'}</span></div>{canManage && component.is_active && <div className="catalog-actions"><button className="primary-button assign-model-button" onClick={() => open('profile-assign', component.id)}><Link2 size={15} />Assign to Model</button>{component.account_id === account.id && <><button className="secondary-button" onClick={() => open('component-edit', component.id)}><Edit3 size={15} />Edit</button><button className="secondary-button icon-only-action" onClick={() => setConfirm({ kind: 'component', item: component })} aria-label={`Delete ${component.name}`}><Trash2 size={15} /></button></>}</div>}</>}</article>
        })}</div>
      </>}

      {!loading && ((view.tab === 'profiles' && !visibleProfiles.length) || (view.tab === 'catalog' && !visibleComponents.length)) && <div className="component-empty">No records in this view.</div>}
      {loading && <div className="machine-loading-state"><RefreshCcw className="spin" size={24} /><strong>Loading component intelligence…</strong></div>}
    </section>

    {canManage && (workflow.type === 'component-create' || (workflow.type === 'component-edit' && editingComponent)) && <ComponentDialog account={account} component={editingComponent} manufacturers={data.manufacturers} onClose={close} onSave={savedComponent} />}
    {canManage && selectedModel && (workflow.type === 'profile-create' || (workflow.type === 'profile-edit' && editingProfile) || (workflow.type === 'profile-assign' && assigningComponent)) && <ProfileDialog account={account} model={profileModel} models={data.models} profile={editingProfile} components={data.components} initialComponent={workflow.type === 'profile-assign' ? assigningComponent : null} draftEntityId={workflow.type === 'profile-assign' ? `assign-${assigningComponent.id}` : undefined} onClose={close} onSave={savedProfile} />}
    {canManage && workflow.type === 'lifecycle-initialize' && initializingLifecycle && lifecycleMachine && <InitializeLifecycleDialog account={account} machine={lifecycleMachine} lifecycle={initializingLifecycle} onClose={close} onInitialize={initializeLifecycle} />}
    {canReplace && workflow.type === 'lifecycle-replace' && initializingLifecycle?.lifecycle_status === 'active' && lifecycleMachine && <ReplaceComponentDialog account={account} machine={lifecycleMachine} lifecycle={initializingLifecycle} members={operational.members} currentProfile={profile} inventoryItems={operational.inventoryItems} inventoryLocations={operational.inventoryLocations} inventoryBalances={operational.inventoryBalances} onClose={close} onReplace={replaceLifecycle} />}
    {workflow.type === 'intelligence-view' && activeIntelligence && <ComponentIntelligenceDialog intelligence={activeIntelligence} samples={data.intelligenceSamples} canManage={canManage} onClose={close} onAdopt={adoptRecommendation} />}
    {confirm && <ConfirmDialog title={confirm.kind === 'component' ? 'Delete Component' : 'Remove Model Profile'} message={confirm.kind === 'component' ? 'If this component has never been referenced, it will be permanently deleted. Historical usage forces a safe archive instead.' : 'Unused profiles are deleted. Any historically referenced profile is archived so operational records remain intact.'} confirmLabel={confirm.kind === 'component' ? 'Delete / archive' : 'Remove / archive'} onCancel={() => setConfirm(null)} onConfirm={runConfirm} busy={busy} />}
  </div>
}
