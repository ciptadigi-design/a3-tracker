import assert from 'node:assert/strict'
import fs from 'node:fs'
import test from 'node:test'

const page = fs.readFileSync(new URL('../../pages/DailyPage.jsx', import.meta.url), 'utf8')
const entry = fs.readFileSync(new URL('./CounterEntryCard.jsx', import.meta.url), 'utf8')

test('Daily primary KPIs are Last Counter, Today Usage, and Last Input only', () => {
  for (const label of ['Last Counter', "Today's Usage", 'Last Input']) assert.match(page, new RegExp(`label="${label.replace("'", "'")}"`))
  assert.doesNotMatch(page, /label="Today's Entries"/)
  assert.equal((page.match(/<SummaryCard/g) ?? []).length, 3)
})

test('Counter form removes duplicate type and counter blocks but keeps submission and history', () => {
  assert.doesNotMatch(entry, /Counter type/)
  assert.doesNotMatch(entry, /Current \/ last counter/)
  assert.match(entry, /Last recorded:/)
  assert.match(entry, /Record counter/)
  assert.match(page, /<CounterHistory/)
})

test('multiple readings and existing database submission remain unchanged', () => {
  assert.match(entry, /Multiple chronological entries per day are supported/)
  assert.match(entry, /recordCounterReading/)
})
