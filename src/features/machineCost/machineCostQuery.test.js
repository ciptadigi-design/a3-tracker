import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { machineCostQuery } from './machineCostQuery.js'

// --- HTTP/request-construction boundary: the literal query string sent to
// the machine-cost endpoint, not just the pure period-resolution helper. ---

test('a valid resolved period produces a clean period_start/period_end query with no other params', () => {
  const query = machineCostQuery('2026-08-01', '2026-08-31')
  assert.equal(query, 'period_start=2026-08-01&period_end=2026-08-31')
  const params = new URLSearchParams(query)
  assert.equal(params.get('period_start'), '2026-08-01')
  assert.equal(params.get('period_end'), '2026-08-31')
  assert.equal([...params.keys()].length, 2)
})

test('an undefined period (the exact missing-argument regression) throws instead of serializing to the literal string "undefined"', () => {
  assert.throws(() => machineCostQuery(undefined, undefined), /canonical YYYY-MM-DD/)
  // Never allow the historical failure mode: a request URL containing the word "undefined".
  try { machineCostQuery(undefined, undefined) } catch { /* expected */ }
})

test('an ISO timestamp (Date#toISOString shape) is rejected, not forwarded', () => {
  assert.throws(() => machineCostQuery('2026-08-01T00:00:00.000Z', '2026-08-31T00:00:00.000Z'), /canonical YYYY-MM-DD/)
})

test('a locale-formatted date string is rejected, not forwarded', () => {
  assert.throws(() => machineCostQuery('08/01/2026', '08/31/2026'), /canonical YYYY-MM-DD/)
})

test('a raw millisecond timestamp is rejected, not forwarded', () => {
  assert.throws(() => machineCostQuery(1785628800000, 1788307200000), /canonical YYYY-MM-DD/)
})

test('a Date object is rejected, not forwarded as [object Object] or its string coercion', () => {
  assert.throws(() => machineCostQuery(new Date('2026-08-01'), new Date('2026-08-31')), /canonical YYYY-MM-DD/)
})

test('only one period_start and one period_end key ever appear — no duplicated period fields', () => {
  const query = machineCostQuery('2026-09-01', '2026-09-04')
  assert.equal((query.match(/period_start=/g) ?? []).length, 1)
  assert.equal((query.match(/period_end=/g) ?? []).length, 1)
})

// --- Source-shape assertions: every caller of the machine-cost endpoint
// must route through the same guarded query builder — no call site may
// bypass it and hand-roll its own period_start/period_end string again. ---

const service = readFileSync(new URL('../../services/laravel/machineCost.js', import.meta.url), 'utf8')
const page = readFileSync(new URL('../../pages/MachineCostPage.jsx', import.meta.url), 'utf8')

test('loadMachineCostPeriod builds its query exclusively through machineCostQuery', () => {
  const implementation = service.match(/export async function loadMachineCostPeriod[\s\S]*?\n\}/)?.[0] ?? ''
  assert.match(implementation, /machineCostQuery\(periodStart, periodEnd\)/)
  assert.doesNotMatch(implementation, /period_start=\$\{/)
})

test('loadMachineOperatingCosts and loadMachineSellingPrices are thin wrappers over the same guarded loader (no second URL construction)', () => {
  const costs = service.match(/export async function loadMachineOperatingCosts[^\n]+/)?.[0] ?? ''
  const prices = service.match(/export async function loadMachineSellingPrices[^\n]+/)?.[0] ?? ''
  assert.match(costs, /loadMachineCostPeriod\(args\)/)
  assert.match(prices, /loadMachineCostPeriod\(args\)/)
})

test('MachineCostPage forwards the same resolved period to every machine-cost call site (summary, operating costs, selling prices)', () => {
  const calls = [...page.matchAll(/load(MachineCostPeriod|MachineOperatingCosts|MachineSellingPrices)\(\{[^}]+\}\)/g)].map((m) => m[0])
  assert.equal(calls.length, 3, 'expected exactly the summary, operating-costs, and selling-prices call sites')
  for (const call of calls) {
    assert.match(call, /periodStart: resolvedPeriod\.start/, `missing periodStart in: ${call}`)
    assert.match(call, /periodEnd: resolvedPeriod\.end/, `missing periodEnd in: ${call}`)
  }
})

test('the operating-costs and selling-prices refreshers are gated on validPeriod, matching the summary refresher', () => {
  const refreshCosts = page.match(/const refreshCosts = useCallback\(async \(\) => \{[^}]+\}/)?.[0] ?? ''
  const refreshPrices = page.match(/const refreshSellingPrices = useCallback\(async \(\) => \{[^}]+\}/)?.[0] ?? ''
  assert.match(refreshCosts, /!validPeriod/)
  assert.match(refreshPrices, /!validPeriod/)
})
