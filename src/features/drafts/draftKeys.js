const DRAFT_ROOT = 'a3tracker:draft:'

function segment(value, fallback) {
  const normalized = value == null || value === '' ? fallback : String(value)
  if (!normalized) throw new Error('Draft key segments must not be empty.')
  return encodeURIComponent(normalized)
}

export function createDraftKey({ userId, accountId, branchId, feature, entityId }) {
  return `${DRAFT_ROOT}${segment(userId)}:${segment(accountId)}:${segment(branchId, 'global')}:${segment(feature)}:${segment(entityId, 'new')}`
}

export function createUserDraftPrefix(userId) {
  return `${DRAFT_ROOT}${segment(userId)}:`
}
