#!/usr/bin/env node

// M2.12F newer-Supabase reference capture. This program performs GET only,
// rejects every project except sxitqjxljoqsnpepymrl, and never prints tokens.

import { createHash } from 'node:crypto'
import { chmod, writeFile } from 'node:fs/promises'

const project = 'sxitqjxljoqsnpepymrl'
const baseUrl = process.env.NEW_SUPABASE_URL
const apiKey = process.env.NEW_SUPABASE_API_KEY
const bearer = process.env.NEW_SUPABASE_BEARER_TOKEN
const output = process.argv[2]
const tables = [
  'accounts', 'branches', 'manufacturers', 'machine_models', 'machines',
  'components', 'machine_model_components', 'machine_component_assignments',
  'machine_component_lifecycles', 'component_replacement_events',
  'counter_types', 'counter_readings', 'operational_people',
  'operational_person_branches', 'inventory_locations', 'inventory_items',
  'inventory_suppliers', 'inventory_purchases', 'inventory_purchase_lines',
  'inventory_receipts', 'inventory_receipt_lines', 'inventory_movements',
  'inventory_cost_lots', 'inventory_cost_allocations', 'operational_incidents',
  'account_operational_permissions', 'machine_selling_prices',
  'machine_operating_costs',
]

function stop(reason) {
  console.error('M2_12F_NEW_SUPABASE_CAPTURE=PENDING_OPERATOR')
  console.error(`STOP_REASON=${String(reason).replace(/[^A-Za-z0-9 ._:/()-]/g, '?')}`)
  console.error('HTTP_METHODS=GET_ONLY\nNEW_SUPABASE_MUTATION=NO\nSTOP')
  process.exit(78)
}

if (!baseUrl || !apiKey || !bearer || !output) stop('NEW_SUPABASE_URL, NEW_SUPABASE_API_KEY, NEW_SUPABASE_BEARER_TOKEN, and output path are required')
let origin
try { origin = new URL(baseUrl) } catch { stop('NEW_SUPABASE_URL is invalid') }
if (origin.protocol !== 'https:' || origin.hostname !== `${project}.supabase.co`) stop('new Supabase project identity mismatch')

async function readTable(table) {
  const rows = []
  const pageSize = 1000
  for (let offset = 0; ; offset += pageSize) {
    const url = new URL(`/rest/v1/${table}`, origin)
    url.searchParams.set('select', '*')
    const response = await fetch(url, {
      method: 'GET',
      headers: {
        apikey: apiKey,
        Authorization: `Bearer ${bearer}`,
        Accept: 'application/json',
        Range: `${offset}-${offset + pageSize - 1}`,
        'Range-Unit': 'items',
      },
    })
    if (!response.ok) throw new Error(`${table} GET failed with HTTP ${response.status}`)
    const page = await response.json()
    if (!Array.isArray(page)) throw new Error(`${table} returned non-array JSON`)
    rows.push(...page)
    if (page.length < pageSize) break
  }
  return rows
}

try {
  const captured = {}
  for (const table of tables) captured[table] = await readTable(table)
  const document = {
    checkpoint: 'M2.12F',
    source: 'NEW_SUPABASE_READ_ONLY_REFERENCE',
    project_ref: project,
    captured_at: new Date().toISOString(),
    http_methods: ['GET'],
    tables: captured,
    auth: { policy: 'DO_NOT_COPY_AUTH_USERS', rows_captured: false },
  }
  const serialized = `${JSON.stringify(document)}\n`
  await writeFile(output, serialized, { mode: 0o600, flag: 'wx' })
  await chmod(output, 0o600)
  console.log('M2_12F_NEW_SUPABASE_CAPTURE=PASS')
  console.log(`NEW_SUPABASE_TABLE_COUNT=${tables.length}`)
  console.log(`NEW_SUPABASE_ROW_COUNT=${Object.values(captured).reduce((sum, rows) => sum + rows.length, 0)}`)
  console.log(`NEW_SUPABASE_CAPTURE_SHA256=${createHash('sha256').update(serialized).digest('hex')}`)
  console.log('HTTP_METHODS=GET_ONLY\nNEW_SUPABASE_MUTATION=NO\nSTOP')
} catch (error) {
  stop(error instanceof Error ? error.message : 'unknown capture failure')
}
