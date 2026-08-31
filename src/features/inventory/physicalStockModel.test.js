import test from 'node:test'
import assert from 'node:assert/strict'
import { paginateRows } from '../pagination/paginationModel.js'
import { sortPhysicalStockRows } from './physicalStockModel.js'

function rows(entries) {
  return entries.map(([name, total], index) => ({ item: { id: `item-${index}`, name }, total }))
}

test('sorts physical stock by numeric quantity high to low before pagination', () => {
  const sorted = sortPhysicalStockRows(rows([['Item A', 0], ['Item B', 5], ['Item C', 12], ['Item D', 1]]))
  assert.deepEqual(sorted.map((row) => row.item.name), ['Item C', 'Item B', 'Item D', 'Item A'])

  const page = paginateRows(sorted, 1, 2)
  assert.deepEqual(page.rows.map((row) => row.item.name), ['Item C', 'Item B'])
})

test('keeps zero stock visible and places it last among known balances', () => {
  const sorted = sortPhysicalStockRows(rows([['A', 0], ['B', 0], ['C', 3]]))
  assert.deepEqual(sorted.map((row) => row.item.name), ['C', 'A', 'B'])
})

test('uses item name ascending as the quantity tie breaker', () => {
  const sorted = sortPhysicalStockRows(rows([['Toner Yellow', 5], ['Developer Cyan', 5], ['Charging Corona', 5]]))
  assert.deepEqual(sorted.map((row) => row.item.name), ['Charging Corona', 'Developer Cyan', 'Toner Yellow'])
})

test('sorts quantities numerically rather than lexically', () => {
  const sorted = sortPhysicalStockRows(rows([['A', 9], ['B', 20], ['C', 100]]))
  assert.deepEqual(sorted.map((row) => row.total), [100, 20, 9])
})

test('search-filtered rows remain quantity sorted', () => {
  const all = rows([['Toner Black', 1], ['Developer Cyan', 12], ['Toner Yellow', 5], ['Fuser', 20]])
  const filtered = all.filter((row) => row.item.name.toLowerCase().includes('toner'))
  assert.deepEqual(sortPhysicalStockRows(filtered).map((row) => row.item.name), ['Toner Yellow', 'Toner Black'])
})

test('re-sorts when a derived opening-balance quantity changes', () => {
  const initial = sortPhysicalStockRows(rows([['A', 0], ['B', 2]]))
  const afterOpening = sortPhysicalStockRows(rows([['A', 8], ['B', 2]]))
  assert.deepEqual(initial.map((row) => row.item.name), ['B', 'A'])
  assert.deepEqual(afterOpening.map((row) => row.item.name), ['A', 'B'])
})

test('keeps unknown balances after numeric balances without coercing them to zero', () => {
  const sorted = sortPhysicalStockRows(rows([['Unknown', null], ['Zero', 0], ['Positive', 1]]))
  assert.deepEqual(sorted.map((row) => row.item.name), ['Positive', 'Zero', 'Unknown'])
})
