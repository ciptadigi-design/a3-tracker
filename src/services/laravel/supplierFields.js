// Pure field-normalization helpers split out of inventory.js so they can be unit
// tested without pulling in the Vite-env-dependent (import.meta.env) apiClient.

export function optional(value) { return typeof value === 'string' ? value.trim() || null : value ?? null }

// Legacy Supabase/AI-assisted migration rows used "-" as a stand-in for "no email on
// file"; the backend's email validator rejects that literally, so treat it (and other
// common non-address placeholders) as absent. Email-only: Notes/Address/Phone can
// legitimately contain "-" as real free text and must not be normalized.
const EMAIL_PLACEHOLDER_VALUES = new Set(['-', '--', 'n/a', 'na', 'none', 'null'])
export function optionalEmail(value) {
  const trimmed = optional(value)
  return trimmed && EMAIL_PLACEHOLDER_VALUES.has(trimmed.toLowerCase()) ? null : trimmed
}
