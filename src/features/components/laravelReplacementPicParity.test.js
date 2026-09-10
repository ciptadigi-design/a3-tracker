import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const lifecycles = readFileSync(new URL('../../services/laravel/componentLifecycles.js', import.meta.url), 'utf8')
const inventory = readFileSync(new URL('../../services/laravel/inventory.js', import.meta.url), 'utf8')

test('Laravel loadMachineComponentLifecycles fetches the real canonical Inventory workspace instead of a hardcoded empty stub', () => {
  const implementation = lifecycles.match(/export async function loadMachineComponentLifecycles[\s\S]+?\n}/)?.[0] ?? ''
  assert.match(implementation, /loadInventory\(/)
  assert.doesNotMatch(implementation, /operationalPeople:\s*\[\],\s*inventoryItems:\s*\[\]/)
})

test('Laravel replaceComponentLifecycle forwards the selected PIC to the backend', () => {
  const implementation = lifecycles.match(/export async function replaceComponentLifecycle[^\n]+/)?.[0] ?? ''
  assert.match(implementation, /performedByPersonId/)
  assert.match(implementation, /performed_by_person_id:\s*performedByPersonId/)
  assert.match(implementation, /performed_by_name:\s*performedByName/)
})

test('Laravel Inventory Opening Balance, Adjustment, and Transfer all forward the selected PIC', () => {
  for (const fn of ['initializeInventoryStock', 'adjustInventoryStock', 'transferInventoryStock']) {
    const implementation = inventory.match(new RegExp(`export async function ${fn}[^\\n]+`))?.[0] ?? ''
    assert.match(implementation, /person_id:\s*values\.personId \|\| null/, `${fn} must forward values.personId`)
  }
})
