import { useCallback, useEffect, useMemo, useState } from 'react'
import ManagerLayout from '../components/ManagerLayout'
import { getManagerAnnouncements } from '../lib/api'
import PaginationControls from '../components/PaginationControls'

function ManagerAnnouncements() {
  const [announcements, setAnnouncements] = useState([])
  const [meta, setMeta] = useState({ types: [] })
  const [filters, setFilters] = useState({ search: '', type: '' })
  const [selectedAnnouncement, setSelectedAnnouncement] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [pageError, setPageError] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  const pageSize = 10

  const filterParams = useMemo(
    () => ({
      search: filters.search.trim() || undefined,
      type: filters.type || undefined,
    }),
    [filters],
  )

  const loadAnnouncements = useCallback(async () => {
    setIsLoading(true)
    setPageError('')

    try {
      const result = await getManagerAnnouncements(filterParams)
      setAnnouncements(result.announcements)
      setCurrentPage(1)
      setMeta(result.meta)
      setSelectedAnnouncement((current) =>
        current ? result.announcements.find((item) => item.id === current.id) ?? null : null,
      )
    } catch {
      setPageError('Unable to load announcements.')
    } finally {
      setIsLoading(false)
    }
  }, [filterParams])

  useEffect(() => {
    const timeoutId = window.setTimeout(loadAnnouncements, 250)

    return () => window.clearTimeout(timeoutId)
  }, [loadAnnouncements])

  function updateFilter(event) {
    const { name, value } = event.target
    setFilters((current) => ({ ...current, [name]: value }))
  }

  function clearFilters() {
    setFilters({ search: '', type: '' })
  }

  const hasActiveFilters = Object.values(filters).some(Boolean)
  const pageCount = Math.max(1, Math.ceil(announcements.length / pageSize))
  const visibleAnnouncements = announcements.slice((currentPage - 1) * pageSize, currentPage * pageSize)

  function formatDate(value) {
    if (!value) {
      return 'Not set'
    }

    return new Intl.DateTimeFormat('en-PH', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    }).format(new Date(value))
  }

  return (
    <ManagerLayout eyebrow="Announcements" title="Staff Announcements">
      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}

      <section className="admin-management-grid announcement-management-grid manager-announcement-management-grid">
        <div className="admin-table-panel">
          <div className="admin-filter-bar announcement-filter-bar manager-announcement-filter-bar">
            <label><span>Search</span><input name="search" value={filters.search} onChange={updateFilter} /></label>
            <label><span>Type</span><select name="type" value={filters.type} onChange={updateFilter}><option value="">All types</option>{meta.types.map((type) => <option key={type} value={type}>{type.replaceAll('_', ' ')}</option>)}</select></label>
          </div>
          <div className="admin-table-scroll">
            <table className="admin-data-table announcement-table manager-announcement-table">
              <thead><tr><th>Title</th><th>Type</th><th>Audience</th><th>Published</th><th>Actions</th></tr></thead>
              <tbody>
                {isLoading ? <tr><td colSpan="5" aria-label="Loading announcements..." /></tr> : null}
                {!isLoading && announcements.length === 0 ? (
                  <tr>
                    <td colSpan="5">
                      <div className="manager-empty-state">
                        <span>{hasActiveFilters ? 'No announcements match your current filters.' : 'No announcements are currently available.'}</span>
                        {hasActiveFilters ? <button type="button" onClick={clearFilters}>Clear filters</button> : null}
                      </div>
                    </td>
                  </tr>
                ) : null}
                {!isLoading ? visibleAnnouncements.map((announcement) => (
                  <tr key={announcement.id} className="manager-clickable-row" onClick={() => setSelectedAnnouncement(announcement)}>
                    <td><strong>{announcement.title}</strong></td>
                    <td><span className={`admin-status-pill announcement-type-${announcement.type}`}>{announcement.type.replaceAll('_', ' ')}</span></td>
                    <td><span className={`admin-status-pill announcement-audience-${announcement.audience}`}>{announcement.audience}</span></td>
                    <td>{formatDate(announcement.publish_at)}</td>
                    <td><button type="button" onClick={(event) => { event.stopPropagation(); setSelectedAnnouncement(announcement) }}>View</button></td>
                  </tr>
                )) : null}
              </tbody>
            </table>
          </div>
          {!isLoading && announcements.length > 0 ? <PaginationControls page={currentPage} pageCount={pageCount} total={announcements.length} pageSize={pageSize} label="announcements" onPageChange={setCurrentPage} /> : null}
        </div>
      </section>

      {selectedAnnouncement ? (
        <>
          <button
            className="manager-announcement-drawer-backdrop"
            type="button"
            aria-label="Close announcement details"
            onClick={() => setSelectedAnnouncement(null)}
          />
          <aside className="admin-form-panel manager-announcement-drawer" aria-label="Announcement details">
            <div className="manager-announcement-drawer-header">
              <div>
              <p className="eyebrow">Announcement Details</p>
                <h2>{selectedAnnouncement.title}</h2>
              </div>
              <button type="button" onClick={() => setSelectedAnnouncement(null)} aria-label="Close announcement details">
                X
              </button>
            </div>

            <dl className="admin-detail-list">
              <div><dt>Type</dt><dd>{selectedAnnouncement.type.replaceAll('_', ' ')}</dd></div>
              <div><dt>Audience</dt><dd>{selectedAnnouncement.audience}</dd></div>
              <div><dt>Published</dt><dd>{formatDate(selectedAnnouncement.publish_at)}</dd></div>
              <div><dt>Expires</dt><dd>{formatDate(selectedAnnouncement.expires_at)}</dd></div>
            </dl>
            <div className="manager-announcement-content">
              <p>{selectedAnnouncement.content}</p>
            </div>
          </aside>
        </>
      ) : null}
    </ManagerLayout>
  )
}

export default ManagerAnnouncements
