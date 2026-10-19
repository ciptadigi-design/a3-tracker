export const ticketStatusLabels = {
  OPEN: 'Open',
  IN_PROGRESS: 'In Progress',
  DONE: 'Done',
  CANCELLED: 'Cancelled',
}

export const ticketTypeLabels = {
  breakdown: 'Breakdown',
  preventive: 'Preventive',
  inspection: 'Inspection',
}

export const ticketPriorityLabels = {
  low: 'Low',
  normal: 'Normal',
  high: 'High',
  urgent: 'Urgent',
}

export const errorCodeSeverityLabels = {
  info: 'Info',
  warning: 'Warning',
  critical: 'Critical',
}

export const knowledgeStatusLabels = {
  DRAFT: 'Draft',
  REVIEW: 'In Review',
  PUBLISHED: 'Published',
}

export const documentStatusLabels = {
  DRAFT: 'Draft',
  PUBLISHED: 'Published',
  ARCHIVED: 'Archived',
}

export const documentTypeLabels = {
  SERVICE_MANUAL: 'Service Manual',
  USER_MANUAL: 'User Manual',
  PART_CATALOG: 'Part Catalog',
  TROUBLESHOOTING_GUIDE: 'Troubleshooting Guide',
  OTHER: 'Other',
}

// Ticket status is a strict workflow: OPEN -> IN_PROGRESS -> DONE, with CANCELLED
// reachable from either open state. Mirrors MaintenanceTicketService::TRANSITIONS
// on the backend so the UI never offers a transition the API would reject.
export const nextTicketStatuses = {
  OPEN: ['IN_PROGRESS', 'CANCELLED'],
  IN_PROGRESS: ['DONE', 'CANCELLED'],
  DONE: [],
  CANCELLED: [],
}

export const importStatusLabels = {
  DRAFT: 'Draft',
  PROCESSING: 'Processing',
  REVIEW: 'In Review',
  PUBLISHED: 'Published',
  REJECTED: 'Rejected',
  // V1.6 - a chunk job exhausted its retries; see FAILED handling in ProcessMaintenanceKnowledgeJob.
  FAILED: 'Processing Failed',
}

export const importTypeLabels = {
  MANUAL_ENTRY: 'Manual Entry',
  BULK_IMPORT: 'Bulk Import',
  // V1.6 - Extracted Knowledge Processing: deterministic candidate detection over a
  // completed PDF extraction, not a human typing entries by hand.
  PDF_EXTRACTION: 'PDF Processing',
}

// V1.6 - deterministic, explainable evidence classification (never a fabricated
// AI-style percentage) - see PdfKnowledgeCandidateDetector.
export const evidenceLabels = {
  HIGH: 'High',
  MEDIUM: 'Medium',
  LOW: 'Low',
}

// V1.6 - whether a candidate's normalized code already exists in the live
// knowledge base at detection time (Section J). Never auto-resolved -
// always a human review signal.
export const collisionStatusLabels = {
  NEW: 'New',
  EXISTING: 'Existing',
  POTENTIAL_UPDATE: 'Potential Update',
}

export const knowledgeTypeLabels = {
  ERROR_CODE: 'Error Code',
  JAM_CODE: 'Jam Code',
  WARNING: 'Warning',
  PM_SCHEDULE: 'PM Schedule',
}

export const entryStatusLabels = {
  DRAFT: 'Draft',
  APPROVED: 'Approved',
  REJECTED: 'Rejected',
}

// Knowledge entry review workflow: DRAFT -> APPROVED/REJECTED, APPROVED -> REJECTED
// (a change of mind before publish). Mirrors MaintenanceKnowledgeImportController::
// ENTRY_TRANSITIONS so the UI never offers a transition the API would reject.
export const nextEntryStatuses = {
  DRAFT: ['APPROVED', 'REJECTED'],
  APPROVED: ['REJECTED'],
  REJECTED: [],
}

export function formatMaintenanceDate(value, timezone, options = {}) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('id-ID', {
    timeZone: timezone,
    dateStyle: 'medium',
    ...(options.dateOnly ? {} : { timeStyle: 'short' }),
  }).format(new Date(value))
}

// Ticket creation autofill: derives a starting title/description from a selected
// error code without ever overwriting what the reporter already typed themselves.
export function buildTicketPrefillFromErrorCode(errorCode) {
  if (!errorCode) return { title: '', description: '' }
  const descriptionParts = [errorCode.operator_description, errorCode.solution_summary ? `Suggested fix: ${errorCode.solution_summary}` : null].filter(Boolean)

  return {
    title: [errorCode.code, errorCode.title].filter(Boolean).join(' · '),
    description: descriptionParts.join('\n\n'),
  }
}

export function mapMaintenanceError(error) {
  if (error?.status === 409) return 'This ticket already moved to a different state. Refresh and try again.'
  if (error?.status === 403) return 'Your role is not authorized to perform this maintenance action.'
  if (error?.status === 422) return Object.values(error.errors || {}).flat()[0] || 'Check the highlighted fields and try again.'
  return 'The request could not be completed. Please try again.'
}

