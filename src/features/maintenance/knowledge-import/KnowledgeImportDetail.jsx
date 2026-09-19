import { useMemo, useState } from 'react'
import { CheckCircle2, ClipboardList, Plus, Rocket, ShieldAlert, X, XCircle } from 'lucide-react'
import { BlockingDialog } from '../../../components/ui/BlockingDialog.jsx'
import { ErrorState } from '../../../components/ui/ErrorState.jsx'
import { LoadingScreen } from '../../../components/ui/LoadingScreen.jsx'
import { userErrorMessage } from '../../../lib/appErrors.js'
import { addKnowledgeEntry, updateKnowledgeEntry } from '../../../services/maintenance.js'
import { collisionStatusLabels, entryStatusLabels, evidenceLabels, formatMaintenanceDate, importStatusLabels, knowledgeTypeLabels, mapMaintenanceError, nextEntryStatuses } from '../maintenanceUtils.js'
import { KnowledgeEntryForm } from './KnowledgeEntryForm.jsx'
import { PublishDialog } from './PublishDialog.jsx'
import { useKnowledgeImport } from './useKnowledgeImport.js'

const entryStatusPillClass = { DRAFT: '', APPROVED: 'resolved', REJECTED: 'voided' }
const collisionPillClass = { NEW: 'resolved', EXISTING: '', POTENTIAL_UPDATE: 'voided' }

function EntryRow({ entry, canManage, onEdit, onTransition, onPublish }) {
  const availableTransitions = canManage ? (nextEntryStatuses[entry.status] ?? []) : []
  const pageLabel = entry.source_page_start
    ? (entry.source_page_start === entry.source_page_end ? `Page ${entry.source_page_start}` : `Pages ${entry.source_page_start}–${entry.source_page_end}`)
    : (entry.page_reference ? `Page ${entry.page_reference}` : null)

  return (
    <li className="incident-narrative-card glass-surface">
      <span><ClipboardList size={16} /></span>
      <div>
        <strong>{entry.code ? `${entry.code} · ` : ''}{entry.title}</strong>
        <span className={`incident-status-pill ${entryStatusPillClass[entry.status] ?? ''}`}>{entryStatusLabels[entry.status] ?? entry.status}</span>
        {entry.collision_status && <span className={`incident-status-pill ${collisionPillClass[entry.collision_status] ?? ''}`}>{collisionStatusLabels[entry.collision_status] ?? entry.collision_status}</span>}
        {entry.evidence && <span className="incident-status-pill">Evidence: {evidenceLabels[entry.evidence] ?? entry.evidence}</span>}
        <p>{knowledgeTypeLabels[entry.knowledge_type] ?? entry.knowledge_type}{pageLabel ? ` · ${pageLabel}` : ''}</p>
        {entry.published_at && <small>Published {formatMaintenanceDate(entry.published_at, undefined, { dateOnly: true })}</small>}
      </div>
      {canManage && (
        <div className="dialog-actions">
          {entry.status === 'DRAFT' && <button className="icon-button" type="button" onClick={() => onEdit(entry)} aria-label="Edit entry">Edit</button>}
          {availableTransitions.includes('APPROVED') && <button className="icon-button" type="button" onClick={() => onTransition(entry.id, 'APPROVED')} aria-label="Approve entry"><CheckCircle2 size={15} /></button>}
          {availableTransitions.includes('REJECTED') && <button className="icon-button" type="button" onClick={() => onTransition(entry.id, 'REJECTED')} aria-label="Reject entry"><XCircle size={15} /></button>}
          {entry.status === 'APPROVED' && !entry.published_at && <button className="secondary-button" type="button" onClick={() => onPublish(entry)}><Rocket size={14} /> Publish</button>}
        </div>
      )}
    </li>
  )
}

const ALL_FILTER = ''

