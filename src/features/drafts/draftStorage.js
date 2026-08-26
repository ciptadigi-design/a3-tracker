import { createUserDraftPrefix } from './draftKeys.js'

const DRAFT_VERSION = 1
const LEGACY_USER_PREFIXES = [
  'a3tracker:daily-counter-draft:',
  'a3tracker:daily-counter-selected-machine:',
]

function getStorage() {
  if (typeof window === 'undefined') return null
  try {
    return window.sessionStorage
  } catch {
    return null
  }
}

export function readDraft(draftKey) {
  const storage = getStorage()
  if (!storage || !draftKey) return null

  try {
    const draft = JSON.parse(storage.getItem(draftKey))
    if (draft?.version !== DRAFT_VERSION || !Object.hasOwn(draft, 'value')) return null
    return draft
  } catch {
    return null
  }
}

export function writeDraft(draftKey, { value, metadata = {} }) {
  const storage = getStorage()
  if (!storage || !draftKey) return false

  try {
    storage.setItem(draftKey, JSON.stringify({
      version: DRAFT_VERSION,
      value,
      metadata,
      savedAt: new Date().toISOString(),
    }))
    return true
  } catch {
    return false
  }
}

export function removeDraft(draftKey) {
  const storage = getStorage()
  if (!storage || !draftKey) return
  try {
    storage.removeItem(draftKey)
  } catch {
    // Forms remain usable when browser storage is unavailable.
  }
}

export function readLegacyDailyDraft(userId, machineId) {
  const storage = getStorage()
  if (!storage || !userId || !machineId) return null
  const legacyKey = `a3tracker:daily-counter-draft:${userId}:${machineId}`

  try {
    const value = JSON.parse(storage.getItem(legacyKey))
    if (value?.userId !== userId || value?.machineId !== machineId) return null
    if (![value.readingValue, value.shiftCode, value.observedAt, value.notes].every((field) => typeof field === 'string')) return null
    return {
      legacyKey,
      value: {
        readingValue: value.readingValue,
        shiftCode: value.shiftCode,
        observedAt: value.observedAt,
        notes: value.notes,
        clientRequestId: typeof value.clientRequestId === 'string' ? value.clientRequestId : crypto.randomUUID(),
      },
    }
  } catch {
    return null
  }
}

export function migrateLegacyDailySelection(draftKey, userId, accountId, branchId) {
  const existing = readDraft(draftKey)
  if (existing) return existing
  const storage = getStorage()
  if (!storage || !userId || !accountId || !branchId) return null
  const legacyKey = `a3tracker:daily-counter-selected-machine:${userId}:${accountId}:${branchId}`

  try {
    const machineId = storage.getItem(legacyKey)
    if (!machineId) return null
    const migrated = writeDraft(draftKey, { value: { machineId } })
    if (migrated) storage.removeItem(legacyKey)
    return readDraft(draftKey)
  } catch {
    return null
  }
}

export function migrateLegacyDraft(draftKey, legacyDraft, metadata = {}) {
  if (!legacyDraft || readDraft(draftKey)) return readDraft(draftKey)
  const migrated = writeDraft(draftKey, { value: legacyDraft.value, metadata })
  if (migrated) removeDraft(legacyDraft.legacyKey)
  return readDraft(draftKey)
}

export function clearDraftsForUser(userId) {
  const storage = getStorage()
  if (!storage || !userId) return

  try {
    const prefixes = [
      createUserDraftPrefix(userId),
      ...LEGACY_USER_PREFIXES.map((prefix) => `${prefix}${userId}:`),
    ]
    for (let index = storage.length - 1; index >= 0; index -= 1) {
      const key = storage.key(index)
      if (key && prefixes.some((prefix) => key.startsWith(prefix))) storage.removeItem(key)
    }
  } catch {
    // Logout continues even when browser storage is unavailable.
  }
}
