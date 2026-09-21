import { useMemo, useState } from 'react'
import { CheckCircle2, ClipboardList, Plus, Rocket, RotateCcw, ShieldAlert, Trash2, X, XCircle } from 'lucide-react'
import { BlockingDialog } from '../../../components/ui/BlockingDialog.jsx'
import { ErrorState } from '../../../components/ui/ErrorState.jsx'
import { LoadingScreen } from '../../../components/ui/LoadingScreen.jsx'
import { userErrorMessage } from '../../../lib/appErrors.js'
import { addKnowledgeEntry, updateKnowledgeEntry } from '../../../services/maintenance.js'
import { collisionStatusLabels, entryStatusLabels, evidenceLabels, formatMaintenanceDate, importStatusLabels, knowledgeTypeLabels, mapMaintenanceError, nextEntryStatuses } from '../maintenanceUtils.js'
import { BulkReviewDialog } from './BulkReviewDialog.jsx'
import { CodeGroupsPanel } from './CodeGroupsPanel.jsx'
import { KnowledgeEntryForm } from './KnowledgeEntryForm.jsx'
import { PublishDialog } from './PublishDialog.jsx'
import { useKnowledgeEntries } from './useKnowledgeEntries.js'
import { useKnowledgeImport } from './useKnowledgeImport.js'

const entryStatusPillClass = { DRAFT: '', APPROVED: 'resolved', REJECTED: 'voided' }
const collisionPillClass = { NEW: 'resolved', EXISTING: '', POTENTIAL_UPDATE: 'voided' }

// V1.7 - only DRAFT/REJECTED are ever reachable by a bulk action (never
// APPROVED, which is how "published" is represented in this domain - status
// stays APPROVED with published_at set, there is no separate PUBLISHED
// value) - see backend BULK_TRANSITIONS' own docblock. A row outside these
// two statuses gets no checkbox at all, not merely a disabled one.
const BULK_ELIGIBLE_STATUSES = new Set(['DRAFT', 'REJECTED'])

