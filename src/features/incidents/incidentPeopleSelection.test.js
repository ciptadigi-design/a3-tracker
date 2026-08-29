import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { revalidateIncidentPeople, selectIncidentOperator, selectIncidentResponsiblePerson } from './incidentPeopleSelection.js'

const akmal = { id: 'akmal', name: 'Akmal Fauzan' }
const bigel = { id: 'bigel', name: 'Bigel' }
const bulan = { id: 'bulan', name: 'Bulan' }

const empty = { operatorPersonId: '', operatorName: '', responsiblePersonId: '', responsibleName: '', responsiblePersonTouched: false }

test('Operator defaults PIC Terlibat through the canonical person identity', () => {
  assert.deepEqual(selectIncidentOperator(empty, akmal), { operatorPersonId: 'akmal', operatorName: 'Akmal Fauzan', responsiblePersonId: 'akmal', responsibleName: 'Akmal Fauzan', responsiblePersonTouched: false })
})

test('explicit PIC Terlibat can differ and survives unrelated or Operator changes', () => {
  const initial = selectIncidentOperator(empty, akmal)
  const explicit = selectIncidentResponsiblePerson(initial, bigel)
  assert.equal(selectIncidentOperator(explicit, bulan).responsiblePersonId, 'bigel')
  assert.equal({ ...explicit, description: 'changed' }.responsiblePersonId, 'bigel')
})

test('untouched auto-default follows a later Operator change', () => {
  const next = selectIncidentOperator(selectIncidentOperator(empty, akmal), bulan)
  assert.equal(next.operatorPersonId, 'bulan')
  assert.equal(next.responsiblePersonId, 'bulan')
})

test('Branch eligibility revalidates both canonical people safely', () => {
  const selected = selectIncidentResponsiblePerson(selectIncidentOperator(empty, akmal), bigel)
  assert.deepEqual(revalidateIncidentPeople(selected, [akmal]), { ...selected, responsiblePersonId: '', responsibleName: '', responsiblePersonTouched: false })
  assert.equal(revalidateIncidentPeople(selected, [akmal, bigel]).responsiblePersonId, 'bigel')
})

test('form uses one canonical eligible people source and persists both selections', () => {
  const form = readFileSync(new URL('./IncidentFormDialog.jsx', import.meta.url), 'utf8')
  const service = readFileSync(new URL('../../services/supabase/operationalIncidents.js', import.meta.url), 'utf8')
  assert.equal((form.match(/people\.map/g) ?? []).length, 2)
  assert.match(form,/operatorPersonId[\s\S]*responsiblePersonId[\s\S]*usePersistentDraft/)
  assert.doesNotMatch(form,/Bigel|Bulan|Epri|Ramdani/)
  assert.match(service,/operational_person_branches!inner/)
  assert.match(service,/target_operator_person_id[\s\S]*target_responsible_person_id/)
})
