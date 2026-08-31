import { callBackend } from './dataBackend.js'
const adapters = { supabase: () => import('./supabase/machineCost.js'), laravel: () => import('./laravel/machineCost.js') }
const invoke = (operation, ...args) => callBackend({ domain: 'machine-cost', operation, args, ...adapters })
export const loadMachineCostPeriod = (args) => invoke('loadMachineCostPeriod', args)
export const loadMachineOperatingCosts = (args) => invoke('loadMachineOperatingCosts', args)
export const loadMachineSellingPrices = (args) => invoke('loadMachineSellingPrices', args)
export const createMachineSellingPrice = (args) => invoke('createMachineSellingPrice', args)
export const voidMachineSellingPrice = (args) => invoke('voidMachineSellingPrice', args)
export const createMachineOperatingCost = (args) => invoke('createMachineOperatingCost', args)
export const voidMachineOperatingCost = (args) => invoke('voidMachineOperatingCost', args)
