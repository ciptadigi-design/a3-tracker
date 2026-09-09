import { useEffect, useRef, useState } from 'react'
import { BarChart3 } from 'lucide-react'
import { dailyPerformanceTooltip, formatClicks, formatCompactClicks, hasAnyPlannedOrActual } from './clickTargetModel.js'

const axisDate = (value) => `${value.slice(8, 10)}/${value.slice(5, 7)}`

function TodayContext({ todayContext }) {
  if (!todayContext) return null
  return <div className={`daily-click-performance-today tone-${todayContext.tone}`}>
    <span>{todayContext.label}</span>{todayContext.detail && <small>{todayContext.detail}</small>}
  </div>
}

/**
 * Upgrades the existing hand-drawn Daily Click Trend chart (see
 * MachineCostPage's DailyTrendChart) into grouped Actual/Target bars.
 * Reuses the same scale/axis/responsive approach; no chart library is
 * introduced since none exists in this codebase.
 */
export function DailyClickPerformanceChart({ rows, todayContext }) {
  const hasData = hasAnyPlannedOrActual(rows)
  const chartWrapRef = useRef(null)
  const [chartWidth, setChartWidth] = useState(920)

  useEffect(() => {
    const element = chartWrapRef.current
    if (!element) return undefined
    const updateWidth = (value) => setChartWidth(Math.max(320, Math.round(value)))
    updateWidth(element.getBoundingClientRect().width)
    const observer = new ResizeObserver(([entry]) => updateWidth(entry.contentRect.width))
    observer.observe(element)
    return () => observer.disconnect()
  }, [hasData])

  if (!hasData) {
    return <section className="machine-cost-panel machine-cost-trend-panel glass-surface"><header><div><span className="card-kicker">Operational trend</span><h2>Daily Click Performance</h2><p className="machine-cost-trend-helper">Actual clicks against the planned target for each day.</p></div><TodayContext todayContext={todayContext} /></header><div className="machine-cost-empty compact"><BarChart3 size={22} /><strong>No recorded activity or target for this period.</strong></div></section>
  }

  const width = chartWidth; const height = 300; const compact = width < 520; const left = compact ? 44 : 60; const right = compact ? 12 : 24; const top = 48; const bottom = 52
  const plotWidth = width - left - right; const plotHeight = height - top - bottom
  const maxValue = Math.max(1, ...rows.map((row) => Math.max(row.actual ?? 0, row.planned ?? 0)))
  const scaleStep = 10 ** Math.floor(Math.log10(maxValue)) / 5
  const axisMaximum = Math.ceil((maxValue * 1.15) / scaleStep) * scaleStep
  const step = plotWidth / Math.max(1, rows.length)
  const groupWidth = Math.max(4, Math.min(30, step * .62))
  const barWidth = groupWidth / 2
  const x = (index) => left + step * index + step / 2
  const valueY = (value) => top + plotHeight - (Number(value) / axisMaximum) * plotHeight
  const maximumDateTicks = Math.max(3, Math.floor(plotWidth / 70))
  const labelEvery = Math.max(1, Math.ceil(rows.length / maximumDateTicks))
  const denseLabels = step < 42

  // Compact value labels: Actual is the priority signal, shown at a density
  // that avoids overlap (every Nth day when bars are packed tight). Target
  // rarely varies day to day (it's a flat monthly allocation almost every
  // time), so labeling it on every bar would just repeat the same number
  // across the whole chart - instead it's only labeled where the planned
  // value actually changes from the previous day, and only on non-mobile
  // widths where there is room for a second row of tiny text.
  const minLabelSpacing = compact ? 46 : 34
  const valueLabelEvery = Math.max(1, Math.ceil(minLabelSpacing / step))
  const { plan: labelPlan } = rows.reduce((acc, row, index) => {
    const isExcluded = row.calendarStatus === 'EXCLUDED'
    const showActual = !isExcluded && index % valueLabelEvery === 0 && row.actual != null
    const plannedChanged = row.planned != null && row.planned !== acc.previousPlanned
    const showTarget = !compact && !isExcluded && plannedChanged
    acc.plan.push({ showActual, showTarget })
    if (row.planned != null) acc.previousPlanned = row.planned

    return acc
  }, { plan: [], previousPlanned: null })

  return <section className="machine-cost-panel machine-cost-trend-panel glass-surface daily-click-performance-panel">
    <header>
      <div><span className="card-kicker">Operational trend</span><h2>Daily Click Performance</h2><p className="machine-cost-trend-helper">Actual clicks (blue) against the planned target (red) for each day.</p></div>
      <div className="daily-click-performance-header-meta">
        <TodayContext todayContext={todayContext} />
        <div className="daily-click-performance-legend" aria-hidden="true"><span className="legend-swatch legend-actual" />Actual<span className="legend-swatch legend-target" />Target</div>
      </div>
    </header>
    <div className="machine-cost-chart-wrap" ref={chartWrapRef}>
      <svg className="machine-cost-chart daily-click-performance-chart" viewBox={`0 0 ${width} ${height}`} role="img" aria-labelledby="daily-performance-title daily-performance-description">
        <title id="daily-performance-title">Daily Click Performance</title>
        <desc id="daily-performance-description">Grouped bars per day: actual clicks in blue, planned target clicks in red. Excluded days are marked and carry zero target.</desc>
        {[0, .5, 1].map((ratio) => <g key={ratio}><line className="trend-grid-line" x1={left} x2={width - right} y1={top + plotHeight * ratio} y2={top + plotHeight * ratio} /><text className="trend-axis-label" x={left - 10} y={top + plotHeight * ratio + 4} textAnchor="end">{formatClicks(Math.round(axisMaximum * (1 - ratio)))}</text></g>)}
        <text className="trend-axis-title" x={left} y={18}>Clicks</text>
        {rows.map((row, index) => {
          const groupX = x(index)
          const tooltip = dailyPerformanceTooltip(row)
          if (row.calendarStatus === 'EXCLUDED') {
            return <g key={`excluded-${row.date}`}><rect className="trend-excluded-marker" x={groupX - groupWidth / 2} y={top + plotHeight - 6} width={groupWidth} height={6} rx="2"><title>{tooltip}</title></rect></g>
          }
          const { showActual, showTarget } = labelPlan[index]
          const actualLabelX = groupX - barWidth / 2
          const targetLabelX = groupX + barWidth / 2

          return <g key={`group-${row.date}`}>
            {row.actual != null && <rect className="daily-performance-bar-actual" x={groupX - barWidth} y={valueY(row.actual)} width={Math.max(2, barWidth - 2)} height={Math.max(0, top + plotHeight - valueY(row.actual))} rx="2"><title>{tooltip}</title></rect>}
            {row.planned != null && <rect className="daily-performance-bar-target" x={groupX} y={valueY(row.planned)} width={Math.max(2, barWidth - 2)} height={Math.max(0, top + plotHeight - valueY(row.planned))} rx="2"><title>{tooltip}</title></rect>}
            {showActual && <text className="daily-performance-label-actual" x={actualLabelX} y={valueY(row.actual) - 6} textAnchor={denseLabels ? 'start' : 'middle'} transform={denseLabels ? `rotate(-55 ${actualLabelX} ${valueY(row.actual) - 6})` : undefined}>{formatCompactClicks(row.actual)}</text>}
            {showTarget && <text className="daily-performance-label-target" x={targetLabelX} y={valueY(row.planned) - 6} textAnchor={denseLabels ? 'start' : 'middle'} transform={denseLabels ? `rotate(-55 ${targetLabelX} ${valueY(row.planned) - 6})` : undefined}>{formatCompactClicks(row.planned)}</text>}
          </g>
        })}
        {rows.map((row, index) => index % labelEvery === 0 || index === rows.length - 1 ? <text className="trend-date-label" key={`date-${row.date}`} x={x(index)} y={height - 20} textAnchor="middle">{axisDate(row.date)}</text> : null)}
      </svg>
    </div>
  </section>
}
