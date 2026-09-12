import { useCallback, useEffect, useMemo, useState } from 'react'
import { Bell, CalendarDays, Megaphone, Search } from 'lucide-react'
import FrontDeskLayout from '../components/FrontDeskLayout'
import PaginationControls from '../components/PaginationControls'
import { getFrontDeskAnnouncement, getFrontDeskAnnouncements } from '../lib/api'

const typeLabels = {
  general: 'General',
  promotion: 'Promotion',
  maintenance: 'Maintenance',
  facility_notice: 'Facility Notice',
  booking_advisory: 'Booking Advisory',
  holiday_notice: 'Holiday Notice',
  staff_notice: 'Staff Notice',
}

const typeTones = {
  general: 'muted',
  promotion: 'amber',
  maintenance: 'slate',
  facility_notice: 'green',
  booking_advisory: 'blue',
  holiday_notice: 'amber',
  staff_notice: 'teal',
}

function FrontDeskAnnouncements() {
  const [announcements, setAnnouncements] = useState([])
  const [summary, setSummary] = useState({ active_announcements: 0, recent_announcements: 0, staff_notices: 0 })
  const [meta, setMeta] = useState({ types: [] })
  const [filters, setFilters] = useState({ search: '', type: '' })
  const [isLoading, setIsLoading] = useState(true)
  const [pageError, setPageError] = useState('')
  const [selectedAnnouncement, setSelectedAnnouncement] = useState(null)
  const [isDetailLoading, setIsDetailLoading] = useState(false)
  const [detailError, setDetailError] = useState('')
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
      const result = await getFrontDeskAnnouncements(filterParams)
      setAnnouncements(result.announcements)
      setSummary(result.summary ?? { active_announcements: 0, recent_announcements: 0, staff_notices: 0 })
      setMeta(result.meta ?? { types: [] })
      setSelectedAnnouncement((current) =>
        current ? result.announcements.find((item) => item.id === current.id) ?? null : null,
      )
    } catch {
      setPageError('Unable to load announcements right now.')
    } finally {
      setIsLoading(false)
    }
  }, [filterParams])

  useEffect(() => {
    const timeoutId = window.setTimeout(loadAnnouncements, 250)
    return () => window.clearTimeout(timeoutId)
  }, [loadAnnouncements])

  useEffect(() => {
    setCurrentPage(1)
  }, [filterParams])

  async function openAnnouncement(announcement) {
    setSelectedAnnouncement(announcement)
    setDetailError('')
    setIsDetailLoading(true)

    try {
      const result = await getFrontDeskAnnouncement(announcement.id)
      setSelectedAnnouncement(result.announcement)
    } catch {
      setDetailError('Unable to load announcement details.')
    } finally {
      setIsDetailLoading(false)
    }
  }

  const hasActiveFilters = Object.values(filters).some(Boolean)
  const pageCount = Math.max(1, Math.ceil(announcements.length / pageSize))
  const visibleAnnouncements = announcements.slice((currentPage - 1) * pageSize, currentPage * pageSize)

  return (
    <FrontDeskLayout eyebrow="Announcements" title="Front Desk Announcements">
      <section className="frontdesk-announcements-header">
        <div>
          <p className="eyebrow">Front Desk</p>
          <h2>Announcements</h2>
          <p className="frontdesk-announcements-subtitle">Stay updated with resort notices and operational announcements.</p>
        </div>
      </section>

      <section className="dashboard-kpi-grid frontdesk-kpi-grid frontdesk-announcements-summary" aria-label="Announcement summary">
        <SummaryCard label="Active Announcements" value={summary.active_announcements} icon={Bell} tone="green" note="Visible to front desk staff" />
        <SummaryCard label="Recent Announcements" value={summary.recent_announcements} icon={CalendarDays} tone="blue" note="Published in the last 7 days" />
        <SummaryCard label="Staff Notices" value={summary.staff_notices} icon={Megaphone} tone="amber" note="Operational notices" />
      </section>

      <section className="frontdesk-announcements-panel">
        <div className="frontdesk-announcements-toolbar">
          <label className="frontdesk-announcements-search">
            <Search size={16} aria-hidden="true" />
            <input
              type="search"
              placeholder="Search announcements..."
              value={filters.search}
              onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))}
            />
          </label>
          <label className="frontdesk-announcements-filter">
            <span>Type</span>
            <select
              value={filters.type}
              onChange={(event) => setFilters((current) => ({ ...current, type: event.target.value }))}
            >
              <option value="">All types</option>
              {(meta.types?.length ? meta.types : Object.keys(typeLabels)).map((type) => (
                <option key={type} value={type}>{typeLabels[type] ?? type}</option>
              ))}
            </select>
          </label>
        </div>

        {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}
        {isLoading ? <div className="frontdesk-section-state" role="status" aria-label="Loading announcements..." /> : null}

        {!isLoading && announcements.length === 0 ? (
          <div className="frontdesk-empty-state frontdesk-announcements-empty">
            <Megaphone size={28} aria-hidden="true" />
            <div>
              <strong>{hasActiveFilters ? 'No announcements found' : 'No announcements available'}</strong>
              <p>{hasActiveFilters ? 'Try changing your search or filters.' : 'New resort notices will appear here.'}</p>
            </div>
          </div>
        ) : null}

        {!isLoading && announcements.length > 0 ? (
          <>
            <div className="frontdesk-announcements-grid">
              {visibleAnnouncements.map((announcement) => (
                <button
                  key={announcement.id}
                  type="button"
                  className="frontdesk-announcement-card"
                  onClick={() => openAnnouncement(announcement)}
                >
                  <div className="frontdesk-announcement-head">
                    <div>
                      <span className={`frontdesk-pill frontdesk-pill-status tone-${typeTones[announcement.type] ?? 'muted'}`}>
                        {typeLabels[announcement.type] ?? announcement.type}
                      </span>
                      <h3>{announcement.title}</h3>
                    </div>
                    <time dateTime={announcement.publish_at ?? announcement.created_at ?? ''}>
                      {formatDate(announcement.publish_at ?? announcement.created_at)}
                    </time>
                  </div>
                  <p>{announcement.preview ?? truncate(announcement.content)}</p>
                  <div className="frontdesk-announcement-meta">
                    <span>{announcement.created_by_name ? `By ${announcement.created_by_name}` : 'Front Desk notice'}</span>
                    <span>View details</span>
                  </div>
                </button>
              ))}
            </div>
            <PaginationControls page={currentPage} pageCount={pageCount} total={announcements.length} pageSize={pageSize} label="announcements" onPageChange={setCurrentPage} />
          </>
        ) : null}
      </section>

      {selectedAnnouncement ? (
        <AnnouncementDetailModal
          announcement={selectedAnnouncement}
          isLoading={isDetailLoading}
          error={detailError}
          onClose={() => setSelectedAnnouncement(null)}
        />
      ) : null}
    </FrontDeskLayout>
  )
}

