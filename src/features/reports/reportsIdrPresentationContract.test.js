import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { formatIdrTotal, formatIdrUnit } from '../machineCost/currencyFormat.js'

const page = readFileSync(new URL('../../pages/ReportsPage.jsx', import.meta.url), 'utf8')

// M2.17.4.2: Reports presented every IDR field - whole totals AND cost/click alike -
// through one local ad-hoc `Intl.NumberFormat('id-ID', { style: 'currency', currency:
// 'IDR', maximumFractionDigits: 2 })`, which defaults minimumFractionDigits to 2 for
// currency style, producing "Rp86.085,00" / "Rp0,00" instead of the app's canonical
// whole-Rupiah presentation used everywhere else (Machine Cost, per M2.12K). Fixed by
// reusing formatIdrTotal/formatIdrUnit instead of introducing a third formatter.

test('exact mission values render with the canonical whole-Rupiah / cost-per-click formatters', () => {
  assert.equal(formatIdrTotal(86085).replace(/\s/g, ''), 'Rp86.085')
  assert.equal(formatIdrTotal(0).replace(/\s/g, ''), 'Rp0')
  assert.equal(formatIdrTotal(3250000).replace(/\s/g, ''), 'Rp3.250.000')
  assert.equal(formatIdrUnit(3.26).replace(/\s/g, ''), 'Rp3,26')
})

test('ReportsPage no longer defines its own competing currency formatter', () => {
  assert.doesNotMatch(page, /new Intl\.NumberFormat\('id-ID', \{ style: 'currency'/)
})

test('ReportsPage reuses the canonical M2.12K formatters rather than a new one', () => {
  assert.match(page, /import \{ formatIdrTotal, formatIdrUnit \} from '\.\.\/features\/machineCost\/currencyFormat\.js'/)
  assert.match(page, /const money = \(value\) => value == null \? '—' : formatIdrTotal\(value\)/)
  assert.match(page, /const moneyPerClick = \(value\) => value == null \? '—' : formatIdrUnit\(value\)/)
})

test('every "Cost / Click" field uses the fractional-precision formatter, not the whole-amount one', () => {
  const costPerClickUsages = page.match(/label="Standard Cost \/ Click" value=\{[^}]+\}/g) ?? []
  const costPerClickCells = page.match(/data-label="Cost \/ Click">\{[^}]+\}/g) ?? []
  assert.ok(costPerClickUsages.length >= 2, 'expected both Overview and Machine Cost KPI cards')
  assert.ok(costPerClickCells.length >= 2, 'expected both comparison and machine-cost table cells')
  for (const usage of [...costPerClickUsages, ...costPerClickCells]) {
    assert.match(usage, /moneyPerClick\(/, `expected moneyPerClick in: ${usage}`)
    assert.doesNotMatch(usage, /\bmoney\(/, `must not use the whole-amount formatter in: ${usage}`)
  }
})

// Component Cost / Error-Waste / Standard Machine Cost / Assessed Loss / Consumed Cost
// must all stay on the whole-amount formatter - this is the actual regression: they
// were never wrong in field selection, only in which formatter `money` called.
test('aggregate monetary fields (Component Cost, Error/Waste, Standard Machine Cost, Assessed Loss, Consumed Cost) use the whole-amount formatter', () => {
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
