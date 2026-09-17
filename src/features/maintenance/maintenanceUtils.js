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
}

export const importTypeLabels = {
  MANUAL_ENTRY: 'Manual Entry',
  BULK_IMPORT: 'Bulk Import',
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
  if (error?.status === 422) return 'Check the highlighted fields and try again.'
  return 'The request could not be completed. Please try again.'
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

export function validatePdfFile(file) {
  if (!file) return 'Select a file.'
  const isPdfType = file.type === 'application/pdf'
  const isPdfExtension = file.name.toLowerCase().endsWith('.pdf')
  if (!isPdfType && !isPdfExtension) return 'Only PDF files are accepted.'
  if (file.size > MAX_DOCUMENT_FILE_SIZE_BYTES) return `File exceeds the maximum allowed size of ${formatFileSize(MAX_DOCUMENT_FILE_SIZE_BYTES)}.`
  return null
}
