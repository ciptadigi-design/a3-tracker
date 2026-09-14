import { useEffect, useState } from 'react'
import { apiClient, unwrapData } from '../../lib/api/apiClient.js'
import { auditActionLabel, auditActorLabel, auditChanges, auditTarget, auditTimestamp } from './auditPresentation.js'

export function AdministrativeHistory({ accountId, platform = false }) {
  return <AccountAdministrativeHistory key={`${accountId}:${platform}`} accountId={accountId} platform={platform} />
}

function AccountAdministrativeHistory({ accountId, platform }) {
  const [page, setPage] = useState(1)
  const [result, setResult] = useState(null)
  const [error, setError] = useState(false)
  const [retry, setRetry] = useState(0)
  useEffect(() => {
    let current = true
    apiClient.get(`/${platform ? 'platform/' : ''}accounts/${encodeURIComponent(accountId)}/audit?page=${page}&per_page=10`)
      .then((payload) => { if (current) setResult(unwrapData(payload)) })
      .catch(() => { if (current) setError(true) })
    return () => { current = false }
  }, [accountId, platform, page, retry])
  const navigate = (next) => { setResult(null); setError(false); setPage(next) }
  return <section className="settings-card glass-surface" aria-label="Administrative history">
    <header><div><span className="card-kicker">Administration evidence</span><h2>Recent administrative changes</h2><p>Recorded changes from audit coverage onward.</p></div></header>
    {error ? <div role="alert">History could not be loaded. <button type="button" onClick={() => { setError(false); setRetry(retry + 1) }}>Retry</button></div> : !result ? <p role="status">Loading history…</p> : <>
      {!result.data.length && <p>No recorded administrative changes.</p>}
      {result.data.length > 0 && <div className="settings-audit-table">
        <div className="settings-audit-head" aria-hidden="true"><span>Action</span><span>Actor / Time</span><span>Target</span><span>Changes</span></div>
        <div className="settings-audit-list">{result.data.map((event) => {
          const target = auditTarget(event.target)
          const changes = auditChanges(event.changes, event.target.type)
          return <article className="settings-audit-row" key={event.id}>
            <div className="settings-audit-cell settings-audit-action"><span className="settings-audit-field-label">Action</span><strong>{auditActionLabel(event.action)}</strong></div>
            <div className="settings-audit-cell settings-audit-actor"><span className="settings-audit-field-label">Actor / Time</span><strong>{auditActorLabel(event.actor.label)}</strong><small>{auditTimestamp(event.created_at)}</small></div>
            <div className="settings-audit-cell settings-audit-target" title={target.fullIdentifier} aria-label={`${target.typeLabel}: ${target.label}${target.fullIdentifier ? `, identifier ${target.fullIdentifier}` : ''}`}><span className="settings-audit-field-label">Target</span><small>{target.typeLabel}</small><strong>{target.label}</strong>{!target.hasHumanLabel && target.fullIdentifier && <span className="settings-audit-short-id">Identifier</span>}</div>
            <div className="settings-audit-cell settings-audit-changes"><span className="settings-audit-field-label">Changes</span>{changes.length ? changes.map((change) => <div className="settings-audit-change" key={`${change.field}:${change.value}`}><small>{change.field}</small><strong>{change.value}</strong></div>) : <span className="settings-audit-no-changes">No field detail</span>}</div>
          </article>
        })}</div>
      </div>}
      <nav className="settings-audit-pagination" aria-label="Administrative history pagination"><button className="secondary-button compact-button" type="button" disabled={page <= 1} onClick={() => navigate(page - 1)}>← Newer</button><span>Page {page} of {result.last_page}</span><button className="secondary-button compact-button" type="button" disabled={page >= result.last_page} onClick={() => navigate(page + 1)}>Older →</button></nav>
    </>}
  </section>
}
