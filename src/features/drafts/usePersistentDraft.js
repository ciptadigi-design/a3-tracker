import { useCallback, useEffect, useRef, useState } from 'react'
import { migrateLegacyDraft, readDraft, removeDraft, writeDraft } from './draftStorage.js'

function loadInitialState({ draftKey, initialValue, metadata, validate, shouldRestore, legacyDraft }) {
  const storedDraft = readDraft(draftKey) ?? migrateLegacyDraft(draftKey, legacyDraft, metadata)
  if (storedDraft && validate && !validate(storedDraft.value)) {
    removeDraft(draftKey)
    return { value: initialValue, metadata, storedDraft: null, restored: false, pending: null }
  }

  if (!storedDraft) return { value: initialValue, metadata, storedDraft: null, restored: false, pending: null }
  const restore = shouldRestore ? shouldRestore(storedDraft) : true
  if (!restore) return { value: initialValue, metadata: storedDraft.metadata, storedDraft, restored: false, pending: storedDraft }
  return { value: storedDraft.value, metadata: storedDraft.metadata, storedDraft, restored: true, pending: null }
}

export function usePersistentDraft({
  draftKey,
  initialValue,
  metadata = {},
  validate,
  shouldRestore,
  legacyDraft = null,
  debounceMs = 150,
}) {
  const [initialState] = useState(() => loadInitialState({ draftKey, initialValue, metadata, validate, shouldRestore, legacyDraft }))
  const [value, setValue] = useState(initialState.value)
  const [hasDraft, setHasDraft] = useState(Boolean(initialState.storedDraft))
  const [wasRestored, setWasRestored] = useState(initialState.restored)
  const [pendingDraft, setPendingDraft] = useState(initialState.pending)
  const initialValueRef = useRef(initialValue)
  const valueRef = useRef(initialState.value)
  const metadataRef = useRef(initialState.metadata)
  const activeRef = useRef(initialState.restored)
  const pendingRef = useRef(Boolean(initialState.pending))
  const skipPersistRef = useRef(false)

  if (!hasDraft && !pendingDraft) {
    initialValueRef.current = initialValue
    metadataRef.current = metadata
  }

  const updateDraft = useCallback((nextValue) => {
    const resolvedValue = typeof nextValue === 'function' ? nextValue(valueRef.current) : nextValue
    valueRef.current = resolvedValue
    activeRef.current = true
    pendingRef.current = false
    skipPersistRef.current = false
    setValue(resolvedValue)
    setHasDraft(true)
    setWasRestored(false)
    setPendingDraft(null)
  }, [])

  const clearDraft = useCallback((nextValue = initialValueRef.current) => {
    skipPersistRef.current = true
    activeRef.current = false
    pendingRef.current = false
    removeDraft(draftKey)
    valueRef.current = nextValue
    metadataRef.current = metadata
    setValue(nextValue)
    setHasDraft(false)
    setWasRestored(false)
    setPendingDraft(null)
  }, [draftKey, metadata])

  const resetDraft = useCallback((nextValue = initialValueRef.current) => {
    clearDraft(nextValue)
  }, [clearDraft])

  const restorePendingDraft = useCallback(() => {
    if (!pendingDraft) return
    valueRef.current = pendingDraft.value
    metadataRef.current = pendingDraft.metadata
    activeRef.current = true
    pendingRef.current = false
    skipPersistRef.current = false
    setValue(pendingDraft.value)
    setHasDraft(true)
    setWasRestored(true)
    setPendingDraft(null)
  }, [pendingDraft])

  const discardPendingDraft = useCallback(() => {
    clearDraft(initialValueRef.current)
  }, [clearDraft])

  useEffect(() => {
    if (!hasDraft || pendingDraft) return undefined
    const timeoutId = window.setTimeout(() => {
      if (activeRef.current && !skipPersistRef.current) {
        writeDraft(draftKey, { value: valueRef.current, metadata: metadataRef.current })
      }
    }, debounceMs)
    return () => window.clearTimeout(timeoutId)
  }, [debounceMs, draftKey, hasDraft, pendingDraft, value])

  useEffect(() => () => {
    if (activeRef.current && !pendingRef.current && !skipPersistRef.current) {
      writeDraft(draftKey, { value: valueRef.current, metadata: metadataRef.current })
    }
  }, [draftKey])

  return {
    value,
    updateDraft,
    hasDraft,
    wasRestored,
    pendingDraft,
    draftMetadata: metadataRef.current,
    clearDraft,
    resetDraft,
    restorePendingDraft,
    discardPendingDraft,
  }
}
