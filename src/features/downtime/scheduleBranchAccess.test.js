import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { effectiveScheduleBranchId, resolveScheduleBranches } from './downtimeModel.js'

const section = readFileSync(new URL('./schedule/ScheduleSettingsSection.jsx', import.meta.url), 'utf8')
const settingsPage = readFileSync(new URL('../../pages/SettingsPage.jsx', import.meta.url), 'utf8')
const branch = (id, name, extra = {}) => ({ id, name, is_active: true, ...extra })

test('single assigned branch is selected automatically and flagged single so the selector locks', () => {
  const result = resolveScheduleBranches([branch('t', 'Tuparev')])
  assert.equal(result.mode, 'single')
  assert.equal(result.defaultBranchId, 't')
  assert.deepEqual(result.branches.map((item) => item.name), ['Tuparev'])
})

test('multiple assigned branches stay selectable and only those branches are offered', () => {
  const result = resolveScheduleBranches([branch('t', 'Tuparev'), branch('g', 'Graha')])
  assert.equal(result.mode, 'multiple')
  assert.deepEqual(result.branches.map((item) => item.name), ['Tuparev', 'Graha'])
})

test('organization-wide access lists every branch the server returned, with no account-name special casing', () => {
  const all = ['Cianjur', 'Graha', 'Purwakarta', 'Tuparev'].map((name) => branch(name, name))
  assert.equal(resolveScheduleBranches(all).branches.length, 4)
  assert.doesNotMatch(section, /imadprakoso|username/i)
})

test('no authorized branch yields the empty state and never a fallback branch', () => {
  for (const input of [[], undefined, null, [branch('x', 'Nonaktif', { is_active: false })]]) {
    const result = resolveScheduleBranches(input)
    assert.equal(result.mode, 'none')
    assert.equal(result.defaultBranchId, '')
  }
  assert.equal(effectiveScheduleBranchId([], 'anything'), '')
  assert.match(section, /Akun ini belum ditugaskan ke cabang mana pun/)
})

test('a stale or unauthorized selection collapses to an authorized branch', () => {
  const branches = [branch('t', 'Tuparev'), branch('g', 'Graha')]
  assert.equal(effectiveScheduleBranchId(branches, 'g'), 'g')
  assert.equal(effectiveScheduleBranchId(branches, 'cianjur'), 't')
  assert.equal(effectiveScheduleBranchId(branches, ''), 't')
})

test('the selector is disabled for a single branch and all three tabs reset per branch', () => {
  assert.match(section, /const locked = branches\.length === 1/)
  assert.match(section, /disabled=\{locked\}/)
  for (const panel of ['BranchSchedulePanel', 'MachineOverridePanel', 'ExceptionsPanel']) assert.match(section, new RegExp(`<${panel} key=\\{branchId\\}`))
})

test('late responses for a previously selected branch cannot overwrite the current form', () => {
  assert.equal((section.match(/isCurrent\(\)/g) || []).length >= 8, true)
  assert.match(section, /machineChoice\.branchId === branchId/)
  assert.match(section, /scopedMachine/)
})

test('Settings relies on the server-scoped branch list for the schedule section', () => {
  assert.match(settingsPage, /<ScheduleSettingsSection account=\{account\} branches=\{data\.branches\} \/>/)
})

test('stacked page headers never inherit the row-direction 320px flex-basis as a height', () => {
  const css = readFileSync(new URL('../../App.css', import.meta.url), 'utf8')
  // Desktop row layout keeps its width basis for the copy block.
  assert.match(css, /\.page-header-copy \{ min-width: 240px; flex: 1 1 320px; \}/)
  // Every rule that turns .page-header into a column must reset that basis (it would become a height).
  assert.match(css, /\.page-header \{ align-items: flex-start; flex-direction: column; \}\n\s+\.page-header-copy \{ flex: 0 0 auto; min-width: 0; \}/)
  assert.match(css, /\.components-page > \.page-header \{ align-items: flex-start; flex-direction: column; \} \.components-page > \.page-header > \.page-header-copy \{ flex: 0 0 auto; \}/)
})
