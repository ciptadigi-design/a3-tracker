import assert from 'node:assert/strict'
import fs from 'node:fs'
import test from 'node:test'

const overview = fs.readFileSync(new URL('../../pages/OverviewPage.jsx', import.meta.url), 'utf8')
const chart = fs.readFileSync(new URL('./DailyClickPerformanceChart.jsx', import.meta.url), 'utf8')
const model = fs.readFileSync(new URL('./clickTargetModel.js', import.meta.url), 'utf8')
const css = fs.readFileSync(new URL('../../App.css', import.meta.url), 'utf8')

test('M2.14: M2.20D.1 selected period card retains the shared comparison row', () => {
  assert.match(overview, /<PeriodComparisonRow comparison={presentation\.comparison} \/>/)
  assert.doesNotMatch(overview, /card-kicker">Last Month/)
  assert.match(overview, /<PeriodCard label={selectedCardLabel} card={projection\.selected} \/>/)
})

test('M2.14: selected range does not falsely label arbitrary periods as MTD', () => {
  assert.doesNotMatch(overview, /monthToDate={true}/)
})

test('M2.14: comparison presentation is computed once in clickTargetModel, not re-derived in the page or chart', () => {
  assert.match(overview, /import \{[^}]*periodCardPresentation[^}]*\} from '\.\.\/features\/clickTargets\/clickTargetModel\.js'/)
  assert.doesNotMatch(overview, /delta_percentage|comparison_status/) // raw backend fields never touched outside the model
  assert.doesNotMatch(chart, /delta_percentage|comparison_status/)
})

test('M2.14: the daily chart still renders only Actual (blue) and Target (red) - no third historical bar or line', () => {
  assert.match(chart, /daily-performance-bar-actual/)
  assert.match(chart, /daily-performance-bar-target/)
  assert.doesNotMatch(chart, /historical|previousMonth|previous_month/i)
  // Exactly two bar rects per group (actual, target) plus the excluded marker - no additional geometry per row.
  const barRectCount = (chart.match(/<rect className="daily-performance-bar-/g) ?? []).length
  assert.equal(barRectCount, 2)
})

test('M2.14: the tooltip builder (not the chart) owns the previous-month block, keeping the SVG title as the single tooltip surface', () => {
  assert.match(model, /PREVIOUS MONTH/)
  assert.match(chart, /const tooltip = dailyPerformanceTooltip\(row\)/)
  assert.match(chart, /<title>\{tooltip\}<\/title>/)
})

test('M2.14: comparison uses subtle text/arrow carriers, not color alone, and stays off the aggressive success/danger palette', () => {
  assert.match(model, /comparisonArrowByDirection/)
  assert.doesNotMatch(model, /gamif|leaderboard/i)
})

test('M2.14: comparison styling reuses the existing tone tokens (no hard-coded raw colors)', () => {
  assert.match(css, /\.overview-period-comparison\.tone-green span \{ color: var\(--green\); \}/)
  assert.match(css, /\.overview-period-comparison\.tone-warning span \{ color: var\(--warning\); \}/)
  assert.doesNotMatch(css.match(/\.overview-period-comparison[^}]*\{[^}]*\}/g)?.join('\n') ?? '', /#[0-9a-fA-F]{3,6}/)
})

test('M2.14: mobile hides the secondary "Previous: X" value but keeps the percentage/period line', () => {
  assert.match(css, /@media \(max-width: 860px\) \{[^]*?\.overview-period-comparison b \{ display: none; \}[^]*?\}/)
})
