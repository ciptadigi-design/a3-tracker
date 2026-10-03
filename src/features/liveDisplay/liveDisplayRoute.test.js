import { test } from 'node:test'
import assert from 'node:assert/strict'
import { getLiveDisplayMachineIdFromPath, isLiveDisplayRoute, isValidMachineId } from '../../hooks/useAppRoute.js'

const validId = 'a3f1c2d4-5e6b-4a7c-8d9e-0f1a2b3c4d5e'

test('isLiveDisplayRoute matches the display route with or without a machine segment', () => {
  assert.equal(isLiveDisplayRoute(`/display/live/${validId}`), true)
  assert.equal(isLiveDisplayRoute('/display/live'), true)
  assert.equal(isLiveDisplayRoute('/display/live/not-a-uuid'), true)
  assert.equal(isLiveDisplayRoute('/display'), false)
  assert.equal(isLiveDisplayRoute('/'), false)
})

test('getLiveDisplayMachineIdFromPath extracts the raw segment without validating its shape', () => {
  assert.equal(getLiveDisplayMachineIdFromPath(`/display/live/${validId}`), validId)
  assert.equal(getLiveDisplayMachineIdFromPath('/display/live/garbage'), 'garbage')
})

test('getLiveDisplayMachineIdFromPath returns null when the machine segment is missing', () => {
  assert.equal(getLiveDisplayMachineIdFromPath('/display/live'), null)
  assert.equal(getLiveDisplayMachineIdFromPath('/display/live/'), null)
})

test('isValidMachineId accepts a canonical UUID and rejects everything else', () => {
  assert.equal(isValidMachineId(validId), true)
  assert.equal(isValidMachineId('garbage'), false)
  assert.equal(isValidMachineId(''), false)
  assert.equal(isValidMachineId(null), false)
  assert.equal(isValidMachineId(undefined), false)
})
