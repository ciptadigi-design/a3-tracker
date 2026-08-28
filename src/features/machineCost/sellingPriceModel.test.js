import test from 'node:test'
import assert from 'node:assert/strict'
import { contributionPerClickPresentation, contributionPresentation, revenuePresentation, sellingPriceCardPresentation, sellingPriceValidation, validSellingPriceDraft, zonedLocalDateTimeToISOString } from './sellingPriceModel.js'

const draft = { pricePerClick: '800', effectiveFrom: '2026-08-16T08:00', notes: '', clientRequestId: 'request' }
const money = (value) => `Rp${value}`
const number = (value) => String(value)

test('selling price validation accepts positive NUMERIC input and rejects client garbage', () => {
  assert.equal(sellingPriceValidation(draft), null)
  for (const pricePerClick of ['', '0', '-1', 'NaN', 'Infinity', '1e3', '1.00001']) {
    assert.match(sellingPriceValidation({ ...draft, pricePerClick }), /greater than zero/)
  }
  assert.equal(validSellingPriceDraft(draft), true)
})

test('machine-local Jakarta time converts to an unambiguous instant', () => {
  assert.equal(zonedLocalDateTimeToISOString('2026-08-16T08:00', 'Asia/Jakarta'), '2026-08-16T01:00:00.000Z')
  assert.equal(zonedLocalDateTimeToISOString('2026-08-16T08:00', 'Asia/Makassar'), '2026-08-16T00:00:00.000Z')
})

test('selling price card distinguishes missing, single, and multiple price evidence', () => {
  assert.equal(sellingPriceCardPresentation({}, money).state, 'missing')
  assert.equal(sellingPriceCardPresentation({ current_selling_price_per_click: 800, period_price_count: 0 }, money).value, 'Rp800')
  assert.equal(sellingPriceCardPresentation({ period_end_selling_price_per_click: 850, period_price_count: 2 }, money).value, 'Multiple prices')
})

test('revenue exposes complete, partial, no-price, and no-click states', () => {
  assert.match(revenuePresentation({ revenue_status: 'COMPLETE', estimated_revenue: 1000, priced_clicks: 10 }, money, number).hint, /complete coverage/)
  assert.match(revenuePresentation({ revenue_status: 'PARTIAL', estimated_revenue: 800, unpriced_clicks: 2 }, money, number).hint, /Priced portion only/)
  assert.equal(revenuePresentation({ revenue_status: 'NO_PRICE' }, money, number).value, 'Unavailable')
  assert.equal(revenuePresentation({ revenue_status: 'NO_CLICKS' }, money, number).value, 'Rp0')
})

test('contribution requires complete price coverage and qualifies partial cost', () => {
  assert.match(contributionPresentation({ revenue_status: 'PARTIAL' }, money).hint, /every recorded click/)
  const partial = contributionPresentation({ estimated_standard_contribution: 900, standard_contribution_status: 'PARTIAL_COST', standard_contribution_margin_percent: 45 }, money)
  assert.equal(partial.value, 'Rp900')
  assert.match(partial.hint, /available cost evidence/)
  assert.equal(partial.margin, 45)
  assert.match(contributionPerClickPresentation({ revenue_status: 'PARTIAL' }, money).hint, /Partial price coverage/)
})
