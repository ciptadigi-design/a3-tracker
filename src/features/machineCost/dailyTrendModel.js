export function normalizeDailyTrend(rows) {
  return (rows ?? []).map((row) => ({
    operationalDate: row.operational_date,
    clicks: row.daily_clicks == null ? null : Number(row.daily_clicks),
    counterReadings: Number(row.counter_readings ?? 0),
    knownCost: row.known_daily_cost == null ? null : Number(row.known_daily_cost),
    componentEvents: Number(row.component_events ?? 0),
    errorWasteEvents: Number(row.error_waste_events ?? 0),
    unknownCostEvents: Number(row.unknown_cost_events ?? 0),
    costEvidenceStatus: row.cost_evidence_status,
  }))
}

export function hasDailyTrendEvidence(rows) {
  return rows.some((row) => row.counterReadings > 0 || row.componentEvents > 0 || row.errorWasteEvents > 0)
}

export function dailyCostLabel(row, formatCurrency) {
  if (row.knownCost == null) return 'No known cost recorded'
  return formatCurrency(row.knownCost)
}
