import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

// M2.19 (closing the M2.18 audit's adaptive_enabled truthfulness finding):
// adaptive_enabled is persisted (schema, validation, fillable) but consumed
// by NO backend calculation anywhere (confirmed by grep across
// backend/app/Services/ - it appears only in ModelProfileSlot's fillable/
// casts and the three ComponentsController validate() calls). Under the
// Laravel backend, loadComponentFoundation() also hardcodes
// `intelligence: []`, so ComponentsPage's status cell rendered the toggle's
// own confidence_label/usable_samples as a garbled "— · 0" placeholder for
// any profile with adaptive_enabled=true - not just an inert toggle, but a
// dishonest-looking one. Fixed by disabling the control with a "(Coming
// soon)" label (mirroring the existing consumption_based/inspection_based
// tracking-method pattern) and replacing the garbled placeholder with an
// honest "Not yet available" when no real intelligence data exists.
const profileDialog = readFileSync(new URL('./ProfileDialog.jsx', import.meta.url), 'utf8')
const componentsPage = readFileSync(new URL('../../pages/ComponentsPage.jsx', import.meta.url), 'utf8')

test('the Adaptive foundation checkbox is disabled and honestly labeled as not yet active', () => {
  assert.match(profileDialog, /type="checkbox" checked=\{values\.adaptiveEnabled\}[\s\S]*?disabled \/>/)
  assert.match(profileDialog, /Adaptive foundation enabled \(Coming soon\)/)
  assert.match(profileDialog, /Reserved for a future release - has no effect on health or remaining-life calculations yet\./)
})

test('no user-facing string claims the toggle is already active', () => {
  assert.doesNotMatch(profileDialog, />Adaptive foundation enabled<\/span>/)
})

test('a profile with adaptive_enabled=true but no real intelligence data reports "Not yet available", never a garbled confidence/sample placeholder', () => {
  assert.match(componentsPage, /intelligence \? \(intelligence\?\.confidence_label === 'no_data' \? 'No Data' : `\$\{intelligence\?\.confidence_label \?\? '—'\} · \$\{intelligence\?\.usable_samples \?\? 0\}`\) : 'Not yet available'/)
})
