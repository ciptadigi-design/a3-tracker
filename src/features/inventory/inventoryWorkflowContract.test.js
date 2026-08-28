import assert from 'node:assert/strict'
import fs from 'node:fs'
import test from 'node:test'

const itemDialog = fs.readFileSync(new URL('./InventoryDialogs.jsx', import.meta.url), 'utf8')
const purchaseDialog = fs.readFileSync(new URL('./PurchasingDialogs.jsx', import.meta.url), 'utf8')
const inventoryPage = fs.readFileSync(new URL('../../pages/InventoryPage.jsx', import.meta.url), 'utf8')

test('Add Item exposes eligible Component Catalog choices without raw identifiers', () => {
  assert.match(itemDialog, /<span>Component <small>Optional for non-machine stock<\/small><\/span>/)
  assert.match(itemDialog, /component\.name} · {component\.code}/)
  assert.doesNotMatch(itemDialog, />{component\.id}</)
})

test('Component defaults only Display Name while SKU and physical unit remain explicit', () => {
  assert.match(itemDialog, /name: item\?\.name \?\? initialComponent\?\.name \?\? ''/)
  assert.match(itemDialog, /sku: item\?\.sku \?\? ''/)
  assert.match(itemDialog, /unit: item\?\.unit \?\? ''/)
  assert.match(itemDialog, /Choose physical unit/)
})

test('Purchase discovery supports item, SKU, and Component with nested item creation', () => {
  assert.match(purchaseDialog, /Search item, SKU, or Component/)
  assert.match(purchaseDialog, /No inventory item configured/)
  assert.match(purchaseDialog, /Create inventory item/)
  assert.match(purchaseDialog, /<InventoryItemDialog/)
})

test('inline creation keeps Purchase mounted, uses an independent item draft, and selects the result', () => {
  assert.match(purchaseDialog, /draftEntityId={`purchase:\${inlineCreate\.component\.id}`}/)
  assert.match(purchaseDialog, /changeLine\(inlineCreate\.rowId, 'itemId', created\.id\)/)
  assert.match(purchaseDialog, /inventory-purchase', entityId: 'new'/)
  assert.match(inventoryPage, /Inventory item created and selected\. Purchase draft preserved; stock remains unchanged\./)
})

test('Purchase and Receive Goods remain separate stock semantics', () => {
  assert.match(purchaseDialog, /Saving this purchase does not increase stock/)
  assert.match(purchaseDialog, /Stock changes only after Receive Goods/)
  assert.match(inventoryPage, /createInventoryPurchase/)
  assert.match(inventoryPage, /receiveInventoryPurchase/)
})
