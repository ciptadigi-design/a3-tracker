import assert from 'node:assert/strict'
import test from 'node:test'
import { formatIdrTotal, formatIdrUnit } from './currencyFormat.js'

// IDR currency formatting via Intl.NumberFormat is ICU-data-dependent for the
// "Rp" prefix spacing, but explicit minimumFractionDigits/maximumFractionDigits
// make the fractional-digit behavior itself spec-determined and portable.
const RP = (integer) => new RegExp(`^Rp\\s?${integer.replace(/\./g, '\\.')}$`)
const RP_UNIT = (digits) => new RegExp(`^Rp\\s?${digits.replace(/[.,]/g, (match) => (match === '.' ? '\\.' : ','))}$`)

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

test('formatIdrUnit keeps up to two fractional digits and drops trailing zeroes', () => {
  assert.match(formatIdrUnit(3.7), RP_UNIT('3,7'))
  assert.match(formatIdrUnit(3.75), RP_UNIT('3,75'))
  assert.match(formatIdrUnit(4), RP_UNIT('4'))
  assert.match(formatIdrUnit(4.5), RP_UNIT('4,5'))
  assert.match(formatIdrUnit(0), RP_UNIT('0'))
})

test('formatIdrUnit never forces whole-Rupiah values to lose their real fraction', () => {
  assert.doesNotMatch(formatIdrUnit(3.7), /^Rp\s?4$/)
})

test('neither formatter ever renders NaN, null, or undefined', () => {
  for (const bad of [null, undefined, NaN, 'not-a-number', {}, []]) {
    assert.doesNotMatch(formatIdrTotal(bad), /NaN|undefined|null/)
    assert.doesNotMatch(formatIdrUnit(bad), /NaN|undefined|null/)
  }
})

test('zero is a real rendered zero, not a fallback dash', () => {
  assert.match(formatIdrTotal(0), RP('0'))
  assert.match(formatIdrUnit(0), RP_UNIT('0'))
})
