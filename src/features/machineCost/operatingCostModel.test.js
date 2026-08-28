import test from 'node:test'
import assert from 'node:assert/strict'
import { operatingCostValidation, validOperatingCostDraft } from './operatingCostModel.js'

const base = { category: 'electricity', amount: '1250000', allocationMethod: 'one_time', effectiveAt: '2026-08-28T10:00', periodStart: '', periodEnd: '', operationalPersonId: '', externalReference: '', description: 'Explicit allocation', notes: '', clientRequestId: 'request' }
test('one-time cost requires explicit positive numeric evidence', () => assert.equal(operatingCostValidation(base), null))
test('period cost requires ordered inclusive dates', () => assert.match(operatingCostValidation({ ...base, allocationMethod: 'daily_proration_v1', periodStart: '2026-08-31', periodEnd: '2026-08-01' }), /valid inclusive period/))
test('financial input rejects JavaScript-style exponent notation and excess precision', () => {
  assert.match(operatingCostValidation({ ...base, amount: '1e6' }), /positive amount/)
  assert.match(operatingCostValidation({ ...base, amount: '1.001' }), /positive amount/)
})
test('persistent operating-cost draft has a distinct validated shape', () => assert.equal(validOperatingCostDraft(base), true))
