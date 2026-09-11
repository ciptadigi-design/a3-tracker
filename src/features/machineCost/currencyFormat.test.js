import assert from 'node:assert/strict'
import test from 'node:test'
import { formatIdrTotal } from './currencyFormat.js'

// IDR currency formatting via Intl.NumberFormat is ICU-data-dependent for the
// "Rp" prefix spacing, but explicit minimumFractionDigits/maximumFractionDigits
// make the fractional-digit behavior itself spec-determined and portable.
const RP = (integer) => new RegExp(`^Rp\\s?${integer.replace(/\./g, '\\.')}$`)

test('formatIdrTotal never renders a fractional-Rupiah suffix', () => {
  assert.match(formatIdrTotal(118170), RP('118.170'))
  assert.match(formatIdrTotal(0), RP('0'))
  assert.match(formatIdrTotal(3900000), RP('3.900.000'))
})

test('formatIdrTotal rounds fractional input for display without exposing a decimal suffix', () => {
  const rendered = formatIdrTotal(118170.99)
  assert.doesNotMatch(rendered, /,\d+$/)
  assert.match(rendered, RP('118.171'))
})

// M2.17.5: Cost/Click and other per-unit economics now render through this same
// formatter (the product decision that previously kept them fractional via a
// separate formatIdrUnit was reversed) - standard "round half up to the nearest
// Rupiah" behavior applies uniformly.
test('formatIdrTotal rounds a per-click value to the nearest whole Rupiah, same as any other amount', () => {
  assert.match(formatIdrTotal(112.47), RP('112'))
  assert.match(formatIdrTotal(3.26), RP('3'))
  assert.match(formatIdrTotal(500.75), RP('501'))
})

test('formatIdrTotal never renders NaN, null, or undefined', () => {
  for (const bad of [null, undefined, NaN, 'not-a-number', {}, []]) {
    assert.doesNotMatch(formatIdrTotal(bad), /NaN|undefined|null/)
  }
})

test('zero is a real rendered zero, not a fallback dash', () => {
  assert.match(formatIdrTotal(0), RP('0'))
})
