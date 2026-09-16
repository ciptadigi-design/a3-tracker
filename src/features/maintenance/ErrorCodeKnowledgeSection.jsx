import { useState } from 'react'
import { AlertTriangle, ChevronDown, ChevronRight, PencilLine, Plus, RefreshCcw, Search, ShieldAlert } from 'lucide-react'
import { useTenant } from '../account/useTenant.js'
import { userErrorMessage } from '../../lib/appErrors.js'
import { ErrorCodeManagementDialog } from './ErrorCodeManagementDialog.jsx'
import { useMachineErrorCodes } from './useMachineErrorCodes.js'
import { errorCodeSeverityLabels } from './maintenanceUtils.js'

function ErrorCodeRow({ code, canManage, canManageThisRow, onEdit }) {
  const [expanded, setExpanded] = useState(false)
  const steps = code.solutions ?? []

  return (
    <li className="incident-narrative-card glass-surface maintenance-error-code-row">
      <button type="button" className="maintenance-error-code-row-header" onClick={() => setExpanded((v) => !v)} aria-expanded={expanded}>
        {expanded ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
        <div>
          <strong>{code.code} · {code.title}</strong>
          <span className={`incident-status-pill ${code.severity}`}>{errorCodeSeverityLabels[code.severity] ?? code.severity}</span>
        </div>
        {canManage && canManageThisRow && <button className="icon-button" type="button" onClick={(event) => { event.stopPropagation(); onEdit(code) }} aria-label={`Edit ${code.code}`}><PencilLine size={15} /></button>}
      </button>
      {expanded && (
        <div className="maintenance-error-code-detail">
          {code.solution_summary && <p><strong>Recommended fix:</strong> {code.solution_summary}</p>}
          {code.operator_description && <p>{code.operator_description}</p>}
          {steps.length > 0 ? (
            <ol>
              {steps.map((step) => <li key={step.id}>{step.instruction}{step.requires_technician && <small> · Requires a technician</small>}</li>)}
            </ol>
          ) : <small>No step-by-step procedure recorded yet.</small>}
        </div>
      )}
    </li>
  )
}

export function ErrorCodeKnowledgeSection() {
  const { account, can, isPlatformSuperuser } = useTenant()
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [workflow, setWorkflow] = useState(null) // null | { mode: 'create' } | { mode: 'edit', code }
  const state = useMachineErrorCodes({ search })
  const canManage = can('machines.manage')

  function canManageThisRow(code) {
    return code.account_id === null ? isPlatformSuperuser : code.account_id === account?.id
  }

  function handleSearchSubmit(event) {
    event.preventDefault()
    setSearch(searchInput.trim())
  }

  return (
    <section className="machine-list-card glass-surface">
      <div className="list-toolbar">
        <div><span className="card-kicker">Error code knowledge base</span><h2>{state.isLoading ? 'Loading…' : `${state.errorCodes.length} ${state.errorCodes.length === 1 ? 'code' : 'codes'}`}</h2></div>
        {/* Admin actions live here, in the toolbar, deliberately separate from the
            search/filter bar below - the two serve different intents (finding a
            code vs. managing the catalog) and shouldn't compete for the same row. */}
        <div className="maintenance-error-toolbar-actions">
          <button className="icon-button" type="button" onClick={state.refresh} aria-label="Refresh error codes" disabled={state.isLoading}><RefreshCcw size={17} className={state.isLoading ? 'spin' : ''} /></button>
          {canManage && <button className="primary-button" type="button" onClick={() => setWorkflow({ mode: 'create' })}><Plus size={16} /> Add error code</button>}
        </div>
      </div>

      <form className="maintenance-error-search" onSubmit={handleSearchSubmit}>
        <label className="form-field"><span>Search</span>
          <input value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder="Search by code or title…" aria-label="Search error codes" />
        </label>
        <button className="secondary-button" type="submit"><Search size={16} /> Search</button>
      </form>

      {state.isLoading ? <div className="machine-loading-state"><RefreshCcw className="spin" size={24} /><strong>Loading error codes…</strong></div>
        : state.error ? <div className="embedded-error" role="alert"><strong>Error codes could not be loaded.</strong><span>{userErrorMessage(state.error, 'Try again shortly.')}</span><button className="secondary-button" type="button" onClick={state.refresh}>Try again</button></div>
        : state.errorCodes.length === 0 ? <div className="machine-empty-state"><AlertTriangle size={38} strokeWidth={1.35} /><h3>No error codes found.</h3><p>{search ? 'Try a different search term.' : 'Manufacturer and internal error codes will appear here.'}</p></div>
          : <ul className="incident-narrative-list">{state.errorCodes.map((code) => <ErrorCodeRow key={code.id} code={code} canManage={canManage} canManageThisRow={canManageThisRow(code)} onEdit={(target) => setWorkflow({ mode: 'edit', code: target })} />)}</ul>}

      {!canManage && <div className="permission-banner"><ShieldAlert size={18} /><span>Read-only access. Only account owners/admins (or Platform for global codes) can manage error code knowledge.</span></div>}

      {workflow && (
        <ErrorCodeManagementDialog
          errorCode={workflow.mode === 'edit' ? workflow.code : null}
          accountId={account?.id}
          onClose={() => setWorkflow(null)}
          onSaved={state.refresh}
        />
      )}
    </section>
  )
}
