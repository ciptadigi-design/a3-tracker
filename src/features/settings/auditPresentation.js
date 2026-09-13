const labels = {
  'membership.created': 'Provisioned member',
  'membership.attached': 'Attached existing identity',
  'membership.role_changed': 'Changed member role',
  'membership.status_changed': 'Changed member status',
  'membership.branch_assignments_changed': 'Changed member branches',
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
export const auditActionLabel = (action) => labels[action] ?? 'Administrative change'
export function auditValue(value) {
  if (value == null) return '—'
  if (typeof value === 'boolean') return value ? 'On' : 'Off'
  if (Array.isArray(value)) return value.length ? value.map(auditValue).join(', ') : 'None'
  return typeof value === 'object' ? 'Changed' : String(value)
}
export const auditChanges = (changes = {}) => Object.entries(changes).map(([field, value]) => value.before === '[not retained]' && value.after === '[not retained]' ? `${field.replaceAll('_', ' ')}: updated (values not retained)` : `${field.replaceAll('_', ' ')}: ${auditValue(value.before)} → ${auditValue(value.after)}`)
