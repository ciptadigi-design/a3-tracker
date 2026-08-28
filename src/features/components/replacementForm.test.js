import test from 'node:test'
import assert from 'node:assert/strict'
import { formatCounterInput, normalizeCounterInput, resolveReplacementPic } from './replacementForm.js'

const people = [
  { id: 'akmal', name: 'Akmal Fauzan', is_active: true },
  { id: 'inactive', name: 'Former PIC', is_active: false },
]

test('active operational PIC selection hides manual fallback', () => {
  assert.deepEqual(resolveReplacementPic(people, 'akmal'), { mode: 'operational', person: people[0], stale: false })
})

test('Manual PIC selection activates the manual fallback', () => {
  assert.deepEqual(resolveReplacementPic(people, 'manual'), { mode: 'manual', person: null, stale: false })
})

test('inactive and deleted persisted PIC selections remain stale until reselected', () => {
  assert.equal(resolveReplacementPic(people, 'inactive').stale, true)
  assert.equal(resolveReplacementPic(people, 'deleted').stale, true)
})

test('operational and manual draft identifiers remain stable', () => {
  assert.equal(resolveReplacementPic(people, 'akmal').person.id, 'akmal')
  assert.equal(resolveReplacementPic(people, 'manual').mode, 'manual')
})

test('counter formatting preserves the canonical numeric payload', () => {
  assert.equal(formatCounterInput('1438992'), '1,438,992')
  assert.equal(normalizeCounterInput('1,438,992'), '1438992')
  assert.equal(Number(normalizeCounterInput('1,438,992')), 1438992)
  assert.equal(normalizeCounterInput('1.438.992'), null)
})
