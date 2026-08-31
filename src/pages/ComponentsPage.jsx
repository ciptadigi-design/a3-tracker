import { useCallback, useEffect, useMemo, useState } from 'react'
import { Activity, Archive, BarChart3, Boxes, Edit3, Eye, EyeOff, Gauge, Link2, Plus, RefreshCcw, RotateCcw, ShieldCheck, Trash2 } from 'lucide-react'
import { BlockingDialog } from '../components/ui/BlockingDialog.jsx'
import { PageHeader } from '../components/ui/PageHeader.jsx'
import { useAuth } from '../features/auth/useAuth.js'
import { useTenant } from '../features/account/useTenant.js'
import { ComponentDialog } from '../features/components/ComponentDialog.jsx'
import { ProfileDialog } from '../features/components/ProfileDialog.jsx'
import { MachineComponentDialog } from '../features/components/MachineComponentDialog.jsx'
import { InitializeLifecycleDialog } from '../features/components/InitializeLifecycleDialog.jsx'
import { ReplaceComponentDialog } from '../features/components/ReplaceComponentDialog.jsx'
import { ReplacementHistory } from '../features/components/ReplacementHistory.jsx'
import { ComponentIntelligenceDialog } from '../features/components/ComponentIntelligenceDialog.jsx'
import { ComponentChannelMarker } from '../features/components/ComponentChannelMarker.jsx'
import { lifecycleActionFor } from '../features/components/lifecycleActions.js'
import { activeAssignmentSummary, machineComponentCapabilities, profileRestoreConflict } from '../features/components/componentAssignmentContracts.js'
import { useComponentWorkflowState } from '../features/components/useComponentWorkflowState.js'
import { createUIStateKey } from '../features/uiState/uiStateKeys.js'
import { usePersistentUIState } from '../features/uiState/usePersistentUIState.js'
import { addMachineComponent, adoptIntelligenceRecommendation, clearMachineComponentExclusion, effectiveProfiles, loadComponentFoundation, removeMachineComponent, reconcileManualComponent, saveComponent, saveProfile, setComponentStatus, setProfileStatus, syncMachineComponents } from '../services/supabase/components.js'
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

