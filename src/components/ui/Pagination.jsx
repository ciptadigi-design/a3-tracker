import { ChevronLeft, ChevronRight } from 'lucide-react'
import { PAGE_SIZE_OPTIONS, visiblePages } from '../../features/pagination/paginationModel.js'

export function Pagination({ total, page, pageSize, pages, start, end, onPageChange, onPageSizeChange, label = 'records' }) {
  if (total === 0) return null
  const singlePage = total <= pageSize
  return <nav className={`pagination-control${singlePage ? ' single-page' : ''}`} aria-label={`${label} pagination`}>
    <span className="pagination-range">Showing {start + 1}–{end} of {total}</span>
    {!singlePage && <div className="pagination-actions">
      <button type="button" onClick={() => onPageChange(page - 1)} disabled={page === 1} aria-label="Previous page"><ChevronLeft size={15} /><span>Previous</span></button>
      <div className="pagination-pages" aria-label={`Page ${page} of ${pages}`}>
        {visiblePages(page, pages).map((item, index) => item === 'ellipsis' ? <span key={`ellipsis-${index}`} aria-hidden="true">…</span> : <button type="button" key={item} className={item === page ? 'active' : ''} aria-current={item === page ? 'page' : undefined} onClick={() => onPageChange(item)}>{item}</button>)}
      </div>
      <span className="pagination-mobile-page">{page} / {pages}</span>
      <button type="button" onClick={() => onPageChange(page + 1)} disabled={page === pages} aria-label="Next page"><span>Next</span><ChevronRight size={15} /></button>
    </div>}
    <label className="pagination-size"><span>Rows</span><select value={pageSize} onChange={(event) => onPageSizeChange(event.target.value)} aria-label={`${label} per page`}>{PAGE_SIZE_OPTIONS.map((size) => <option value={size} key={size}>{size}</option>)}</select><small>per page</small></label>
  </nav>
}
