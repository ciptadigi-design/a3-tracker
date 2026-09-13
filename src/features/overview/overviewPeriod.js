import { inheritedMachineTimezone } from '../machines/timezones.js'
import { normalizePeriodDateKey, resolveMachineCostPeriod } from '../machineCost/machineCostPeriods.js'

export const overviewInitialPeriod = { machineId: null, preset: 'this_month', customStart: '', customEnd: '' }

// Matches Reports' established maximum elapsed span (366 days, inclusive endpoints).
export function overviewRangeError({ start, end }) {
  if (!normalizePeriodDateKey(start) || !normalizePeriodDateKey(end) || start > end) return 'Choose a valid date range'
  if ((Date.parse(end) - Date.parse(start)) / 86400000 > 366) return 'The report period cannot exceed 366 days. Narrow the date range and try again.'
  return null
}

export function overviewContextTimezone(account, branch) {
  // Match MachineTimezoneResolver's canonical backend fallback.
  return inheritedMachineTimezone({ account, branch }, 'UTC').value
}

export function overviewPeriod(filters, account, branch, now) {
  return resolveMachineCostPeriod({ ...filters, timezone: overviewContextTimezone(account, branch), now })
}

// Same request-sequence pattern as Reports/useMachines, including invalidation
// on cleanup. A single result commits both projections together.
export function createOverviewRequestGate() {
  let sequence = 0
  return {
    invalidate() { sequence += 1 },
    async run(load, commit) {
      const request = ++sequence
      try {
        const data = await load()
        if (request === sequence) commit({ data, error: null })
      } catch (error) {
        if (request === sequence) commit({ data: null, error })
      }
    },
  }
}
