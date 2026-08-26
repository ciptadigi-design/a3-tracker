import { useCallback, useState } from 'react'
import { migrateDraftToUIState, readUIState, removeUIState, writeUIState } from './uiStateStorage.js'

function loadState(uiStateKey, initialValue, validate, legacyDraftKey, prepareLegacyState) {
  if (!readUIState(uiStateKey) && prepareLegacyState) prepareLegacyState()
  const stored = readUIState(uiStateKey) ?? migrateDraftToUIState(uiStateKey, legacyDraftKey)
  if (!stored || (validate && !validate(stored.value))) {
    if (stored) removeUIState(uiStateKey)
    return { key: uiStateKey, value: initialValue, persisted: false }
  }
  return { key: uiStateKey, value: stored.value, persisted: true }
}

export function usePersistentUIState({ uiStateKey, initialValue, validate, legacyDraftKey = null, prepareLegacyState = null }) {
  const [state, setState] = useState(() => loadState(uiStateKey, initialValue, validate, legacyDraftKey, prepareLegacyState))
  const currentState = state.key === uiStateKey ? state : loadState(uiStateKey, initialValue, validate, legacyDraftKey, prepareLegacyState)

  const setUIState = useCallback((nextValue) => {
    setState((current) => {
      const active = current.key === uiStateKey ? current : loadState(uiStateKey, initialValue, validate, legacyDraftKey, prepareLegacyState)
      const resolvedValue = typeof nextValue === 'function' ? nextValue(active.value) : nextValue
      writeUIState(uiStateKey, resolvedValue)
      return { key: uiStateKey, value: resolvedValue, persisted: true }
    })
  }, [initialValue, legacyDraftKey, prepareLegacyState, uiStateKey, validate])

  const clearUIState = useCallback(() => {
    removeUIState(uiStateKey)
    setState({ key: uiStateKey, value: initialValue, persisted: false })
  }, [initialValue, uiStateKey])

  return {
    value: currentState.value,
    setUIState,
    clearUIState,
    hasPersistedState: currentState.persisted,
  }
}
