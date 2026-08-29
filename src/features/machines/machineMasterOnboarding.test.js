import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const dialog = readFileSync(new URL('./MachineFormDialog.jsx', import.meta.url), 'utf8')
const page = readFileSync(new URL('../../pages/MachinesPage.jsx', import.meta.url), 'utf8')
const route = readFileSync(new URL('../../hooks/useAppRoute.js', import.meta.url), 'utf8')
const service = readFileSync(new URL('../../services/supabase/machines.js', import.meta.url), 'utf8')

test('Add Machine uses governed active Manufacturer and Model choices', () => {
  assert.match(dialog, /models\.filter\(\(model\) => model\.manufacturer_id === values\.manufacturerId\)/)
  assert.match(dialog, /disabled={!values\.manufacturerId}/)
  assert.match(dialog, /machineModelId: manufacturerModels\.length === 1 \? manufacturerModels\[0\]\.id : ''/)
  assert.match(service, /\.eq\('is_active', true\)/)
  assert.doesNotMatch(dialog, /creatable|freeSolo|Add Manufacturer|Add Model/)
})

test('missing-master guidance routes only Platform Superusers to governance', () => {
  assert.match(dialog, /Can't find the manufacturer or model/)
  assert.match(dialog, /Machine masters are managed centrally in Settings/)
  assert.match(dialog, /Contact your platform administrator/)
  assert.match(page, /canManageMachineMasters={isPlatformSuperuser}/)
  assert.match(page, /navigate\('\/settings\/machine-models'\)/)
  assert.match(route, /'\/settings\/machine-models'/)
})