function EntryFilters({ statusFilter, onStatusFilter, collisionFilter, onCollisionFilter, codeSearch, onCodeSearch }) {
  return (
    <div className="maintenance-filter-row" role="search">
      <select value={statusFilter} onChange={(event) => onStatusFilter(event.target.value)} aria-label="Filter by review status">
        <option value={ALL_FILTER}>All statuses</option>
        {Object.entries(entryStatusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
      </select>
      <select value={collisionFilter} onChange={(event) => onCollisionFilter(event.target.value)} aria-label="Filter by NEW/EXISTING status">
        <option value={ALL_FILTER}>New &amp; existing</option>
        {Object.entries(collisionStatusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
      </select>
      <input value={codeSearch} onChange={(event) => onCodeSearch(event.target.value)} placeholder="Search code…" aria-label="Search by code" />
    </div>
  )
}

export function KnowledgeImportDetail({ importId, canManage, onClose }) {
  const state = useKnowledgeImport(importId)
  const [showAddEntry, setShowAddEntry] = useState(false)
  const [editingEntry, setEditingEntry] = useState(null)
  const [publishingEntry, setPublishingEntry] = useState(null)
  const [actionError, setActionError] = useState(null)
  const [statusFilter, setStatusFilter] = useState(ALL_FILTER)
  const [collisionFilter, setCollisionFilter] = useState(ALL_FILTER)
  const [codeSearch, setCodeSearch] = useState('')

  const isProcessing = state.documentImport?.import_type === 'PDF_EXTRACTION' && state.documentImport?.status === 'PROCESSING'
  const filteredEntries = useMemo(() => {
    const entries = state.documentImport?.entries ?? []
    const search = codeSearch.trim().toLowerCase()
    return entries.filter((entry) => (
      (!statusFilter || entry.status === statusFilter)
      && (!collisionFilter || entry.collision_status === collisionFilter)
      && (!search || (entry.code ?? '').toLowerCase().includes(search) || entry.title.toLowerCase().includes(search))
    ))
  }, [state.documentImport, statusFilter, collisionFilter, codeSearch])

  async function handleAddEntry(payload) {
    const entry = await addKnowledgeEntry(importId, payload)
    state.setDocumentImport((prev) => ({ ...prev, entries: [...(prev.entries ?? []), entry] }))
    setShowAddEntry(false)
  }

  async function handleEditEntry(payload) {
    const entry = await updateKnowledgeEntry(editingEntry.id, payload)
    state.setDocumentImport((prev) => ({ ...prev, entries: prev.entries.map((e) => (e.id === entry.id ? entry : e)) }))
    setEditingEntry(null)
  }

  async function handleTransition(entryId, status) {
    setActionError(null)
    try {
      const entry = await updateKnowledgeEntry(entryId, { status })
      state.setDocumentImport((prev) => ({ ...prev, entries: prev.entries.map((e) => (e.id === entry.id ? entry : e)) }))
    } catch (error) {
      setActionError(mapMaintenanceError(error))
    }
  }

  function handlePublished(result) {
    state.setDocumentImport((prev) => ({ ...prev, entries: prev.entries.map((e) => (e.id === result.entry.id ? result.entry : e)) }))
    state.refresh()
  }

  return (
    <BlockingDialog className="machine-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="knowledge-import-title" onClose={onClose}>
      <header className="dialog-header">
        <div className="dialog-heading"><span className="dialog-icon"><ClipboardList size={22} /></span><div><span className="card-kicker">Knowledge import</span><h2 id="knowledge-import-title">{state.documentImport?.document?.title ?? 'Knowledge import'}</h2></div></div>
        <button className="icon-button" type="button" onClick={onClose} aria-label="Close"><X size={19} /></button>
      </header>

      <div className="machine-form-body">
        {state.isLoading ? <LoadingScreen label="Loading import session" />
          : state.error ? <ErrorState title="Import could not be loaded" detail={userErrorMessage(state.error, 'Try again shortly.')} onRetry={state.refresh} />
          : !state.documentImport ? <ErrorState title="Import not available" detail="It may belong to a different account." />
          : (
            <>
              <p><strong>Status:</strong> {importStatusLabels[state.documentImport.status] ?? state.documentImport.status} · <strong>Machine model:</strong> {state.documentImport.machine_model?.name ?? 'Not specified'}</p>
              {isProcessing && (
                <p className="maintenance-processing-progress">
                  Processing page {state.documentImport.pages_processed ?? 0} of {state.documentImport.extraction?.total_pages ?? '…'} · {state.documentImport.candidate_count ?? 0} candidate{state.documentImport.candidate_count === 1 ? '' : 's'} found so far
                </p>
              )}
              {actionError && <div className="form-error" role="alert"><span>{actionError}</span></div>}

              <div className="maintenance-step-section" style={{ margin: '18px 0 0', paddingTop: '16px' }}>
                <div className="form-section-heading"><strong>Knowledge entries</strong><span>Add, review, approve, then publish into the live knowledge base.</span></div>
                {(state.documentImport.entries ?? []).length === 0 ? (
                  <small>{isProcessing ? 'No candidates detected yet.' : 'No knowledge entries recorded yet.'}</small>
                ) : (
                  <>
                    <EntryFilters statusFilter={statusFilter} onStatusFilter={setStatusFilter} collisionFilter={collisionFilter} onCollisionFilter={setCollisionFilter} codeSearch={codeSearch} onCodeSearch={setCodeSearch} />
                    {filteredEntries.length === 0 ? (
                      <small>No entries match the current filters.</small>
                    ) : (
                      <ul className="incident-narrative-list">
                        {filteredEntries.map((entry) => (
                          <EntryRow key={entry.id} entry={entry} canManage={canManage} onEdit={setEditingEntry} onTransition={handleTransition} onPublish={setPublishingEntry} />
                        ))}
                      </ul>
                    )}
                  </>
                )}

                {canManage && (editingEntry ? (
                  <KnowledgeEntryForm entry={editingEntry} submitLabel="Save entry" onCancel={() => setEditingEntry(null)} onSubmit={handleEditEntry} />
                ) : showAddEntry ? (
                  <KnowledgeEntryForm submitLabel="Add entry" onCancel={() => setShowAddEntry(false)} onSubmit={handleAddEntry} />
                ) : (
                  <button className="secondary-button" type="button" onClick={() => setShowAddEntry(true)}><Plus size={16} /> Add knowledge entry</button>
                ))}
              </div>

              {!canManage && <div className="permission-banner"><ShieldAlert size={18} /><span>Read-only access.</span></div>}
            </>
          )}
      </div>

      {publishingEntry && <PublishDialog entry={publishingEntry} onClose={() => setPublishingEntry(null)} onPublished={handlePublished} />}
    </BlockingDialog>
  )
}
