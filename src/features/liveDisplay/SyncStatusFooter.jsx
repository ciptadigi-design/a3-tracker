import { useEffect, useState } from 'react'

const statusLabels = { fresh: 'LIVE', stale: 'STALE', offline: 'OFFLINE', loading: 'CONNECTING', empty: 'LIVE' }

function formatSyncTime(date, timezone) {
  if (!date) return '—'
  return new Intl.DateTimeFormat('en-GB', { timeZone: timezone, hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(date)
}

/**
 * Status text only - no flashing/pulsing failure indicator (STALE/OFFLINE
 * are a calm, static badge, same visual weight as LIVE). The "refresh in
 * XXs" countdown is informational and ticks off the local clock, not a
 * second polling timer.
 */
export function SyncStatusFooter({ status, lastSyncedAt, timezone, pollIntervalSeconds = 30 }) {
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const intervalId = setInterval(() => setNow(new Date()), 1000)
    return () => clearInterval(intervalId)
  }, [])

  const secondsSinceSync = lastSyncedAt ? Math.max(0, Math.floor((now.getTime() - lastSyncedAt.getTime()) / 1000)) : null
  const refreshInSeconds = secondsSinceSync == null ? null : Math.max(0, pollIntervalSeconds - (secondsSinceSync % pollIntervalSeconds))

  return (
    <footer className={`live-display-footer tone-${status}`}>
      <span className="live-display-footer-status">● {statusLabels[status] ?? 'LIVE'}</span>
      <span>Last sync {formatSyncTime(lastSyncedAt, timezone)}</span>
      {status !== 'offline' && refreshInSeconds != null && <span className="live-display-footer-countdown">Refresh in {refreshInSeconds}s</span>}
    </footer>
  )
}
