const UI_STATE_ROOT = 'a3tracker:ui-state:'

function segment(value, fallback) {
  const normalized = value == null || value === '' ? fallback : String(value)
  if (!normalized) throw new Error('UI state key segments must not be empty.')
  return encodeURIComponent(normalized)
}

export function createUIStateKey({ userId, accountId, branchId, feature, entityId }) {
  return `${UI_STATE_ROOT}${segment(userId)}:${segment(accountId)}:${segment(branchId, 'global')}:${segment(feature)}:${segment(entityId, 'active')}`
}

export function createUserUIStatePrefix(userId) {
  return `${UI_STATE_ROOT}${segment(userId)}:`
}

