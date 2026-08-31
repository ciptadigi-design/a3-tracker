import { apiClient, unwrapData } from '../../lib/api/apiClient.js'

export async function loadMachineCostPeriod({ machineId, periodStart, periodEnd }) {
  return unwrapData(await apiClient.get(`/machines/${machineId}/cost?period_start=${encodeURIComponent(periodStart)}&period_end=${encodeURIComponent(periodEnd)}`))
}

export async function loadMachineOperatingCosts(args) {
  return loadMachineCostPeriod(args)
}

export async function loadMachineSellingPrices(args) {
  return loadMachineCostPeriod(args)
}

const unsupported = (operation) => { throw new Error(`machine-cost.${operation} is not implemented for Laravel Production mode. No Supabase fallback was attempted.`) }
export const createMachineSellingPrice = () => unsupported('createMachineSellingPrice')
export const voidMachineSellingPrice = () => unsupported('voidMachineSellingPrice')
export const createMachineOperatingCost = () => unsupported('createMachineOperatingCost')
export const voidMachineOperatingCost = () => unsupported('voidMachineOperatingCost')