function EntryRow({ entry, canManage, onEdit, onTransition, onPublish, selected, onToggleSelect }) {
  const availableTransitions = canManage ? (nextEntryStatuses[entry.status] ?? []) : []
  const pageLabel = entry.source_page_start
    ? (entry.source_page_start === entry.source_page_end ? `Page ${entry.source_page_start}` : `Pages ${entry.source_page_start}–${entry.source_page_end}`)
    : (entry.page_reference ? `Page ${entry.page_reference}` : null)
  const bulkEligible = canManage && BULK_ELIGIBLE_STATUSES.has(entry.status)

  return (
    <li className="incident-narrative-card glass-surface">
      {bulkEligible
        ? <input type="checkbox" checked={selected} onChange={() => onToggleSelect(entry.id)} aria-label={`Select ${entry.code ?? entry.title}`} />
        : <span><ClipboardList size={16} /></span>}
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

// V1.6.1: evidence filter alongside the existing status/collision filters -
// all three (plus code search) are sent server-side (useKnowledgeEntries),
// never applied client-side against an already-paginated page of rows.
function EntryFilters({ statusFilter, onStatusFilter, collisionFilter, onCollisionFilter, evidenceFilter, onEvidenceFilter, codeSearch, onCodeSearch }) {
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
      <select value={evidenceFilter} onChange={(event) => onEvidenceFilter(event.target.value)} aria-label="Filter by evidence">
        <option value={ALL_FILTER}>All evidence</option>
        {Object.entries(evidenceLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
      </select>
      <input value={codeSearch} onChange={(event) => onCodeSearch(event.target.value)} placeholder="Search code…" aria-label="Search by code" />
    </div>
  )
}

// V1.6.1: real Production counts (HIGH 0 / MEDIUM 3 / LOW 1282 on the first
// real run) - always real DB counts from the import's own evidence_summary,
// never a fabricated confidence score.
function EvidenceSummary({ summary }) {
  if (!summary) return null
  return (
    <p className="maintenance-processing-progress">
      Evidence: HIGH {summary.HIGH} · MEDIUM {summary.MEDIUM} · LOW {summary.LOW}
    </p>
  )
}

// V1.7 - Bulk Knowledge Review. Selection is deliberately scoped to the
// current page only (Section D: "a safe page-level selection model is
// acceptable") and cleared on ANY filter or page change (Section E/K) - a
// selection never silently refers to rows the reviewer can no longer see.
// The offered action is derived from the selected rows' shared status: all
// DRAFT -> "Reject selected"; all REJECTED -> "Restore to draft"; a mixed
// selection offers neither, rather than guessing which action was intended.
function BulkActionBar({ selectedIds, entries, onClearSelection, onRequestAction }) {
  if (selectedIds.size === 0) return null
  const selectedEntries = entries.filter((e) => selectedIds.has(e.id))
  const statuses = new Set(selectedEntries.map((e) => e.status))
  const uniformStatus = statuses.size === 1 ? [...statuses][0] : null

  return (
    <div className="maintenance-bulk-action-bar" role="toolbar" aria-label="Bulk candidate review actions">
      <span>{selectedIds.size} selected</span>
      {uniformStatus === 'DRAFT' && <button className="danger-outline-button" type="button" onClick={() => onRequestAction('reject')}><Trash2 size={15} /> Reject selected</button>}
      {uniformStatus === 'REJECTED' && <button className="secondary-button" type="button" onClick={() => onRequestAction('restore')}><RotateCcw size={15} /> Restore to draft</button>}
      {!uniformStatus && <small>Select candidates with the same review status to act on them together.</small>}
      <button className="icon-button" type="button" onClick={onClearSelection} aria-label="Clear selection">Clear</button>
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
  const [evidenceFilter, setEvidenceFilter] = useState(ALL_FILTER)
  const [codeSearch, setCodeSearch] = useState('')
  const [selectedIds, setSelectedIds] = useState(() => new Set())
  const [pendingBulkAction, setPendingBulkAction] = useState(null)
  // V1.7.2: PDF-derived imports open on the consolidated code-group view; the flat candidate list
  // (with its individual actions and V1.7 ID-based bulk selection) stays one tab away, unchanged.
  const [viewMode, setViewMode] = useState(null)
  const [groupsVersion, setGroupsVersion] = useState(0)

  const entriesState = useKnowledgeEntries({ importId, status: statusFilter || undefined, collisionStatus: collisionFilter || undefined, evidence: evidenceFilter || undefined, code: codeSearch.trim() || undefined })
  const isProcessing = state.documentImport?.import_type === 'PDF_EXTRACTION' && state.documentImport?.status === 'PROCESSING'
  const isPdfImport = state.documentImport?.import_type === 'PDF_EXTRACTION'
  const activeView = viewMode ?? (isPdfImport ? 'groups' : 'flat')
  const bumpGroups = () => setGroupsVersion((v) => v + 1)
  const hasAnyFilter = Boolean(statusFilter || collisionFilter || evidenceFilter || codeSearch.trim())
  const eligibleOnPage = useMemo(() => entriesState.entries.filter((e) => BULK_ELIGIBLE_STATUSES.has(e.status)), [entriesState.entries])
  const allEligibleSelected = eligibleOnPage.length > 0 && eligibleOnPage.every((e) => selectedIds.has(e.id))

  // Selection never silently outlives the context it was made in - any filter
  // or page change clears it (Section E/U#12/U#13). React's own recommended
  // "adjust state during render" pattern, not useEffect + setState (which
  // would cause an extra cascading render for a value derivable from props).
  const selectionContextKey = `${statusFilter}|${collisionFilter}|${evidenceFilter}|${codeSearch}|${entriesState.currentPage}`
  const [lastSelectionContextKey, setLastSelectionContextKey] = useState(selectionContextKey)
  if (selectionContextKey !== lastSelectionContextKey) {
    setLastSelectionContextKey(selectionContextKey)
    setSelectedIds(new Set())
  }

  function toggleSelect(entryId) {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (next.has(entryId)) next.delete(entryId); else next.add(entryId)
      return next
    })
  }

  function toggleSelectPage() {
    setSelectedIds(allEligibleSelected ? new Set() : new Set(eligibleOnPage.map((e) => e.id)))
  }

  async function handleAddEntry(payload) {
    await addKnowledgeEntry(importId, payload)
    setShowAddEntry(false)
    entriesState.refresh()
  }

  async function handleEditEntry(payload) {
    await updateKnowledgeEntry(editingEntry.id, payload)
    setEditingEntry(null)
    entriesState.refresh()
  }

  async function handleTransition(entryId, status) {
    setActionError(null)
    try {
      await updateKnowledgeEntry(entryId, { status })
      entriesState.refresh()
      bumpGroups()
    } catch (error) {
      setActionError(mapMaintenanceError(error))
    }
  }

  function handlePublished() {
    entriesState.refresh()
    state.refresh()
    bumpGroups()
  }

  function handleBulkReviewed() {
    setSelectedIds(new Set())
    entriesState.refresh()
    bumpGroups()
  }

  // Any review change made from the consolidated view refreshes the flat list, the import header and the groups.
  function handleGroupsChanged() {
    entriesState.refresh()
    state.refresh()
    bumpGroups()
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
              <EvidenceSummary summary={state.documentImport.evidence_summary} />
              {actionError && <div className="form-error" role="alert"><span>{actionError}</span></div>}

              <div className="maintenance-step-section" style={{ margin: '18px 0 0', paddingTop: '16px' }}>
                <div className="form-section-heading"><strong>Knowledge entries</strong><span>Add, review, approve, then publish into the live knowledge base.</span></div>

                {isPdfImport && (state.documentImport.candidate_count ?? 0) > 0 && (
                  <div className="maintenance-view-tabs" role="tablist" aria-label="Review view">
                    <button role="tab" type="button" aria-selected={activeView === 'groups'} className={activeView === 'groups' ? 'primary-button' : 'secondary-button'} onClick={() => setViewMode('groups')}>Code groups</button>
                    <button role="tab" type="button" aria-selected={activeView === 'flat'} className={activeView === 'flat' ? 'primary-button' : 'secondary-button'} onClick={() => setViewMode('flat')}>All candidates</button>
                  </div>
                )}

                {(state.documentImport.candidate_count ?? 0) === 0 && !hasAnyFilter ? (
                  <small>{isProcessing ? 'No candidates detected yet.' : 'No knowledge entries recorded yet.'}</small>
                ) : activeView === 'groups' && isPdfImport ? (
                  <CodeGroupsPanel importId={importId} canManage={canManage} documentImport={state.documentImport} version={groupsVersion} onChanged={handleGroupsChanged} onPublish={setPublishingEntry} />
                ) : (
                  <>
                    <EntryFilters statusFilter={statusFilter} onStatusFilter={setStatusFilter} collisionFilter={collisionFilter} onCollisionFilter={setCollisionFilter} evidenceFilter={evidenceFilter} onEvidenceFilter={setEvidenceFilter} codeSearch={codeSearch} onCodeSearch={setCodeSearch} />
                    {entriesState.isLoading && entriesState.entries.length === 0 ? (
                      <small>Loading entries…</small>
                    ) : entriesState.total === 0 ? (
                      <small>No entries match the current filters.</small>
                    ) : (
                      <>
                        {canManage && eligibleOnPage.length > 0 && (
                          <label className="maintenance-select-page-row">
                            <input type="checkbox" checked={allEligibleSelected} onChange={toggleSelectPage} aria-label="Select all eligible candidates on this page" />
                            <span>Select all on this page ({eligibleOnPage.length})</span>
                          </label>
                        )}
                        <ul className="incident-narrative-list">
                          {entriesState.entries.map((entry) => (
                            <EntryRow key={entry.id} entry={entry} canManage={canManage} onEdit={setEditingEntry} onTransition={handleTransition} onPublish={setPublishingEntry} selected={selectedIds.has(entry.id)} onToggleSelect={toggleSelect} />
                          ))}
                        </ul>
                        <BulkActionBar selectedIds={selectedIds} entries={entriesState.entries} onClearSelection={() => setSelectedIds(new Set())} onRequestAction={setPendingBulkAction} />
                        {entriesState.lastPage > 1 && (
                          <div className="maintenance-extracted-pages-nav">
                            <button className="secondary-button" type="button" disabled={entriesState.currentPage <= 1 || entriesState.isLoading} onClick={() => entriesState.goToPage(entriesState.currentPage - 1)}>Previous</button>
                            <span>Page {entriesState.currentPage} of {entriesState.lastPage} ({entriesState.total} total)</span>
                            <button className="secondary-button" type="button" disabled={entriesState.currentPage >= entriesState.lastPage || entriesState.isLoading} onClick={() => entriesState.goToPage(entriesState.currentPage + 1)}>Next</button>
                          </div>
                        )}
                      </>
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
      {pendingBulkAction && (
        <BulkReviewDialog
          importId={importId}
          entryIds={[...selectedIds]}
          action={pendingBulkAction}
          onClose={() => setPendingBulkAction(null)}
          onReviewed={handleBulkReviewed}
        />
      )}
    </BlockingDialog>
  )
}
