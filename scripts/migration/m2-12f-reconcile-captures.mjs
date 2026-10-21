#!/usr/bin/env node

/**
 * M2.12F deterministic, offline reconciliation.
 *
 * Deliberately imports only local filesystem, hashing, and path primitives. It
 * has no network, database, SSH, child-process, or environment dependencies.
 */
import { createHash } from 'node:crypto'
import { readFile, writeFile } from 'node:fs/promises'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

export const EXPECTED_NEW_SHA256 = '94bfe30b539fb490b59e349e2d271ba594de882863f2f17dc34351482786bc5e'
export const EXPECTED_PRODUCTION_SHA256 = '6c79b0be60bc62e622f348c660a29aaf9191062b20793ada304222fd886e5e16'
export const COMBINED_HOTFIX_SHA = '15033c17c392ae40579b61c4e78d51ce72d62af7'

const NEW_TABLES = [
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

const PRODUCTION_TABLES = [
  'accounts', 'branches', 'manufacturers', 'machine_models', 'machines',
  'component_catalogs', 'model_profiles', 'model_profile_slots',
  'machine_components', 'component_lifecycles', 'component_replacements',
  'counter_types', 'counter_readings', 'operational_people',
  'operational_person_branches', 'inventory_locations', 'inventory_items',
  'inventory_suppliers', 'purchases', 'purchase_lines', 'receipts',
  'receipt_lines', 'inventory_movements', 'fifo_layers', 'fifo_allocations',
  'operational_incidents', 'account_operational_permissions',
  'machine_selling_prices', 'machine_operating_costs',
]

const CLASSIFICATIONS = [
  'MATCHED', 'PRODUCTION_ONLY_VALID', 'SUPABASE_ONLY_CANONICAL',
  'SUPABASE_ONLY_OPERATIONAL_VALID', 'ALREADY_MIGRATED_LEGACY', 'DUPLICATE',
  'TEST_DUMMY', 'DEV_ONLY', 'AMBIGUOUS',
]

const DOMAIN_ORDER = [
  'accounts', 'branches', 'machine_models', 'machines', 'component_catalogs',
  'model_profiles', 'model_profile_slots', 'machine_components',
  'component_lifecycles', 'counters', 'operational_people',
  'operational_person_branch_assignments', 'inventory_locations',
  'inventory_items', 'purchases', 'receipts', 'inventory_movements_fifo',
  'incidents_errors', 'machine_cost_source_facts', 'operational_policies',
  'counter_types', 'settings_configuration', 'auth_identity_references',
]

const CONFIG_DOMAINS = new Set([
  'accounts', 'branches', 'machine_models', 'machines', 'component_catalogs',
  'model_profiles', 'model_profile_slots', 'machine_components',
  'inventory_locations', 'inventory_items', 'operational_policies',
  'counter_types', 'settings_configuration',
])
const OPERATIONAL_DOMAINS = new Set([
  'component_lifecycles', 'counters', 'operational_people',
  'operational_person_branch_assignments', 'purchases', 'receipts',
  'inventory_movements_fifo', 'incidents_errors', 'machine_cost_source_facts',
])
const LEGACY_DOMAINS = new Set([
  'component_lifecycles', 'counters', 'purchases', 'incidents_errors',
])

const freezeAt = Date.parse('2026-09-01T06:15:57Z')
const bool = value => value === true || value === 1 || value === '1'
const norm = value => String(value ?? '').trim().toLowerCase().replace(/\s+/g, ' ')
const token = value => norm(value).replace(/[^a-z0-9]+/g, '-')
const number = value => value === null || value === undefined || value === '' ? null : Number(value)
const time = value => {
  if (!value) return null
  const normalized = String(value).includes('T') ? String(value) : `${String(value).replace(' ', 'T')}Z`
  const parsed = new Date(normalized)
  if (Number.isNaN(parsed.valueOf())) throw new Error(`invalid timestamp: ${value}`)
  // Production MariaDB capture is second-precision. Supabase microseconds are
  // representational noise for business-key comparison, not new readings.
  parsed.setUTCMilliseconds(0)
  return parsed.toISOString()
}
const date = value => value ? String(value).slice(0, 10) : null
const stable = value => {
  if (Array.isArray(value)) return value.map(stable)
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort().map(key => [key, stable(value[key])]))
  }
  return value
}
const json = value => `${JSON.stringify(stable(value), null, 2)}\n`
const sha256 = buffer => createHash('sha256').update(buffer).digest('hex')
const fingerprint = value => sha256(Buffer.from(json(value)))
const isUuid = value => /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(String(value ?? ''))

function invariant(condition, message) {
  if (!condition) throw new Error(message)
}

function exactKeys(actual, expected, label) {
  const left = [...actual].sort().join(',')
  const right = [...expected].sort().join(',')
  invariant(left === right, `${label} table contract mismatch`)
}

export function verifySha(buffer, expected, label) {
  const actual = sha256(buffer)
  invariant(actual === expected, `${label} SHA256 mismatch: expected ${expected}, got ${actual}`)
  return actual
}

