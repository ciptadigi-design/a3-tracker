import { useCallback } from 'react'
import { createUIStateKey } from '../uiState/uiStateKeys.js'
import { removeUIState, writeUIState } from '../uiState/uiStateStorage.js'
import { usePersistentUIState } from '../uiState/usePersistentUIState.js'

const emptyWorkflow = { type: null, machineId: null }
const emptyPointer = { branchId: null }

function isWorkflow(value) {
  return value && (value.type === 'create' || value.type === 'edit') && (value.type === 'create' ? value.machineId == null : typeof value.machineId === 'string')
}

function isPointer(value) {
  return value && (value.branchId == null || typeof value.branchId === 'string')
}

export function useMachineWorkflowState({ userId, accountId, branchId }) {
  const pointerKey = createUIStateKey({ userId, accountId, feature: 'machine-workflow-pointer', entityId: 'active' })
  const { value: pointerValue, setUIState: setPointer, clearUIState: clearPointer } = usePersistentUIState({ uiStateKey: pointerKey, initialValue: emptyPointer, validate: isPointer })
  const workflowBranchId = pointerValue.branchId ?? branchId
  const workflowKey = createUIStateKey({ userId, accountId, branchId: workflowBranchId, feature: 'machine-workflow', entityId: 'active' })
  const { value: workflowValue, setUIState: setWorkflow, clearUIState: clearStoredWorkflow } = usePersistentUIState({ uiStateKey: workflowKey, initialValue: emptyWorkflow, validate: isWorkflow })

  const openWorkflow = useCallback((nextWorkflow) => {
    const targetKey = createUIStateKey({ userId, accountId, branchId, feature: 'machine-workflow', entityId: 'active' })
    if (targetKey === workflowKey) setWorkflow(nextWorkflow)
    else {
      removeUIState(workflowKey)
      writeUIState(targetKey, nextWorkflow)
    }
    setPointer({ branchId })
  }, [accountId, branchId, setPointer, setWorkflow, userId, workflowKey])

  const openCreate = useCallback(() => {
    openWorkflow({ type: 'create', machineId: null })
  }, [openWorkflow])

  const openEdit = useCallback((machineId) => {
    openWorkflow({ type: 'edit', machineId })
  }, [openWorkflow])

  const clearWorkflow = useCallback(() => {
    clearStoredWorkflow()
    clearPointer()
  }, [clearPointer, clearStoredWorkflow])

  return {
    workflow: workflowValue,
    workflowBranchId,
    isContextActive: !pointerValue.branchId || pointerValue.branchId === branchId,
    openCreate,
    openEdit,
    clearWorkflow,
  }
}
