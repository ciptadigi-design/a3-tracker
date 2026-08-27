import { useCallback } from 'react'
import { createUIStateKey } from '../uiState/uiStateKeys.js'
import { usePersistentUIState } from '../uiState/usePersistentUIState.js'

const empty = { type: null, entityId: null }
const validTypes = ['component-create', 'component-edit', 'profile-create', 'profile-edit', 'profile-assign', 'lifecycle-initialize', 'lifecycle-replace', 'intelligence-view']
const valid = (value) => value && validTypes.includes(value.type) && (value.entityId == null || typeof value.entityId === 'string')

export function useComponentWorkflowState({ userId, accountId }) {
  const key = createUIStateKey({ userId, accountId, feature: 'component-workflow', entityId: 'active' })
  const { value, setUIState, clearUIState } = usePersistentUIState({ uiStateKey: key, initialValue: empty, validate: valid })
  const open = useCallback((type, entityId = null) => setUIState({ type, entityId }), [setUIState])
  return { workflow: value, open, close: clearUIState }
}
