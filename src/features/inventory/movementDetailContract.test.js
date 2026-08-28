import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const inventoryPage = await readFile(new URL('../../pages/InventoryPage.jsx', import.meta.url), 'utf8')
const detailDialog = await readFile(new URL('./InventoryMovementDetailDialog.jsx', import.meta.url), 'utf8')
const blockingDialog = await readFile(new URL('../../components/ui/BlockingDialog.jsx', import.meta.url), 'utf8')

test('movement list uses the accessible Eye detail action and has no inline floating detail', () => {
  assert.match(inventoryPage, /aria-label="View movement details"/)
  assert.match(inventoryPage, /<Eye size=\{16\}/)
  assert.doesNotMatch(inventoryPage, /<details><summary>Details/)
})

test('read-only movement dialog has an explicit Close action', () => {
  assert.match(detailDialog, />Close<\/button>/)
  assert.match(detailDialog, /DialogFrame/)
})

test('shared blocking dialog preserves Escape and focus restoration contracts', () => {
  assert.match(blockingDialog, /event\.key === 'Escape'/)
  assert.match(blockingDialog, /previouslyFocused\.focus\(\)/)
  assert.match(blockingDialog, /event\.key !== 'Tab'/)
})
