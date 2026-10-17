import { laravelDowntime } from '../lib/api/downtime.js'
import { unwrapData } from '../lib/api/apiClient.js'

function toQueryString(params = {}) {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') search.set(key, value)
  }
  return search.toString()
}

export const loadDowntimeOverview = async ({ accountId, branchId, machineId, periodStart, periodEnd }) =>
  unwrapData(await laravelDowntime.overview(toQueryString({ account_id: accountId, branch_id: branchId, machine_id: machineId, period_start: periodStart, period_end: periodEnd })))

export const loadDowntimeEvents = async ({ machineId, branchId, status, page, perPage } = {}) =>
  unwrapData(await laravelDowntime.events(toQueryString({ machine_id: machineId, branch_id: branchId, status, page, per_page: perPage })))

export const loadActiveDowntime = async (machineId) => unwrapData(await laravelDowntime.active(machineId))

export const startDowntime = async (machineId, payload) => unwrapData(await laravelDowntime.start(machineId, payload))

export const addDowntimeNote = async (eventId, payload) => unwrapData(await laravelDowntime.addNote(eventId, payload))

export const endDowntime = async (eventId, payload) => unwrapData(await laravelDowntime.end(eventId, payload))

export const loadBranchProductionSchedule = async (branchId) => unwrapData(await laravelDowntime.branchSchedule(branchId))
export const saveBranchProductionSchedule = async (branchId, days) => unwrapData(await laravelDowntime.putBranchSchedule(branchId, days))
export const loadMachineScheduleOverride = async (machineId) => unwrapData(await laravelDowntime.machineOverride(machineId))
export const saveMachineScheduleOverride = async (machineId, days) => unwrapData(await laravelDowntime.putMachineOverride(machineId, days))
