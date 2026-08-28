import assert from 'node:assert/strict'
import fs from 'node:fs'
import test from 'node:test'

const itemDialog = fs.readFileSync(new URL('./InventoryDialogs.jsx', import.meta.url), 'utf8')
const purchaseDialog = fs.readFileSync(new URL('./PurchasingDialogs.jsx', import.meta.url), 'utf8')
const inventoryPage = fs.readFileSync(new URL('../../pages/InventoryPage.jsx', import.meta.url), 'utf8')
const blockingDialog = fs.readFileSync(new URL('../../components/ui/BlockingDialog.jsx', import.meta.url), 'utf8')
const css = fs.readFileSync(new URL('../../App.css', import.meta.url), 'utf8')

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
  assert.match(purchaseDialog, /Search item, SKU, or Component\.\.\./)
  assert.match(purchaseDialog, /No inventory item configured/)
  assert.match(purchaseDialog, /Create inventory item/)
  assert.match(purchaseDialog, /<InventoryItemDialog/)
})

test('Purchase item picker is a full-width line-scoped combobox with anchored results', () => {
  assert.match(purchaseDialog, /role="combobox"/)
  assert.match(purchaseDialog, /role="listbox"/)
  assert.match(purchaseDialog, /role="option"/)
  assert.match(purchaseDialog, /purchase-item-options-\$\{line\.rowId\}/)
  assert.match(css, /\.purchase-item-discovery[^\n]+grid-column: 1 \/ -1/)
  assert.match(css, /\.purchase-item-results[^\n]+width: 100%[^\n]+position: absolute[^\n]+z-index: 20/)
  assert.match(css, /max-height: min\(260px,40vh\)/)
})

test('Purchase item keyboard selection supports arrows, Enter, and Escape', () => {
  for (const key of ['ArrowDown', 'ArrowUp', 'Enter', 'Escape']) assert.match(purchaseDialog, new RegExp(`event\\.key === '${key}'`))
  assert.match(purchaseDialog, /selectLineItem\(line\.rowId, visibleItems\[highlightedIndex\]\)/)
  assert.match(blockingDialog, /getAttribute\('role'\) === 'combobox'[\s\S]+getAttribute\('aria-expanded'\) === 'true'/)
})

test('item selection and picker state stay scoped to the matching purchase line', () => {
  assert.match(purchaseDialog, /highlightedItems\[line\.rowId\]/)
  assert.match(purchaseDialog, /openItemPicker === line\.rowId/)
  assert.match(purchaseDialog, /line\.rowId === rowId \? \{ \.\.\.line, itemId: item\.id, itemSearch:/)
  assert.match(purchaseDialog, /line\.rowId === rowId \? \{ \.\.\.line, \[field\]: value \} : line/)
})

test('Purchase line layout separates discovery from values and stacks on mobile', () => {
  assert.match(css, /\.purchase-line-editor[^\n]+grid-template-columns: minmax\(100px,\.7fr\) minmax\(160px,1\.1fr\) minmax\(130px,\.9fr\) 38px/)
  assert.match(css, /@media \(max-width: 860px\)[\s\S]+\.purchase-line-editor \{ grid-template-columns: repeat\(2,minmax\(0,1fr\)\)/)
  assert.match(css, /@media \(max-width: 680px\)[\s\S]+\.purchase-line-editor \{ grid-template-columns: minmax\(0,1fr\)/)
  assert.match(purchaseDialog, /aria-label={`Remove purchase line \$\{index \+ 1\}`}/)
})

test('inline creation keeps Purchase mounted, uses an independent item draft, and selects the result', () => {
  assert.match(purchaseDialog, /draftEntityId={`purchase:\${inlineCreate\.component\.id}`}/)
  assert.match(purchaseDialog, /selectLineItem\(inlineCreate\.rowId, created\)/)
  assert.match(purchaseDialog, /line\.rowId === rowId \? \{ \.\.\.line, itemId: item\.id, itemSearch: inventoryItemLabel\(item\) \}/)
  assert.match(purchaseDialog, /inventory-purchase', entityId: 'new'/)
  assert.match(inventoryPage, /Inventory item created and selected\. Purchase draft preserved; stock remains unchanged\./)
})

test('Purchase and Receive Goods remain separate stock semantics', () => {
  assert.match(purchaseDialog, /Saving this purchase does not increase stock/)
  assert.match(purchaseDialog, /Stock changes only after Receive Goods/)
  assert.match(inventoryPage, /createInventoryPurchase/)
  assert.match(inventoryPage, /receiveInventoryPurchase/)
})
