// Pure, backend-agnostic error-shaping helpers split out of apiClient.js so they can be
// unit tested without pulling in the Vite-env-dependent (import.meta.env) transport code.

// Laravel's default ValidationException message truncates to "first error (and N more errors)".
// The `errors` map on a 422 response always carries every field's messages, so prefer that
// when present instead of showing the user an incomplete summary.
export function describeApiError(error) {
  const fieldMessages = error?.errors && typeof error.errors === 'object' ? Object.values(error.errors).flat() : []
  return fieldMessages.length ? fieldMessages.join(' ') : error?.message ?? 'The request could not be completed.'
}

// Backend-agnostic "this record is referenced elsewhere" detection: Postgres/PostgREST
// surfaces foreign-key violations as error.code (23503), while the Laravel API normalizes
// the same condition to an HTTP 409 with no .code at all.
export function isReferenceConflict(error) {
  return error?.status === 409 || error?.code === '23503' || error?.code === '23505'
}

// The inventory ledger (InventoryLedgerService) raises a 409 with one of a small, fixed
// set of developer-authored reason strings - the Laravel exception renderer now passes
// these through verbatim (see bootstrap/app.php) instead of collapsing every 409 to the
// bare word "Conflict.". This maps each known reason to operator-facing guidance; an
// unrecognized reason (a future guard, or a non-ledger 409) falls back to the raw message
// so nothing is silently swallowed.
const inventoryConflictReasons = {
  'insufficient stock': 'Stock changed since this form was opened. Refresh the balance and try again.',
  'incomplete FIFO cost basis': 'This item’s cost history is incomplete at this location, so the adjustment could not be completed. Contact support with the item and location.',
  'active item and location in same account required': 'This item or location is no longer active. Refresh and choose an active one.',
  'locations must differ': 'Choose two different locations for the transfer.',
  'Request key belongs to another movement kind.': 'This action was already submitted as a different kind of movement. Refresh the movement history before retrying.',
  'Incomplete transfer retry.': 'An earlier attempt at this transfer only partially completed. Refresh the movement history before retrying.',
  'quantity must be positive': 'Enter a quantity greater than zero.',
}

export function describeInventoryConflict(error) {
  if (error?.status !== 409) return describeApiError(error)
  return inventoryConflictReasons[error?.message] ?? describeApiError(error)
}