function MachineComponentsPanel({ branchName, machines, lifecycles, exclusions, profiles, components, replacementHistory, selectedMachine, onMachineChange, canManage, canInitialize, canReplace, onRemove, onClearExclusion, onInitialize, onReplace, onReconcile, density, busy }) {
  const rows = lifecycles.filter((row) => row.machine_id === selectedMachine?.id)
  const initialized = rows.filter((row) => row.lifecycle_status === 'active').length
  const currentCounter = rows.find((row) => row.latest_effective_counter != null)?.latest_effective_counter

  return <>
    <div className="model-selector-row components-context-row machine-components-context lifecycle-machine-selector"><label><span>Physical machine</span><select value={selectedMachine?.id ?? ''} onChange={(event) => onMachineChange(event.target.value)} disabled={!machines.length}><option value="">{machines.length ? 'Choose machine' : `No active machines in ${branchName ?? 'this branch'}`}</option>{machines.map((machine) => <option key={machine.id} value={machine.id}>{machine.machine_code} · {machine.display_name}</option>)}</select></label><div className="components-context-count"><strong>{rows.length}</strong><span>component slots · {initialized} initialized</span></div></div>
    {exclusions.filter((item) => item.machine_id === selectedMachine?.id).map((exclusion) => {
      const profile = profiles.find((item) => item.id === exclusion.model_component_profile_id)
      const component = components.find((item) => item.id === profile?.component_id)
      return <div className="machine-component-exclusion" key={exclusion.id}><span><strong>{component?.name ?? 'Model component'} · <code>{profile?.slot_code ?? 'Archived slot'}</code></strong><br />Excluded from this machine. Model Profile is not changed.</span>{canManage && profile?.is_active && <button className="secondary-button" type="button" onClick={() => onClearExclusion(exclusion)} disabled={busy}><RotateCcw size={15} />Restore to Machine</button>}</div>
    })}
    {selectedMachine && density === 'detailed' && <div className="lifecycle-counter-banner"><span><Gauge size={17} />Latest effective Total Impressions</span><strong>{number(currentCounter)}</strong><small>Daily Counter source of truth</small></div>}
    <div className="lifecycle-grid">{rows.map((row) => {
      const unknown = row.lifecycle_status === 'unknown'
      const consumption = row.tracking_method === 'consumption_based'
      const visualPercent = Math.max(0, Math.min(100, Number(row.remaining_percent ?? 0)))
      const lifecycleAction = lifecycleActionFor({ lifecycleStatus: row.lifecycle_status, canInitialize, canReplace })
      const capabilities = { ...machineComponentCapabilities({ assignment: row, canManage }), canInitialize: Boolean(canInitialize && unknown && machineComponentCapabilities({ assignment: row, canManage: true }).canInitialize) }
      return <article className={`lifecycle-card ${density === 'compact' ? 'lifecycle-card-compact' : ''} ${unknown ? 'lifecycle-unknown' : `health-${row.health_status}`}`} key={row.assignment_id}>
        {density === 'compact' ? <>
        <header><h3><ComponentChannelMarker slotCode={row.slot_code} name={row.component_name} />{row.component_name}<code>{row.slot_code}</code></h3><span className="source-pill">{row.source_type === 'machine_specific' ? 'Machine-specific' : 'Inherited'}</span><span className={`health-badge ${unknown ? 'health-unknown' : `health-${row.health_status}`}`}>{unknown ? 'Unknown' : row.health_status}</span></header>
          {unknown ? <div className="compact-unknown-state"><span>Lifecycle not initialized</span>{row.reconciliation_candidate?.eligible && <button type="button" onClick={() => onReconcile(row)} disabled={busy}>Reconcile with Model Profile</button>}{capabilities.canInitialize && lifecycleAction === 'initialize' && <button type="button" onClick={() => onInitialize(row.assignment_id)} aria-label={`Initialize ${row.component_name} lifecycle`} title="Start tracking the component already installed">Initialize</button>}{capabilities.canRemove && <button type="button" onClick={() => onRemove(row)} aria-label={`Remove ${row.component_name} from machine`}>Remove from Machine</button>}</div> : <div className="compact-life-row"><div className="lifecycle-progress" role="progressbar" aria-label={`${row.component_name} remaining life: ${Number(row.remaining_percent).toFixed(1)} percent, ${row.health_status}`} aria-valuemin="0" aria-valuemax="100" aria-valuenow={visualPercent}><span style={{ width: `${visualPercent}%` }} /></div><strong>{Number(row.remaining_percent).toFixed(1)}%</strong>{lifecycleAction === 'replace' && <button className="compact-replace-button" type="button" onClick={() => onReplace(row.lifecycle_id)} aria-label={`Replace ${row.component_name}`}><RefreshCcw size={13} />Replace</button>}</div>}
        </> : <>
        <header><div><span className="component-icon"><Activity size={18} /></span><div><h3><ComponentChannelMarker slotCode={row.slot_code} name={row.component_name} />{row.component_name}<code>{row.slot_code}</code></h3><span>{row.source_type === 'machine_specific' ? 'Machine-specific assignment' : consumption ? 'Yield-based tracking' : 'Counter-based lifecycle'}</span></div></div>{!unknown && <span className={`health-badge health-${row.health_status}`}>{row.health_status}</span>}</header>
        {unknown ? <div className="unknown-lifecycle-body"><div><span>Installation history</span><strong>Unknown</strong></div><div><span>Tracking status</span><strong>Not initialized</strong></div><div><span>Expected baseline</span><strong>{number(row.current_profile_baseline)} clicks</strong></div><p>Start tracking a component that is already installed. Inventory will not change.</p><div className="row-actions">{row.reconciliation_candidate?.eligible && <button className="secondary-button" onClick={() => onReconcile(row)}>Reconcile with Model Profile</button>}{capabilities.canInitialize && lifecycleAction === 'initialize' && <button className="primary-button initialize-lifecycle-button" onClick={() => onInitialize(row.assignment_id)} aria-label={`Initialize ${row.component_name} lifecycle`}><span className="initialize-label-full">Initialize Lifecycle</span><span className="initialize-label-compact" aria-hidden="true">Initialize</span></button>}{capabilities.canRemove && <button className="secondary-button" onClick={() => onRemove(row)} aria-label={`Remove ${row.component_name} from machine`}>Remove from Machine</button>}</div></div> : <>
          <div className="lifecycle-metrics"><div><span>{consumption ? 'Used Yield' : 'Current Usage'}</span><strong>{number(row.current_usage)}</strong></div><div><span>{consumption ? 'Expected Yield' : 'Expected Life'}</span><strong>{number(row.effective_expected)}</strong></div><div><span>{consumption ? 'Estimated Remaining' : 'Remaining Clicks'}</span><strong>{number(row.remaining_clicks)}</strong></div><div><span>Remaining</span><strong>{Number(row.remaining_percent).toFixed(1)}%</strong></div><div className="replace-counter"><span>Estimated Replacement Counter</span><strong>{number(row.estimated_replacement_counter)}</strong></div></div>
          <div className="lifecycle-progress" aria-label={`${Math.max(0, Number(row.remaining_percent)).toFixed(1)} percent remaining`}><span style={{ width: `${visualPercent}%` }} /></div>
          <footer><div className="lifecycle-snapshot-context"><span>{row.expected_source}</span>{Number(row.current_profile_baseline) !== Number(row.expected_at_install) && <span>Current profile: {number(row.current_profile_baseline)} · install snapshot: {number(row.expected_at_install)}</span>}</div>{lifecycleAction === 'replace' && <button className="secondary-button lifecycle-replace-button" type="button" onClick={() => onReplace(row.lifecycle_id)} aria-label={`Replace ${row.component_name}`}><RefreshCcw size={14} />{consumption ? 'Replace / Refill Toner' : 'Replace Component'}</button>}</footer>
        </>}</>}
      </article>
    })}</div>
    {!selectedMachine ? <div className="component-empty"><strong>No active machines in {branchName ?? 'this branch'}.</strong><span>Machine Components follows the global Branch. Model Profiles and Component Catalog remain account-wide.</span></div> : !rows.length && <div className="component-empty"><strong>No components configured for this machine model.</strong><span>No lifecycle or inventory movement was fabricated.</span></div>}
    {selectedMachine && density === 'detailed' && <ReplacementHistory history={replacementHistory} machine={selectedMachine} />}
  </>
}