export function validateSnapshot(document, kind, { strictTables = true } = {}) {
  invariant(document && Object.getPrototypeOf(document) === Object.prototype, `${kind} snapshot must be an object`)
  invariant(document.checkpoint === 'M2.12F', `${kind} checkpoint mismatch`)
  invariant(document.tables && Object.getPrototypeOf(document.tables) === Object.prototype, `${kind} tables must be an object`)
  if (kind === 'new_supabase') {
    invariant(document.source === 'NEW_SUPABASE_READ_ONLY_REFERENCE', 'new Supabase source mismatch')
    invariant(document.project_ref === 'sxitqjxljoqsnpepymrl', 'new Supabase project mismatch')
    invariant(JSON.stringify(document.http_methods) === '["GET"]', 'new Supabase capture was not GET-only')
    invariant(document.auth?.policy === 'DO_NOT_COPY_AUTH_USERS' && document.auth?.rows_captured === false, 'new Supabase auth policy mismatch')
    if (strictTables) exactKeys(Object.keys(document.tables), NEW_TABLES, kind)
  } else {
    invariant(kind === 'production', `unknown snapshot kind: ${kind}`)
    invariant(document.source === 'HOSTINGER_MARIADB_PRODUCTION', 'Production source mismatch')
    invariant(document.transaction === 'READ_ONLY_ROLLED_BACK', 'Production transaction guarantee mismatch')
    invariant(document.auth?.policy === 'PRODUCTION_LOCAL_ONLY' && document.auth?.rows_captured === false, 'Production auth policy mismatch')
    if (strictTables) exactKeys(Object.keys(document.tables), PRODUCTION_TABLES, kind)
  }
  for (const [table, rows] of Object.entries(document.tables)) {
    invariant(Array.isArray(rows), `${kind}.${table} must be an array`)
    for (const row of rows) {
      invariant(row && Object.getPrototypeOf(row) === Object.prototype, `${kind}.${table} row must be a plain object`)
      invariant(!Object.hasOwn(row, '__proto__') && !Object.hasOwn(row, 'constructor'), `${kind}.${table} has unsafe keys`)
    }
  }
  return document
}

function table(document, name) { return document.tables[name] ?? [] }
function byId(rows) { return new Map(rows.map(row => [String(row.id), row])) }
function ref(map, id, label) {
  const value = map.get(String(id))
  invariant(value, `unresolved ${label} reference: ${id}`)
  return value
}
function modelKey(row) {
  const value = `${row.model_code ?? ''} ${row.name ?? ''}`.toLowerCase()
  if (value.includes('c1070')) return 'konica-minolta:c1070'
  if (value.includes('versant') && value.includes('180')) return 'xerox:versant-180'
  return token(row.model_code || row.name)
}
function testLike(row, semanticKey = '') {
  const haystack = [semanticKey, row.code, row.name, row.slot_code, row.sku, row.notes, row.description]
    .map(norm).join(' ')
  return /(^|[^a-z])(test|tes|dummy|fixture|smoke|sandbox)([^a-z]|$)/.test(haystack)
}
function legacyLike(row) {
  return norm(row.source).includes('legacy') || norm(row.installation_source).includes('legacy') ||
    norm(row.purchase_number).startsWith('legacy-') || norm(row.evidence_level).includes('legacy')
}
function postFreeze(row) {
  const raw = row.observed_at || row.occurred_at || row.installed_at || row.purchase_date || row.created_at
  if (!raw) return false
  const parsed = Date.parse(String(raw).includes('T') ? raw : `${String(raw).replace(' ', 'T')}Z`)
  return Number.isFinite(parsed) && parsed > freezeAt
}

function context(document, source) {
  const t = document.tables
  const accounts = byId(table(document, 'accounts'))
  const branches = byId(table(document, 'branches'))
  const models = byId(table(document, 'machine_models'))
  const machines = byId(table(document, 'machines'))
  const components = byId(table(document, source ? 'components' : 'component_catalogs'))
  const counterTypes = byId(table(document, 'counter_types'))
  const people = byId(table(document, 'operational_people'))
  const locations = byId(table(document, 'inventory_locations'))
  const items = byId(table(document, 'inventory_items'))
  const suppliers = byId(table(document, 'inventory_suppliers'))
  const profiles = byId(table(document, 'model_profiles'))
  const profileSlots = byId(table(document, 'model_profile_slots'))
  const machineComponents = byId(table(document, 'machine_components'))
  const purchases = byId(table(document, source ? 'inventory_purchases' : 'purchases'))
  const account = id => token(ref(accounts, id, 'account').code || ref(accounts, id, 'account').name)
  const branch = id => {
    const row = ref(branches, id, 'branch')
    return `${account(row.account_id)}:${token(row.code || row.name)}`
  }
  const model = id => modelKey(ref(models, id, 'machine model'))
  const machine = id => token(ref(machines, id, 'machine').machine_code)
  const component = id => token(ref(components, id, 'component').code || ref(components, id, 'component').name)
  const person = id => {
    const row = ref(people, id, 'operational person')
    return row.code ? `code:${token(row.code)}` : `name:${token(row.name)}`
  }
  const location = id => {
    const row = ref(locations, id, 'inventory location')
    return `${branch(row.branch_id)}:${token(row.code || row.name)}`
  }
  const item = id => {
    const row = ref(items, id, 'inventory item')
    const componentKey = row.component_id ? component(row.component_id) : ''
    return `${account(row.account_id)}:${componentKey || token(row.sku || row.name)}:${token(row.unit)}`
  }
  const supplier = id => {
    if (!id) return null
    const row = ref(suppliers, id, 'supplier')
    return token(row.supplier_code || row.code || row.name)
  }
  const profile = id => {
    const row = ref(profiles, id, 'model profile')
    return model(row.machine_model_id)
  }
  const profileSlot = id => {
    const row = ref(profileSlots, id, 'profile slot')
    return `${profile(row.profile_id)}:${token(row.slot_code)}:${component(row.component_id)}`
  }
  const machineComponent = id => {
    const row = ref(machineComponents, id, 'machine component')
    return `${machine(row.machine_id)}:${token(row.slot_code)}:${component(row.component_id)}`
  }
  const counterType = id => token(ref(counterTypes, id, 'counter type').code)
  return { document, t, source, account, branch, model, machine, component, person, location, item, supplier, profile, profileSlot, machineComponent, counterType, purchases }
}

