import { useEffect, useState } from 'react'
import { apiClient, unwrapData } from '../../lib/api/apiClient.js'
import { auditActionLabel, auditChanges } from './auditPresentation.js'

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
      <div className="settings-audit-list">{result.data.map((event) => <div key={event.id}>
        <strong>{auditActionLabel(event.action)}</strong>
        <span>{event.actor.label} {event.actor.user_id && `(${event.actor.user_id})`} · {new Date(event.created_at).toLocaleString()}</span>
        <small>{event.target.type.replaceAll('_', ' ')} · {event.target.id}</small>
        {auditChanges(event.changes).map((change) => <small key={change}>{change}</small>)}
      </div>)}</div>
      <div className="dialog-actions"><button type="button" disabled={page <= 1} onClick={() => navigate(page - 1)}>Newer</button><span>Page {page} of {result.last_page}</span><button type="button" disabled={page >= result.last_page} onClick={() => navigate(page + 1)}>Older</button></div>
    </>}
  </section>
}
