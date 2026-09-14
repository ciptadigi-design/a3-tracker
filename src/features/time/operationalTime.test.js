import assert from 'node:assert/strict'
import fs from 'node:fs'
import test from 'node:test'
import { localDateTimeInZone, zonedLocalDateTimeToISOString } from '../machineCost/sellingPriceModel.js'
import { resolveMachineCostPeriod } from '../machineCost/machineCostPeriods.js'

const source = (relative) => fs.readFileSync(new URL(relative, import.meta.url), 'utf8')

test('operational local datetime conversion is browser-zone independent for pilot zones and boundaries', () => {
  const cases = [
    ['2026-09-14T00:30', 'Asia/Jakarta', '2026-09-13T17:30:00.000Z'],
    ['2026-09-14T00:30', 'Asia/Makassar', '2026-09-13T16:30:00.000Z'],
    ['2026-09-14T00:30', 'Asia/Jayapura', '2026-09-13T15:30:00.000Z'],
    ['2026-09-30T23:30', 'Asia/Jakarta', '2026-09-30T16:30:00.000Z'],
    ['2026-12-31T23:30', 'Asia/Jayapura', '2026-12-31T14:30:00.000Z'],
    ['2026-09-14T00:30', 'America/New_York', '2026-09-14T04:30:00.000Z'],
  ]
  for (const [local, timezone, expected] of cases) {
    assert.equal(zonedLocalDateTimeToISOString(local, timezone), expected)
  }
})

test('instant to datetime-local rendering uses the explicit operational timezone', () => {
  const instant = new Date('2026-09-13T16:30:00.000Z')
  assert.equal(localDateTimeInZone('Asia/Jakarta', instant), '2026-09-13T23:30')
  assert.equal(localDateTimeInZone('Asia/Makassar', instant), '2026-09-14T00:30')
  assert.equal(localDateTimeInZone('Asia/Jayapura', instant), '2026-09-14T01:30')
})

test('Today recomputes from the active tenant timezone across Indonesian zones', () => {
  const now = new Date('2026-09-13T16:30:00.000Z')
  assert.deepEqual(resolveMachineCostPeriod({ preset: 'today', timezone: 'Asia/Jakarta', now }), { start: '2026-09-13', end: '2026-09-13' })
  assert.deepEqual(resolveMachineCostPeriod({ preset: 'today', timezone: 'Asia/Makassar', now }), { start: '2026-09-14', end: '2026-09-14' })
  assert.deepEqual(resolveMachineCostPeriod({ preset: 'today', timezone: 'Asia/Jayapura', now }), { start: '2026-09-14', end: '2026-09-14' })
})

test('opening stock, counter, and incident transports never parse datetime-local in the browser timezone', () => {
  assert.match(source('../../pages/InventoryPage.jsx'), /zonedLocalDateTimeToISOString\(values\.occurredAt, operationalTimezone\)/)
  assert.match(source('../counters/CounterEntryCard.jsx'), /zonedLocalDateTimeToISOString\(observedAt, timezone\)/)
  assert.match(source('../../services/laravel/incidents.js'), /zonedLocalDateTimeToISOString\(values\.occurredAt, timezone\)/)
  assert.doesNotMatch(source('../../pages/InventoryPage.jsx'), /new Date\(values\.occurredAt\)\.toISOString\(\)/)
  assert.doesNotMatch(source('../counters/CounterEntryCard.jsx'), /new Date\(observedAt\)\.toISOString\(\)/)
})

test('purchase dates remain date-only and are not passed through Date parsing', () => {
  assert.match(source('../../services/laravel/inventory.js'), /purchase_date: values\.purchaseDate/)
  assert.doesNotMatch(source('../../services/laravel/inventory.js'), /new Date\(values\.purchaseDate/)
})

test('pilot frontend fixes IDR and does not offer unsupported currency choices', () => {
  assert.match(source('../../services/laravel/inventory.js'), /currency_code: 'IDR'/)
  assert.doesNotMatch(source('../inventory/PurchasingDialogs.jsx'), /<select[^>]+currency/i)
})

test('receipt presentation follows branch/location context instead of browser or account-only time', () => {
  assert.match(source('../inventory/PurchasingPanel.jsx'), /timeZone=\{operationalTimezone\}/)
  assert.match(source('../../pages/InventoryPage.jsx'), /locationBranch\?\.timezone \|\| account\.default_timezone \|\| 'UTC'/)
})
