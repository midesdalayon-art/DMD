import { useEffect } from 'react'

function PaginationControls({ page, pageCount, total, pageSize, label, onPageChange }) {
  useEffect(() => {
    if (page > pageCount) {
      onPageChange(pageCount)
    }
  }, [page, pageCount, onPageChange])

  if (total <= 0) return null

  const from = (page - 1) * pageSize + 1
  const to = Math.min(page * pageSize, total)

  return (
    <footer className="shared-pagination" aria-label={`${label} pagination`}>
      <span>Showing {from}–{to} of {total} {label}</span>
      <div className="shared-pagination-controls">
        <button type="button" onClick={() => onPageChange(Math.max(1, page - 1))} disabled={page <= 1}>Previous</button>
        {Array.from({ length: pageCount }, (_, index) => index + 1).map((number) => (
          <button key={number} type="button" className={number === page ? 'is-active' : ''} aria-current={number === page ? 'page' : undefined} onClick={() => onPageChange(number)}>
            {number}
          </button>
        ))}
        <button type="button" onClick={() => onPageChange(Math.min(pageCount, page + 1))} disabled={page >= pageCount}>Next</button>
      </div>
    </footer>
  )
}

export default PaginationControls
