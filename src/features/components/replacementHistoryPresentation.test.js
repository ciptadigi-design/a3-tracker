import assert from 'node:assert/strict'
import test from 'node:test'
import {
  filterReplacementHistory,
  historyForMachine,
  latestReplacementEvent,
  replacementHistoryComponentOptions,
} from './replacementHistoryPresentation.js'

const rows = [
  { replacement_event_id: '1', machine_id: 'm1', component_id: 'drum', component_name: 'Drum', replaced_at: '2026-01-01T00:00:00Z', include_in_adaptive_learning: true },
  { replacement_event_id: '2', machine_id: 'm1', component_id: 'fuser', component_name: 'Fuser', replaced_at: '2026-03-01T00:00:00Z', include_in_adaptive_learning: false },
  { replacement_event_id: '3', machine_id: 'm2', component_id: 'drum', component_name: 'Drum', replaced_at: '2026-02-01T00:00:00Z', include_in_adaptive_learning: true },
]

test('historyForMachine only returns events for the selected machine', () => {
  const result = historyForMachine(rows, 'm1')
  assert.equal(result.length, 2)
  assert.ok(result.every((event) => event.machine_id === 'm1'))
})

test('historyForMachine tolerates missing history', () => {
  assert.deepEqual(historyForMachine(undefined, 'm1'), [])
})

test('latestReplacementEvent picks the newest replaced_at regardless of array order', () => {
  const machine1 = historyForMachine(rows, 'm1')
  assert.equal(latestReplacementEvent(machine1).replacement_event_id, '2')
})

test('latestReplacementEvent returns null for an empty history', () => {
  assert.equal(latestReplacementEvent([]), null)
})

test('replacementHistoryComponentOptions lists distinct components sorted by name', () => {
  const options = replacementHistoryComponentOptions(rows)
  assert.deepEqual(options, [
    { id: 'drum', name: 'Drum' },
    { id: 'fuser', name: 'Fuser' },
  ])
})

test('filterReplacementHistory filters by component and learning status', () => {
  assert.equal(filterReplacementHistory(rows, { componentId: 'drum' }).length, 2)
  assert.equal(filterReplacementHistory(rows, { learningStatus: 'eligible' }).length, 2)
  assert.equal(filterReplacementHistory(rows, { learningStatus: 'excluded' }).length, 1)
  assert.equal(filterReplacementHistory(rows, { componentId: 'drum', learningStatus: 'excluded' }).length, 0)
})

test('filterReplacementHistory with no filters returns everything', () => {
  assert.equal(filterReplacementHistory(rows).length, rows.length)
})
