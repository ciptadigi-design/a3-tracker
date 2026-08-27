import { useCallback, useEffect, useMemo, useState } from 'react'
import { Archive, ArrowDownToLine, ArrowRightLeft, ArrowUpFromLine, Boxes, Edit3, History, MapPin, Package, PackagePlus, Plus, RefreshCcw, Scale, ShieldCheck, ShoppingCart, Trash2 } from 'lucide-react'
import { PageHeader } from '../components/ui/PageHeader.jsx'
import { ComponentChannelMarker } from '../features/components/ComponentChannelMarker.jsx'
import { useAuth } from '../features/auth/useAuth.js'
import { useTenant } from '../features/account/useTenant.js'
import { createUIStateKey } from '../features/uiState/uiStateKeys.js'
import { usePersistentUIState } from '../features/uiState/usePersistentUIState.js'
import { DeleteInventoryMasterDialog, InventoryItemDialog, InventoryLocationDialog, InventoryMovementDialog } from '../features/inventory/InventoryDialogs.jsx'
import { CancelInventoryPurchaseDialog, InventoryPurchaseDetailDialog, InventoryPurchaseDialog, InventoryReceiveDialog, InventorySupplierDialog } from '../features/inventory/PurchasingDialogs.jsx'
import { PurchasingPanel } from '../features/inventory/PurchasingPanel.jsx'
import { useInventoryWorkflowState } from '../features/inventory/useInventoryWorkflowState.js'
import { adjustInventoryStock, cancelInventoryPurchase, createInventoryPurchase, deleteInventoryItem, deleteInventoryLocation, deleteInventorySupplier, initializeInventoryStock, loadInventory, receiveInventoryPurchase, saveInventoryItem, saveInventoryLocation, saveInventorySupplier, transferInventoryStock } from '../services/supabase/inventory.js'

const tabs = [
  { id: 'stock', label: 'Stock', icon: Package },
  { id: 'movements', label: 'Movements', icon: History },
  { id: 'purchasing', label: 'Purchasing', icon: ShoppingCart },
  { id: 'locations', label: 'Locations', icon: MapPin },
]

const movementLabels = {
  opening_balance: 'Opening Balance', receipt: 'Receipt', issue: 'Issue', adjustment_in: 'Adjustment In',
  adjustment_out: 'Adjustment Out', transfer_in: 'Transfer In', transfer_out: 'Transfer Out',
}

function validView(value) { return value && tabs.some((tab) => tab.id === value.tab) && typeof value.showArchived === 'boolean' }
function quantity(value, maximumFractionDigits = 4) { return Number(value ?? 0).toLocaleString('en-US', { maximumFractionDigits }) }
function statusFor(total, minimum) {
  if (Number(total) <= 0) return { key: 'out', label: 'Out of Stock' }
  if (minimum != null && Number(minimum) > 0 && Number(total) <= Number(minimum)) return { key: 'low', label: 'Low Stock' }
  return { key: 'healthy', label: 'Healthy' }
}

