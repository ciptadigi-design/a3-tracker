const businessMessages = {
  '23503': 'A related record is unavailable in the active workspace. Refresh and try again.',
  '23505': 'That record already exists or this request was already completed.',
  '23514': 'The request conflicts with an operational rule. Review the values and try again.',
  '42501': 'You are not authorized to perform this operation in the active workspace.',
  PGRST116: 'The record changed or is no longer available. Refresh and try again.',
}

const networkPatterns = /failed to fetch|networkerror|load failed|fetch failed/i

export function failureCategory(error) {
  if (networkPatterns.test(error?.message ?? '')) return 'network'
  if (error?.code === '42501' || error?.status === 401 || error?.status === 403) return 'authorization'
  if (error?.code === '23505' || error?.status === 409) return 'conflict'
  if (String(error?.code ?? '').startsWith('23') || error?.status === 400 || error?.status === 422) return 'validation'
  return 'server'
}

export function userErrorMessage(error, fallback = 'The request could not be completed. Please try again.') {
  const source = `${error?.message ?? ''} ${error?.details ?? ''}`
  if (networkPatterns.test(source)) return 'A3 Tracker could not reach the service. Check your connection and try again.'
  if (/insufficient stock|tidak mencukupi/i.test(source)) return 'Insufficient stock is available for this operation. Refresh balances and review the quantity.'
  if (/already fully received|no longer receivable/i.test(source)) return 'This purchase is no longer available for receiving. Refresh Purchasing to see its current state.'
  if (/client request id|request.*already.*used/i.test(source)) return 'This request was already processed with different values. Refresh before trying again.'
  if (/last active owner|retain at least one active owner/i.test(source)) return 'The workspace must retain at least one active Owner.'
  return error?.userMessage || businessMessages[error?.code] || fallback
}

export function reportFailure(error, context = {}) {
  const diagnostic = {
    operation: context.operation ?? 'unknown',
    route: context.route ?? (typeof window === 'undefined' ? null : window.location.pathname),
    accountId: context.accountId ?? null,
    branchId: context.branchId ?? null,
    clientRequestId: context.clientRequestId ?? null,
    category: failureCategory(error),
    code: error?.code ?? null,
    status: error?.status ?? null,
  }
  if (import.meta.env?.DEV) diagnostic.developmentMessage = error?.message ?? String(error)
  console.error('[A3 Tracker] operation failed', diagnostic)
  return diagnostic
}

export function operationalError(error, context, fallback) {
  reportFailure(error, context)
  const message = userErrorMessage(error, fallback)
  return Object.assign(new Error(message), {
    cause: error,
    code: error?.code,
    status: error?.status,
    details: error?.details,
    hint: error?.hint,
    userMessage: message,
  })
}
