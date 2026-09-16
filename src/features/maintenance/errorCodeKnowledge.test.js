import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const page = readFileSync(new URL('../../pages/MaintenancePage.jsx', import.meta.url), 'utf8')
const section = readFileSync(new URL('./ErrorCodeKnowledgeSection.jsx', import.meta.url), 'utf8')
const dialog = readFileSync(new URL('./ErrorCodeManagementDialog.jsx', import.meta.url), 'utf8')
const ticketDialog = readFileSync(new URL('./CreateTicketDialog.jsx', import.meta.url), 'utf8')

test('Maintenance page exposes an Error Codes tab that renders the knowledge section', () => {
  assert.match(page, /Error Codes/)
  assert.match(page, /tab === 'error-codes'[\s\S]*?<ErrorCodeKnowledgeSection \/>/)
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

test('ticket creation autofills from the selected error code without discarding manually typed text', () => {
  assert.match(ticketDialog, /function changeErrorCode/)
  assert.match(ticketDialog, /if \(!title\.trim\(\)\) setTitle\(prefill\.title\)/)
  assert.match(ticketDialog, /if \(!description\.trim\(\)\) setDescription\(prefill\.description\)/)
  assert.match(ticketDialog, /onChange=\{\(event\) => changeErrorCode\(event\.target\.value\)\}/)
})
