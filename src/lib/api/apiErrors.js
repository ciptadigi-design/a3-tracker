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
