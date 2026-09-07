import test from 'node:test'
import assert from 'node:assert/strict'
import { resolveMachineCostTab } from './operatingCostGate.js'

test('shows the operating tab when requested and the feature is enabled', () => {
  assert.equal(resolveMachineCostTab('operating', true), 'operating')
})

test('falls back to summary when operating is requested but the feature is disabled', () => {
  assert.equal(resolveMachineCostTab('operating', false), 'summary')
})

test('stays on summary when summary is requested regardless of the feature flag', () => {
  assert.equal(resolveMachineCostTab('summary', true), 'summary')
  assert.equal(resolveMachineCostTab('summary', false), 'summary')
})

test('treats an unrecognized view as summary', () => {
  assert.equal(resolveMachineCostTab(undefined, true), 'summary')
})