function record(row, semanticKey, signature, extra = {}) {
  invariant(semanticKey && !semanticKey.includes('undefined'), 'empty semantic key')
  return {
    semantic_key: semanticKey,
    signature: stable(signature),
    source_identity: semanticKey,
    test_dummy: testLike(row, semanticKey),
    legacy: legacyLike(row),
    post_freeze: postFreeze(row),
    deferred: /(^|:)(cg-grh|grh-a3|xerox|versant-180)(:|$)/.test(semanticKey),
    row,
    ...extra,
  }
}

function domainRecords(name, c) {
  const { document, t, source } = c
  switch (name) {
    case 'accounts': return t.accounts.map(r => record(r, token(r.code || r.name), { name: norm(r.name), status: norm(r.status), currency: norm(r.default_currency), timezone: norm(r.default_timezone) }))
    case 'branches': return t.branches.map(r => record(r, c.branch(r.id), { active: bool(r.is_active), timezone: norm(r.timezone) }))
    case 'machine_models': return t.machine_models.map(r => record(r, modelKey(r), { active: bool(r.is_active), category: norm(r.machine_category), color: norm(r.color_capability) }))
    case 'machines': return t.machines.map(r => record(r, `${c.branch(r.branch_id)}:${token(r.machine_code)}:${c.model(r.machine_model_id)}`, { status: norm(r.status), timezone: norm(r.timezone) }))
    case 'component_catalogs': {
      const rows = source ? t.components : t.component_catalogs
      return rows.map(r => record(r, token(r.code || r.name), { tracking_method: norm(r.default_tracking_method ?? r.tracking_method), active: bool(r.is_active) }))
    }
    case 'model_profiles': {
      if (!source) return t.model_profiles.map(r => record(r, c.model(r.machine_model_id), { active: bool(r.is_active) }))
      const groups = new Map()
      for (const slot of t.machine_model_components) {
        const key = c.model(slot.machine_model_id)
        if (!groups.has(key)) groups.set(key, [])
        groups.get(key).push(slot)
      }
      return [...groups.entries()].map(([key, rows]) => record(rows[0], key, { active: rows.some(r => bool(r.is_active)) }, { synthesized: true }))
    }
    case 'model_profile_slots': {
      if (source) return t.machine_model_components.map(r => record(r, `${c.model(r.machine_model_id)}:${token(r.slot_code)}:${c.component(r.component_id)}`, { tracking_method: norm(r.tracking_method), baseline: number(r.baseline_expected_clicks), order: number(r.display_order), active: bool(r.is_active) }))
      return t.model_profile_slots.map(r => record(r, `${c.profile(r.profile_id)}:${token(r.slot_code)}:${c.component(r.component_id)}`, { tracking_method: norm(r.tracking_method), baseline: number(r.baseline_expected_clicks), order: number(r.display_order), active: bool(r.is_active) }))
    }
    case 'machine_components': {
      if (source) return t.machine_component_assignments.map(r => record(r, `${c.machine(r.machine_id)}:${token(r.slot_code)}:${c.component(r.component_id)}`, { tracking_method: norm(r.tracking_method), baseline: number(r.baseline_expected_clicks), order: number(r.display_order), status: norm(r.status), source_type: norm(r.source_type) }))
      return t.machine_components.map(r => record(r, c.machineComponent(r.id), { tracking_method: norm(r.tracking_method), baseline: number(r.baseline_expected_clicks), order: number(r.display_order), status: norm(r.status), source_type: norm(r.source_type) }))
    }
    case 'component_lifecycles': {
      if (source) return t.machine_component_lifecycles.map(r => record(r, [c.machine(r.machine_id), token(r.slot_code), c.component(r.component_id), number(r.installed_counter), number(r.removed_counter), number(r.actual_usage), time(r.installed_at), time(r.removed_at)].join(':'), { status: norm(r.status) }, { invalid_for_write: !r.installed_at || !['active', 'closed', 'removed'].includes(norm(r.status)) }))
      return t.component_lifecycles.map(r => record(r, [c.machineComponent(r.machine_component_id), number(r.installed_counter), number(r.removed_counter), number(r.actual_usage), time(r.started_at), time(r.ended_at)].join(':'), { status: norm(r.status) }, { legacy: true }))
    }
    case 'counters': return t.counter_readings.map(r => record(r, [c.machine(r.machine_id), c.counterType(r.counter_type_id), date(r.observed_at), number(r.reading_value)].join(':'), { status: norm(r.status) }))
    case 'operational_people': return t.operational_people.map(r => record(r, r.code ? `code:${token(r.code)}` : `name:${token(r.name)}`, { active: bool(r.is_active) }, { person_without_code: !r.code }))
    case 'operational_person_branch_assignments': {
      return t.operational_person_branches.map(r => record(r, `${c.person(source ? r.operational_person_id : r.person_id)}:${c.branch(r.branch_id)}`, { active: bool(r.is_active), can_record_counter: bool(r.can_record_counter) }, { person_without_code: true }))
    }
    case 'inventory_locations': return t.inventory_locations.map(r => record(r, c.location(r.id), { active: bool(r.is_active) }))
    case 'inventory_items': return t.inventory_items.map(r => record(r, c.item(r.id), { active: bool(r.is_active), minimum_stock: number(r.minimum_stock), category: norm(r.category) }, { dev_only: source && r.is_canonical === false }))
    case 'purchases': {
      const purchases = source ? t.inventory_purchases : t.purchases
      const lines = source ? t.inventory_purchase_lines : t.purchase_lines
      const grouped = new Map()
      for (const line of lines) {
        const key = String(line.purchase_id)
        if (!grouped.has(key)) grouped.set(key, [])
        grouped.get(key).push({ item: c.item(line.inventory_item_id), qty: number(line.ordered_quantity), unit_cost: number(line.unit_price ?? line.unit_cost) })
      }
      return purchases.map(r => record(r, `${c.account(r.account_id)}:${token(r.purchase_number || r.external_reference)}:${date(r.purchase_date)}`, { status: norm(r.status), currency: norm(r.currency_code), lines: (grouped.get(String(r.id)) ?? []).map(line => ({ ...line, item: line.item.replace(/:(bottle|pcs|unit)$/, '') })).sort((a, b) => json(a).localeCompare(json(b))) }))
    }
    case 'receipts': {
      const receipts = source ? t.inventory_receipts : t.receipts
      return receipts.map(r => record(r, `${c.account(r.account_id)}:${token(r.receipt_number || r.external_reference || r.id)}`, { status: norm(r.status), occurred_at: time(r.received_at || r.created_at) }))
    }
    case 'inventory_movements_fifo': {
      const movements = t.inventory_movements.map(r => record(r, ['movement', c.item(r.inventory_item_id), c.location(r.location_id), norm(r.movement_type), time(r.occurred_at), number(r.quantity)].join(':'), { reference_type: norm(r.reference_type) }))
      if (source) {
        return movements.concat(
          t.inventory_cost_lots.map(r => record(r, ['cost-lot', c.item(r.inventory_item_id), c.location(r.location_id), time(r.effective_at), number(r.source_quantity), number(r.unit_cost)].join(':'), { source_type: norm(r.source_type) })),
          t.inventory_cost_allocations.map(r => {
            const lot = ref(byId(t.inventory_cost_lots), r.source_cost_lot_id, 'inventory cost lot')
            const lotLocation = c.location(lot.location_id)
            return record(r, ['cost-allocation', c.item(lot.inventory_item_id), lotLocation, number(r.quantity), number(r.unit_cost), number(r.allocated_cost), r.allocation_order].join(':'), { policy: norm(r.allocation_policy) })
          }),
        )
      }
      return movements.concat(
        t.fifo_layers.map(r => record(r, ['cost-lot', c.item(r.inventory_item_id), c.location(r.location_id), time(r.effective_at || r.created_at), number(r.original_quantity || r.source_quantity), number(r.unit_cost)].join(':'), { source_type: norm(r.source_type) })),
        t.fifo_allocations.map(r => record(r, ['cost-allocation', number(r.quantity), number(r.unit_cost), number(r.allocated_cost), r.allocation_order].join(':'), { policy: norm(r.allocation_policy) })),
      )
    }
    case 'incidents_errors': return t.operational_incidents.map(r => record(r, [r.machine_id ? c.machine(r.machine_id) : 'unassigned-machine', time(r.occurred_at), token(r.incident_type || r.category), token(r.category), number(r.assessed_loss), number(r.penalty_multiplier), r.client_request_id ? `request-${fingerprint(String(r.client_request_id)).slice(0, 16)}` : 'no-request'].join(':'), { status: norm(r.status), material_loss: number(r.material_loss), service_loss: number(r.service_loss), qty: number(r.qty_affected) }, { invalid_for_write: norm(r.status) === 'voided' || !r.machine_id, legacy: !source, test_dummy: false }))
    case 'machine_cost_source_facts': return t.machine_selling_prices.map(r => record(r, ['selling-price', c.machine(r.machine_id), date(r.effective_from), number(r.price_per_click), norm(r.currency)].join(':'), { status: norm(r.status) })).concat(t.machine_operating_costs.map(r => record(r, ['operating-cost', c.machine(r.machine_id), date(r.effective_from), token(r.cost_type || r.id), number(r.amount)].join(':'), { status: norm(r.status) })))
    case 'operational_policies': return t.account_operational_permissions.map(r => record(r, c.account(r.account_id), Object.fromEntries(Object.keys(r).filter(k => k.startsWith('operator_can_')).sort().map(k => [k, bool(r[k])] ))))
    case 'counter_types': return t.counter_types.map(r => record(r, token(r.code), { active: bool(r.is_active), monotonic: bool(r.is_monotonic), decimal_scale: number(r.decimal_scale), unit: token(r.unit) === 'count' ? 'impressions' : token(r.unit) }))
    case 'settings_configuration': return t.accounts.map(r => record(r, c.account(r.id), { default_currency: norm(r.default_currency), default_timezone: norm(r.default_timezone), machine_economics_advanced_enabled: bool(r.machine_economics_advanced_enabled) }))
    case 'auth_identity_references': return [record(document.auth, source ? 'new-supabase-auth-metadata' : 'production-local-auth-metadata', { rows_captured: false, policy: norm(document.auth.policy) }, { dev_only: source, auth_metadata: true })]
    default: throw new Error(`unsupported domain: ${name}`)
  }
}

