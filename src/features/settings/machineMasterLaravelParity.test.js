import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const service = readFileSync(new URL('../../services/laravel/operationalMasters.js', import.meta.url), 'utf8')

test('Laravel manufacturer save maps to snake_case backend fields', () => {
  const implementation = service.match(/export async function saveManufacturer[^\n]+/)?.[0] ?? ''
  assert.match(implementation, /code: values\.code/)
  assert.match(implementation, /notes: values\.notes/)
  assert.doesNotMatch(implementation, /\.\.\.values/)
  assert.doesNotMatch(implementation, /website/)
})

test('Laravel machine model save maps camelCase draft fields to the real schema columns', () => {
  const implementation = service.match(/export async function saveMachineModel[^\n]+/)?.[0] ?? ''
  assert.match(implementation, /model_code: values\.modelCode/)
  assert.match(implementation, /manufacturer_id: values\.manufacturerId/)
  assert.match(implementation, /machine_category: values\.machineCategory/)
  assert.match(implementation, /color_capability: values\.colorCapability/)
  assert.doesNotMatch(implementation, /\.\.\.values/)
})
