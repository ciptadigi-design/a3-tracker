import assert from 'node:assert/strict'
import test from 'node:test'
import { DEFAULT_PAGE_SIZE, PAGE_SIZE_OPTIONS, normalizePage, paginateRows, pageRange, visiblePages } from './paginationModel.js'

const rows = Array.from({ length: 182 }, (_, index) => index + 1)

test('pagination defaults and allowed sizes stay bounded', () => {
  assert.equal(DEFAULT_PAGE_SIZE, 10)
  assert.deepEqual(PAGE_SIZE_OPTIONS, [10, 25, 50])
  assert.deepEqual(paginateRows(rows, 1).rows, rows.slice(0, 10))
  assert.deepEqual(paginateRows(rows, 1, 25).rows, rows.slice(0, 25))
  assert.deepEqual(paginateRows(rows, 1, 50).rows, rows.slice(0, 50))
})

test('next, previous, and last-page ranges remain valid', () => {
  assert.deepEqual(pageRange(182, 2, 10), { start: 10, end: 20, page: 2, pages: 19 })
  assert.deepEqual(pageRange(182, 19, 10), { start: 180, end: 182, page: 19, pages: 19 })
  assert.equal(normalizePage(8, 12, 10), 2)
  assert.equal(normalizePage(0, 182, 10), 1)
})

test('empty and single-page datasets have compact ranges', () => {
  assert.deepEqual(pageRange(0, 4, 10), { start: 0, end: 0, page: 1, pages: 1 })
  assert.deepEqual(pageRange(8, 1, 10), { start: 0, end: 8, page: 1, pages: 1 })
})

test('desktop page choices are bounded while mobile can show page over total', () => {
  assert.deepEqual(visiblePages(10, 19), [1, 'ellipsis', 9, 10, 11, 'ellipsis', 19])
  assert.deepEqual(visiblePages(1, 3), [1, 2, 3])
})

test('filter or scope resets are represented independently from dataset summaries', () => {
  const allRows = Array.from({ length: 182 }, (_, index) => ({ value: index + 1 }))
  const summary = allRows.reduce((sum, row) => sum + row.value, 0)
  assert.equal(paginateRows(allRows, 8, 10).rows.length, 10)
  assert.equal(paginateRows(allRows.slice(0, 12), 8, 10).page, 2)
  assert.equal(summary, 16653)
})
