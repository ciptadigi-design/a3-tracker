import assert from 'node:assert/strict'
import test from 'node:test'
import { branchOperationalPeople, counterOperatorsForBranch } from './eligibility.js'

const people = [
  { id: 'a', name: 'Akmal', is_active: true, operational_person_branches: [{ branch_id: 'one', is_active: true, can_record_counter: true }] },
  { id: 'b', name: 'Dhea', is_active: true, operational_person_branches: [{ branch_id: 'one', is_active: true, can_record_counter: false }] },
  { id: 'c', name: 'Archived', is_active: false, operational_person_branches: [{ branch_id: 'one', is_active: true, can_record_counter: true }] },
  { id: 'd', name: 'Other branch', is_active: true, operational_person_branches: [{ branch_id: 'two', is_active: true, can_record_counter: true }] },
]

test('counter eligibility is active, assigned, and explicitly capable', () => {
  assert.deepEqual(counterOperatorsForBranch(people, 'one').map((person) => person.id), ['a'])
})

test('PIC eligibility includes counter operators and general staff', () => {
  assert.deepEqual(branchOperationalPeople(people, 'one').map((person) => person.id), ['a', 'b'])
  assert.deepEqual(counterOperatorsForBranch(people, 'one').map((person) => person.id), ['a'])
})

test('same person is valid in both incident selector roles', () => {
  const counter = counterOperatorsForBranch(people, 'one')
  const pic = branchOperationalPeople(people, 'one')
  assert.ok(counter.some((person) => person.id === 'a'))
  assert.ok(pic.some((person) => person.id === 'a'))
  assert.ok(pic.some((person) => person.id === 'b'))
})
