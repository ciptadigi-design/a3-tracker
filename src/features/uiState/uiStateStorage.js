import { readDraft, removeDraft } from '../drafts/draftStorage.js'
import { createUserUIStatePrefix } from './uiStateKeys.js'

const UI_STATE_VERSION = 1

function getStorage() {
  if (typeof window === 'undefined') return null
  try {
    return window.sessionStorage
  } catch {
    return null
  }
}

export function readUIState(uiStateKey) {
  const storage = getStorage()
  if (!storage || !uiStateKey) return null
  try {
    const state = JSON.parse(storage.getItem(uiStateKey))
    if (state?.version !== UI_STATE_VERSION || !Object.hasOwn(state, 'value')) return null
    return state
  } catch {
    return null
  }
}

export function writeUIState(uiStateKey, value) {
  const storage = getStorage()
  if (!storage || !uiStateKey) return false
  try {
    storage.setItem(uiStateKey, JSON.stringify({ version: UI_STATE_VERSION, value, savedAt: new Date().toISOString() }))
    return true
  } catch {
    return false
  }
}

export function removeUIState(uiStateKey) {
  const storage = getStorage()
  if (!storage || !uiStateKey) return
  try {
    storage.removeItem(uiStateKey)
  } catch {
    // Workflows remain usable when browser storage is unavailable.
  }
}

export function migrateDraftToUIState(uiStateKey, draftKey) {
  const existing = readUIState(uiStateKey)
  if (existing || !draftKey) return existing
  const legacyDraft = readDraft(draftKey)
  if (!legacyDraft) return null
  const migrated = writeUIState(uiStateKey, legacyDraft.value)
  if (migrated) removeDraft(draftKey)
  return readUIState(uiStateKey)
}

export function clearUIStateForUser(userId) {
  const storage = getStorage()
  if (!storage || !userId) return
  try {
    const prefix = createUserUIStatePrefix(userId)
    for (let index = storage.length - 1; index >= 0; index -= 1) {
      const key = storage.key(index)
      if (key?.startsWith(prefix)) storage.removeItem(key)
    }
  } catch {
    // Logout continues even when browser storage is unavailable.
  }
}