function StockPanel({ data, showArchived, canManage, onEdit, onDelete, onOpening, onAdjust, onTransfer }) {
  const rows = data.items.filter((item) => showArchived ? !item.is_active : item.is_active)
  const totalByItem = new Map(data.totals.map((row) => [row.inventory_item_id, row.quantity]))
  return <>
    <div className="inventory-section-toolbar"><div><span className="card-kicker">Physical stock</span><h2>{rows.length} {showArchived ? 'archived' : 'active'} items</h2><p>Totals and location balances are derived from posted ledger movements.</p></div></div>
    {rows.length === 0 ? <div className="inventory-empty"><Package size={25} /><strong>No {showArchived ? 'archived' : 'active'} inventory items.</strong><span>{showArchived ? 'Archived item history will remain available here.' : 'Create an item, then initialize its physical opening stock.'}</span></div> : <div className="inventory-stock-list">
      <div className="inventory-stock-head"><span>Item</span><span>Component</span><span>Total</span><span>Minimum</span><span>Status</span><span>Actions</span></div>
      {rows.map((item) => {
        const total = totalByItem.get(item.id) ?? 0; const status = statusFor(total, item.minimum_stock); const lastPrice = data.lastPrices.find((row) => row.inventory_item_id === item.id); const costHistory = data.costHistory.filter((row) => row.inventory_item_id === item.id).slice(0, 8)
        const breakdown = data.balances.filter((row) => row.inventory_item_id === item.id).map((row) => ({ ...row, location: data.locations.find((location) => location.id === row.location_id) })).filter((row) => row.location)
        return <details className={`inventory-stock-row ${item.is_active ? '' : 'archived'}`} key={item.id}>
          <summary><div className="inventory-item-identity"><span className="inventory-item-icon"><Boxes size={17} /></span><div><strong><ComponentChannelMarker code={item.components?.code ?? item.sku} name={item.name} />{item.name}</strong><span>{item.sku} · {item.category || 'Uncategorized'}</span></div></div><div className="inventory-component-link">{item.components ? <><strong>{item.components.name}</strong><code>{item.components.code}</code></> : <span>Not linked</span>}</div><div className="inventory-quantity"><strong>{quantity(total)}</strong><span>{item.unit}</span></div><span className="inventory-minimum">{item.minimum_stock == null ? 'Not set' : `${quantity(item.minimum_stock)} ${item.unit}`}</span><span className={`stock-status stock-${status.key}`}>{status.label}</span><div className="inventory-row-actions" onClick={(event) => event.preventDefault()}>{canManage && item.is_active && <><button type="button" onClick={() => onOpening(item)} title="Opening balance" aria-label={`Initialize ${item.name}`}><PackagePlus size={15} /></button><button type="button" onClick={() => onAdjust(item)} title="Adjust stock" aria-label={`Adjust ${item.name}`}><Scale size={15} /></button><button type="button" onClick={() => onTransfer(item)} title="Transfer stock" aria-label={`Transfer ${item.name}`}><ArrowRightLeft size={15} /></button></>} {canManage && <><button type="button" onClick={() => onEdit(item)} title="Edit item" aria-label={`Edit ${item.name}`}><Edit3 size={15} /></button><button type="button" onClick={() => onDelete(item)} title="Delete item" aria-label={`Delete ${item.name}`}><Trash2 size={15} /></button></>}</div></summary>
          <div className="inventory-location-breakdown"><header><strong>Location breakdown</strong><span>Total {quantity(total)} {item.unit}</span></header>{lastPrice && <div className="inventory-last-purchase"><span>Last purchase evidence · {lastPrice.supplier_name_snapshot}</span><strong>{new Intl.NumberFormat('id-ID', { style: 'currency', currency: lastPrice.currency_code }).format(Number(lastPrice.unit_price))} / {lastPrice.unit_snapshot}</strong></div>}{breakdown.length ? breakdown.map((row) => <div key={row.location_id}><span><MapPin size={14} />{row.location.name}{!row.location.is_active && <small>Archived</small>}</span><strong>{quantity(row.quantity)} {item.unit}</strong></div>) : <p>No stock movements have been posted for this item.</p>}{costHistory.length > 0 && <section className="inventory-cost-history"><strong>Purchase-cost history</strong>{costHistory.map((row) => <div key={`${row.purchase_line_id}-${row.receipt_id ?? 'pending'}`}><span>{row.purchase_number} · {row.supplier_name_snapshot} · {row.purchase_date}</span><span>Ordered {quantity(row.ordered_quantity)} · Received {quantity(row.received_quantity)} {row.unit_snapshot}</span><span>{new Intl.NumberFormat('id-ID', { style: 'currency', currency: row.currency_code }).format(Number(row.unit_price))} / {row.unit_snapshot}</span><span>{row.received_at ? `${new Date(row.received_at).toLocaleDateString('id-ID')} · ${row.location_name}` : 'Not received'}</span></div>)}</section>}</div>
        </details>
      })}
    </div>}
  </>
}