function ConfirmDialog({ title, message, confirmLabel, onCancel, onConfirm, busy }) {
  return <BlockingDialog className="confirm-dialog glass-surface" role="alertdialog" labelledBy="components-confirm-title" describedBy="components-confirm-description" onClose={onCancel} busy={busy}><span className="danger-dialog-icon"><Trash2 size={22} /></span><h2 id="components-confirm-title">{title}</h2><p id="components-confirm-description">{message}</p><div className="dialog-actions"><button className="secondary-button" onClick={onCancel} disabled={busy}>Cancel</button><button className="danger-button" onClick={onConfirm} disabled={busy}>{busy ? 'Working…' : confirmLabel}</button></div></BlockingDialog>
}

function ReconcileDialog({ row, candidate, onCancel, onConfirm, busy, error }) {
  return <BlockingDialog className="confirm-dialog glass-surface" role="alertdialog" labelledBy="reconcile-dialog-title" describedBy="reconcile-dialog-description" onClose={onCancel} busy={busy}><div className="confirm-dialog-body"><span className="dialog-icon"><Link2 size={22} /></span><h2 id="reconcile-dialog-title">Reconcile with Model Profile</h2><p id="reconcile-dialog-description">Current assignment: <strong>{row.component_name}</strong> · <code>{row.slot_code}</code> · Machine-specific</p><p>Target Model Profile: <strong>{candidate.machine_model ?? 'Matching machine model'}</strong> · <code>{candidate.profile_slot_code ?? row.slot_code}</code> · Inherited</p><p className="preservation-notice">Machine Component identity and existing lifecycle/replacement history will be preserved. This does not create a new lifecycle and does not change historical evidence.</p>{error && <div className="form-error" role="alert">{error.message ?? 'Reconciliation could not be completed.'}</div>}</div><footer className="dialog-actions"><button className="secondary-button" type="button" onClick={onCancel} disabled={busy}>Cancel</button><button className="primary-button" type="button" onClick={onConfirm} disabled={busy}>{busy ? 'Reconciling…' : 'Reconcile'}</button></footer></BlockingDialog>
}

