import assert from 'node:assert/strict'
import fs from 'node:fs'
import test from 'node:test'
import { primaryCostPerClickPresentation } from '../machineCost/machineCostPresentation.js'
import { formatIdrUnit } from '../machineCost/currencyFormat.js'

const overview = fs.readFileSync(new URL('../../pages/OverviewPage.jsx', import.meta.url), 'utf8')
const chart = fs.readFileSync(new URL('./DailyClickPerformanceChart.jsx', import.meta.url), 'utf8')
const settings = fs.readFileSync(new URL('../../pages/SettingsPage.jsx', import.meta.url), 'utf8')
const machineCostPage = fs.readFileSync(new URL('../../pages/MachineCostPage.jsx', import.meta.url), 'utf8')

test('M2.13.2: canonical reconciliation - Overview Cost/Click equals Machine Cost Cost/Click for the same machine/period summary', () => {
  // A single /machines/{machine}/cost response for one account/branch/machine/period,
  // as both MachineCostPage and OverviewPage receive it. Both pages must render the
  // identical Cost/Click value from it via the same presentation function - there is
  // no second economics calculation for Overview to drift from.
  const summary = { known_standard_cost_per_click: '487.3200', unknown_consumption_events: 0, unknown_error_waste_events: 0, total_clicks: 20038, counter_status: 'COMPLETE' }
  const overviewCostPerClick = primaryCostPerClickPresentation(summary, formatIdrUnit)
  const machineCostCostPerClick = primaryCostPerClickPresentation(summary, formatIdrUnit)
  assert.deepEqual(overviewCostPerClick, machineCostCostPerClick)
  assert.equal(overviewCostPerClick.value, formatIdrUnit(487.32))
})

test('M2.13.2: canonical reconciliation - an unavailable Machine Cost never renders as Rp0 on Overview', () => {
  const summary = { known_standard_cost_per_click: null, total_clicks: 0, counter_status: 'COMPLETE' }
  const presentation = primaryCostPerClickPresentation(summary, formatIdrUnit)
  assert.equal(presentation.value, 'Unavailable')
  assert.notEqual(presentation.value, formatIdrUnit(0))
})

test('M2.13.2: the large Today KPI card is removed, This Week/This Month remain', () => {
  assert.doesNotMatch(overview, /<PeriodCard label="Today"/)
  assert.match(overview, /<PeriodCard label="This Week"/)
  assert.match(overview, /<PeriodCard label="This Month"/)
})

test('M2.13.2: Overview Cost/Click reuses the canonical Machine Cost presentation, no separate economics', () => {
  assert.match(overview, /import \{ primaryCostPerClickPresentation \} from '\.\.\/features\/machineCost\/machineCostPresentation\.js'/)
  assert.match(overview, /primaryCostPerClickPresentation\(summary, formatIdrUnit\)/)
  // The exact same function MachineCostPage already renders its Cost/Click card with.
  assert.match(machineCostPage, /primaryCostPerClickPresentation/)
  assert.doesNotMatch(overview, /known_component_cost_per_click|component consumption.*error.*waste ÷|standard\s*=\s*.*\+.*loss/i)
})

test('M2.13.2: Overview Cost/Click loads from the canonical Machine Cost period endpoint, not a bespoke Overview endpoint', () => {
  assert.match(overview, /import \{ loadMachineCostPeriod \} from '\.\.\/services\/machineCost\.js'/)
  assert.match(overview, /resolveMachineCostPeriod\(\{ preset: 'this_month', timezone \}\)/)
})

test('M2.13.2: Today context is preserved near the Daily Click Performance chart, not as a large card', () => {
  assert.match(overview, /todayContextPresentation/)
  assert.match(overview, /<DailyClickPerformanceChart rows={dailyRows} todayContext={todayContext} \/>/)
  assert.match(chart, /function TodayContext/)
})

test('M2.13.2: Overview Manage Target and the no-target Set Target action both route to the canonical Click Targets page', () => {
  const matches = overview.match(/navigate\?\.\('\/settings\/click-targets'\)/g) ?? []
  assert.ok(matches.length >= 2, 'expected both the hero "Manage target" and empty-state "Set monthly target" actions to share the same route')
})

test('M2.13.2: chart keeps grouped Actual (blue) vs Target (red) bars unchanged', () => {
  assert.match(chart, /daily-performance-bar-actual/)
  assert.match(chart, /daily-performance-bar-target/)
  assert.match(chart, /className="trend-excluded-marker"/)
})

test('M2.13.2: compact bar labels never label a null actual, and are presentation-only (exact tooltip untouched)', () => {
  assert.match(chart, /showActual && <text className="daily-performance-label-actual"/)
  assert.match(chart, /row\.actual != null/)
  // Exact values for the tooltip must still come from dailyPerformanceTooltip, not the compact labels.
  assert.match(chart, /const tooltip = dailyPerformanceTooltip\(row\)/)
  assert.match(chart, /<title>\{tooltip\}<\/title>/)
})

test('M2.13.2: target bar labels are deduplicated by value change, not shown on every bar, and suppressed on narrow width', () => {
  assert.match(chart, /plannedChanged/)
  assert.match(chart, /showTarget = !compact && !isExcluded && plannedChanged/)
})

test('M2.13.2: Settings > Operations keeps its existing content and gains a Click Targets gateway to the same route (no duplicate page)', () => {
  assert.match(settings, /Operational behavior/)
  assert.match(settings, /Operators \/ PIC/)
  assert.match(settings, /<h2>Click Targets<\/h2>/)
  assert.match(settings, /onClick={\(\) => navigate\('\/settings\/click-targets'\)}/)
})

test('M2.13.2: Machine Models remains a distinct Settings section, no new sidebar item was added', () => {
  // Section tuples are ['id', 'Label', IconComponent] - the icon reference is
  // always a capitalized identifier, distinguishing this array from the
  // unrelated `capabilities` tuples (['key', 'label', booleanLiteral]).
  const sectionIds = [...settings.matchAll(/\['(\w+)', '[^']+', ([A-Z]\w*)\]/g)].map((match) => match[1])
  assert.deepEqual(sectionIds, ['workspace', 'branches', 'members', 'permissions', 'operations', 'models'])
})

test('M2.13.2: the Click Targets gateway respects existing Settings authorization (hidden/read-only when not authorized)', () => {
  assert.match(settings, /\{canManage && <button className="secondary-button compact-button" type="button" onClick={\(\) => navigate\('\/settings\/click-targets'\)}/)
  assert.match(settings, /\{!canManage && <p className="click-target-gateway-readonly">/)
})
