const DRAFT_PREFIX = 'a3tracker:daily-counter-draft:'
const SELECTION_PREFIX = 'a3tracker:daily-counter-selected-machine:'

function getSessionStorage() {
  if (typeof window === 'undefined') return null
  try {
    return window.sessionStorage
  } catch {
    return null
  }
}

export function dailyCounterDraftKey(userId, machineId) {
  return `${DRAFT_PREFIX}${userId}:${machineId}`
}

export function loadDailyCounterDraft(userId, machineId) {
  if (!userId || !machineId) return null
  const storage = getSessionStorage()
  if (!storage) return null

  try {
    const draft = JSON.parse(storage.getItem(dailyCounterDraftKey(userId, machineId)))
    if (draft?.userId !== userId || draft?.machineId !== machineId) return null
    if (![draft.readingValue, draft.shiftCode, draft.observedAt, draft.notes].every((value) => typeof value === 'string')) return null
    return draft
  } catch {
    return null
  }
}

export function saveDailyCounterDraft(userId, machineId, draft) {
  if (!userId || !machineId) return
  const storage = getSessionStorage()
  if (!storage) return

  try {
    storage.setItem(dailyCounterDraftKey(userId, machineId), JSON.stringify({
      userId,
      machineId,
      readingValue: draft.readingValue,
      shiftCode: draft.shiftCode,
      observedAt: draft.observedAt,
      notes: draft.notes,
      clientRequestId: draft.clientRequestId,
    }))
  } catch {
    // The form remains usable if browser storage is unavailable or full.
  }
}

export function clearDailyCounterDraft(userId, machineId) {
  const storage = getSessionStorage()
  if (!storage || !userId || !machineId) return
  try {
    storage.removeItem(dailyCounterDraftKey(userId, machineId))
  } catch {
    // The form remains usable if browser storage is unavailable.
  }
}

function selectedMachineKey(userId, accountId, branchId) {
  return `${SELECTION_PREFIX}${userId}:${accountId}:${branchId}`
}

export function loadSelectedDailyMachine(userId, accountId, branchId) {
  const storage = getSessionStorage()
  if (!storage || !userId || !accountId || !branchId) return ''
  try {
    return storage.getItem(selectedMachineKey(userId, accountId, branchId)) ?? ''
  } catch {
    return ''
  }
}

export function saveSelectedDailyMachine(userId, accountId, branchId, machineId) {
  const storage = getSessionStorage()
  if (!storage || !userId || !accountId || !branchId || !machineId) return
  try {
    storage.setItem(selectedMachineKey(userId, accountId, branchId), machineId)
  } catch {
    // Machine selection falls back to the first active machine.
  }
}

export function clearDailyCounterDraftsForUser(userId) {
  const storage = getSessionStorage()
  if (!storage || !userId) return

  try {
    const draftPrefix = `${DRAFT_PREFIX}${userId}:`
    const selectionPrefix = `${SELECTION_PREFIX}${userId}:`
    for (let index = storage.length - 1; index >= 0; index -= 1) {
      const key = storage.key(index)
      if (key?.startsWith(draftPrefix) || key?.startsWith(selectionPrefix)) storage.removeItem(key)
    }
  } catch {
    // The auth session still signs out if browser storage is unavailable.
  }
}