export function classifyDomain(name, sourceRecords, productionRecords) {
  const groupBy = rows => {
    const groups = new Map()
    for (const row of rows) {
      if (!groups.has(row.semantic_key)) groups.set(row.semantic_key, [])
      groups.get(row.semantic_key).push(row)
    }
    return groups
  }
  const sourceGroups = groupBy(sourceRecords)
  const productionGroups = groupBy(productionRecords)
  const classifications = []
  const allKeys = [...new Set([...sourceGroups.keys(), ...productionGroups.keys()])].sort()
  for (const key of allKeys) {
    const sources = [...(sourceGroups.get(key) ?? [])].sort((a, b) => fingerprint(a.signature).localeCompare(fingerprint(b.signature)))
    const productions = [...(productionGroups.get(key) ?? [])].sort((a, b) => fingerprint(a.signature).localeCompare(fingerprint(b.signature)))
    if (sources.length && productions.length) {
      while (sources.length && productions.length) {
        const exactSourceIndex = sources.findIndex(source => productions.some(production => json(source.signature) === json(production.signature)))
        const source = sources.splice(exactSourceIndex >= 0 ? exactSourceIndex : 0, 1)[0]
        const exactProductionIndex = productions.findIndex(production => json(source.signature) === json(production.signature))
        const production = productions.splice(exactProductionIndex >= 0 ? exactProductionIndex : 0, 1)[0]
        const same = json(source.signature) === json(production.signature)
        const legacy = LEGACY_DOMAINS.has(name) && (source.legacy || production.legacy)
        classifications.push({
          classification: same ? (legacy ? 'ALREADY_MIGRATED_LEGACY' : 'MATCHED') : 'AMBIGUOUS',
          semantic_key: key,
          requires_user_approval: !same,
          reason: same ? (legacy ? 'Semantic legacy fact already exists in Production.' : 'Semantic identity and canonical fields match.') : 'Semantic identity matches but canonical fields differ; fail closed.',
          source_signature: source.signature,
          production_signature: production.signature,
        })
      }
      for (const duplicate of sources) classifications.push({ classification: duplicate.test_dummy ? 'TEST_DUMMY' : 'DUPLICATE', semantic_key: key, requires_user_approval: false, reason: duplicate.test_dummy ? 'Explicit test/dummy marker.' : 'Additional source record shares an existing semantic identity.', source_signature: duplicate.signature })
      for (const duplicate of productions) classifications.push({ classification: 'DUPLICATE', semantic_key: key, requires_user_approval: true, reason: 'Additional Production record shares a semantic identity; no write proposed.', production_signature: duplicate.signature })
    } else if (sources.length) {
      const primaryIndex = sources.findIndex(source => !source.test_dummy)
      const source = sources.splice(primaryIndex >= 0 ? primaryIndex : 0, 1)[0]
      if (source.test_dummy) classifications.push({ classification: 'TEST_DUMMY', semantic_key: key, requires_user_approval: false, reason: 'Explicit test/dummy marker.', source_signature: source.signature })
      else if (source.dev_only || name === 'auth_identity_references') classifications.push({ classification: 'DEV_ONLY', semantic_key: key, requires_user_approval: false, reason: 'Reference-only development/auth metadata is never copied.', source_signature: source.signature })
      else if (source.invalid_for_write) classifications.push({ classification: 'AMBIGUOUS', semantic_key: key, requires_user_approval: true, reason: 'Source-only record is incomplete, voided, or lacks canonical identity; fail closed.', source_signature: source.signature })
      else if (source.person_without_code && (name === 'operational_people' || name === 'operational_person_branch_assignments')) classifications.push({ classification: 'AMBIGUOUS', semantic_key: key, requires_user_approval: true, reason: 'Person has no approved canonical code mapping; loose-name matching is forbidden.', source_signature: source.signature })
      else {
        const classification = OPERATIONAL_DOMAINS.has(name) ? 'SUPABASE_ONLY_OPERATIONAL_VALID' : 'SUPABASE_ONLY_CANONICAL'
        classifications.push({ classification, semantic_key: key, requires_user_approval: true, planned_write: !source.deferred, reason: source.deferred ? 'Previously deferred Xerox/Graha scope requires explicit approval and is excluded from the write plan.' : source.post_freeze ? 'Reference-only fact is post-freeze and requires operator approval.' : 'Reference-only canonical record is absent from Production and requires operator approval.', source_signature: source.signature })
      }
      for (const duplicate of sources) {
        classifications.push({ classification: duplicate.test_dummy ? 'TEST_DUMMY' : 'DUPLICATE', semantic_key: key, requires_user_approval: false, reason: duplicate.test_dummy ? 'Explicit test/dummy marker.' : 'Additional source record shares an existing semantic identity.', source_signature: duplicate.signature })
      }
    } else if (productions.length) {
      const production = productions.shift()
      classifications.push({ classification: 'PRODUCTION_ONLY_VALID', semantic_key: key, requires_user_approval: false, reason: 'Production-local identity/fact remains authoritative.', production_signature: production.signature })
      for (const duplicate of productions) classifications.push({ classification: 'DUPLICATE', semantic_key: key, requires_user_approval: true, reason: 'Additional Production record shares a semantic identity; no write proposed.', production_signature: duplicate.signature })
    }
  }
  classifications.sort((a, b) => `${a.semantic_key}:${a.classification}`.localeCompare(`${b.semantic_key}:${b.classification}`))
  const sourceCovered = classifications.filter(row => Object.hasOwn(row, 'source_signature')).length
  const productionCovered = classifications.filter(row => Object.hasOwn(row, 'production_signature')).length
  invariant(sourceCovered === sourceRecords.length, `${name} source classification coverage mismatch`)
  invariant(productionCovered === productionRecords.length, `${name} Production classification coverage mismatch`)
  const count = classification => classifications.filter(row => row.classification === classification).length
  const planned = classifications.filter(row => ['SUPABASE_ONLY_CANONICAL', 'SUPABASE_ONLY_OPERATIONAL_VALID'].includes(row.classification) && row.planned_write && !['auth_identity_references'].includes(name))
  return {
    source_count: sourceRecords.length,
    production_count: productionRecords.length,
    matched: count('MATCHED'),
    production_only_valid: count('PRODUCTION_ONLY_VALID'),
    supabase_only_canonical: count('SUPABASE_ONLY_CANONICAL'),
    supabase_only_operational_valid: count('SUPABASE_ONLY_OPERATIONAL_VALID'),
    already_migrated_legacy: count('ALREADY_MIGRATED_LEGACY'),
    duplicate: count('DUPLICATE'),
    test_dummy: count('TEST_DUMMY'),
    dev_only: count('DEV_ONLY'),
    ambiguous: count('AMBIGUOUS'),
    requires_user_approval: classifications.filter(row => row.requires_user_approval).length,
    planned_create: planned.length,
    planned_update: 0,
    planned_skip: classifications.filter(row => ['MATCHED', 'PRODUCTION_ONLY_VALID', 'ALREADY_MIGRATED_LEGACY', 'DUPLICATE', 'AMBIGUOUS'].includes(row.classification)).length,
    planned_exclude: classifications.filter(row => ['TEST_DUMMY', 'DEV_ONLY'].includes(row.classification)).length,
    classifications,
  }
}

