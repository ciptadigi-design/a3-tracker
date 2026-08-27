import { useCallback } from 'react'
import { createUIStateKey } from '../uiState/uiStateKeys.js'
import { usePersistentUIState } from '../uiState/usePersistentUIState.js'

const emptyWorkflow = { type: null, inventoryItemId: null, locationId: null, entityActiveAtOpen: null }
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
]

function validWorkflow(value) {
  if (!value || !workflowTypes.includes(value.type)) return false
  const itemIdValid = value.inventoryItemId == null || typeof value.inventoryItemId === 'string'
  const locationIdValid = value.locationId == null || typeof value.locationId === 'string'
  const activeSnapshotValid = value.entityActiveAtOpen == null || typeof value.entityActiveAtOpen === 'boolean'
  if (!itemIdValid || !locationIdValid || !activeSnapshotValid) return false
  if (value.type.startsWith('item:') && value.type !== 'item:create') return typeof value.inventoryItemId === 'string' && value.locationId == null
  if (value.type.startsWith('location:') && value.type !== 'location:create') return typeof value.locationId === 'string' && value.inventoryItemId == null
  if (value.type.startsWith('stock:')) return typeof value.inventoryItemId === 'string' && value.locationId == null
  return value.inventoryItemId == null && value.locationId == null
}

export function useInventoryWorkflowState({ userId, accountId }) {
  const key = createUIStateKey({ userId, accountId, feature: 'inventory-workflow', entityId: 'active' })
  const { value, setUIState, clearUIState } = usePersistentUIState({ uiStateKey: key, initialValue: emptyWorkflow, validate: validWorkflow })

  const open = useCallback((type, { inventoryItemId = null, locationId = null, entityActiveAtOpen = null } = {}) => {
    setUIState({ type, inventoryItemId, locationId, entityActiveAtOpen })
  }, [setUIState])

  return { workflow: value, open, close: clearUIState }
}
