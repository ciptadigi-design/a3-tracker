const exportDefinitions = {
  overview: {
    slug: 'overview-machine-summary',
    rows: (report) => report.machineComparison.map((row) => ({
      Machine: `${row.machine_code} · ${row.machine_name}`, Branch: `${row.branch_code} · ${row.branch_name}`,
      'Total Clicks': row.total_clicks, 'Cost / Click (IDR)': row.standard_cost_per_click,
      'Error / Waste (IDR)': row.error_waste_cost, 'Estimated Machine Revenue (IDR)': row.estimated_machine_revenue,
      'Estimated Contribution (IDR)': row.estimated_standard_contribution,
      'Contribution Margin (%)': row.standard_contribution_margin_percent, 'Evidence Status': row.comparison_status,
    })),
  },
  performance: {
    slug: 'machine-performance',
    rows: (report) => report.performance.map((row) => ({
      Machine: `${row.machine_code} · ${row.machine_name}`, Branch: `${row.branch_code} · ${row.branch_name}`,
      'Total Clicks': row.total_clicks, 'Active Days': row.active_days, 'Daily Average Clicks': row.daily_average_clicks,
      'Latest Counter': row.latest_counter, 'Last Input': row.last_input_at, 'Counter Status': row.counter_status,
    })),
  },
  economics: {
    slug: 'machine-economics',
    rows: (report) => report.economics.map((row) => ({
      Machine: `${row.machine_code} · ${row.machine_name}`, Branch: `${row.branch_code} · ${row.branch_name}`,
      'Total Clicks': row.total_clicks, 'Component Consumption (IDR)': row.component_consumption_cost,
      'Error / Waste (IDR)': row.error_waste_cost, 'Standard Machine Cost (IDR)': row.standard_machine_cost,
      'Cost / Click (IDR)': row.standard_cost_per_click, 'Priced Clicks': row.priced_clicks, 'Unpriced Clicks': row.unpriced_clicks,
      'Estimated Machine Revenue (IDR)': row.estimated_machine_revenue, 'Revenue Status': row.revenue_status,
      'Estimated Contribution (IDR)': row.estimated_standard_contribution,
      'Contribution Margin (%)': row.standard_contribution_margin_percent, 'Contribution Status': row.standard_contribution_status,
      'Advanced Enabled': row.advanced_enabled ? 'Yes' : 'No', 'Full Operating Cost (IDR)': row.full_machine_operating_cost,
      'Full Contribution (IDR)': row.estimated_full_contribution,
    })),
  },
  comparison: {
    slug: 'machine-comparison',
    rows: (report) => exportDefinitions.overview.rows(report),
  },
  components: {
    slug: 'component-consumption',
    rows: (report) => report.components.map((row) => ({
      Component: `${row.component_code} · ${row.component_name}`, Category: row.component_category,
      Machine: `${row.machine_code} · ${row.machine_name}`, Branch: row.branch_name,
      Replacements: row.replacement_count, 'Known Consumed Cost (IDR)': row.known_consumed_cost,
      'Unknown Cost Events': row.unknown_cost_events, 'Average Observed Yield': row.average_observed_yield,
    })),
  },
  errors: {
    slug: 'error-waste',
    rows: (report) => report.errors.map((row) => ({
      Date: row.occurred_at, Branch: `${row.branch_code} · ${row.branch_name}`,
      Scope: row.attribution_scope === 'BRANCH_ONLY' ? 'Branch only' : `${row.machine_code} · ${row.machine_name}`,
      Category: row.category, Type: row.incident_type, PIC: row.responsible_name || 'Unassigned', Status: row.status,
      'Material Loss (IDR)': row.material_loss, 'Service Loss (IDR)': row.service_loss,
      'Assessed Loss (IDR)': row.assessed_loss, Description: row.description,
    })),
  },
  inventory: {
    slug: 'inventory-purchasing',
    rows: (report) => [
      ...report.purchases.map((row) => ({ Section: 'Purchasing', Reference: row.purchase_number,
        Description: row.item_sku ? `${row.item_sku} · ${row.item_name}` : row.item_name, Supplier: row.supplier_name,
        Date: row.purchase_date, Status: row.status, Quantity: row.ordered_quantity, Unit: row.unit,
        'Unit Price (IDR)': row.unit_price, 'Total Value (IDR)': row.line_total,
        'Received Quantity': row.received_quantity, 'Remaining Quantity': row.remaining_quantity, Locations: '' })),
      ...report.stock.map((row) => ({ Section: 'Inventory', Reference: row.sku || 'No SKU',
        Description: row.component_name ? `${row.item_name} · ${row.component_name}` : row.item_name,
        Supplier: '', Date: '', Status: row.status, Quantity: row.total_stock, Unit: row.unit,
        'Unit Price (IDR)': '', 'Total Value (IDR)': '', 'Received Quantity': '', 'Remaining Quantity': '',
        Locations: row.location_breakdown.map((location) => `${location.location_name}: ${location.quantity}`).join('; ') })),
    ],
  },
}

function exportValue(value) {
  if (value == null || value === '') return ''
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  return String(value)
}

function csvCell(value) {
  const text = exportValue(value)
  return /[",\r\n]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text
}

export function createCsv(rows) {
  if (!rows.length) return ''
  const headers = Object.keys(rows[0])
  return [`\uFEFF${headers.map(csvCell).join(',')}`, ...rows.map((row) => headers.map((header) => csvCell(row[header])).join(','))].join('\r\n')
}

function slug(value) {
  return String(value || '').toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
}

export function buildReportExport({ tab, report, periodStart, periodEnd, branchLabel, machineLabel }) {
  const definition = exportDefinitions[tab] ?? exportDefinitions.overview
  const scope = [machineLabel || branchLabel || 'all-scope'].map(slug).filter(Boolean).join('-')
  const rows = definition.rows(report)
  return {
    rows,
    csv: createCsv(rows),
    filename: `a3-tracker-${definition.slug}-${scope}-${periodStart}-to-${periodEnd}.csv`,
  }
}

export function downloadCsv({ csv, filename }) {
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.append(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}