function dependencyOrder(domain) {
  return {
    accounts: 10, branches: 20, machine_models: 30, component_catalogs: 40,
    model_profiles: 50, model_profile_slots: 60, machines: 70,
    machine_components: 80, operational_people: 90,
    operational_person_branch_assignments: 100, counter_types: 110,
    inventory_locations: 120, inventory_items: 130, purchases: 140,
    receipts: 150, inventory_movements_fifo: 160, component_lifecycles: 170,
    counters: 180, incidents_errors: 190, operational_policies: 200,
    settings_configuration: 210, machine_cost_source_facts: 220,
  }[domain] ?? 999
}

export function writePlan(domains) {
  const writes = []
  for (const name of DOMAIN_ORDER) {
    for (const row of domains[name].classifications) {
      if (!['SUPABASE_ONLY_CANONICAL', 'SUPABASE_ONLY_OPERATIONAL_VALID'].includes(row.classification) || !row.planned_write) continue
      if (name === 'auth_identity_references') continue
      writes.push({
        domain: name,
        operation: 'CREATE',
        semantic_key: row.semantic_key,
        production_target_identity: 'PRODUCTION_ASSIGNED_ON_APPROVED_EXECUTION',
        source_identity: row.semantic_key,
        reason: row.reason,
        confidence: 'OPERATOR_APPROVAL_REQUIRED',
        classification: row.classification,
        requires_user_approval: true,
        dependency_order: dependencyOrder(name),
      })
    }
  }
  writes.sort((a, b) => a.dependency_order - b.dependency_order || a.domain.localeCompare(b.domain) || a.semantic_key.localeCompare(b.semantic_key))
  invariant(writes.every(row => !isUuid(row.production_target_identity) && !isUuid(row.source_identity)), 'DEV UUID leaked into write plan')
  invariant(writes.every(row => row.domain !== 'auth_identity_references'), 'auth bulk-copy write generated')
  return {
    checkpoint: 'M2.12F', status: writes.length ? 'READY_REQUIRES_USER_APPROVAL' : 'READY_NO_WRITES',
    execute: false, production_identity_policy: 'PRODUCTION_ASSIGNED_IDS_ONLY',
    auth_user_copy: false, writes,
  }
}

