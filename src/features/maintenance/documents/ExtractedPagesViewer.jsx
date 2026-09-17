import { useExtractedPages } from './useExtractedPages.js'

// Extracted content can run to hundreds of pages - only one pagination page's
// worth of rows is ever fetched/rendered at a time (useExtractedPages), never
// the whole document at once.
export function ExtractedPagesViewer({ documentId }) {
  const { pages, currentPage, lastPage, total, isLoading, goToPage } = useExtractedPages(documentId)

  if (isLoading && !pages.length) return <small>Loading extracted content…</small>
  if (!total) return <small>No extracted content yet.</small>

  return (
    <div className="maintenance-extracted-pages">
      {pages.map((page) => (
        <div key={page.id} className="maintenance-extracted-page">
          <strong>Page {page.page_number}</strong>
          <hr />
          <p>{page.raw_text?.trim() || 'No extractable text on this page.'}</p>
        </div>
      ))}
      {lastPage > 1 && (
        <div className="maintenance-extracted-pages-nav">
          <button className="secondary-button" type="button" disabled={currentPage <= 1 || isLoading} onClick={() => goToPage(currentPage - 1)}>Previous</button>
          <span>Page {currentPage} of {lastPage}</span>
          <button className="secondary-button" type="button" disabled={currentPage >= lastPage || isLoading} onClick={() => goToPage(currentPage + 1)}>Next</button>
        </div>
      )}
    </div>
  )
}
