import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const service = readFileSync(new URL('../../services/laravel/incidents.js', import.meta.url), 'utf8')

// M2.17: IncidentsController::index()/show() previously never returned a
// `people` key at all, so the Incident/Error selectors were always empty
// regardless of how correctly this frontend contract or the person-selection
// helpers behaved. This locks the frontend side of that contract in place —
// the backend fix must be paired with the frontend reading the response at
// the same top-level shape it already expected.
test('Laravel loadOperationalIncidents reads the top-level people field the backend now populates', () => {
  const implementation = service.match(/export async function loadOperationalIncidents[^\n]+/)?.[0] ?? ''
  assert.match(implementation, /payload\.people \?\? \[\]/)
})

test('Laravel loadOperationalIncident (single incident / edit form) also reads the top-level people field', () => {
  const implementation = service.match(/export async function loadOperationalIncident\(/)
  assert.ok(implementation, 'loadOperationalIncident should exist')
  const implementationLine = service.match(/export async function loadOperationalIncident\([^\n]+/)?.[0] ?? ''
  assert.match(implementationLine, /payload\.people \?\? \[\]/)
})