function SummaryCard({ label, value, icon: Icon, tone, note }) {
  return (
    <article className={`dashboard-kpi-card frontdesk-kpi-card frontdesk-kpi-tone-${tone}`}>
      <span className={`dashboard-kpi-icon frontdesk-kpi-icon tone-${tone}`}>
        <Icon size={24} strokeWidth={2.5} aria-hidden="true" />
      </span>
      <div>
        <p>{label}</p>
        <strong>{value.toLocaleString()}</strong>
        <small>{note}</small>
      </div>
    </article>
  )
}

function AnnouncementDetailModal({ announcement, isLoading, error, onClose }) {
  return (
    <>
      <button className="frontdesk-modal-backdrop" type="button" aria-label="Close announcement details" onClick={onClose} />
      <section className="frontdesk-modal frontdesk-announcement-modal" role="dialog" aria-modal="true" aria-labelledby="frontdesk-announcement-title">
        <div className="frontdesk-modal-header">
          <div>
            <p className="eyebrow">Announcement Details</p>
            <h3 id="frontdesk-announcement-title">{announcement.title}</h3>
          </div>
          <button type="button" onClick={onClose} aria-label="Close dialog">X</button>
        </div>
        {isLoading ? <div className="frontdesk-section-state" role="status" aria-label="Loading announcement details..." /> : null}
        {error ? <div className="admin-inline-alert" role="alert">{error}</div> : null}
        {!isLoading && !error ? (
          <div className="frontdesk-announcement-detail">
            <dl className="frontdesk-modal-list">
              <div><dt>Type</dt><dd>{typeLabels[announcement.type] ?? announcement.type}</dd></div>
              <div><dt>Audience</dt><dd>{announcement.audience}</dd></div>
              <div><dt>Published</dt><dd>{formatDate(announcement.publish_at)}</dd></div>
              <div><dt>Expires</dt><dd>{formatDate(announcement.expires_at)}</dd></div>
              <div><dt>Created By</dt><dd>{announcement.created_by_name ?? 'Front Desk'}</dd></div>
            </dl>
            <div className="frontdesk-announcement-content">
              <p>{announcement.content}</p>
            </div>
          </div>
        ) : null}
      </section>
    </>
  )
}

function formatDate(value) {
  if (!value) return 'Not set'
  return new Intl.DateTimeFormat('en-PH', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  }).format(new Date(value))
}

function truncate(value = '') {
  return value.length > 180 ? `${value.slice(0, 177)}...` : value
}

export default FrontDeskAnnouncements
