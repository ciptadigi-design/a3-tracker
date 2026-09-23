import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const page = readFileSync(new URL('../../pages/MaintenancePage.jsx', import.meta.url), 'utf8')
const section = readFileSync(new URL('./ErrorCodeKnowledgeSection.jsx', import.meta.url), 'utf8')
const dialog = readFileSync(new URL('./ErrorCodeManagementDialog.jsx', import.meta.url), 'utf8')
const ticketDialog = readFileSync(new URL('./CreateTicketDialog.jsx', import.meta.url), 'utf8')
// Code only: block/line comments are stripped where a test asserts something must not exist in the
// BEHAVIOR - a docblock explaining why a feature exists (e.g. referencing the real motivating example) is fine.
const code = (source) => source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')

// Maintenance Clean Slate Phase 1: the Error Codes tab is retired from user-facing
// navigation (see docs/M2 Maintenance Clean Slate Phase 1 report). ErrorCodeKnowledgeSection.jsx
// and ErrorCodeManagementDialog.jsx themselves are left in place, unreferenced, per the
// "prefer remove entry points over aggressive one-pass deletion" Phase 1 guidance - the
// underlying machine_error_codes/maintenance_error_solutions tables are still live (Tickets
// depends on them), just no longer reachable through this tab.
test('Maintenance page no longer exposes an Error Codes tab', () => {
  assert.doesNotMatch(page, /Error Codes<\/button>/)
  assert.doesNotMatch(page, /ErrorCodeKnowledgeSection/)
})

test('Error code search is a real input wired to the errorCodes list hook, not a client-side filter', () => {
  assert.match(section, /placeholder="Search by code or title…"/)
  assert.match(section, /useMachineErrorCodes\(\{ search \}\)/)
  assert.match(section, /setSearch\(searchInput\.trim\(\)\)/)
})

test('permission visibility: mutation affordances are gated behind machines.manage, read list is not', () => {
  assert.match(section, /const canManage = can\('machines\.manage'\)/)
  assert.match(section, /canManage && <button className="primary-button" type="button" onClick=\{\(\) => setWorkflow/)
  assert.match(section, /canManage && canManageThisRow && <button className="icon-button"/)
  assert.match(section, /Read-only access\. Only account owners\/admins/)
  // The list itself must render unconditionally on canManage - only the mutation controls are gated.
  assert.doesNotMatch(section, /canManage &&[\s\S]{0,40}<ul className="incident-narrative-list">/)
})

test('global vs owned error codes resolve edit authority the same way the backend does (Superuser for global, own-account for owned)', () => {
  assert.match(section, /code\.account_id === null \? isPlatformSuperuser : code\.account_id === account\?\.id/)
})

test('admin dialog supports both create and edit, and only shows the ordered step editor once the code exists', () => {
  assert.match(dialog, /const isEdit = Boolean\(current\?\.id\)/)
  assert.match(dialog, /\{isEdit && \(/)
  assert.match(dialog, /Ordered solution steps/)
  assert.match(dialog, /Add solution step/)
})

// V1.8.1 - published technician solutions are grouped by applicability_label; NULL displays as "General".

test('published solution steps are grouped by applicability, ordered as the API returns them (by step_number)', () => {
  assert.match(section, /function groupSolutionsByApplicability\(/)
  assert.doesNotMatch(section, /\.sort\(/, 'never re-sorted alphabetically - the API already orders by step_number')
  assert.match(section, /const label = step\.applicability_label \?\? null/)
})

test('a single unlabelled solution group renders without a visible "General" heading, but a labelled group shows its label', () => {
  assert.match(section, /solutionGroups\.length > 1 && <strong>\[\{group\.label \?\? 'General'\}\]<\/strong>/)
})

test('the section never hard-codes a real accessory label in its behavior - applicability is entirely server data', () => {
  assert.doesNotMatch(code(section), /PK-512|PK-513|PK-522/)
})

test('ticket creation autofills from the selected error code without discarding manually typed text', () => {
  assert.match(ticketDialog, /function changeErrorCode/)
  assert.match(ticketDialog, /if \(!title\.trim\(\)\) setTitle\(prefill\.title\)/)
  assert.match(ticketDialog, /if \(!description\.trim\(\)\) setDescription\(prefill\.description\)/)
  assert.match(ticketDialog, /onChange=\{\(event\) => changeErrorCode\(event\.target\.value\)\}/)
})
