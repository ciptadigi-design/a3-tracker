const BRANCH_PREFERENCE_ROOT = 'a3tracker:branch-preference:v1:'
const BRANCH_PREFERENCE_VERSION = 1

function segment(value) {
  if (value == null || value === '') throw new Error('Branch preference identity must not be empty.')
  return encodeURIComponent(String(value))
}

function browserStorage() {
  if (typeof window === 'undefined') return null
  try {
    return window.localStorage
  } catch {
    return null
  }
}

export function createBranchPreferenceKey({ userId, accountId }) {
  return `${BRANCH_PREFERENCE_ROOT}${segment(userId)}:${segment(accountId)}`
}

export function readBranchPreference({ userId, accountId, storage = browserStorage() }) {
  if (!storage || !userId || !accountId) return null
  try {
    const value = JSON.parse(storage.getItem(createBranchPreferenceKey({ userId, accountId })))
    return value?.version === BRANCH_PREFERENCE_VERSION && typeof value.branchId === 'string' ? value.branchId : null
  } catch {
    return null
  }
}

export function writeBranchPreference({ userId, accountId, branchId, storage = browserStorage() }) {
  if (!storage || !userId || !accountId || !branchId) return false
  try {
    storage.setItem(createBranchPreferenceKey({ userId, accountId }), JSON.stringify({ version: BRANCH_PREFERENCE_VERSION, branchId }))
    return true
  } catch {
    return false
  }
}

export function resolveAuthorizedBranchId({ currentBranchId, preferredBranchId, branches }) {
  const activeBranches = branches.filter((branch) => branch.is_active !== false)
  if (activeBranches.some((branch) => branch.id === currentBranchId)) return currentBranchId
  if (activeBranches.some((branch) => branch.id === preferredBranchId)) return preferredBranchId
  return activeBranches[0]?.id ?? null
}
