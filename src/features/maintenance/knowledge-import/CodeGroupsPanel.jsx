import { useState } from 'react'
import { ChevronRight, RotateCcw, Trash2 } from 'lucide-react'
import { collisionStatusLabels } from '../maintenanceUtils.js'
import { CodeGroupDetailDialog } from './CodeGroupDetailDialog.jsx'
import { TriageDialog } from './TriageDialog.jsx'
import {
  BEST_EVIDENCE_LABELS, DEFAULT_PER_PAGE, EMPTY_FILTERS, EVIDENCE_LABELS, FILTER_PRESETS, PER_PAGE_OPTIONS, REFERENCE_LIKE_LABELS, REVIEW_STATE_LABELS, REVIEW_STATE_PILL_CLASS,
  evidenceMix, filterValidationError, filtersIgnoredByBulk, hasBulkCriteria, occurrenceLabel, pageRangeLabel, reviewProgress, sourcePagesLabel, toBulkFilters,
} from './codeGroupUtils.js'
import { useCodeGroups } from './useCodeGroups.js'

const bestEvidencePillClass = { HIGH: 'resolved', MEDIUM: '', LOW: 'voided' }

function SummaryCards({ summary }) {
  if (!summary) return null
  const progress = reviewProgress(summary)
  const cards = [
    { key: 'high', label: 'High-best codes', value: summary.best_evidence_codes?.HIGH ?? 0 },
    { key: 'medium', label: 'Medium-best codes', value: summary.best_evidence_codes?.MEDIUM ?? 0 },
    { key: 'low', label: 'Low-only codes', value: summary.best_evidence_codes?.LOW ?? 0 },
    { key: 'progress', label: 'Reviewed / remaining', value: `${progress.reviewed} / ${progress.remaining}` },
  ]
  return (
    <div className="maintenance-summary-cards" role="list" aria-label="Code group summary">
      {cards.map((card) => (
        <div key={card.key} className="maintenance-summary-card" role="listitem" data-card={card.key}>
          <strong>{card.value}</strong><span>{card.label}</span>
        </div>
      ))}
    </div>
  )
}

function collisionLabel(group) {
  const c = group.collision_counts ?? {}
  const present = Object.entries(c).filter(([, n]) => n > 0)
  if (present.length === 0) return '—'
  if (present.length === 1) return collisionStatusLabels[present[0][0]] ?? present[0][0]
  return present.map(([k, n]) => `${collisionStatusLabels[k] ?? k} ${n}`).join(' · ')
}

function GroupRow({ group, filtersActive, onOpen }) {
  const partial = group.matching_occurrence_count !== group.occurrence_count
  return (
    <li className="maintenance-code-group-row glass-surface" data-code={group.normalized_code}>
      <div className="maintenance-code-group-main">
        <button className="maintenance-code-link" type="button" onClick={() => onOpen(group.normalized_code)} aria-label={`Review code ${group.normalized_code}`}>{group.normalized_code}</button>
        <span className={`incident-status-pill ${bestEvidencePillClass[group.best_evidence] ?? ''}`}>Best: {EVIDENCE_LABELS[group.best_evidence] ?? group.best_evidence}</span>
        <span className={`incident-status-pill ${REVIEW_STATE_PILL_CLASS[group.review_state] ?? ''}`}>{REVIEW_STATE_LABELS[group.review_state] ?? group.review_state}</span>
        {group.reference_like_all && <span className="incident-status-pill">Reference-like</span>}
        {!group.reference_like_all && group.reference_like_count > 0 && <span className="incident-status-pill">{group.reference_like_count} reference-like</span>}
      </div>
      <div className="maintenance-code-group-facts">
        <span>{occurrenceLabel(group.occurrence_count)}{filtersActive && partial ? ` · ${group.matching_occurrence_count} match` : ''}</span>
        <span title="High · Medium · Low occurrences">{evidenceMix(group)}</span>
        <span title={sourcePagesLabel(group)}>{pageRangeLabel(group)}{group.has_multi_page_candidate ? ' · spans pages' : ''}</span>
        <span>{collisionLabel(group)}</span>
      </div>
      <button className="secondary-button" type="button" onClick={() => onOpen(group.normalized_code)}>Review <ChevronRight size={14} /></button>
    </li>
  )
}

