const requiredOperationalZones = ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura']

export function supportedTimezones() {
  let values = []
  try {
    values = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : []
  } catch {
    values = []
  }
  return [...new Set([...requiredOperationalZones, ...values])].sort((left, right) => left.localeCompare(right))
}

export function inheritedMachineTimezone({ branch, account }) {
  if (branch?.timezone) return { value: branch.timezone, source: 'branch' }
  if (account?.default_timezone) return { value: account.default_timezone, source: 'account' }
  return { value: 'Asia/Jakarta', source: 'default' }
}