export function ComponentsPage() {
  const { user } = useAuth()
  const { account, branch, membership, operationalPermissions } = useTenant()
  const canManage = ['owner', 'admin'].includes(membership?.role)
  const canInitialize = canManage || (membership?.role === 'operator' && operationalPermissions?.operator_can_initialize_component)
  const canReplace = ['owner', 'admin', 'technician'].includes(membership?.role) || (membership?.role === 'operator' && operationalPermissions?.operator_can_replace_component)
  const [data, setData] = useState({ manufacturers: [], models: [], components: [], profiles: [], intelligence: [], intelligenceSamples: [] })
  const [operational, setOperational] = useState({ branchId: null, machines: [], lifecycles: [], exclusions: [], replacementHistory: [], operationalPeople: [], inventoryItems: [], inventoryLocations: [], inventoryBalances: [] })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)
  const [restoreConflict, setRestoreConflict] = useState(null)
  const [confirm, setConfirm] = useState(null)
  const [reconcile, setReconcile] = useState(null)
  const [reconcileError, setReconcileError] = useState(null)
  const [machineDraft, setMachineDraft] = useState(null)
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
      const [foundation, lifecycleData] = await Promise.all([loadComponentFoundation({ accountId: account.id }), loadMachineComponentLifecycles({ accountId: account.id, branchId: branch?.id })])
      setData(foundation)
      setOperational(lifecycleData)
    } catch (loadError) {
      setError(loadError)
    } finally {
      setLoading(false)
    }
  }, [account.id, branch?.id])

  useEffect(() => {
    let active = true
    Promise.all([loadComponentFoundation({ accountId: account.id }), loadMachineComponentLifecycles({ accountId: account.id, branchId: branch?.id })])
      .then(([foundation, lifecycleData]) => { if (active) { setData(foundation); setOperational(lifecycleData) } })
      .catch((loadError) => { if (active) setError(loadError) })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [account.id, branch?.id])

  const selectedModel = data.models.find((model) => model.id === view.modelId) ?? data.models[0] ?? null
  useEffect(() => {
    if (!view.modelId && data.models[0]) setView((current) => ({ ...current, modelId: data.models[0].id }))
  }, [data.models, setView, view.modelId])
  const scopedOperational = operational.branchId === branch?.id ? operational : { ...operational, machines: [], lifecycles: [], exclusions: [], replacementHistory: [], operationalPeople: [], inventoryLocations: [], inventoryBalances: [] }
  const selectedMachine = scopedOperational.machines.find((machine) => machine.id === view.machineId) ?? null
  useEffect(() => {
    const machineIdIsValid = scopedOperational.machines.some((machine) => machine.id === view.machineId)
    if (view.machineId && !machineIdIsValid) setView((current) => ({ ...current, machineId: null }))
    else if (!view.machineId && scopedOperational.machines[0]) setView((current) => ({ ...current, machineId: scopedOperational.machines[0].id }))
  }, [scopedOperational.machines, setView, view.machineId])

  const effective = useMemo(() => effectiveProfiles(data.profiles, account.id, selectedModel?.id), [account.id, data.profiles, selectedModel?.id])
  const allEffectiveProfiles = useMemo(() => data.models.flatMap((model) => effectiveProfiles(data.profiles, account.id, model.id)), [account.id, data.models, data.profiles])
  const assignments = useMemo(() => activeAssignmentSummary({ profiles: data.profiles, models: data.models, accountId: account.id, resolveProfiles: effectiveProfiles }), [account.id, data.models, data.profiles])
  const visibleProfiles = effective.filter((profile) => view.showArchived ? !profile.is_active : profile.is_active)
  const visibleComponents = data.components.filter((component) => view.showArchived ? !component.is_active : component.is_active)
  const editingComponent = data.components.find((component) => component.id === workflow.entityId)
  const editingProfile = allEffectiveProfiles.find((profile) => profile.id === workflow.entityId)
  const intelligenceProfile = allEffectiveProfiles.find((item) => item.id === workflow.entityId)
  const activeIntelligence = data.intelligence.find((item) => item.effective_profile_id === intelligenceProfile?.id)
  const assigningComponent = data.components.find((component) => component.id === workflow.entityId)
  const profileModel = data.models.find((model) => model.id === editingProfile?.machine_model_id) ?? selectedModel
  const initializingLifecycle = scopedOperational.lifecycles.find((row) => workflow.type === 'lifecycle-initialize' ? row.assignment_id === workflow.entityId : row.lifecycle_id === workflow.entityId)
  const lifecycleMachine = scopedOperational.machines.find((machine) => machine.id === initializingLifecycle?.machine_id)

  async function savedComponent(values) {
    const saved = await saveComponent({ accountId: account.id, component: editingComponent, values })
    await refresh()
    setNotice('Component definition saved. Machine-model assignments were not changed.')
    if (!editingComponent && machineDraft) {
      setMachineDraft((draft) => ({ ...draft, componentId: saved.id }))
      setTimeout(() => open('machine-component-add'), 0)
    }
    return saved
  }

  function closeComponentDialog() {
    const returningToMachine = Boolean(machineDraft && !editingComponent)
    close()
    if (returningToMachine) setTimeout(() => open('machine-component-add'), 0)
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
      if (confirm.kind === 'component') {
        await setComponentStatus({ accountId: account.id, componentId: confirm.item.id, action: 'archive', clientRequestId: crypto.randomUUID() })
        setNotice('Component Catalog entry archived. Model Profiles and historical references remain intact.')
      } else if (confirm.kind === 'profile') {
        await setProfileStatus({ accountId: account.id, profileId: confirm.item.id, action: 'archive', clientRequestId: crypto.randomUUID() })
        setNotice('Model Profile archived. UNKNOWN inherited slots were retired; lifecycle history remains intact.')
      } else {
        await removeMachineComponent({ accountId: account.id, assignmentId: confirm.item.assignment_id, reason: 'Removed from Machine Components', clientRequestId: crypto.randomUUID() })
        setNotice(confirm.item.source_type === 'model_profile' ? 'Component removed from this machine. Its durable exclusion will prevent profile sync from restoring it.' : 'Machine-specific component removed. Model Profile was not changed.')
      }
      setConfirm(null)
      await refresh()
    } catch (mutationError) {
      setError(mutationError)
    } finally {
      setBusy(false)
    }
  }

  async function restoreComponent(component) {
    setBusy(true)
    try {
      await setComponentStatus({ accountId: account.id, componentId: component.id, action: 'restore', clientRequestId: crypto.randomUUID() })
      await refresh()
      setNotice('Component Catalog entry restored. Archived Model Profiles were not restored.')
    } catch (mutationError) { setError(mutationError) } finally { setBusy(false) }
  }

  async function restoreProfile(profile) {
    setBusy(true)
    setError(null)
    setRestoreConflict(null)
    try {
      await setProfileStatus({ accountId: account.id, profileId: profile.id, action: 'restore', clientRequestId: crypto.randomUUID() })
      await refresh()
      setNotice('Model Profile restored. Eligible machines were synchronized; explicit machine exclusions still win.')
    } catch (mutationError) {
      const conflict = profileRestoreConflict({ error: mutationError, profile, model: selectedModel })
      if (conflict) setRestoreConflict(conflict)
      else setError(mutationError)
    } finally { setBusy(false) }
  }

  async function savedMachineComponent(values) {
    await addMachineComponent({ accountId: account.id, machineId: selectedMachine.id, values })
    await refresh()
    setNotice('Machine-specific component added. Model Profile is not changed.')
  }

  async function syncSelectedMachine() {
    setBusy(true)
    try {
      await syncMachineComponents({ accountId: account.id, machineId: selectedMachine.id })
      await refresh()
      setNotice('Active Model Profile slots were applied to this machine. Manual components and explicit exclusions were preserved.')
    } catch (mutationError) { setError(mutationError) } finally { setBusy(false) }
  }

  async function runReconcile() {
    setBusy(true); setReconcileError(null)
    try {
      await reconcileManualComponent({ accountId: account.id, assignmentId: reconcile.assignment_id, profileId: reconcile.reconciliation_candidate.profile_slot_id, clientRequestId: crypto.randomUUID() })
      setReconcile(null); await refresh(); setNotice('Machine Component reconciled. Identity and lifecycle/replacement history were preserved.')
    } catch (mutationError) { setReconcileError(mutationError) } finally { setBusy(false) }
  }

  async function clearExclusion(exclusion) {
    setBusy(true)
    try {
      await clearMachineComponentExclusion({ accountId: account.id, machineId: selectedMachine.id, profileId: exclusion.model_component_profile_id, clientRequestId: crypto.randomUUID() })
      await refresh()
      setNotice('Machine exclusion cleared. The component returned as UNKNOWN without a fabricated lifecycle.')
    } catch (mutationError) { setError(mutationError) } finally { setBusy(false) }
  }

  async function initializeLifecycle(values) {
    await initializeComponentLifecycle({ accountId: account.id, machineId: lifecycleMachine.id, profileId: initializingLifecycle.model_component_profile_id, assignmentId: initializingLifecycle.assignment_id, ...values })
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

  const pageAction = canManage ? <div className="page-header-actions components-page-actions">{view.tab === 'machine' && selectedMachine && <><button className="secondary-button" onClick={syncSelectedMachine}><RefreshCcw size={17} />Sync Model Profile</button><button className="primary-button" onClick={() => open('machine-component-add')}><Plus size={17} />Add Machine-Specific Component</button></>}{view.tab === 'profiles' && selectedModel && <button className="primary-button" onClick={() => open('profile-create')}><Plus size={17} />Add Profile</button>}{view.tab === 'catalog' && <button className="primary-button" onClick={() => open('component-create')}><Plus size={17} />New Component</button>}</div> : null

  return <div className="page-stack components-page">
    <PageHeader eyebrow={`${account.name} · Component intelligence`} title="Components" description="Machine lifecycles turn Daily Counter readings into current usage, remaining clicks, replacement targets, and health." action={pageAction} />
    {notice && <div className="success-banner"><span>{notice}</span><button onClick={() => setNotice(null)}>Dismiss</button></div>}
    {!canManage && <div className="permission-banner"><ShieldCheck size={18} /><span>Your {membership?.role} role can record operational component replacements. Catalog and model-profile management remain owner/admin only.</span></div>}
    {error && <div className="inline-error catalog-error" role="alert"><span>Components could not be loaded.</span><button className="secondary-button" onClick={refresh}>Try again</button></div>}

    <section className={`component-shell component-density-${density} glass-surface`}>
      <div className="component-toolbar components-shell-header"><div className="machine-view-tabs"><button className={view.tab === 'machine' ? 'selected' : ''} onClick={() => setView((current) => ({ ...current, tab: 'machine' }))}>Machine Components</button><button className={view.tab === 'profiles' ? 'selected' : ''} onClick={() => setView((current) => ({ ...current, tab: 'profiles' }))}>Model Profiles</button><button className={view.tab === 'catalog' ? 'selected' : ''} onClick={() => setView((current) => ({ ...current, tab: 'catalog' }))}>Component Catalog</button></div><div className="component-toolbar-actions"><DensityControl density={density} onChange={(mode) => setDensityState({ mode })} /><button className="icon-button" type="button" onClick={refresh} aria-label="Refresh Components" title="Refresh Components"><RefreshCcw size={17} className={loading ? 'spin' : ''} /></button></div></div>

      {view.tab === 'machine' && <MachineComponentsPanel branchName={branch?.name} machines={scopedOperational.machines} lifecycles={scopedOperational.lifecycles} exclusions={scopedOperational.exclusions} profiles={data.profiles} components={data.components} replacementHistory={scopedOperational.replacementHistory} selectedMachine={selectedMachine} onMachineChange={(machineId) => setView((current) => ({ ...current, machineId }))} canManage={canManage} canInitialize={canInitialize} canReplace={canReplace} onRemove={(assignment) => setConfirm({ kind: 'machine-component', item: assignment })} onClearExclusion={clearExclusion} onInitialize={(assignmentId) => open('lifecycle-initialize', assignmentId)} onReplace={(lifecycleId) => open('lifecycle-replace', lifecycleId)} onReconcile={setReconcile} density={density} busy={busy} />}

      {view.tab === 'profiles' && <>
        <div className="model-selector-row components-context-row model-profiles-context"><label><span>Machine model</span><select value={selectedModel?.id ?? ''} onChange={(event) => setView((current) => ({ ...current, modelId: event.target.value }))}>{data.models.map((model) => <option key={model.id} value={model.id}>{model.manufacturers?.name} · {model.name}</option>)}</select></label><div className="components-context-count"><strong>{effective.filter((profile) => profile.is_active).length}</strong><span>active profiles</span></div><div className="profile-list-tools"><div className="machine-view-tabs record-tabs"><button className={!view.showArchived ? 'selected' : ''} onClick={() => setView((current) => ({ ...current, showArchived: false }))}>Active <span>{effective.filter((profile) => profile.is_active).length}</span></button><button className={view.showArchived ? 'selected' : ''} onClick={() => setView((current) => ({ ...current, showArchived: true }))}>Archived <span>{effective.filter((profile) => !profile.is_active).length}</span></button></div></div></div>
        {restoreConflict && <div className="profile-restore-conflict" role="alert" aria-live="assertive"><span><strong>Profile could not be restored.</strong>{restoreConflict.message}</span><button className="secondary-button compact-action" type="button" onClick={() => setRestoreConflict(null)}>Close</button></div>}
        {density === 'detailed' && <div className="component-table-head"><span>Component / slot</span><span>Tracking</span><span>Baseline</span><span>Adaptive</span><span>Actions</span></div>}
        <div className={`component-list ${density === 'compact' ? 'compact-profile-grid' : ''}`}>{visibleProfiles.map((profile) => {
          const intelligence = data.intelligence.find((item) => item.effective_profile_id === profile.id)
          const activeActions = canManage && profile.is_active && <><button onClick={() => open('profile-edit', profile.id)} aria-label={`Edit ${profile.components?.name}`} disabled={busy}><Edit3 size={16} /></button><button onClick={() => setConfirm({ kind: 'profile', item: profile })} aria-label={`Archive ${profile.components?.name} ${profile.slot_code}`} disabled={busy}><Archive size={16} /></button></>
          const restoreAction = canManage && !profile.is_active && <button className="secondary-button compact-action" type="button" onClick={() => restoreProfile(profile)} aria-label={`Restore ${profile.components?.name} ${profile.slot_code}`} disabled={busy}><RotateCcw size={14} />Restore</button>
          return <article className={density === 'compact' ? 'compact-profile-item' : 'component-row'} key={profile.id}>
            {density === 'compact' ? <>
              <div className="compact-item-heading"><strong><ComponentChannelMarker slotCode={profile.slot_code} code={profile.components?.code} name={profile.components?.name} />{profile.components?.name}<code>{profile.slot_code}</code></strong>{activeActions && <div className="compact-context-actions">{activeActions}</div>}</div>
              <strong className="compact-baseline">{profile.baseline_expected_clicks?.toLocaleString() ?? 'Reference only'}<small> expected clicks</small></strong>
              <div className="compact-profile-meta"><span className="tracking-pill">{trackingLabels[profile.tracking_method]}</span>{restoreAction}{intelligence && profile.is_active && <button className={profile.adaptive_enabled ? 'adaptive-intelligence-badge adaptive-on' : 'adaptive-intelligence-badge adaptive-off'} type="button" onClick={() => open('intelligence-view', profile.id)} aria-label={`View intelligence for ${profile.components?.name}`}><BarChart3 size={12} />Adaptive · {intelligence.usable_samples}</button>}</div>
            </> : <>
              <div className="component-identity"><span className="component-icon"><Boxes size={18} /></span><div><strong><ComponentChannelMarker slotCode={profile.slot_code} code={profile.components?.code} name={profile.components?.name} />{profile.components?.name}</strong><code>{profile.slot_code}</code></div></div>
              <span className="tracking-pill">{trackingLabels[profile.tracking_method]}</span>
              <div className="component-baseline"><strong>{profile.baseline_expected_clicks?.toLocaleString() ?? 'Reference only'}</strong><span>expected clicks</span></div>
              <div className="adaptive-intelligence-cell"><span className={profile.adaptive_enabled ? 'adaptive-on' : 'adaptive-off'}>{profile.is_active ? (profile.adaptive_enabled ? `${intelligence?.confidence_label === 'no_data' ? 'No Data' : `${intelligence?.confidence_label ?? '—'} · ${intelligence?.usable_samples ?? 0}`}` : 'Disabled') : 'Archived'}</span>{intelligence && profile.is_active && <button type="button" onClick={() => open('intelligence-view', profile.id)}><BarChart3 size={13} />View Intelligence</button>}</div>
              <div className="row-actions">{activeActions}{restoreAction}</div>
            </>}
          </article>
        })}</div>
      </>}

      {view.tab === 'catalog' && <>
        <div className="catalog-summary components-context-row catalog-components-context"><div><span className="card-kicker">Reusable definitions</span><h2>{visibleComponents.length} {view.showArchived ? 'archived' : 'active'} components</h2></div><div className="machine-view-tabs record-tabs"><button className={!view.showArchived ? 'selected' : ''} onClick={() => setView((current) => ({ ...current, showArchived: false }))}>Active</button><button className={view.showArchived ? 'selected' : ''} onClick={() => setView((current) => ({ ...current, showArchived: true }))}>Archived</button></div></div>
        <div className={`catalog-grid ${density === 'compact' ? 'compact-catalog-grid' : ''}`}>{visibleComponents.map((component) => {
          const manufacturer = data.manufacturers.find((item) => item.id === component.manufacturer_id)
          const assignmentSummary = assignments.get(component.id) ?? { models: [], slotCount: 0 }
          const summaryLabel = assignmentSummary.slotCount ? `${assignmentSummary.models.length} model${assignmentSummary.models.length === 1 ? '' : 's'} · ${assignmentSummary.slotCount} slot${assignmentSummary.slotCount === 1 ? '' : 's'}` : 'Not assigned'
          const actions = canManage && (component.is_active ? <>
            <button className="primary-button assign-model-button" onClick={() => open('profile-assign', component.id)} disabled={busy}><Link2 size={15} />Assign to Model</button>
            {component.account_id === account.id && <><button className="secondary-button compact-action catalog-edit-action" onClick={() => open('component-edit', component.id)} disabled={busy}><Edit3 size={14} />Edit</button><button className="secondary-button compact-action compact-icon-action" onClick={() => setConfirm({ kind: 'component', item: component })} aria-label={`Archive component ${component.name}`} title={`Archive ${component.name}`} disabled={busy}><Archive size={15} /></button></>}
          </> : component.account_id === account.id && <button className="secondary-button compact-action" type="button" onClick={() => restoreComponent(component)} disabled={busy}><RotateCcw size={14} />Restore</button>)
          return <article className={`catalog-card ${density === 'compact' ? 'catalog-card-compact' : ''}`} key={component.id}>{density === 'compact' ? <>
            <div className="compact-item-heading"><h3><ComponentChannelMarker code={component.code} name={component.name} />{component.name}</h3><span className={component.account_id ? 'scope-pill custom' : 'scope-pill'}>{component.account_id ? 'Workspace' : 'Shared'}</span></div>
            <div className={assignmentSummary.slotCount ? 'compact-assignment-status assigned' : 'compact-assignment-status'}><Link2 size={13} /><span>{summaryLabel}</span></div>
            {actions && <div className="compact-catalog-actions">{actions}</div>}
          </> : <>
            <div><span className="component-icon"><Boxes size={18} /></span><span className={component.account_id ? 'scope-pill custom' : 'scope-pill'}>{component.account_id ? 'Workspace' : 'Shared'}</span></div>
            <h3><ComponentChannelMarker code={component.code} name={component.name} />{component.name}</h3><code>{component.code}</code><p>{manufacturer?.name ?? 'Any manufacturer'} · {trackingLabels[component.default_tracking_method]}</p><span className="catalog-category">{component.category ?? 'Uncategorized'}</span>
            <div className={assignmentSummary.slotCount ? 'assignment-context assigned' : 'assignment-context'}><Link2 size={14} /><span>{summaryLabel}</span></div>
            {actions && <div className="catalog-actions">{actions}</div>}
          </>}</article>
        })}</div>
      </>}

      {!loading && ((view.tab === 'profiles' && !visibleProfiles.length) || (view.tab === 'catalog' && !visibleComponents.length)) && <div className="component-empty">No records in this view.</div>}
      {loading && <div className="machine-loading-state"><RefreshCcw className="spin" size={24} /><strong>Loading component intelligence…</strong></div>}
    </section>

    {canManage && (workflow.type === 'component-create' || (workflow.type === 'component-edit' && editingComponent)) && <ComponentDialog account={account} component={editingComponent} manufacturers={data.manufacturers} onClose={closeComponentDialog} onSave={savedComponent} />}
    {canManage && selectedModel && (workflow.type === 'profile-create' || (workflow.type === 'profile-edit' && editingProfile) || (workflow.type === 'profile-assign' && assigningComponent)) && <ProfileDialog account={account} model={profileModel} models={data.models} profile={editingProfile} components={data.components} initialComponent={workflow.type === 'profile-assign' ? assigningComponent : null} draftEntityId={workflow.type === 'profile-assign' ? `assign-${assigningComponent.id}` : undefined} onClose={close} onSave={savedProfile} />}
    {canManage && workflow.type === 'machine-component-add' && selectedMachine && <MachineComponentDialog machine={selectedMachine} components={data.components} profiles={data.profiles} existingAssignments={scopedOperational.lifecycles.filter((row) => row.machine_id === selectedMachine.id)} exclusions={scopedOperational.exclusions.filter((row) => row.machine_id === selectedMachine.id)} initialValue={machineDraft} onCreateNewComponent={(draft) => { setMachineDraft(draft); close(); open('component-create') }} onClose={() => { setMachineDraft(null); close() }} onSave={async (values) => { await savedMachineComponent(values); setMachineDraft(null) }} />}
    {canInitialize && workflow.type === 'lifecycle-initialize' && initializingLifecycle && lifecycleMachine && <InitializeLifecycleDialog account={account} machine={lifecycleMachine} lifecycle={initializingLifecycle} onClose={close} onInitialize={initializeLifecycle} />}
    {canReplace && workflow.type === 'lifecycle-replace' && initializingLifecycle?.lifecycle_status === 'active' && lifecycleMachine && <ReplaceComponentDialog account={account} machine={lifecycleMachine} lifecycle={initializingLifecycle} operationalPeople={scopedOperational.operationalPeople} inventoryItems={scopedOperational.inventoryItems} inventoryLocations={scopedOperational.inventoryLocations} inventoryBalances={scopedOperational.inventoryBalances} onClose={close} onReplace={replaceLifecycle} />}
    {workflow.type === 'intelligence-view' && activeIntelligence && <ComponentIntelligenceDialog intelligence={activeIntelligence} samples={data.intelligenceSamples} canManage={canManage} onClose={close} onAdopt={adoptRecommendation} />}
    {confirm && <ConfirmDialog
      title={confirm.kind === 'component' ? 'Archive Component Catalog Entry' : confirm.kind === 'profile' ? 'Archive Model Profile' : 'Remove from Machine'}
      message={confirm.kind === 'component' ? 'Archive this reusable Catalog entry? Active Model Profiles must be archived first. Inventory and history will remain intact.' : confirm.kind === 'profile' ? `Archive ${confirm.item.components?.name} · ${confirm.item.slot_code}? UNKNOWN inherited assignments may be retired, while lifecycle and cost history remain intact.` : `Remove ${confirm.item.component_name} · ${confirm.item.slot_code} from this physical machine? ${confirm.item.source_type === 'model_profile' ? 'A durable exclusion will prevent Model Profile sync from bringing it back.' : 'The Model Profile is not changed.'}`}
      confirmLabel={confirm.kind === 'component' ? 'Archive Catalog' : confirm.kind === 'profile' ? 'Archive Profile' : 'Remove from Machine'}
      onCancel={() => setConfirm(null)} onConfirm={runConfirm} busy={busy}
    />}
    {reconcile && <ReconcileDialog row={reconcile} candidate={reconcile.reconciliation_candidate} onCancel={() => { if (!busy) { setReconcile(null); setReconcileError(null) } }} onConfirm={runReconcile} busy={busy} error={reconcileError} />}
  </div>
}