function Toolbar({ filters, onChange, onPreset, perPage, onPerPage, sort, onSort }) {
  const set = (key) => (event) => onChange({ ...filters, [key]: event.target.value })
  return (
    <div className="maintenance-code-toolbar">
      <div className="maintenance-preset-row" role="group" aria-label="Review presets">
        {FILTER_PRESETS.map((preset) => (
          <button key={preset.id} className="secondary-button" type="button" title={preset.hint} onClick={() => onPreset(preset)}>{preset.label}</button>
        ))}
      </div>
      <div className="maintenance-filter-row" role="search">
        <input value={filters.code} onChange={set('code')} placeholder="Search code…" aria-label="Search by code" maxLength={64} />
        <select value={filters.bestEvidence} onChange={set('bestEvidence')} aria-label="Filter by best evidence">
          <option value="">Any best evidence</option>
          {Object.entries(BEST_EVIDENCE_LABELS).map(([value, label]) => <option key={value} value={value}>Best: {label}</option>)}
        </select>
        <select value={filters.evidence} onChange={set('evidence')} aria-label="Filter by occurrence evidence">
          <option value="">Any occurrence evidence</option>
          {Object.entries(EVIDENCE_LABELS).map(([value, label]) => <option key={value} value={value}>Has {label.toLowerCase()} occurrence</option>)}
        </select>
        <select value={filters.reviewState} onChange={set('reviewState')} aria-label="Filter by review state">
          <option value="">Any review state</option>
          {Object.entries(REVIEW_STATE_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </select>
      </div>
      <div className="maintenance-filter-row">
        <input value={filters.pageFrom} onChange={set('pageFrom')} inputMode="numeric" placeholder="From page" aria-label="From page" />
        <input value={filters.pageTo} onChange={set('pageTo')} inputMode="numeric" placeholder="To page" aria-label="To page" />
        <select value={filters.referenceLike} onChange={set('referenceLike')} aria-label="Filter by reference-like signal">
          <option value="">Reference-like: any</option>
          {Object.entries(REFERENCE_LIKE_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </select>
        <select value={filters.collisionStatus} onChange={set('collisionStatus')} aria-label="Filter by collision status">
          <option value="">Any collision status</option>
          {Object.entries(collisionStatusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </select>
      </div>
      <details className="maintenance-more-filters">
        <summary>More filters &amp; sorting</summary>
        <div className="maintenance-filter-row">
          <input value={filters.minOccurrences} onChange={set('minOccurrences')} inputMode="numeric" placeholder="Min occurrences" aria-label="Minimum occurrences" />
          <input value={filters.maxOccurrences} onChange={set('maxOccurrences')} inputMode="numeric" placeholder="Max occurrences" aria-label="Maximum occurrences" />
          <select value={sort} onChange={(event) => onSort(event.target.value)} aria-label="Sort groups">
            <option value="best_evidence">Sort: best evidence</option>
            <option value="occurrences">Sort: occurrences</option>
            <option value="page">Sort: first page</option>
            <option value="code">Sort: code</option>
          </select>
          <select value={perPage} onChange={(event) => onPerPage(Number(event.target.value))} aria-label="Groups per page">
            {PER_PAGE_OPTIONS.map((n) => <option key={n} value={n}>{n} per page</option>)}
          </select>
        </div>
      </details>
    </div>
  )
}

/**
 * V1.7.2 - the primary review surface: one row per distinct normalized code, aggregated
 * server-side, with every underlying candidate one click away in the group detail.
 * Consolidation is for REVIEW only - nothing here approves or publishes in bulk.
 */
export function CodeGroupsPanel({ importId, canManage, documentImport, version, onChanged, onPublish }) {
  const [filters, setFilters] = useState({ ...EMPTY_FILTERS })
  const [perPage, setPerPage] = useState(DEFAULT_PER_PAGE)
  const [sort, setSort] = useState('best_evidence')
  const [openCode, setOpenCode] = useState(null)
  const [triageAction, setTriageAction] = useState(null)
  const groupsState = useCodeGroups({ importId, filters, perPage, sort, version })

  const filtersActive = JSON.stringify(filters) !== JSON.stringify(EMPTY_FILTERS)
  const validationError = filterValidationError(filters)
  const bulkFilters = toBulkFilters(filters)
  const bulkReady = hasBulkCriteria(filters) && !validationError
  const ignored = filtersIgnoredByBulk(filters)
  const summary = groupsState.summary

  return (
    <div className="maintenance-code-groups">
      <p className="maintenance-processing-progress" data-testid="import-progress">
        {documentImport?.pages_processed ?? 0} / {documentImport?.extraction?.total_pages ?? documentImport?.pages_processed ?? 0} pages · {documentImport?.candidate_count ?? summary?.total_candidates ?? 0} candidates · {summary?.distinct_codes ?? '…'} distinct codes
      </p>
      <SummaryCards summary={summary} />
      <p className="maintenance-evidence-note"><small>Evidence is detector evidence, not verification. Several candidate rows can support the same error code; open a code to see every occurrence and where it came from.</small></p>

      <Toolbar filters={filters} onChange={setFilters} onPreset={(preset) => setFilters({ ...EMPTY_FILTERS, ...preset.filters })} perPage={perPage} onPerPage={setPerPage} sort={sort} onSort={setSort} />

      {validationError && <div className="form-error" role="alert"><span>{validationError}</span></div>}
      {groupsState.error && <div className="form-error" role="alert"><span>The code groups could not be loaded.</span> <button className="secondary-button" type="button" onClick={groupsState.refresh}>Retry</button></div>}

      {canManage && (
        <div className="maintenance-bulk-action-bar" role="toolbar" aria-label="Bulk triage">
          <span>Bulk triage</span>
          <button className="danger-outline-button" type="button" disabled={!bulkReady} onClick={() => setTriageAction('reject')}><Trash2 size={15} /> Preview reject…</button>
          <button className="secondary-button" type="button" disabled={!bulkReady} onClick={() => setTriageAction('restore')}><RotateCcw size={15} /> Preview restore…</button>
          <small>{bulkReady ? 'Acts on the candidates matching the current filters, after a preview and an explicit confirmation.' : 'Choose at least one filter (evidence, page range, code, collision or reference-like) to triage in bulk.'}</small>
        </div>
      )}

      {groupsState.isLoading && groupsState.groups.length === 0 ? (
        <small>Loading code groups…</small>
      ) : groupsState.total === 0 && !groupsState.error ? (
        <small>{filtersActive ? 'No codes match the current filters.' : 'No detected codes in this import.'}</small>
      ) : (
        <>
          <p className="maintenance-result-count" aria-live="polite">{groupsState.total} code{groupsState.total === 1 ? '' : 's'}{filtersActive ? ' match' : ''}</p>
          <ul className="maintenance-code-group-list">
            {groupsState.groups.map((group) => <GroupRow key={group.normalized_code} group={group} filtersActive={filtersActive} onOpen={setOpenCode} />)}
          </ul>
          {groupsState.lastPage > 1 && (
            <div className="maintenance-extracted-pages-nav">
              <button className="secondary-button" type="button" disabled={groupsState.currentPage <= 1 || groupsState.isLoading} onClick={() => groupsState.goToPage(groupsState.currentPage - 1)}>Previous</button>
              <span>Page {groupsState.currentPage} of {groupsState.lastPage} ({groupsState.total} codes)</span>
              <button className="secondary-button" type="button" disabled={groupsState.currentPage >= groupsState.lastPage || groupsState.isLoading} onClick={() => groupsState.goToPage(groupsState.currentPage + 1)}>Next</button>
            </div>
          )}
        </>
      )}

      {openCode && (
        <CodeGroupDetailDialog importId={importId} code={openCode} canManage={canManage} version={version} onClose={() => setOpenCode(null)} onChanged={onChanged} onPublish={onPublish} />
      )}
      {triageAction && (
        <TriageDialog importId={importId} action={triageAction} bulkFilters={bulkFilters} ignoredFilters={ignored} onClose={() => setTriageAction(null)} onApplied={onChanged} />
      )}
    </div>
  )
}
