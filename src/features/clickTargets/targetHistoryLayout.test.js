import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const page = readFileSync(new URL('../../pages/ClickTargetSettingsPage.jsx', import.meta.url), 'utf8')
const styles = readFileSync(new URL('../../App.css', import.meta.url), 'utf8')

test('target history uses separate semantic event and timestamp elements for every row', () => {
  assert.match(page, /history\.map\(\(row\) => <article className="target-history-item"/)
  assert.match(page, /<strong>Target \{row\.previous_target == null \? 'set' : 'revised'\} to/)
  assert.match(page, /<time dateTime=\{row\.created_at\}>\{formatTargetHistoryTimestamp\(row\.created_at\)\}<\/time>/)
  assert.doesNotMatch(page, /new Date\(row\.created_at\)\.toLocaleString\(\)/)
})

test('target history retains an explicit empty state and existing response metadata', () => {
  assert.match(page, /history\.length === 0/)
  assert.match(page, /No revisions yet\./)
  assert.match(page, /Previous target:/)
  assert.match(page, /Reason:/)
})

test('history rows are padded, divided, and constrained against overflow', () => {
  assert.match(styles, /\.target-history-list \{ min-width: 0; display: grid; \}/)
  assert.match(styles, /\.target-history-item \{[^}]*min-width: 0;[^}]*grid-template-columns: auto minmax\(0,1fr\);[^}]*padding: 14px 20px;[^}]*border-top:/)
  assert.match(styles, /\.target-history-copy \{ min-width: 0; display: grid; gap: 4px; \}/)
  assert.match(styles, /\.target-history-copy > strong \{[^}]*overflow-wrap: anywhere;/)
})

test('mobile history preserves the stacked hierarchy inside narrower card padding', () => {
  const mobileRuleIndex = styles.indexOf('.target-history-item { padding: 14px 16px; }')
  const mobileMediaIndex = styles.lastIndexOf('@media (max-width: 767px)', mobileRuleIndex)
  assert.ok(mobileMediaIndex >= 0 && mobileMediaIndex < mobileRuleIndex)
  assert.match(styles, /\.target-history-copy > time \{ color: var\(--text-muted\); font-size: 9px;/)
})
