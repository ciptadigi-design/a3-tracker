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

// Ticket status is a strict workflow: OPEN -> IN_PROGRESS -> DONE, with CANCELLED
// reachable from either open state. Mirrors MaintenanceTicketService::TRANSITIONS
// on the backend so the UI never offers a transition the API would reject.
export const nextTicketStatuses = {
  OPEN: ['IN_PROGRESS', 'CANCELLED'],
  IN_PROGRESS: ['DONE', 'CANCELLED'],
  DONE: [],
  CANCELLED: [],
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
