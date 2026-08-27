import { useCallback, useEffect, useMemo, useState } from 'react'
import { Archive, ArrowDownToLine, ArrowRightLeft, ArrowUpFromLine, Boxes, Edit3, History, MapPin, Package, PackagePlus, Plus, RefreshCcw, Scale, ShieldCheck, Trash2 } from 'lucide-react'
import { PageHeader } from '../components/ui/PageHeader.jsx'
import { ComponentChannelMarker } from '../features/components/ComponentChannelMarker.jsx'
import { useAuth } from '../features/auth/useAuth.js'
import { useTenant } from '../features/account/useTenant.js'
import { createUIStateKey } from '../features/uiState/uiStateKeys.js'
import { usePersistentUIState } from '../features/uiState/usePersistentUIState.js'
import { DeleteInventoryMasterDialog, InventoryItemDialog, InventoryLocationDialog, InventoryMovementDialog } from '../features/inventory/InventoryDialogs.jsx'
import { adjustInventoryStock, deleteInventoryItem, deleteInventoryLocation, initializeInventoryStock, loadInventory, saveInventoryItem, saveInventoryLocation, transferInventoryStock } from '../services/supabase/inventory.js'

const tabs = [
  { id: 'stock', label: 'Stock', icon: Package },
  { id: 'movements', label: 'Movements', icon: History },
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
        const total = totalByItem.get(item.id) ?? 0; const status = statusFor(total, item.minimum_stock)
        const breakdown = data.balances.filter((row) => row.inventory_item_id === item.id).map((row) => ({ ...row, location: data.locations.find((location) => location.id === row.location_id) })).filter((row) => row.location)
        return <details className={`inventory-stock-row ${item.is_active ? '' : 'archived'}`} key={item.id}>
          <summary><div className="inventory-item-identity"><span className="inventory-item-icon"><Boxes size={17} /></span><div><strong><ComponentChannelMarker code={item.components?.code ?? item.sku} name={item.name} />{item.name}</strong><span>{item.sku} · {item.category || 'Uncategorized'}</span></div></div><div className="inventory-component-link">{item.components ? <><strong>{item.components.name}</strong><code>{item.components.code}</code></> : <span>Not linked</span>}</div><div className="inventory-quantity"><strong>{quantity(total)}</strong><span>{item.unit}</span></div><span className="inventory-minimum">{item.minimum_stock == null ? 'Not set' : `${quantity(item.minimum_stock)} ${item.unit}`}</span><span className={`stock-status stock-${status.key}`}>{status.label}</span><div className="inventory-row-actions" onClick={(event) => event.preventDefault()}>{canManage && item.is_active && <><button type="button" onClick={() => onOpening(item)} title="Opening balance" aria-label={`Initialize ${item.name}`}><PackagePlus size={15} /></button><button type="button" onClick={() => onAdjust(item)} title="Adjust stock" aria-label={`Adjust ${item.name}`}><Scale size={15} /></button><button type="button" onClick={() => onTransfer(item)} title="Transfer stock" aria-label={`Transfer ${item.name}`}><ArrowRightLeft size={15} /></button></>} {canManage && <><button type="button" onClick={() => onEdit(item)} title="Edit item" aria-label={`Edit ${item.name}`}><Edit3 size={15} /></button><button type="button" onClick={() => onDelete(item)} title="Delete item" aria-label={`Delete ${item.name}`}><Trash2 size={15} /></button></>}</div></summary>
          <div className="inventory-location-breakdown"><header><strong>Location breakdown</strong><span>Total {quantity(total)} {item.unit}</span></header>{breakdown.length ? breakdown.map((row) => <div key={row.location_id}><span><MapPin size={14} />{row.location.name}{!row.location.is_active && <small>Archived</small>}</span><strong>{quantity(row.quantity)} {item.unit}</strong></div>) : <p>No stock movements have been posted for this item.</p>}</div>
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
      return <article key={row.movement_id}><span className={`movement-direction ${positive ? 'movement-in' : 'movement-out'}`}>{positive ? <ArrowDownToLine size={17} /> : <ArrowUpFromLine size={17} />}</span><div className="movement-primary"><strong><ComponentChannelMarker code={row.sku} name={row.item_name} />{row.item_name}</strong><span>{movementLabels[row.movement_type]}</span></div><div><span>Quantity</span><strong className={positive ? 'quantity-positive' : 'quantity-negative'}>{positive ? '+' : ''}{quantity(row.quantity)} {row.unit_snapshot}</strong></div><div><span>Location</span><strong>{row.location_name}</strong></div><div><span>PIC</span><strong>{row.operational_person_name_snapshot}</strong></div><div><span>Time</span><strong>{formatter.format(new Date(row.occurred_at))}</strong></div><details><summary>Details</summary><div><span>Reason: {row.reason || '—'}</span><span>Notes: {row.notes || '—'}</span><span>Entered by: {row.created_by_name_snapshot}</span><span>Reference: {row.reference_type.replaceAll('_', ' ')}</span></div></details></article>
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
  const [data, setData] = useState({ items: [], locations: [], balances: [], totals: [], movements: [], components: [], people: [] })
  const [loading, setLoading] = useState(true); const [error, setError] = useState(null); const [notice, setNotice] = useState(null); const [dialog, setDialog] = useState(null)
  const refresh = useCallback(async () => { setLoading(true); setError(null); try { setData(await loadInventory({ accountId: account.id, includeArchived: canManage })) } catch (loadError) { setError(loadError) } finally { setLoading(false) } }, [account.id, canManage])
  useEffect(() => { refresh() }, [refresh])
  async function completed(message) { setNotice(message); await refresh() }
  async function saveItem(values) { await saveInventoryItem({ accountId: account.id, itemId: dialog.item?.id, values }); await completed('Inventory item saved.') }
  async function saveLocation(values) { await saveInventoryLocation({ accountId: account.id, locationId: dialog.location?.id, values }); await completed('Inventory location saved.') }
  async function submitMovement(values, clientRequestId) {
    const normalized = { ...values, occurredAt: new Date(values.occurredAt).toISOString() }
    if (dialog.kind === 'opening') await initializeInventoryStock({ accountId: account.id, values: normalized, clientRequestId })
    else if (dialog.kind === 'adjustment') await adjustInventoryStock({ accountId: account.id, values: normalized, clientRequestId })
    else await transferInventoryStock({ accountId: account.id, values: normalized, clientRequestId })
    await completed(dialog.kind === 'transfer' ? 'Stock transferred atomically.' : 'Inventory movement posted.')
  }
  async function removeMaster() {
    if (dialog.kind === 'delete-item') await deleteInventoryItem({ accountId: account.id, itemId: dialog.item.id })
    else await deleteInventoryLocation({ accountId: account.id, locationId: dialog.location.id })
    await completed('Unreferenced inventory master deleted.')
  }
  const activeItems = data.items.filter((item) => item.is_active); const activeLocations = data.locations.filter((location) => location.is_active)
  return <div className="page-stack inventory-page">
    <PageHeader eyebrow={`${account.name} · Inventory`} title="Inventory" description="Auditable physical stock by item and location, derived from an immutable movement ledger." action={canManage ? <div className="page-header-actions"><button className="secondary-button" type="button" onClick={() => setDialog({ kind: 'location' })}><MapPin size={16} />Add location</button><button className="primary-button" type="button" onClick={() => setDialog({ kind: 'item' })}><Plus size={17} />Add item</button></div> : null} />
    {!canManage && <div className="permission-banner"><ShieldCheck size={18} /><span>Your {membership?.role} role can read current stock and immutable history. M2.4A stock mutations are restricted to owner/admin.</span></div>}
    {notice && <div className="success-banner" role="status"><span>{notice}</span><button type="button" onClick={() => setNotice(null)}>Dismiss</button></div>}
    {error && <div className="embedded-error" role="alert"><strong>Inventory could not be loaded.</strong><span>{error.message}</span><button className="secondary-button" type="button" onClick={refresh}>Try again</button></div>}
    <section className="inventory-shell glass-surface">
      <div className="inventory-tabs" role="tablist" aria-label="Inventory sections">{tabs.map((tab) => <button key={tab.id} type="button" role="tab" aria-selected={viewState.value.tab === tab.id} className={viewState.value.tab === tab.id ? 'selected' : ''} onClick={() => viewState.setUIState((current) => ({ ...current, tab: tab.id }))}><tab.icon size={16} />{tab.label}{tab.id === 'movements' && <span>{data.movements.length}</span>}</button>)}<button className="inventory-refresh" type="button" onClick={refresh} disabled={loading} aria-label="Refresh inventory"><RefreshCcw className={loading ? 'spin' : ''} size={17} /></button></div>
      {canManage && viewState.value.tab !== 'movements' && <div className="inventory-record-toggle"><button className={!viewState.value.showArchived ? 'selected' : ''} onClick={() => viewState.setUIState((current) => ({ ...current, showArchived: false }))}>Active</button><button className={viewState.value.showArchived ? 'selected' : ''} onClick={() => viewState.setUIState((current) => ({ ...current, showArchived: true }))}>Archived</button></div>}
      <div className="inventory-content">{loading ? <div className="inventory-empty"><RefreshCcw className="spin" size={25} /><strong>Loading inventory ledger…</strong></div> : !error && <>
        {viewState.value.tab === 'stock' && <StockPanel data={data} showArchived={viewState.value.showArchived} canManage={canManage} onEdit={(item) => setDialog({ kind: 'item', item })} onDelete={(item) => setDialog({ kind: 'delete-item', item })} onOpening={(item) => setDialog({ kind: 'opening', item })} onAdjust={(item) => setDialog({ kind: 'adjustment', item })} onTransfer={(item) => setDialog({ kind: 'transfer', item })} />}
        {viewState.value.tab === 'movements' && <MovementPanel data={data} account={account} />}
        {viewState.value.tab === 'locations' && <LocationsPanel locations={data.locations} showArchived={viewState.value.showArchived} canManage={canManage} onEdit={(location) => setDialog({ kind: 'location', location })} onDelete={(location) => setDialog({ kind: 'delete-location', location })} />}
      </>}</div>
    </section>
    {dialog?.kind === 'item' && <InventoryItemDialog account={account} item={dialog.item} components={data.components} onClose={() => setDialog(null)} onSave={saveItem} />}
    {dialog?.kind === 'location' && <InventoryLocationDialog account={account} branches={branches} location={dialog.location} onClose={() => setDialog(null)} onSave={saveLocation} />}
    {['opening','adjustment','transfer'].includes(dialog?.kind) && <InventoryMovementDialog kind={dialog.kind} account={account} item={dialog.item} items={activeItems} locations={activeLocations} people={data.people} balances={data.balances} onClose={() => setDialog(null)} onSubmit={submitMovement} />}
    {dialog?.kind === 'delete-item' && <DeleteInventoryMasterDialog kind="inventory item" label={dialog.item.name} onClose={() => setDialog(null)} onDelete={removeMaster} />}
    {dialog?.kind === 'delete-location' && <DeleteInventoryMasterDialog kind="location" label={dialog.location.name} onClose={() => setDialog(null)} onDelete={removeMaster} />}
  </div>
}
