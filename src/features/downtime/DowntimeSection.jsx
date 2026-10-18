import { useCallback, useEffect, useState } from 'react'
import { AlertTriangle, ClipboardList, LoaderCircle, PowerOff, RefreshCcw } from 'lucide-react'
import { userErrorMessage } from '../../lib/appErrors.js'
import { loadActiveDowntime } from '../../services/downtime.js'
import { describeMachineStatus, elapsedMinutesBetween, formatElapsedMinutes, machineConditionPath, UNAVAILABLE_LABEL } from './downtimeModel.js'

function LiveElapsed({ startAt }) {
  const [minutes, setMinutes] = useState(() => elapsedMinutesBetween(startAt))
  useEffect(() => {
    const interval = setInterval(() => setMinutes(elapsedMinutesBetween(startAt)), 30000)
    return () => clearInterval(interval)
  }, [startAt])
  return <>{minutes == null ? UNAVAILABLE_LABEL : formatElapsedMinutes(minutes)}</>
}

/**
 * Compact, read-only Status Mesin card for Machine Detail. Recording, noting and
 * closing downtime live in Maintenance > Kondisi Mesin; this card only shows the
 * current state and links there with the machine preselected.
 */
export function DowntimeSection({ machine, timezone, canManage, canOpenMaintenance, navigate }) {
  const [active, setActive] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)

  const refresh = useCallback(async () => {
    setIsLoading(true)
    setError(null)
    try {
      setActive(await loadActiveDowntime(machine.id))
    } catch (loadError) {
      setError(loadError)
    } finally {
      setIsLoading(false)
    }
  }, [machine.id])

  useEffect(() => { refresh() }, [refresh])

  const status = describeMachineStatus(active, timezone)
  const isStopped = status.state === 'stopped'
  const action = isStopped ? 'manage' : 'report'
  const actionLabel = isStopped ? 'Kelola Downtime' : 'Lapor Gangguan'

  return (
    <section className="downtime-section glass-surface" aria-labelledby="downtime-section-title">
      <header className="maintenance-history-header">
        <div>
          <span className="card-kicker" id="downtime-section-title">Status Mesin</span>
          <div className="downtime-status-line">
            {isLoading && !active ? <span className="downtime-status-loading">Memuat…</span> : error ? null : <span className={`machine-status ${isStopped ? 'machine-status-down' : ''}`}>{status.label}</span>}
          </div>
        </div>
        <div className="maintenance-history-header-actions"><button className="icon-button" type="button" onClick={refresh} disabled={isLoading} aria-label="Muat ulang status mesin"><RefreshCcw size={17} className={isLoading ? 'spin' : ''} /></button></div>
      </header>

      {isLoading && !active ? <div className="downtime-status-state"><LoaderCircle className="spin" size={20} /><span>Memuat status mesin…</span></div>
        : error ? <div className="embedded-error" role="alert"><strong>Status mesin tidak dapat dimuat.</strong><span>{userErrorMessage(error, 'Coba lagi beberapa saat.')}</span><button className="secondary-button" type="button" onClick={refresh}>Coba lagi</button></div>
          : (
            <>
              {isStopped ? (
                <dl className="maintenance-history-summary downtime-status-summary">
                  <div><dt>Mulai</dt><dd>{status.startText}</dd></div>
                  <div><dt>Penyebab</dt><dd>{status.causeText}</dd></div>
                  <div><dt>Durasi</dt><dd>{status.canComplete ? <LiveElapsed startAt={active.actual_start_at} /> : status.durationText}</dd></div>
                  {status.errorCode && <div><dt>Kode error</dt><dd>{status.errorCode}</dd></div>}
                </dl>
              ) : <p className="downtime-status-empty">Tidak ada downtime aktif.</p>}
              {isStopped && !status.canComplete && <div className="downtime-status-notice" role="alert"><AlertTriangle size={16} /><span>Waktu mulai downtime ini tidak valid. Hubungi admin untuk memperbaiki data.</span></div>}
              {canOpenMaintenance && (
                <div className="downtime-status-card-actions">
                  {canManage
                    ? <button className={isStopped ? 'primary-button' : 'danger-outline-button'} type="button" onClick={() => navigate(machineConditionPath(machine.id, action))}>{isStopped ? <ClipboardList size={17} /> : <PowerOff size={17} />} {actionLabel}</button>
                    : <button className="secondary-button" type="button" onClick={() => navigate(machineConditionPath(machine.id, 'manage'))}><ClipboardList size={17} /> Lihat di Maintenance</button>}
                </div>
              )}
            </>
          )}
    </section>
  )
}
