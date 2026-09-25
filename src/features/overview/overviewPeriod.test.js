import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { createOverviewRequestGate, overviewContextTimezone, overviewInitialPeriod, overviewPeriod, overviewRangeError } from './overviewPeriod.js'
import { machineCostPeriodPresets, resolveMachineCostPeriod } from '../machineCost/machineCostPeriods.js'
import { createUIStateKey } from '../uiState/uiStateKeys.js'

const now = new Date('2026-09-13T12:00:00Z')
const account = { id: 'a', default_timezone: 'UTC' }
const page = readFileSync(new URL('../../pages/OverviewPage.jsx', import.meta.url), 'utf8')

test('O01 default is This Month and the six shared options are exact', () => {
  assert.equal(overviewInitialPeriod.preset, 'this_month')
  assert.deepEqual(machineCostPeriodPresets.map((p) => p.label), ['Today', 'This Week', 'This Month', 'Last Month', 'This Year', 'Custom Range'])
})
for (const [preset, start, end] of [
  ['today', '2026-09-13', '2026-09-13'],
  ['this_week', '2026-09-07', '2026-09-13'],
  ['this_month', '2026-09-01', '2026-09-13'],
  ['last_month', '2026-08-01', '2026-08-31'],
  ['this_year', '2026-01-01', '2026-09-13'],
  ['custom', '2026-08-31', '2026-09-02'],
]) test(`O02–O07 ${preset} resolves identically to Machine Cost/Reports`, () => {
  const filters = { ...overviewInitialPeriod, preset, customStart: start, customEnd: end }
  assert.deepEqual(overviewPeriod(filters, account, null, now), { start, end })
  assert.deepEqual(overviewPeriod(filters, account, null, now), resolveMachineCostPeriod({ ...filters, timezone: 'UTC', now }))
})

test('O08–O09 invalid/reversed/impossible dates and spans over Reports maximum are rejected', () => {
  for (const [start, end] of [['', ''], ['2026-02-30', '2026-03-02'], ['2026-09-02', '2026-09-01'], ['2026-01-01', '2027-01-03'], ['bad', '2026-01-01']]) assert.ok(overviewRangeError({ start, end }))
  assert.equal(overviewRangeError({ start: '2026-01-01', end: '2027-01-02' }), null)
})

test('O12–O13 preset recomputes across account/branch midnight and week/month/year boundaries', () => {
  const instant = new Date('2027-01-01T00:30:00Z')
  const filters = { ...overviewInitialPeriod, preset: 'today' }
  assert.deepEqual(overviewPeriod(filters, account, null, instant), { start: '2027-01-01', end: '2027-01-01' })
  const west = { timezone: 'America/Los_Angeles' }
  assert.deepEqual(overviewPeriod(filters, account, west, instant), { start: '2026-12-31', end: '2026-12-31' })
  assert.deepEqual(overviewPeriod(filters, { default_timezone: west.timezone }, null, instant), overviewPeriod(filters, account, west, instant))
  assert.equal(overviewContextTimezone({}, {}), 'UTC')
  assert.equal(overviewPeriod({ preset: 'this_week' }, account, west, instant).start, '2026-12-28')
  const key = (accountId) => createUIStateKey({ userId: 'u', accountId, feature: 'overview-period', entityId: 'workspace' })
  assert.notEqual(key('a'), key('b'))
})

const deferred = () => { let resolve; let reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no }); return { promise, resolve, reject } }
for (const staleError of [false, true]) test(`O14 old-context ${staleError ? 'failure' : 'success'} cannot replace new data`, async () => {
  const gate = createOverviewRequestGate(); const old = deferred(); const next = deferred(); const commits = []
  const first = gate.run(() => old.promise, (result) => commits.push(result))
  gate.invalidate()
  const second = gate.run(() => next.promise, (result) => commits.push(result))
  next.resolve({ projection: 'new', costSummary: 'new' }); await second
  if (staleError) old.reject(new Error('old context')); else old.resolve({ projection: 'old' })
  await first
  assert.deepEqual(commits, [{ data: { projection: 'new', costSummary: 'new' }, error: null }])
})

test('O10–O11 both metric sources settle before the single commit; invalidation cancels pending commits', async () => {
  const gate = createOverviewRequestGate(); const projection = deferred(); const cost = deferred(); const commits = []
  const request = gate.run(() => Promise.all([projection.promise, cost.promise]), (result) => commits.push(result))
  projection.resolve('chart and targets'); await Promise.resolve(); assert.equal(commits.length, 0)
  cost.resolve('cost'); await request; assert.deepEqual(commits[0].data, ['chart and targets', 'cost'])
  const pending = deferred(); const cancelled = gate.run(() => pending.promise, (result) => commits.push(result))
  gate.invalidate(); pending.resolve('stale'); await cancelled; assert.equal(commits.length, 1)
})

test('O10–O20 Overview wiring shares period controls, bounded inputs and synchronous context isolation', () => {
  assert.match(page, /<PeriodFields filters={filters} setFilters={setFilters}/)
  assert.match(page, /key={`\$\{account.id\}:\$\{branch\?\.id/)
  assert.match(page, /result\?\.key === requestKey/)
  assert.match(page, /Promise\.all/)
  assert.match(page, /periodStart: period\.start, periodEnd: period\.end/)
  assert.match(page, /summaryOnly: true/)
  assert.match(page, /Purchase Value/)
  assert.match(page, /Branch purchasing/)
  assert.match(page, /costSummary\?\.purchase_summary/)
  assert.doesNotMatch(page, /projection\.(week|month|actual_month_to_date)/)
  for (const name of ['MachineCostPage', 'ReportsPage']) {
    const source = readFileSync(new URL(`../../pages/${name}.jsx`, import.meta.url), 'utf8')
    assert.match(source, /<PeriodFields filters={filters} setFilters={setFilters}/)
    assert.match(source, /resolveMachineCostPeriod/)
  }
  const css = readFileSync(new URL('../../App.css', import.meta.url), 'utf8')
  assert.match(css, /\.machine-cost-filters \{ grid-template-columns: minmax\(0,1fr\)/)
  assert.match(css, /\.overview-period-grid \{ display: grid; grid-template-columns: repeat\(4, minmax\(0, 1fr\)\)/)
  assert.match(css, /@media \(max-width: 1100px\)[\s\S]+\.overview-period-grid \{ grid-template-columns: repeat\(2, minmax\(0, 1fr\)\)/)
  assert.match(css, /@media \(max-width: 680px\)[\s\S]+\.overview-period-grid \{ grid-template-columns: minmax\(0, 1fr\)/)
})

test('Purchase KPI formats Rupiah and protects the zero-value percentage state', () => {
  assert.match(page, /formatIdrTotal\(purchaseValue\)/)
  assert.match(page, /formatIdrTotal\(receivedValue\)/)
  assert.match(page, /Number\.isFinite\(receivedPercentage\) && purchaseValue > 0 \? receivedPercentage : 0/)
  assert.match(page, /maximumFractionDigits: 1/)
})
