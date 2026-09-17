import { useState } from 'react'
import { FileText, Plus, RefreshCcw, Search, ShieldAlert } from 'lucide-react'
import { useTenant } from '../../account/useTenant.js'
import { userErrorMessage } from '../../../lib/appErrors.js'
import { DocumentDetail } from './DocumentDetail.jsx'
import { DocumentFormDialog } from './DocumentFormDialog.jsx'
import { DocumentList } from './DocumentList.jsx'
import { useMaintenanceDocuments } from './useMaintenanceDocuments.js'

export function DocumentRepositorySection() {
  const { account, can, isPlatformSuperuser } = useTenant()
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [workflow, setWorkflow] = useState(null) // null | { mode: 'create' } | { mode: 'edit', doc } | { mode: 'view', id }
  const state = useMaintenanceDocuments({ search })
  const canManage = can('machines.manage')

  function canManageRow(doc) {
    return doc.account_id === null ? isPlatformSuperuser : doc.account_id === account?.id
  }

  function handleSearchSubmit(event) {
    event.preventDefault()
    setSearch(searchInput.trim())
  }

  return (
    <section className="machine-list-card glass-surface">
      <div className="list-toolbar">
        <div><span className="card-kicker">Machine document repository</span><h2>{state.isLoading ? 'Loading…' : `${state.documents.length} published ${state.documents.length === 1 ? 'document' : 'documents'}`}</h2></div>
        <div className="maintenance-error-toolbar-actions">
          <button className="icon-button" type="button" onClick={state.refresh} aria-label="Refresh documents" disabled={state.isLoading}><RefreshCcw size={17} className={state.isLoading ? 'spin' : ''} /></button>
          {canManage && <button className="primary-button" type="button" onClick={() => setWorkflow({ mode: 'create' })}><Plus size={16} /> Register document</button>}
        </div>
      </div>

      <form className="maintenance-error-search" onSubmit={handleSearchSubmit}>
        <label className="form-field"><span>Search</span>
          <input value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder="Search by title…" aria-label="Search documents" />
        </label>
        <button className="secondary-button" type="submit"><Search size={16} /> Search</button>
      </form>

      {state.isLoading ? <div className="machine-loading-state"><RefreshCcw className="spin" size={24} /><strong>Loading documents…</strong></div>
        : state.error ? <div className="embedded-error" role="alert"><strong>Documents could not be loaded.</strong><span>{userErrorMessage(state.error, 'Try again shortly.')}</span><button className="secondary-button" type="button" onClick={state.refresh}>Try again</button></div>
        : state.documents.length === 0 ? <div className="machine-empty-state"><FileText size={38} strokeWidth={1.35} /><h3>No documents found.</h3><p>{search ? 'Try a different search term.' : 'Manufacturer manuals, service documents, and reference files will appear here.'}</p></div>
          : <DocumentList documents={state.documents} canManageRow={canManageRow} onOpen={(doc) => setWorkflow({ mode: 'view', id: doc.id })} onEdit={(doc) => setWorkflow({ mode: 'edit', doc })} />}

      {!canManage && <div className="permission-banner"><ShieldAlert size={18} /><span>Read-only access. Only account owners/admins (or Platform for global documents) can manage the document repository.</span></div>}

      {(workflow?.mode === 'create' || workflow?.mode === 'edit') && (
        <DocumentFormDialog
          document={workflow.mode === 'edit' ? workflow.doc : null}
          accountId={account?.id}
          onClose={() => setWorkflow(null)}
          onSaved={state.refresh}
        />
      )}
      {workflow?.mode === 'view' && (
        <DocumentDetail
          documentId={workflow.id}
          canManage={canManage}
          onClose={() => setWorkflow(null)}
        />
      )}
    </section>
  )
}
