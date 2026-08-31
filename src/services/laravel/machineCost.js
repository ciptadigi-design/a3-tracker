import { apiClient, unwrapData } from '../../lib/api/apiClient.js'

export async function loadMachineCostPeriod({ machineId, periodStart, periodEnd }) {
  return unwrapData(await apiClient.get(`/machines/${machineId}/cost?period_start=${encodeURIComponent(periodStart)}&period_end=${encodeURIComponent(periodEnd)}`))
}

export async function loadMachineOperatingCosts(args) { const data = await loadMachineCostPeriod(args); return { costs: data.operating_costs || [], people: [] } }

export async function loadMachineSellingPrices(args) { const data = await loadMachineCostPeriod(args); return data.selling_prices || [] }

export async function createMachineSellingPrice({ machineId, values }) { return unwrapData(await apiClient.post(`/machines/${machineId}/cost/selling-prices`, { price_per_click: values.pricePerClick, effective_from: values.effectiveFrom, notes: values.notes, client_request_id: values.clientRequestId })) }
export async function voidMachineSellingPrice({ priceId, reason, clientRequestId }) { return unwrapData(await apiClient.post(`/selling-prices/${priceId}/void`, { reason, client_request_id: clientRequestId })) }
export async function createMachineOperatingCost({ machineId, values }) { return unwrapData(await apiClient.post(`/machines/${machineId}/cost/operating-costs`, { category: values.category, amount: values.amount, allocation_method: values.allocationMethod, description: values.description, effective_at: values.effectiveAt || null, period_start: values.periodStart || null, period_end: values.periodEnd || null, operational_person_id: values.operationalPersonId || null, external_reference: values.externalReference || null, notes: values.notes || null, client_request_id: values.clientRequestId })) }
export async function voidMachineOperatingCost({ costId, reason, clientRequestId }) { return unwrapData(await apiClient.post(`/operating-costs/${costId}/void`, { reason, client_request_id: clientRequestId })) }
