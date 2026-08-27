import { useCallback } from 'react'
import { createUIStateKey } from '../uiState/uiStateKeys.js'
import { usePersistentUIState } from '../uiState/usePersistentUIState.js'

const emptyWorkflow = { type: null, inventoryItemId: null, locationId: null, supplierId: null, purchaseId: null, entityActiveAtOpen: null }
const workflowTypes = [
  'item:create',
  'item:edit',
  'item:delete',
  'location:create',
  'location:edit',
  'location:delete',
  'stock:opening',
  'stock:adjustment',
  'stock:transfer',
  'supplier:create',
  'supplier:edit',
  'supplier:delete',
  'purchase:create',
  'purchase:detail',
  'purchase:receive',
  'purchase:cancel',
]

function validWorkflow(value) {
  if (!value || !workflowTypes.includes(value.type)) return false
  const itemIdValid = value.inventoryItemId == null || typeof value.inventoryItemId === 'string'
  const locationIdValid = value.locationId == null || typeof value.locationId === 'string'
  const activeSnapshotValid = value.entityActiveAtOpen == null || typeof value.entityActiveAtOpen === 'boolean'
  const supplierIdValid = value.supplierId == null || typeof value.supplierId === 'string'
  const purchaseIdValid = value.purchaseId == null || typeof value.purchaseId === 'string'
  if (!itemIdValid || !locationIdValid || !supplierIdValid || !purchaseIdValid || !activeSnapshotValid) return false
  if (value.type.startsWith('item:') && value.type !== 'item:create') return typeof value.inventoryItemId === 'string' && value.locationId == null
  if (value.type.startsWith('location:') && value.type !== 'location:create') return typeof value.locationId === 'string' && value.inventoryItemId == null
  if (value.type.startsWith('stock:')) return typeof value.inventoryItemId === 'string' && value.locationId == null
  if (value.type.startsWith('supplier:') && value.type !== 'supplier:create') return typeof value.supplierId === 'string'
  if (value.type.startsWith('purchase:') && value.type !== 'purchase:create') return typeof value.purchaseId === 'string'
  return value.inventoryItemId == null && value.locationId == null && value.supplierId == null && value.purchaseId == null
}

export function useInventoryWorkflowState({ userId, accountId }) {
  const key = createUIStateKey({ userId, accountId, feature: 'inventory-workflow', entityId: 'active' })
  const { value, setUIState, clearUIState } = usePersistentUIState({ uiStateKey: key, initialValue: emptyWorkflow, validate: validWorkflow })

  const open = useCallback((type, { inventoryItemId = null, locationId = null, supplierId = null, purchaseId = null, entityActiveAtOpen = null } = {}) => {
    setUIState({ type, inventoryItemId, locationId, supplierId, purchaseId, entityActiveAtOpen })
  }, [setUIState])

  return { workflow: value, open, close: clearUIState }
}