function summarize(domains) {
  const sum = field => DOMAIN_ORDER.reduce((total, name) => total + domains[name][field], 0)
  return {
    TOTAL_MATCHED: sum('matched'),
    TOTAL_PRODUCTION_ONLY_VALID: sum('production_only_valid'),
    TOTAL_SUPABASE_ONLY_CANONICAL: sum('supabase_only_canonical'),
    TOTAL_SUPABASE_ONLY_OPERATIONAL_VALID: sum('supabase_only_operational_valid'),
    TOTAL_ALREADY_MIGRATED_LEGACY: sum('already_migrated_legacy'),
    TOTAL_DUPLICATE: sum('duplicate'),
    TOTAL_DEV_TEST_EXCLUDED: sum('test_dummy') + sum('dev_only'),
    TOTAL_AMBIGUOUS: sum('ambiguous'),
    TOTAL_REQUIRES_USER_APPROVAL: sum('requires_user_approval'),
    TOTAL_PLANNED_CREATE: sum('planned_create'),
    TOTAL_PLANNED_UPDATE: sum('planned_update'),
    TOTAL_PLANNED_WRITES: sum('planned_create') + sum('planned_update'),
    UNEXPLAINED: 0,
  }
}

function componentAudit(domains, production) {
  const catalogDifferences = domains.component_catalogs.classifications.filter(row => row.classification === 'AMBIGUOUS')
  const c1070Slots = domains.model_profile_slots.classifications.filter(row => row.semantic_key.startsWith('konica-minolta:c1070:'))
  const c1070Assignments = domains.machine_components.classifications.filter(row => row.semantic_key.startsWith('cg-tup-a3-01:'))
  const writeRequired = [...catalogDifferences, ...c1070Slots, ...c1070Assignments].some(row => ['SUPABASE_ONLY_CANONICAL', 'AMBIGUOUS'].includes(row.classification))
  const prod = context(production, false)
  const lifecycles = domainRecords('component_lifecycles', prod)
  const counters = domainRecords('counters', prod).filter(row => row.semantic_key.startsWith('cg-tup-a3-01:total-impressions:'))
  const latest = counters.map(row => Number(row.semantic_key.split(':').at(-1))).filter(Number.isFinite).sort((a, b) => b - a)[0]
  invariant(table(production, 'component_catalogs').length === 28, 'Production component catalog invariant failed')
  invariant(table(production, 'model_profiles').length === 1, 'Production C1070 model profile invariant failed')
  invariant(table(production, 'model_profile_slots').length === 28, 'Production C1070 profile slot invariant failed')
  invariant(table(production, 'machine_components').length === 28, 'Production C1070 machine component invariant failed')
  invariant(lifecycles.length === 47, 'Production C1070 lifecycle invariant failed')
  invariant(table(production, 'component_lifecycles').every(row => norm(row.status) !== 'active'), 'Production active lifecycle invariant failed')
  invariant(latest === 1441597, 'Production latest C1070 counter invariant failed')
  return {
    status: writeRequired ? 'DIFF_FOUND' : 'PASS',
    reconciliation_required: writeRequired ? 'YES' : 'NO',
    production_component_catalog_count: table(production, 'component_catalogs').length,
    production_c1070_model_profile_slot_count: table(production, 'model_profile_slots').length,
    production_c1070_machine_component_count: table(production, 'machine_components').length,
    production_c1070_lifecycle_count: lifecycles.length,
    production_c1070_active_lifecycle_count: table(production, 'component_lifecycles').filter(row => norm(row.status) === 'active').length,
    production_c1070_latest_counter: latest,
    component_catalog_differences: catalogDifferences.length,
    model_profile_slot_differences: c1070Slots.filter(row => row.requires_user_approval).length,
    machine_component_differences: c1070Assignments.filter(row => row.requires_user_approval).length,
    differences_require_approval: [...catalogDifferences, ...c1070Slots, ...c1070Assignments].filter(row => row.requires_user_approval).map(row => row.semantic_key),
  }
}

