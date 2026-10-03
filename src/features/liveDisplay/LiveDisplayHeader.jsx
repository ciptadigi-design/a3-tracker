import { useEffect, useMemo, useState } from 'react'

const clockFormatter = (timezone) => new Intl.DateTimeFormat('en-GB', {
  timeZone: timezone,
  weekday: 'short',
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
  hour12: false,
})

/**
 * The header's clock is purely local wall-clock display - it ticks every
 * second off the browser's own clock and never triggers a backend request.
 * Data freshness ("LIVE"/"STALE"/"OFFLINE") is a separate concern rendered
 * by SyncStatusFooter from the poller's own state, not from this clock.
 */
export function LiveDisplayHeader({ accountName, machineCode, machineLabel, timezone }) {
  const [now, setNow] = useState(() => new Date())
  const formatter = useMemo(() => clockFormatter(timezone), [timezone])

  useEffect(() => {
    const intervalId = setInterval(() => setNow(new Date()), 1000)
    return () => clearInterval(intervalId)
  }, [])

  return (
    <header className="live-display-header">
      <div className="live-display-brand">
        <span className="live-display-brand-name">{accountName}</span>
        <span className="live-display-brand-sub">LIVE PRODUCTION</span>
      </div>
      <div className="live-display-clock">{formatter.format(now)}</div>
      <div className="live-display-machine">
        <strong>{machineCode}</strong>
        {machineLabel && <span>{machineLabel}</span>}
      </div>
    </header>
  )
}
