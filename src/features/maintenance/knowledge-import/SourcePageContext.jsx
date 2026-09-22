import { useEffect, useMemo, useState } from 'react'
import { LoaderCircle } from 'lucide-react'
import { loadCodeGroupPage } from '../../../services/maintenance.js'
import { mapMaintenanceError } from '../maintenanceUtils.js'

// V1.9 - Source Page Context. The real C-1127 review needed the FULL extracted text of its
// supporting pages, not the bounded per-candidate excerpt already shown above this section -
// see KnowledgeCodeGroupQuery::detail(). This never edits anything: it is read-only evidence for
// the human reviewer, rendered as plain text (no dangerouslySetInnerHTML, no HTML from raw
// extracted content).

/** Deterministic, exact-substring highlighting only - never semantic, never inferred. */
function highlightCode(text, code) {
  if (!code || !text || !text.includes(code)) return text
  const segments = text.split(code)

  return segments.flatMap((segment, index) => (
    index === segments.length - 1 ? [segment] : [segment, <mark key={index}>{code}</mark>]
  ))
}

function PageBody({ text, code }) {
  const trimmed = text?.trim()
  if (!trimmed) return <p>No extractable text on this page.</p>

  return <p className="maintenance-source-page-text">{highlightCode(text, code)}</p>
}

export function SourcePageContext({ importId, code, sourcePages, truncated, onSourceView }) {
  const directPages = useMemo(() => [...(sourcePages ?? [])].sort((a, b) => a.page_number - b.page_number), [sourcePages])
  const [activePage, setActivePage] = useState(directPages[0]?.page_number ?? null)
  const [adjacent, setAdjacent] = useState({}) // page_number -> { raw_text, is_direct_source, loading, error }

  useEffect(() => {
    if (!directPages.some((p) => p.page_number === activePage)) {
      setActivePage(directPages[0]?.page_number ?? null)
    }
    // Re-anchor to this group's own pages whenever the group changes - never keep showing a
    // stale page from a previously viewed code.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [code, directPages])

  if (directPages.length === 0) {
    return (
      <section className="maintenance-source-page-context" aria-label="Source page context">
        <div className="form-section-heading"><strong>Source Page Context</strong></div>
        <small>No extracted page text is available for this group's supporting pages.</small>
      </section>
    )
  }

  const activeDirect = directPages.find((p) => p.page_number === activePage)
  const activeAdjacent = !activeDirect ? adjacent[activePage] : null

  async function goToAdjacent(pageNumber) {
    setActivePage(pageNumber)
    onSourceView?.()
    if (directPages.some((p) => p.page_number === pageNumber) || adjacent[pageNumber]) return
    setAdjacent((prev) => ({ ...prev, [pageNumber]: { loading: true, error: null, raw_text: null, is_direct_source: false } }))
    try {
      const page = await loadCodeGroupPage(importId, code, pageNumber)
      setAdjacent((prev) => ({ ...prev, [pageNumber]: { loading: false, error: null, raw_text: page.raw_text, is_direct_source: page.is_direct_source } }))
    } catch (error) {
      setAdjacent((prev) => ({ ...prev, [pageNumber]: { loading: false, error: mapMaintenanceError(error), raw_text: null, is_direct_source: false } }))
    }
  }

  return (
    <section className="maintenance-source-page-context" aria-label="Source page context">
      <div className="form-section-heading"><strong>Source Page Context</strong><span>The complete extracted text of this group's supporting page(s), exactly as stored - read-only.</span></div>

      {directPages.length > 1 && (
        <div className="maintenance-source-page-selector" role="tablist" aria-label="Source pages">
          {directPages.map((p) => (
            <button key={p.page_number} type="button" role="tab" aria-selected={activePage === p.page_number} className={`incident-status-pill${activePage === p.page_number ? ' resolved' : ''}`} onClick={() => { setActivePage(p.page_number); onSourceView?.() }}>
              Page {p.page_number}
            </button>
          ))}
        </div>
      )}
      {truncated && <small>Showing the first {directPages.length} supporting pages.</small>}

      <div className="maintenance-source-page-nav">
        <button className="secondary-button" type="button" disabled={activePage === null} onClick={() => goToAdjacent(activePage - 1)}>&larr; Previous page ({activePage !== null ? activePage - 1 : '—'})</button>
        <span className="incident-status-pill">Page {activePage ?? '—'}{activeDirect ? '' : ' · adjacent context'}</span>
        <button className="secondary-button" type="button" disabled={activePage === null} onClick={() => goToAdjacent(activePage + 1)}>Next page ({activePage !== null ? activePage + 1 : '—'}) &rarr;</button>
      </div>

      {activeDirect && (
        <div className="maintenance-extracted-page" data-testid={`source-page-${activeDirect.page_number}`}>
          <strong>Page {activeDirect.page_number} <span className="incident-status-pill">Direct source page</span></strong>
          <hr />
          <PageBody text={activeDirect.raw_text} code={code} />
        </div>
      )}
      {!activeDirect && activeAdjacent?.loading && <small><LoaderCircle className="spin" size={13} /> Loading page {activePage}…</small>}
      {!activeDirect && activeAdjacent?.error && <div className="form-error" role="alert"><span>{activeAdjacent.error} · Page {activePage} may be outside the reviewable context for this code.</span></div>}
      {!activeDirect && activeAdjacent && !activeAdjacent.loading && !activeAdjacent.error && (
        <div className="maintenance-extracted-page" data-testid={`source-page-${activePage}`}>
          <strong>Page {activePage} <span className="incident-status-pill">{activeAdjacent.is_direct_source ? 'Direct source page' : 'Adjacent context page'}</span></strong>
          <hr />
          <PageBody text={activeAdjacent.raw_text} code={code} />
        </div>
      )}
    </section>
  )
}
