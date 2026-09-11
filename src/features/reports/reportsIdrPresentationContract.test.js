import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { formatIdrTotal } from '../machineCost/currencyFormat.js'

const page = readFileSync(new URL('../../pages/ReportsPage.jsx', import.meta.url), 'utf8')

// M2.17.4.2 fixed Reports' local ad-hoc `Intl.NumberFormat('id-ID', { style:
// 'currency', currency: 'IDR', maximumFractionDigits: 2 })` (implicit
// minimumFractionDigits=2), which produced "Rp86.085,00" / "Rp0,00" for whole
// amounts, while deliberately keeping Cost/Click fractional via a separate
// moneyPerClick/formatIdrUnit. M2.17.5 changed that product decision: Cost/Click now
// also renders whole-Rupiah like every other IDR value, so Reports has gone back to
// a single canonical formatter (formatIdrTotal) for every user-facing monetary field,
// with no separate per-click formatter left in this file at all.

test('exact mission values render with the canonical whole-Rupiah formatter', () => {
  assert.equal(formatIdrTotal(86085).replace(/\s/g, ''), 'Rp86.085')
  assert.equal(formatIdrTotal(0).replace(/\s/g, ''), 'Rp0')
  assert.equal(formatIdrTotal(3250000).replace(/\s/g, ''), 'Rp3.250.000')
  // M2.17.5: 112.47 now rounds to whole Rupiah, same as every other IDR value.
  assert.equal(formatIdrTotal(112.47).replace(/\s/g, ''), 'Rp112')
})

test('ReportsPage no longer defines its own competing currency formatter', () => {
  assert.doesNotMatch(page, /new Intl\.NumberFormat\('id-ID', \{ style: 'currency'/)
})

test('ReportsPage reuses the single canonical whole-Rupiah formatter, with no separate per-click formatter', () => {
  assert.match(page, /import \{ formatIdrTotal \} from '\.\.\/features\/machineCost\/currencyFormat\.js'/)
  assert.match(page, /const money = \(value\) => value == null \? '—' : formatIdrTotal\(value\)/)
  assert.doesNotMatch(page, /moneyPerClick\(/)
  assert.doesNotMatch(page, /formatIdrUnit\(/)
})

// M2.17.5: every "Cost / Click" field now uses the SAME whole-amount formatter as
// every other monetary field - the fractional-vs-whole distinction M2.17.4.2
// introduced no longer exists anywhere in Reports.
test('every "Cost / Click" field uses the same canonical formatter as aggregate totals', () => {
  const costPerClickUsages = page.match(/label="Standard Cost \/ Click" value=\{[^}]+\}/g) ?? []
  const costPerClickCells = page.match(/data-label="Cost \/ Click">\{[^}]+\}/g) ?? []
  assert.ok(costPerClickUsages.length >= 2, 'expected both Overview and Machine Cost KPI cards')
  assert.ok(costPerClickCells.length >= 2, 'expected both comparison and machine-cost table cells')
  for (const usage of [...costPerClickUsages, ...costPerClickCells]) {
    assert.match(usage, /\bmoney\(/, `expected the canonical money() formatter in: ${usage}`)
  }
})

test('aggregate monetary fields (Component Cost, Error/Waste, Standard Machine Cost, Assessed Loss, Consumed Cost) use the same canonical formatter', () => {
  for (const pattern of [
    /label="Component Cost" value=\{money\(/,
    /label="Error \/ Waste Loss" value=\{money\(/,
    /label="Standard Machine Cost" value=\{money\(/,
    /label="Assessed Loss" value=\{money\(/,
    /data-label="Component Cost">\{money\(/,
    /data-label="Error \/ Waste">\{money\(/,
    /data-label="Standard Cost">\{money\(/,
    /data-label="Consumed Cost">\{money\(/,
    /data-label="Loss">\{money\(/,
  ]) {
    assert.match(page, pattern)
  }
})
