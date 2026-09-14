const labels = {
  'membership.created': 'Provisioned member',
  'membership.attached': 'Attached existing identity',
  'membership.role_changed': 'Changed member role',
  'membership.status_changed': 'Changed member status',
  'membership.branch_assignments_changed': 'Changed member branches',
  'membership.updated': 'Updated member',
  'identity.credentials_reset': 'Reset managed credentials',
  'identity.email_changed': 'Changed identity email',
  'identity.profile_updated': 'Changed identity profile',
  'account.created': 'Created account',
  'account.updated': 'Updated account profile',
  'account.policy_updated': 'Changed account permissions',
  'branch.created': 'Created branch',
  'branch.updated': 'Updated branch',
  'branch.archived': 'Archived branch',
  'branch.restored': 'Restored branch',
  'machine.created': 'Created machine',
  'machine.updated': 'Updated machine',
  'machine.status_changed': 'Changed machine status',
  'supplier.created': 'Created supplier',
  'supplier.updated': 'Updated supplier',
  'supplier.archived': 'Archived supplier',
  'supplier.restored': 'Restored supplier',
  'supplier.deleted': 'Removed supplier',
  'supplier.branch_assignments_changed': 'Changed supplier branches',
  'operational_person.created': 'Created operational person',
  'operational_person.updated': 'Updated operational person',
  'operational_person.deactivated': 'Deactivated operational person',
  'operational_person.reactivated': 'Reactivated operational person',
  'operational_person.branch_assignment_changed': 'Changed operational person assignment',
  'calendar_exception.created': 'Added calendar exception',
  'calendar_exception.updated': 'Changed calendar exception',
  'calendar_exception.deleted': 'Removed calendar exception',
  'model_profile.configuration_changed': 'Changed model profile configuration',
  'machine_model.created': 'Created machine model',
  'machine_model.updated': 'Updated machine model',
  'machine_component.created': 'Added machine component',
  'machine_component.excluded': 'Excluded machine component',
  'machine_component.exclusion_cleared': 'Cleared component exclusion',
  'machine_component.reconciled': 'Reconciled component configuration',
  'machine_component.retired': 'Retired component configuration',
  'machine_component.profile_synced': 'Synchronized component profile',
}
const targetTypes = {
  account: 'Workspace',
  account_membership: 'Member',
  user: 'Member',
  supplier: 'Supplier',
  branch: 'Branch',
  machine: 'Machine',
  operational_person: 'Operational person',
  operational_person_branch: 'Operational person',
}

const fieldLabels = {
  branch_ids: 'Branches',
  is_active: 'Active',
  role: 'Role',
  status: 'Status',
  name: 'Name',
  display_name: 'Display name',
  machine_code: 'Machine code',
}

const titleCase = (value) => String(value).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
const booleanState = (value) => {
  if ([true, 1, '1'].includes(value) || ['true', 'on'].includes(String(value).toLowerCase())) return true
  if ([false, 0, '0'].includes(value) || ['false', 'off'].includes(String(value).toLowerCase())) return false
  return null
}

export const auditActionLabel = (action) => titleCase(labels[action] ?? 'Administrative change')
export const auditActorLabel = (label) => titleCase(label || 'Unknown actor')

export function shortenAuditIdentifier(value) {
  const identifier = String(value ?? '')
  return identifier.length > 16 ? `${identifier.slice(0, 8)}…${identifier.slice(-4)}` : identifier || 'Not available'
}

export function auditTarget(target = {}) {
  const typeLabel = targetTypes[target.type] ?? titleCase(target.type || 'Record')
  return {
    typeLabel,
    label: target.label || shortenAuditIdentifier(target.id),
    hasHumanLabel: Boolean(target.label),
    fullIdentifier: target.id || '',
  }
}

export function auditValue(value, { field, targetType } = {}) {
  if (value == null) return '—'
  const state = booleanState(value)
  if (field === 'is_active' && state != null && ['supplier', 'branch', 'operational_person'].includes(targetType)) return state ? 'Active' : 'Archived'
  if (state != null && (typeof value === 'boolean' || /(^is_|^can_|_enabled$)/.test(field || ''))) return /(^can_|_enabled$)/.test(field || '') ? (state ? 'Enabled' : 'Disabled') : (state ? 'Yes' : 'No')
  if (Array.isArray(value)) return value.length ? value.map((item) => auditValue(item, { field, targetType })).join(', ') : 'None'
  if (typeof value === 'string' && ['role', 'status'].includes(field)) return titleCase(value)
  if (typeof value === 'string' && uuidPattern.test(value)) return shortenAuditIdentifier(value)
  return typeof value === 'object' ? 'Changed' : String(value)
}

export const auditChanges = (changes = {}, targetType) => Object.entries(changes).map(([field, value]) => {
  const label = field === 'is_active' && ['supplier', 'branch', 'operational_person'].includes(targetType)
    ? 'Status'
    : fieldLabels[field] ?? titleCase(field)
  if (value.before === '[not retained]' && value.after === '[not retained]') {
    return { field: label, value: 'Updated (values not retained)' }
  }
  return {
    field: label,
    value: `${auditValue(value.before, { field, targetType })} → ${auditValue(value.after, { field, targetType })}`,
  }
})

export function auditTimestamp(value) {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return 'Time unavailable'
  const parts = new Intl.DateTimeFormat('en-US', { day: '2-digit', month: 'short', year: 'numeric' }).formatToParts(date)
  const values = Object.fromEntries(parts.map((part) => [part.type, part.value]))
  const day = `${values.day} ${values.month} ${values.year}`
  const time = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false }).format(date)
  return `${day} · ${time}`
}