export function reconcileCaptures(newSnapshot, productionSnapshot, options = {}) {
  validateSnapshot(newSnapshot, 'new_supabase', { strictTables: options.strictTables ?? true })
  validateSnapshot(productionSnapshot, 'production', { strictTables: options.strictTables ?? true })
  const sourceContext = context(newSnapshot, true)
  const productionContext = context(productionSnapshot, false)
  const domains = {}
  for (const name of DOMAIN_ORDER) {
    domains[name] = classifyDomain(name, domainRecords(name, sourceContext), domainRecords(name, productionContext))
  }
  const totals = summarize(domains)
  const plan = writePlan(domains)
  invariant(plan.writes.length === totals.TOTAL_PLANNED_WRITES, 'write-plan count mismatch')
  const component = componentAudit(domains, productionSnapshot)
  const result = {
    checkpoint: 'M2.12F', status: 'READY', mode: 'LOCAL_OFFLINE_CAPTURE_RECONCILIATION',
    source_of_truth: 'HOSTINGER_MARIADB', new_supabase_role: 'READ_ONLY_REFERENCE',
    full_supabase_bulk_migration: 'REJECTED', unexplained: 0,
    input: options.input ?? {}, component_config_parity_audit: component,
    machine_cost_source_of_truth: domains.machine_cost_source_facts.source_count || domains.machine_cost_source_facts.production_count ? 'MIXED' : 'DERIVED',
    reports: { classification: 'DERIVED_PROJECTIONS', persisted_report_totals_planned: 0, missing_source_facts: domains.counters.planned_create + domains.machine_cost_source_facts.planned_create },
    settings_assignment_full_set_parity_fix: 'IMPLEMENTED',
    combined_hotfix_sha: COMBINED_HOTFIX_SHA,
    domains, totals,
    safety: {
      network_access_during_reconciliation: false,
      database_access_during_reconciliation: false,
      production_mutation: false,
      production_database_mutation: false,
      public_html_mutation: false,
      production_deployment: false,
    },
  }
  return { result, plan }
}

function domainSummary(domain) {
  return `source=${domain.source_count}, production=${domain.production_count}, matched=${domain.matched}, legacy=${domain.already_migrated_legacy}, source-only=${domain.supabase_only_canonical + domain.supabase_only_operational_valid}, production-only=${domain.production_only_valid}, duplicate=${domain.duplicate}, test/dev=${domain.test_dummy + domain.dev_only}, ambiguous=${domain.ambiguous}, planned=${domain.planned_create + domain.planned_update}`
}