// Mirrors MaintenanceTicketService::TEST_RESULTS - labels shown on the resolution form.
export const testResultLabels = {
  normal: 'Normal',
  monitoring: 'Perlu Monitoring',
  not_normal: 'Belum Normal',
}

// Client-side fast-fail only; MaintenanceTicketService::validatedResolution is authoritative.
// Breakdown tickets need a full resolution and cannot close while "Belum Normal".
export function validateResolution(ticketType, values) {
  const errors = {}
  if (!values.resolution_cause?.trim()) errors.resolution_cause = 'Penyebab masalah wajib diisi.'
  if (!values.resolution_action?.trim()) errors.resolution_action = 'Tindakan penyelesaian wajib diisi.'
  if (!testResultLabels[values.resolution_test_result]) errors.resolution_test_result = 'Hasil pengujian wajib dipilih.'
  else if (values.resolution_test_result === 'not_normal' && ticketType === 'breakdown') errors.resolution_test_result = 'Mesin belum normal. Tiket gangguan tetap Dalam Proses.'
  return errors
}

// Preventive/inspection tickets may still complete without any resolution (legacy behaviour).
export const resolutionOptionalTypes = ['preventive', 'inspection']

// Who performed one activity: internal operator and/or external technician (+ vendor).
export function performerParts(entry) {
  const parts = []
  if (entry?.operator_name) parts.push({ kind: 'internal', label: entry.operator_name })
  if (entry?.external_technician_name || entry?.vendor_name) {
    parts.push({ kind: 'external', label: [entry.external_technician_name, entry.vendor_name && `(${entry.vendor_name})`].filter(Boolean).join(' ') })
  }
  return parts
}

export function sortActivitiesChronologically(actions) {
  return [...(actions || [])].sort((a, b) => new Date(a.performed_at) - new Date(b.performed_at))
}

// Corrections are append-only rows that reference an original activity. The timeline shows
// each original (never rewritten) with its corrections attached, oldest first; the latest
// correction is the "current" information.
export function groupActivities(actions) {
  const rows = actions || []
  const corrections = rows.filter((row) => row.corrects_action_id)
  return sortActivitiesChronologically(rows.filter((row) => !row.corrects_action_id)).map((original) => {
    const own = sortActivitiesChronologically(corrections.filter((row) => row.corrects_action_id === original.id))
    return { original, corrections: own, current: own.length ? own[own.length - 1] : original }
  })
}

// A ticket completed while its linked downtime is still running needs a visible reminder;
// the two lifecycles are independent and nothing closes downtime automatically.
export function hasActiveLinkedDowntime(ticket) {
  return Boolean(ticket?.downtime_event && !ticket.downtime_event.actual_end_at)
}

// Mirrors DocumentStorageService::MAX_FILE_SIZE_BYTES - client-side validation is a
// fast-fail UX convenience only; the backend remains the authoritative enforcement.
// Raised from 50MB to 250MB in V1.4.1.
export const MAX_DOCUMENT_FILE_SIZE_BYTES = 250 * 1024 * 1024

export function formatFileSize(bytes) {
  if (bytes == null) return '—'
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

// Mirrors ExtractMaintenanceDocumentJob's PENDING -> PROCESSING -> COMPLETED
// flow (or -> FAILED) - the UI never invents a state the backend doesn't have.
export const extractionStatusLabels = {
  PENDING: 'Pending',
  PROCESSING: 'Processing',
  COMPLETED: 'Completed',
  FAILED: 'Failed',
}

// Mirrors PdfExtractionErrorCode (backend/app/Services/PdfExtraction) - the backend
// owns classification, this is display text only. The frontend never pattern-matches
// a raw error_message to decide what happened; it always keys off error_code.
export const extractionErrorMessages = {
  UNSUPPORTED_PDF_SECURITY: 'This PDF is protected using a security format that the current extractor does not support. The original PDF remains safe and available.',
  INVALID_OR_CORRUPT_PDF: 'This PDF file could not be read. It may be corrupted or not a valid PDF.',
  FILE_MISSING: 'The stored PDF file could not be found. Try re-uploading the document.',
  RESOURCE_LIMIT: 'This document is too large for the extraction worker to process safely right now.',
  EXTRACTION_RUNTIME_FAILURE: 'Extraction failed due to an unexpected error. It will be retried automatically.',
}

// Mirrors PdfExtractionErrorCode::isPermanent() - retrying cannot fix these, so the
// UI must not offer a meaningless Retry action for them.
export const permanentExtractionErrorCodes = ['UNSUPPORTED_PDF_SECURITY', 'INVALID_OR_CORRUPT_PDF', 'FILE_MISSING', 'RESOURCE_LIMIT']

export function validatePdfFile(file) {
  if (!file) return 'Select a file.'
  const isPdfType = file.type === 'application/pdf'
  const isPdfExtension = file.name.toLowerCase().endsWith('.pdf')
  if (!isPdfType && !isPdfExtension) return 'Only PDF files are accepted.'
  if (file.size > MAX_DOCUMENT_FILE_SIZE_BYTES) return `File exceeds the maximum allowed size of ${formatFileSize(MAX_DOCUMENT_FILE_SIZE_BYTES)}.`
  return null
}
