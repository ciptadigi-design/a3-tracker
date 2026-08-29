export const PAGE_SIZE_OPTIONS = [10, 25, 50]
export const DEFAULT_PAGE_SIZE = 10

export function pageCount(total, pageSize = DEFAULT_PAGE_SIZE) {
  return Math.max(1, Math.ceil(Math.max(0, Number(total) || 0) / pageSize))
}

export function normalizePage(page, total, pageSize = DEFAULT_PAGE_SIZE) {
  return Math.min(Math.max(1, Number(page) || 1), pageCount(total, pageSize))
}

export function pageRange(total, page, pageSize = DEFAULT_PAGE_SIZE) {
  const safeTotal = Math.max(0, Number(total) || 0)
  const safePage = normalizePage(page, safeTotal, pageSize)
  if (!safeTotal) return { start: 0, end: 0, page: 1, pages: 1 }
  const start = (safePage - 1) * pageSize
  return { start, end: Math.min(start + pageSize, safeTotal), page: safePage, pages: pageCount(safeTotal, pageSize) }
}

export function paginateRows(rows, page, pageSize = DEFAULT_PAGE_SIZE) {
  const range = pageRange(rows.length, page, pageSize)
  return { ...range, rows: rows.slice(range.start, range.end) }
}

export function visiblePages(page, pages, radius = 1) {
  if (pages <= 5) return Array.from({ length: pages }, (_, index) => index + 1)
  const candidates = new Set([1, pages])
  for (let value = page - radius; value <= page + radius; value += 1) {
    if (value > 1 && value < pages) candidates.add(value)
  }
  const ordered = [...candidates].sort((left, right) => left - right)
  return ordered.flatMap((value, index) => index && value - ordered[index - 1] > 1 ? ['ellipsis', value] : [value])
}
