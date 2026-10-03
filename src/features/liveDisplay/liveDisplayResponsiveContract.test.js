import assert from 'node:assert/strict'
import fs from 'node:fs'
import test from 'node:test'

const css = fs.readFileSync(new URL('../../App.css', import.meta.url), 'utf8')
const machineDetail = fs.readFileSync(new URL('../machines/MachineDetailPage.jsx', import.meta.url), 'utf8')

function rule(selector) {
  const start = css.indexOf(`${selector} {`)
  assert.ok(start >= 0, `expected a "${selector}" rule in App.css`)
  const end = css.indexOf('}', start)
  return css.slice(start, end)
}

// V1.1 regression: Production acceptance found Monthly Target content
// overflowing its card at 1366x768/1280x720 and bleeding into Recent
// Activity. Root cause was the layout MODEL, not any single pixel value -
// `.live-display-root` only had `min-height: 100vh` (a floor, not a
// ceiling), so overflowing content just grew the root taller than the
// viewport instead of being constrained to it, and every internal gap/font
// size was a fixed px that could not shrink on a shorter viewport. These
// tests guard the fix at the model level: a ceiling on the root, and fluid
// (clamp/vh) sizing on every piece of the Monthly Target panel - not just a
// single patched value for one screenshot's dimensions.
test('the live display root has a hard viewport height ceiling, not just a floor', () => {
  const root = rule('.live-display-root')
  assert.match(root, /height:\s*100vh/)
  assert.match(root, /max-height:\s*100vh/)
  assert.doesNotMatch(root, /min-height:\s*100vh/, 'a floor alone does not stop overflowing content from growing past the viewport')
})

test('every panel clips its own overflow instead of bleeding into a sibling card', () => {
  assert.match(rule('.live-panel'), /overflow:\s*hidden/)
})

test('Monthly Target panel sizing is fluid (clamp/vh), not fixed px, for every piece that overflowed in Production', () => {
  for (const selector of ['.live-target-actual', '.live-target-progress-track', '.live-target-percentage', '.live-target-status', '.live-target-metrics']) {
    const block = rule(selector)
    assert.match(block, /clamp\(/, `${selector} must use fluid clamp() sizing`)
  }
})

test('Current Counter hero typography is fluid against both viewport width and height', () => {
  const hero = rule('.live-current-counter-value')
  assert.match(hero, /clamp\(/)
  assert.match(hero, /vh/, 'the hero number must scale against viewport height, not only width, since height is the constrained dimension on a shorter TV viewport')
})

test('Recent Activity drops to fewer rows at short viewport heights instead of shrinking rows unreadably or scrolling', () => {
  assert.match(css, /@media \(max-height:\s*\d+px\)\s*\{\s*\.live-activity-row:nth-child\(n\+4\)\s*\{\s*display:\s*none;/)
})

test('Recent Activity list itself is never internally scrollable', () => {
  const list = rule('.live-activity-list')
  assert.doesNotMatch(list, /overflow(-y)?:\s*(auto|scroll)/)
})

// Machine Detail entry point (task section 6): deterministic, from the
// machine's own real id, no selector introduced anywhere.
test('Machine Detail exposes a secondary Live Display action for its own machine id', () => {
  assert.match(machineDetail, /href=\{`\/display\/live\/\$\{machine\.id\}`\}/)
  assert.match(machineDetail, /target="_blank"/)
})

test('Machine Detail does not introduce a machine selector for Live Display', () => {
  assert.doesNotMatch(machineDetail, /display\/live.*<select/s)
})