function MovementPanel({ data, account }) {
  const [filters, setFilters] = useState({ item: '', location: '', type: '' })
  const filtered = data.movements.filter((row) => (!filters.item || row.inventory_item_id === filters.item) && (!filters.location || row.location_id === filters.location) && (!filters.type || row.movement_type === filters.type))
  const formatter = useMemo(() => new Intl.DateTimeFormat('id-ID', { timeZone: account.default_timezone || 'Asia/Jakarta', dateStyle: 'medium', timeStyle: 'short' }), [account.default_timezone])
  return <>
    <div className="movement-filter-bar" aria-label="Movement filters"><label><span>Item</span><select value={filters.item} onChange={(event) => setFilters((current) => ({ ...current, item: event.target.value }))}><option value="">All items</option>{data.items.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label><span>Location</span><select value={filters.location} onChange={(event) => setFilters((current) => ({ ...current, location: event.target.value }))}><option value="">All locations</option>{data.locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}</select></label><label><span>Movement type</span><select value={filters.type} onChange={(event) => setFilters((current) => ({ ...current, type: event.target.value }))}><option value="">All types</option>{Object.entries(movementLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label></div>
    {filtered.length === 0 ? <div className="inventory-empty"><History size={25} /><strong>No movements match this view.</strong><span>Posted opening balances, adjustments, and transfers appear chronologically.</span></div> : <div className="inventory-movement-list">{filtered.map((row) => {
      const positive = Number(row.quantity) > 0
      return <article key={row.movement_id}><span className={`movement-direction ${positive ? 'movement-in' : 'movement-out'}`}>{positive ? <ArrowDownToLine size={17} /> : <ArrowUpFromLine size={17} />}</span><div className="movement-primary"><strong><ComponentChannelMarker code={row.sku} name={row.item_name} />{row.item_name}</strong><span>{movementLabels[row.movement_type]}</span></div><div><span>Quantity</span><strong className={positive ? 'quantity-positive' : 'quantity-negative'}>{positive ? '+' : ''}{quantity(row.quantity)} {row.unit_snapshot}</strong></div><div><span>Location</span><strong>{row.location_name}</strong></div><div><span>PIC</span><strong>{row.operational_person_name_snapshot}</strong></div><div><span>Time</span><strong>{formatter.format(new Date(row.occurred_at))}</strong></div><details><summary>Details</summary><div><span>Reason: {row.reason || '—'}</span><span>Notes: {row.notes || '—'}</span><span>Entered by: {row.created_by_name_snapshot}</span><span>Reference: {row.reference_type === 'component_replacement' ? 'Component Replacement' : row.reference_type === 'purchase_receipt' ? 'Purchase Receipt' : row.reference_type.replaceAll('_', ' ')}</span>{row.reference_type === 'component_replacement' && <><span>Machine: {row.replacement_machine_code} · {row.replacement_machine_name}</span><span>Component: {row.replacement_component_name}</span></>}{row.reference_type === 'purchase_receipt' && <><span>Supplier: {row.receipt_supplier_name}</span><span>Purchase: {row.receipt_purchase_number}</span><span>Receipt: {row.receipt_number}</span><span>Unit price: {new Intl.NumberFormat('id-ID', { style: 'currency', currency: row.receipt_currency_code }).format(Number(row.receipt_unit_price))} / {row.unit_snapshot}</span></>}</div></details></article>
    })}</div>}
  </>
}

function LocationsPanel({ locations, showArchived, canManage, onEdit, onDelete }) {
  const visible = locations.filter((location) => showArchived ? !location.is_active : location.is_active)
  return visible.length === 0 ? <div className="inventory-empty"><MapPin size={25} /><strong>No {showArchived ? 'archived' : 'active'} locations.</strong><span>Locations represent real physical stock points and may optionally belong to a branch.</span></div> : <div className="inventory-location-list">{visible.map((location) => <article className={location.is_active ? '' : 'archived'} key={location.id}><span className="inventory-item-icon"><MapPin size={18} /></span><div><strong>{location.name}</strong><span>{location.code} · {location.branches?.name ?? 'Account-wide'}</span>{location.notes && <small>{location.notes}</small>}</div>{!location.is_active && <span className="scope-pill archived"><Archive size={12} />Archived</span>}{canManage && <div className="inventory-row-actions"><button type="button" onClick={() => onEdit(location)} aria-label={`Edit ${location.name}`}><Edit3 size={15} /></button><button type="button" onClick={() => onDelete(location)} aria-label={`Delete ${location.name}`}><Trash2 size={15} /></button></div>}</article>)}</div>
}

export function InventoryPage() {
  const { user } = useAuth(); const { account, branches, membership } = useTenant()
  const canManage = ['owner', 'admin'].includes(membership?.role)
  const viewKey = createUIStateKey({ userId: user.id, accountId: account.id, feature: 'inventory-view', entityId: 'workspace' })
  const viewState = usePersistentUIState({ uiStateKey: viewKey, initialValue: { tab: 'stock', showArchived: false }, validate: validView })
  const { workflow, open: openWorkflow, close: closeWorkflow } = useInventoryWorkflowState({ userId: user.id, accountId: account.id })
  const [data, setData] = useState({ items: [], locations: [], balances: [], totals: [], movements: [], components: [], people: [], suppliers: [], purchases: [], purchaseLines: [], receipts: [], lastPrices: [], costHistory: [] })
  const [loading, setLoading] = useState(true); const [error, setError] = useState(null); const [notice, setNotice] = useState(null)
  const refresh = useCallback(async () => { setLoading(true); setError(null); try { setData(await loadInventory({ accountId: account.id, includeArchived: canManage })) } catch (loadError) { setError(loadError) } finally { setLoading(false) } }, [account.id, canManage])
  useEffect(() => { refresh() }, [refresh])
  async function completed(message) { setNotice(message); await refresh() }
  async function saveItem(values) { await saveInventoryItem({ accountId: account.id, itemId: workflow.inventoryItemId, values }); await completed('Inventory item saved.') }
  async function saveLocation(values) { await saveInventoryLocation({ accountId: account.id, locationId: workflow.locationId, values }); await completed('Inventory location saved.') }
  async function saveSupplier(values) { await saveInventorySupplier({ accountId: account.id, supplierId: workflow.supplierId, values }); await completed('Supplier saved.') }
  async function createPurchase(values, clientRequestId) { await createInventoryPurchase({ accountId: account.id, values, clientRequestId }); await completed('Purchase created. Stock remains unchanged until receiving.') }
  async function receivePurchase(values, clientRequestId) { await receiveInventoryPurchase({ accountId: account.id, purchaseId: workflow.purchaseId, values, clientRequestId }); await completed('Goods received and inventory stock increased atomically.') }
  async function cancelPurchase(reason, clientRequestId) { await cancelInventoryPurchase({ accountId: account.id, purchaseId: workflow.purchaseId, reason, clientRequestId }); await completed('Purchase cancelled. Existing receipt history and stock were not changed.') }
  async function submitMovement(values, clientRequestId) {
    const normalized = { ...values, occurredAt: new Date(values.occurredAt).toISOString() }
    if (workflow.type === 'stock:opening') await initializeInventoryStock({ accountId: account.id, values: normalized, clientRequestId })
    else if (workflow.type === 'stock:adjustment') await adjustInventoryStock({ accountId: account.id, values: normalized, clientRequestId })
    else await transferInventoryStock({ accountId: account.id, values: normalized, clientRequestId })
    await completed(workflow.type === 'stock:transfer' ? 'Stock transferred atomically.' : 'Inventory movement posted.')
  }
  async function removeMaster() {
    if (workflow.type === 'item:delete') await deleteInventoryItem({ accountId: account.id, itemId: workflow.inventoryItemId })
    else if (workflow.type === 'location:delete') await deleteInventoryLocation({ accountId: account.id, locationId: workflow.locationId })
    else await deleteInventorySupplier({ accountId: account.id, supplierId: workflow.supplierId })
    await completed('Unreferenced inventory master deleted.')
  }
  const activeItems = data.items.filter((item) => item.is_active); const activeLocations = data.locations.filter((location) => location.is_active)
  const workflowItem = data.items.find((item) => item.id === workflow.inventoryItemId) ?? null
  const workflowLocation = data.locations.find((location) => location.id === workflow.locationId) ?? null
  const workflowSupplier = data.suppliers.find((supplier) => supplier.id === workflow.supplierId) ?? null
  const workflowPurchase = data.purchases.find((purchase) => purchase.purchase_id === workflow.purchaseId) ?? null
  const workflowPurchaseLines = data.purchaseLines.filter((line) => line.purchase_id === workflow.purchaseId)

  useEffect(() => {
    if (loading || error || !workflow.type) return
    let staleMessage = null
    if (!canManage && workflow.type !== 'purchase:detail') staleMessage = 'The saved Inventory workflow was closed because your current role cannot manage inventory.'
    else if ((workflow.type.startsWith('item:') && workflow.type !== 'item:create') && !workflowItem) staleMessage = 'The saved inventory item workflow is no longer available and was closed safely.'
    else if (workflow.type.startsWith('item:') && workflow.entityActiveAtOpen === true && !workflowItem?.is_active) staleMessage = 'The saved inventory item workflow was closed because the item was archived.'
    else if ((workflow.type.startsWith('location:') && workflow.type !== 'location:create') && !workflowLocation) staleMessage = 'The saved inventory location workflow is no longer available and was closed safely.'
    else if (workflow.type.startsWith('location:') && workflow.entityActiveAtOpen === true && !workflowLocation?.is_active) staleMessage = 'The saved inventory location workflow was closed because the location was archived.'
    else if (workflow.type.startsWith('stock:') && (!workflowItem || !workflowItem.is_active)) staleMessage = 'The saved stock workflow referenced an unavailable or archived item and was closed safely.'
    else if ((workflow.type.startsWith('supplier:') && workflow.type !== 'supplier:create') && !workflowSupplier) staleMessage = 'The saved supplier workflow is no longer available and was closed safely.'
    else if (workflow.type.startsWith('supplier:') && workflow.entityActiveAtOpen === true && !workflowSupplier?.is_active) staleMessage = 'The saved supplier workflow was closed because the supplier was archived.'
    else if ((workflow.type.startsWith('purchase:') && workflow.type !== 'purchase:create') && !workflowPurchase) staleMessage = 'The saved purchase workflow is no longer available and was closed safely.'
    else if (workflow.type === 'purchase:receive' && ['received', 'cancelled'].includes(workflowPurchase?.status)) staleMessage = 'The saved receiving workflow was closed because the purchase is no longer receivable.'
    if (staleMessage) { closeWorkflow(); setNotice(staleMessage) }
  }, [canManage, closeWorkflow, error, loading, workflow.entityActiveAtOpen, workflow.type, workflowItem, workflowLocation, workflowPurchase, workflowSupplier])

  const movementKind = workflow.type?.startsWith('stock:') ? workflow.type.slice('stock:'.length) : null
  return <div className="page-stack inventory-page">
    <PageHeader eyebrow={`${account.name} · Inventory`} title="Inventory" description="Auditable physical stock by item and location, derived from an immutable movement ledger." action={canManage ? <div className="page-header-actions"><button className="secondary-button" type="button" onClick={() => openWorkflow('location:create')}><MapPin size={16} />Add location</button><button className="primary-button" type="button" onClick={() => openWorkflow('item:create')}><Plus size={17} />Add item</button></div> : null} />
    {!canManage && <div className="permission-banner"><ShieldCheck size={18} /><span>Your {membership?.role} role can read current stock and immutable history. M2.4A stock mutations are restricted to owner/admin.</span></div>}
    {notice && <div className="success-banner" role="status"><span>{notice}</span><button type="button" onClick={() => setNotice(null)}>Dismiss</button></div>}
    {error && <div className="embedded-error" role="alert"><strong>Inventory could not be loaded.</strong><span>{error.message}</span><button className="secondary-button" type="button" onClick={refresh}>Try again</button></div>}
    <section className="inventory-shell glass-surface">
      <div className="inventory-tabs" role="tablist" aria-label="Inventory sections">{tabs.map((tab) => <button key={tab.id} type="button" role="tab" aria-selected={viewState.value.tab === tab.id} className={viewState.value.tab === tab.id ? 'selected' : ''} onClick={() => viewState.setUIState((current) => ({ ...current, tab: tab.id }))}><tab.icon size={16} />{tab.label}{tab.id === 'movements' && <span>{data.movements.length}</span>}</button>)}<button className="inventory-refresh" type="button" onClick={refresh} disabled={loading} aria-label="Refresh inventory"><RefreshCcw className={loading ? 'spin' : ''} size={17} /></button></div>
      {canManage && ['stock', 'locations'].includes(viewState.value.tab) && <div className="inventory-record-toggle"><button className={!viewState.value.showArchived ? 'selected' : ''} onClick={() => viewState.setUIState((current) => ({ ...current, showArchived: false }))}>Active</button><button className={viewState.value.showArchived ? 'selected' : ''} onClick={() => viewState.setUIState((current) => ({ ...current, showArchived: true }))}>Archived</button></div>}
      <div className="inventory-content">{loading ? <div className="inventory-empty"><RefreshCcw className="spin" size={25} /><strong>Loading inventory ledger…</strong></div> : !error && <>
        {viewState.value.tab === 'stock' && <StockPanel data={data} showArchived={viewState.value.showArchived} canManage={canManage} onEdit={(item) => openWorkflow('item:edit', { inventoryItemId: item.id, entityActiveAtOpen: item.is_active })} onDelete={(item) => openWorkflow('item:delete', { inventoryItemId: item.id, entityActiveAtOpen: item.is_active })} onOpening={(item) => openWorkflow('stock:opening', { inventoryItemId: item.id, entityActiveAtOpen: true })} onAdjust={(item) => openWorkflow('stock:adjustment', { inventoryItemId: item.id, entityActiveAtOpen: true })} onTransfer={(item) => openWorkflow('stock:transfer', { inventoryItemId: item.id, entityActiveAtOpen: true })} />}
        {viewState.value.tab === 'movements' && <MovementPanel data={data} account={account} />}
        {viewState.value.tab === 'purchasing' && <PurchasingPanel userId={user.id} account={account} data={data} canManage={canManage} onCreateSupplier={() => openWorkflow('supplier:create')} onEditSupplier={(supplier) => openWorkflow('supplier:edit', { supplierId: supplier.id, entityActiveAtOpen: supplier.is_active })} onDeleteSupplier={(supplier) => openWorkflow('supplier:delete', { supplierId: supplier.id, entityActiveAtOpen: supplier.is_active })} onCreatePurchase={() => openWorkflow('purchase:create')} onOpenPurchase={(purchase) => openWorkflow('purchase:detail', { purchaseId: purchase.purchase_id })} />}
        {viewState.value.tab === 'locations' && <LocationsPanel locations={data.locations} showArchived={viewState.value.showArchived} canManage={canManage} onEdit={(location) => openWorkflow('location:edit', { locationId: location.id, entityActiveAtOpen: location.is_active })} onDelete={(location) => openWorkflow('location:delete', { locationId: location.id, entityActiveAtOpen: location.is_active })} />}
      </>}</div>
    </section>
    {!loading && canManage && workflow.type === 'item:create' && <InventoryItemDialog account={account} components={data.components} onClose={closeWorkflow} onSave={saveItem} />}
    {!loading && canManage && workflow.type === 'item:edit' && workflowItem && <InventoryItemDialog account={account} item={workflowItem} components={data.components} onClose={closeWorkflow} onSave={saveItem} />}
    {!loading && canManage && workflow.type === 'location:create' && <InventoryLocationDialog account={account} branches={branches} onClose={closeWorkflow} onSave={saveLocation} />}
    {!loading && canManage && workflow.type === 'location:edit' && workflowLocation && <InventoryLocationDialog account={account} branches={branches} location={workflowLocation} onClose={closeWorkflow} onSave={saveLocation} />}
    {!loading && canManage && ['opening','adjustment','transfer'].includes(movementKind) && workflowItem?.is_active && <InventoryMovementDialog kind={movementKind} account={account} item={workflowItem} items={activeItems} locations={activeLocations} people={data.people} balances={data.balances} onClose={closeWorkflow} onSubmit={submitMovement} />}
    {!loading && canManage && workflow.type === 'item:delete' && workflowItem && <DeleteInventoryMasterDialog kind="inventory item" label={workflowItem.name} onClose={closeWorkflow} onDelete={removeMaster} />}
    {!loading && canManage && workflow.type === 'location:delete' && workflowLocation && <DeleteInventoryMasterDialog kind="location" label={workflowLocation.name} onClose={closeWorkflow} onDelete={removeMaster} />}
    {!loading && canManage && workflow.type === 'supplier:create' && <InventorySupplierDialog account={account} onClose={closeWorkflow} onSave={saveSupplier} />}
    {!loading && canManage && workflow.type === 'supplier:edit' && workflowSupplier && <InventorySupplierDialog account={account} supplier={workflowSupplier} onClose={closeWorkflow} onSave={saveSupplier} />}
    {!loading && canManage && workflow.type === 'supplier:delete' && workflowSupplier && <DeleteInventoryMasterDialog kind="supplier" label={workflowSupplier.name} onClose={closeWorkflow} onDelete={removeMaster} />}
    {!loading && canManage && workflow.type === 'purchase:create' && <InventoryPurchaseDialog account={account} suppliers={data.suppliers.filter((supplier) => supplier.is_active)} items={activeItems} onClose={closeWorkflow} onCreate={createPurchase} />}
    {!loading && workflow.type === 'purchase:detail' && workflowPurchase && <InventoryPurchaseDetailDialog purchase={workflowPurchase} lines={workflowPurchaseLines} canManage={canManage} onClose={closeWorkflow} onReceive={() => openWorkflow('purchase:receive', { purchaseId: workflowPurchase.purchase_id })} onCancel={() => openWorkflow('purchase:cancel', { purchaseId: workflowPurchase.purchase_id })} />}
    {!loading && canManage && workflow.type === 'purchase:receive' && workflowPurchase && <InventoryReceiveDialog account={account} purchase={workflowPurchase} lines={workflowPurchaseLines} locations={activeLocations} people={data.people} onClose={closeWorkflow} onReceive={receivePurchase} />}
    {!loading && canManage && workflow.type === 'purchase:cancel' && workflowPurchase && <CancelInventoryPurchaseDialog account={account} purchase={workflowPurchase} onClose={closeWorkflow} onCancel={cancelPurchase} />}
  </div>
}