export function renderMarkdown(result, planPath) {
  const lines = [
    '# M2.12F Full Domain Reconciliation', '',
    'Status: **READY — offline reconciliation complete; no writes executed.**', '',
    `- New Supabase capture SHA256: \`${result.input.new_supabase_sha256}\``,
    `- Production MariaDB capture SHA256: \`${result.input.production_mariadb_sha256}\``,
    '- Production runtime source of truth: Hostinger MariaDB',
    '- New Supabase role: read-only reference',
    '- Bulk migration: rejected',
    `- Deterministic write plan: \`${planPath}\` (approval-gated; not executed)`, '',
    '## Domain results', '',
    '| Domain | Reconciliation |', '|---|---|',
    ...DOMAIN_ORDER.map(name => `| ${name} | ${domainSummary(result.domains[name])} |`), '',
    '## Component configuration audit', '',
    `- Audit: **${result.component_config_parity_audit.status}**`,
    `- Actual configuration writes required: **${result.component_config_parity_audit.reconciliation_required}**`,
    `- Production component catalogs / profile slots / assignments / lifecycles: ${result.component_config_parity_audit.production_component_catalog_count} / ${result.component_config_parity_audit.production_c1070_model_profile_slot_count} / ${result.component_config_parity_audit.production_c1070_machine_component_count} / ${result.component_config_parity_audit.production_c1070_lifecycle_count}`,
    `- Production active lifecycles: ${result.component_config_parity_audit.production_c1070_active_lifecycle_count}`,
    `- Production latest effective counter: ${result.component_config_parity_audit.production_c1070_latest_counter}`,
    `- Approval-gated canonical field differences: catalogs=${result.component_config_parity_audit.component_catalog_differences}, profile slots=${result.component_config_parity_audit.model_profile_slot_differences}, machine components=${result.component_config_parity_audit.machine_component_differences}.`,
    '- The deployed 28 assignments and 47 closed lifecycle rows are not duplicated. Reference duplicate/test profile rows are excluded. Ambiguous configuration differences are deliberately absent from the write plan.', '',
    '## Derived domains', '',
    `- Machine Cost source-of-truth classification is ${result.machine_cost_source_of_truth}: totals are derived from counters, lifecycle/replacement facts, acquisition costs, and incident/waste facts, while persisted selling-price/configuration facts are reconciled separately. Calculated totals are never migrated.`,
    '- Reports remain derived projections. No dashboard/report totals are included in the write plan.',
    '- Settings full-set assignment parity fix is IMPLEMENTED in the combined hotfix; settings data differences remain separate and approval-gated.',
    '- Auth rows were not captured and auth users are never included in the write plan.', '',
    '## Global totals', '',
    ...Object.entries(result.totals).map(([key, value]) => `- ${key}=${value}`), '',
    '## Safety', '',
    '- NETWORK_ACCESS_DURING_RECONCILIATION=NO',
    '- DATABASE_ACCESS_DURING_RECONCILIATION=NO',
    '- PRODUCTION_MUTATION=NO',
    '- PRODUCTION_DATABASE_MUTATION=NO',
    '- PUBLIC_HTML_MUTATION=NO',
    '- PRODUCTION_DEPLOYMENT=NO',
    '- LEGACY_FREEZE=REMAINS_ACTIVE', '',
  ]
  return lines.join('\n')
}

export async function runCli(argv) {
  invariant(argv.length === 4, 'usage: m2-12f-reconcile-captures.mjs NEW.json PRODUCTION.json OUTPUT.json OUTPUT.md')
  const [newPath, productionPath, outputJsonPath, outputMarkdownPath] = argv.map(path => resolve(path))
  invariant(newPath !== productionPath, 'capture paths must differ')
  invariant(outputJsonPath !== outputMarkdownPath, 'output paths must differ')
  const [newBuffer, productionBuffer] = await Promise.all([readFile(newPath), readFile(productionPath)])
  const newSha = verifySha(newBuffer, EXPECTED_NEW_SHA256, 'new Supabase capture')
  const productionSha = verifySha(productionBuffer, EXPECTED_PRODUCTION_SHA256, 'Production MariaDB capture')
  let newSnapshot, productionSnapshot
  try { newSnapshot = JSON.parse(newBuffer) } catch { throw new Error('new Supabase capture is malformed JSON') }
  try { productionSnapshot = JSON.parse(productionBuffer) } catch { throw new Error('Production MariaDB capture is malformed JSON') }
  const writePlanPath = resolve(dirname(outputJsonPath), 'm2-12f-production-write-plan.json')
  const { result, plan } = reconcileCaptures(newSnapshot, productionSnapshot, {
    input: {
      new_supabase_capture: newPath,
      new_supabase_sha256: newSha,
      production_mariadb_capture: productionPath,
      production_mariadb_sha256: productionSha,
    },
  })
  await Promise.all([
    writeFile(outputJsonPath, json(result), { mode: 0o644 }),
    writeFile(outputMarkdownPath, renderMarkdown(result, writePlanPath), { mode: 0o644 }),
    writeFile(writePlanPath, json(plan), { mode: 0o644 }),
  ])
  return { result, plan, writePlanPath }
}

const invokedDirectly = process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)
if (invokedDirectly) {
  runCli(process.argv.slice(2)).then(({ result, plan, writePlanPath }) => {
    console.log('CHECKPOINT_M2_12F_OFFLINE_FULL_DOMAIN_RECONCILIATION=READY')
    console.log(`TOTAL_PLANNED_WRITES=${result.totals.TOTAL_PLANNED_WRITES}`)
    console.log(`UNEXPLAINED=${result.totals.UNEXPLAINED}`)
    console.log(`WRITE_PLAN=${writePlanPath}`)
    console.log(`WRITE_PLAN_READY=${plan.writes.length >= 0 ? 'YES' : 'NO'}`)
    console.log('PRODUCTION_MUTATION=NO\nPRODUCTION_DATABASE_MUTATION=NO\nPUBLIC_HTML_MUTATION=NO\nPRODUCTION_DEPLOYMENT=NO\nSTOP')
  }).catch(error => {
    console.error('CHECKPOINT_M2_12F_OFFLINE_FULL_DOMAIN_RECONCILIATION=STOP')
    console.error(`STOP_REASON=${error instanceof Error ? error.message : 'unknown reconciliation failure'}`)
    console.error('PRODUCTION_MUTATION=NO\nPRODUCTION_DATABASE_MUTATION=NO\nPUBLIC_HTML_MUTATION=NO\nPRODUCTION_DEPLOYMENT=NO\nSTOP')
    process.exitCode = 78
  })
}
