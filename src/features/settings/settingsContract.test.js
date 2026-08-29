import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const page = readFileSync(new URL('../../pages/SettingsPage.jsx', import.meta.url), 'utf8')
const service = readFileSync(new URL('../../services/supabase/settings.js', import.meta.url), 'utf8')
const sidebar = readFileSync(new URL('../../components/layout/Sidebar.jsx', import.meta.url), 'utf8')
const app = readFileSync(new URL('../../app/AppShell.jsx', import.meta.url), 'utf8')
const css = readFileSync(new URL('../../App.css', import.meta.url), 'utf8')

test('Settings route and seven-section control-plane IA are active', () => {
  assert.match(app, /path === '\/settings'.*SettingsPage/)
  assert.doesNotMatch(app, /'\/settings': \['Settings'/)
  for (const label of ['Workspace', 'Branches', 'Members & Roles', 'Permissions', 'Operations', 'Machine Models', 'Advanced']) assert.match(page, new RegExp(label.replace('&', '\\&')))
  assert.match(sidebar, /Settings, active: true/)
  assert.match(sidebar, /isPlatformSuperuser && <NavLink/)
  assert.doesNotMatch(sidebar, /membership\?\.role|\['owner'/)
  assert.match(app, /path === '\/settings' && tenant\.isPlatformSuperuser/)
  assert.match(app, /Settings is temporarily available only to Platform Superusers/)
})

test('Workspace and branch forms preserve drafts, timezone inheritance, and compact mobile actions', () => {
  assert.match(page, /usePersistentDraft/)
  assert.match(page, /supportedTimezones/)
  assert.match(page, /Machine → branch → workspace/i)
  assert.match(page, /form-action-footer/)
  assert.match(page, /draft-reset-button/)
  assert.match(css, /\.machine-form > \.form-action-footer/)
  assert.match(css, /@media \(max-width: 430px\)/)
})

test('Settings mutations use DB-authoritative idempotent RPCs', () => {
  for (const rpc of ['manage_workspace_settings', 'manage_settings_branch', 'manage_settings_membership', 'manage_operational_permissions', 'manage_advanced_economics_setting']) assert.match(service, new RegExp(rpc))
  assert.match(service, /target_client_request_id/)
})

test('owner protection, lifecycle actions, and destructive confirmations are explicit', () => {
  assert.match(page, /last active Owner/)
  assert.match(page, /Historical attribution remains visible/)
  assert.match(page, /BlockingDialog/)
  assert.match(page, /Archive branch/)
  assert.match(page, /Restore branch/)
})

test('operational CRUD is handed off instead of duplicated', () => {
  assert.match(page, /Manage Components/)
  assert.match(page, /navigate\('\/components'\)/)
  assert.match(page, /Open Inventory/)
  assert.match(page, /navigate\('\/inventory'\)/)
  assert.doesNotMatch(page, /saveInventoryItem|saveComponent|saveProfile/)
})

test('permission matrix exposes exactly the seven configurable Operator capabilities', () => {
  const keys = [...page.matchAll(/\['(operator_can_[a-z_]+)',/g)].map((match) => match[1])
  assert.deepEqual(keys, [
    'operator_can_initialize_component', 'operator_can_replace_component', 'operator_can_create_purchase',
    'operator_can_receive_goods', 'operator_can_adjust_inventory', 'operator_can_transfer_inventory',
    'operator_can_log_errors',
  ])
})

test('Advanced Economics copy preserves Standard and forbids fabricated costs', () => {
  assert.match(page, /Standard remains unchanged/)
  assert.match(page, /does not create or backfill costs/)
})
