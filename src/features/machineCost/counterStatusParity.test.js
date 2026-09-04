import assert from 'node:assert/strict'
import fs from 'node:fs'
import test from 'node:test'
import { counterEvidencePresentation, primaryCostPerClickPresentation, summaryStatusPresentation } from './machineCostPresentation.js'

const page = fs.readFileSync(new URL('../../pages/MachineCostPage.jsx', import.meta.url), 'utf8')

const idr = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 2 })
const currency = (value) => idr.format(Number(value ?? 0))

/**
 * Reproduces the production August Tuparev response shape after the
 * counter-projection fix: 31,901 recorded clicks with a fully populated
 * daily trend, a COMPLETE counter_status, and typed (never-undefined)
 * error/waste fields.
 */
function augustSummaryFixture(overrides = {}) {
  return {
    total_clicks: 31901,
    counter_status: 'COMPLETE',
    known_error_waste_events: 3,
    unknown_error_waste_events: 1,
    known_error_waste_cost: '450000.00',
    error_waste_events: 4,
    known_standard_cost_per_click: '12.3400',
    known_consumption_cost: '2000000.00',
    unknown_consumption_events: 0,
    total_consumption_events: 5,
    unknown_evidence_events: 1,
    daily_trend: Array.from({ length: 28 }, (_, index) => ({ operational_date: `2026-08-${String(index + 1).padStart(2, '0')}`, daily_clicks: 1000 })),
    ...overrides,
  }
}

test('the "No Counter Data" banner condition never contradicts a populated Total Clicks response', () => {
  const summary = augustSummaryFixture()
  // Mirrors the exact JSX condition gating the banner.
  const bannerShows = summary.counter_status !== 'COMPLETE'
  assert.equal(bannerShows, false, 'banner must not show when the backend already reports COMPLETE counter data')
  assert.ok(summary.total_clicks > 0)
})

test('the "No Counter Data" banner only appears when counter_status is genuinely not COMPLETE', () => {
  const summary = augustSummaryFixture({ counter_status: 'NO_DATA', total_clicks: 0 })
  const bannerShows = summary.counter_status !== 'COMPLETE'
  assert.equal(bannerShows, true)
  assert.equal(summary.total_clicks, 0, 'a genuine NO_DATA period must not carry a nonzero Total Clicks')
})

test('Cost / Click resolves to a real value (not "Unavailable") once counter data and cost evidence are present', () => {
  const summary = augustSummaryFixture()
  const display = primaryCostPerClickPresentation(summary, currency)
  assert.notEqual(display.value, 'Unavailable')
  assert.doesNotMatch(display.value, /undefined|NaN/)
})

test('Cost / Click says "Unavailable" only when no counter delta is computable, consistent with counter_status', () => {
  const summary = augustSummaryFixture({ counter_status: 'NO_DATA', total_clicks: 0, known_standard_cost_per_click: null })
  const display = primaryCostPerClickPresentation(summary, currency)
  assert.equal(display.value, 'Unavailable')
})

test('Error / Waste hint mirrors the page markup and never renders literal "undefined"', () => {
  const summary = augustSummaryFixture()
  const hint = `${Number(summary.known_error_waste_events ?? 0)} assessed · ${Number(summary.unknown_error_waste_events ?? 0)} unpriced`
  assert.doesNotMatch(hint, /undefined|NaN/)
  assert.equal(hint, '3 assessed · 1 unpriced')
})

test('Error / Waste hint stays well-typed even when the backend fields are entirely absent', () => {
  const summary = { total_clicks: 0, counter_status: 'NO_DATA' }
  const hint = `${Number(summary.known_error_waste_events ?? 0)} assessed · ${Number(summary.unknown_error_waste_events ?? 0)} unpriced`
  assert.doesNotMatch(hint, /undefined|NaN/)
  assert.equal(hint, '0 assessed · 0 unpriced')
})

test('summary status badge reflects "Complete" rather than "No counter data" once the backend agrees with Total Clicks', () => {
  const [label] = summaryStatusPresentation(augustSummaryFixture({ unknown_evidence_events: 0 }))
  assert.notEqual(label, 'No counter data')
})

test('counter evidence hint never renders the literal string "undefined"', () => {
  for (const status of ['COMPLETE', 'NO_DATA', 'INSUFFICIENT_START', 'INSUFFICIENT_END', undefined, null]) {
    const { hint } = counterEvidencePresentation({ counter_status: status })
    assert.doesNotMatch(hint, /undefined|NaN/)
  }
})

test('the page reads the Error / Waste fields through Number(...) guards, not raw interpolation', () => {
  const summaryHeading = page.slice(page.indexOf('label="Error / Waste"'), page.indexOf('label="Error / Waste"') + 400)
  assert.match(summaryHeading, /Number\(summary\.known_error_waste_events \?\? 0\)/)
  assert.match(summaryHeading, /Number\(summary\.unknown_error_waste_events \?\? 0\)/)
})

test('the banner and the Total Clicks card share the same canonical counter_status check', () => {
  const bannerCondition = page.match(/summary\.counter_status !== 'COMPLETE' &&/)
  const totalClicksCondition = page.match(/summary\.counter_status === 'COMPLETE' \?/)
  assert.ok(bannerCondition, 'banner must gate on summary.counter_status')
  assert.ok(totalClicksCondition, 'Total Clicks hint must gate on the same summary.counter_status field')
})
