import assert from 'node:assert/strict'
import test from 'node:test'
import { describeFinancialBreakdown, describeFinancialImpact, formatFinancialAmount } from './downtimeModel.js'
import { ratesFromForm, ratesToForm } from '../machineCost/financialRatesModel.js'

const rupiah = (n) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(n)

test('missing amount is Belum dikonfigurasi, never Rp0; a real zero stays Rp0', () => {
  assert.equal(formatFinancialAmount(null), 'Belum dikonfigurasi')
  assert.equal(formatFinancialAmount(undefined), 'Belum dikonfigurasi')
  assert.equal(formatFinancialAmount(0), rupiah(0))
  assert.equal(formatFinancialAmount(125000), rupiah(125000))
})

test('no financial block (no machine_cost.view) renders nothing', () => {
  assert.equal(describeFinancialImpact(undefined), null)
  assert.equal(describeFinancialBreakdown(null), null)
})

test('operating cost and lost revenue are separate indicators; partial coverage is called out', () => {
  const m = describeFinancialImpact({ production_downtime_minutes: 150, operating_cost_impact: { amount: 125000, rated_machine_count: 1, status: 'partial' }, potential_lost_revenue: { amount: null, rated_machine_count: 0, status: 'not_configured' } })
  assert.equal(m.productionDowntime, '2j 30m')
  assert.equal(m.operatingCost.value, rupiah(125000))
  assert.match(m.operatingCost.partialNote, /1 mesin/)
  assert.equal(m.lostRevenue.value, 'Belum dikonfigurasi')
  assert.equal(m.lostRevenue.partialNote, null)
})

test('breakdown shows applied hourly rates', () => {
  const b = describeFinancialBreakdown({ operating_cost_per_hour: 30000, estimated_revenue_per_hour: null, operating_cost_impact: 60000, potential_lost_revenue: null })
  assert.equal(b.costRate, `${rupiah(30000)}/jam`)
  assert.equal(b.revenueRate, 'Belum dikonfigurasi')
  assert.equal(b.lostRevenue, 'Belum dikonfigurasi')
})

test('rate form: blank -> null, whole Rupiah only', () => {
  assert.deepEqual(ratesFromForm({ operatingCostPerHour: '', estimatedRevenuePerHour: ' 90000 ' }), { operating_cost_per_hour: null, estimated_revenue_per_hour: 90000 })
  assert.equal(ratesFromForm({ operatingCostPerHour: '12.5', estimatedRevenuePerHour: '' }), null)
  assert.equal(ratesFromForm({ operatingCostPerHour: '-1', estimatedRevenuePerHour: '' }), null)
  assert.deepEqual(ratesToForm({ operating_cost_per_hour: 5, estimated_revenue_per_hour: null }), { operatingCostPerHour: '5', estimatedRevenuePerHour: '' })
})
