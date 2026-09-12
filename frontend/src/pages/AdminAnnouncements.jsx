import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  createAdminAnnouncement,
  getAdminAnnouncement,
  getAdminAnnouncements,
  updateAdminAnnouncement,
  updateAdminAnnouncementStatus,
} from '../lib/api'
import { ToastMessage } from '../context/ToastContext'
import PaginationControls from '../components/PaginationControls'

const defaultFormValues = {
  title: '',
  content: '',
  type: 'general',
  audience: 'everyone',
  status: 'draft',
  publish_at: '',
  expires_at: '',
}

const typeLabels = {
  general: 'General',
  promotion: 'Promotion',
  maintenance: 'Maintenance',
  facility_notice: 'Facility Notice',
  booking_advisory: 'Booking Advisory',
  holiday_notice: 'Holiday Notice',
  staff_notice: 'Staff Notice',
}

const audienceLabels = {
  everyone: 'Everyone',
  guests: 'Guests',
  staff: 'Staff',
}

const statusLabels = {
  draft: 'Draft',
  published: 'Published',
  archived: 'Archived',
}

function AdminAnnouncements() {
  const [announcements, setAnnouncements] = useState([])
  const [summary, setSummary] = useState(null)
  const [meta, setMeta] = useState({ types: [], audiences: [], statuses: [] })
  const [selectedAnnouncement, setSelectedAnnouncement] = useState(null)
  const [formValues, setFormValues] = useState(defaultFormValues)
  const [filters, setFilters] = useState({
    search: '',
    type: '',
    audience: '',
    status: '',
  })
  const [fieldErrors, setFieldErrors] = useState({})
  const [pageMessage, setPageMessage] = useState('')
  const [pageError, setPageError] = useState('')
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)
  const [pendingActionId, setPendingActionId] = useState(null)
  const [isDrawerOpen, setIsDrawerOpen] = useState(false)
  const [currentPage, setCurrentPage] = useState(1)
  const pageSize = 10
  const announcementsRequestSequence = useRef(0)

  const filterParams = useMemo(
    () => ({
      search: filters.search.trim() || undefined,
      type: filters.type || undefined,
      audience: filters.audience || undefined,
      status: filters.status || undefined,
    }),
    [filters],
  )

  const hasActiveFilters = Object.values(filters).some((value) => value.trim() !== '')
  const hasAnyAnnouncements = (summary?.total_announcements ?? 0) > 0
  const pageCount = Math.max(1, Math.ceil(announcements.length / pageSize))
  const visibleAnnouncements = announcements.slice((currentPage - 1) * pageSize, currentPage * pageSize)

  const loadAnnouncements = useCallback(async () => {
    const requestId = ++announcementsRequestSequence.current
    setIsLoading(true)
    setPageError('')

    try {
      const result = await getAdminAnnouncements(filterParams)
      if (requestId !== announcementsRequestSequence.current) return
      setAnnouncements(result.announcements)
      setCurrentPage(1)
      setSummary(result.summary)
      setMeta(result.meta)
    } catch {
      if (requestId !== announcementsRequestSequence.current) return
      setPageError('Unable to load announcements right now.')
    } finally {
      if (requestId === announcementsRequestSequence.current) setIsLoading(false)
    }
  }, [filterParams])

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      loadAnnouncements()
    }, 250)

    return () => {
      window.clearTimeout(timeoutId)
      announcementsRequestSequence.current += 1
    }
  }, [loadAnnouncements])

  function updateFilter(event) {
    const { name, value } = event.target
    setFilters((current) => ({ ...current, [name]: value }))
  }

  function updateFormField(event) {
    const { name, value } = event.target
    setFormValues((current) => ({ ...current, [name]: value }))
    setFieldErrors((current) => ({ ...current, [name]: '' }))
    setPageError('')
    setPageMessage('')
  }

  function clearFilters() {
    setFilters({
      search: '',
      type: '',
      audience: '',
      status: '',
    })
  }

  function startCreate() {
    setSelectedAnnouncement(null)
    setFormValues(defaultFormValues)
    setFieldErrors({})
    setPageError('')
    setPageMessage('')
    setIsDrawerOpen(true)
  }

  function closeDrawer() {
    setIsDrawerOpen(false)
    setFieldErrors({})
  }

  async function selectAnnouncement(announcement) {
    setPageError('')
    setPageMessage('')

    try {
      const result = await getAdminAnnouncement(announcement.id)
      setSelectedAnnouncement(result.announcement)
      setMeta((current) => ({ ...current, ...result.meta }))
      setFormValues(announcementToForm(result.announcement))
      setFieldErrors({})
      setIsDrawerOpen(true)
    } catch {
      setPageError('Unable to load announcement details.')
    }
  }

  function announcementToForm(announcement) {
    return {
      title: announcement.title ?? '',
      content: announcement.content ?? '',
      type: announcement.type ?? 'general',
      audience: announcement.audience ?? 'everyone',
      status: announcement.status ?? 'draft',
      publish_at: toDateTimeInput(announcement.publish_at),
      expires_at: toDateTimeInput(announcement.expires_at),
    }
  }

  function buildPayload() {
    return {
      title: formValues.title.trim(),
      content: formValues.content.trim(),
      type: formValues.type,
      audience: formValues.audience,
      status: formValues.status,
      publish_at: formValues.publish_at || null,
      expires_at: formValues.expires_at || null,
    }
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setIsSaving(true)
    setFieldErrors({})
    setPageError('')
    setPageMessage('')

    try {
      const result = selectedAnnouncement
        ? await updateAdminAnnouncement(selectedAnnouncement.id, buildPayload())
        : await createAdminAnnouncement(buildPayload())
      setPageMessage(result.message)
      await loadAnnouncements()
      await selectAnnouncement(result.announcement)
    } catch (error) {
      const errors = error?.response?.data?.errors
      if (errors) {
        setFieldErrors(Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, value[0]])))
      } else {
        setPageError('Unable to save announcement.')
      }
    } finally {
      setIsSaving(false)
    }
  }

  async function quickStatus(announcement, status) {
    setPendingActionId(announcement.id)
    setPageError('')
    setPageMessage('')

    try {
      const result = await updateAdminAnnouncementStatus(announcement.id, status)
      setPageMessage(result.message)
      await loadAnnouncements()
      if (selectedAnnouncement?.id === announcement.id) await selectAnnouncement(result.announcement)
    } catch (error) {
      setPageError(error?.response?.data?.errors?.status?.[0] ?? 'Unable to update announcement status.')
    } finally {
      setPendingActionId(null)
    }
  }

  useEffect(() => {
    if (!isDrawerOpen) return undefined

    function handleKeyDown(event) {
      if (event.key === 'Escape') closeDrawer()
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [isDrawerOpen])

  function formatDateTime(value) {
    if (!value) return 'Not set'
    return new Intl.DateTimeFormat('en-PH', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
    }).format(new Date(value))
  }

  return (
    <>
      <section className="admin-summary-grid announcement-summary-grid" aria-label="Announcement summary">
        {[
          ['Total Announcements', summary?.total_announcements ?? 0],
          ['Published', summary?.published ?? 0],
          ['Drafts', summary?.drafts ?? 0],
          ['Archived', summary?.archived ?? 0],
        ].map(([label, value]) => (
          <article className="admin-summary-card" key={label}>
            <span>{label}</span>
            <strong>{isLoading ? '...' : value}</strong>
          </article>
        ))}
      </section>

      <ToastMessage message={pageMessage} />
      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}

      <section className="admin-management-grid announcement-management-grid">
        <div className="admin-table-panel">
          <div className="admin-table-heading">
            <div>
              <h2>Announcements ({isLoading ? '...' : announcements.length.toLocaleString()})</h2>
              <p>Manage resort notices for guests and staff.</p>
            </div>
            <div className="admin-table-heading-actions">
              {hasActiveFilters ? (
                <button className="outline-button" type="button" onClick={clearFilters}>
                  Clear Filters
                </button>
              ) : null}
              <button className="primary-button" type="button" onClick={startCreate}>
                + Create Announcement
              </button>
            </div>
          </div>

          <div className="admin-filter-bar announcement-filter-bar">
            <label>
              <span>Search</span>
              <input name="search" value={filters.search} onChange={updateFilter} placeholder="Title or message" />
            </label>
            <label>
              <span>Type</span>
              <select name="type" value={filters.type} onChange={updateFilter}>
                <option value="">All</option>
                {(meta.types ?? Object.keys(typeLabels)).map((type) => <option key={type} value={type}>{typeLabels[type]}</option>)}
              </select>
            </label>
            <label>
              <span>Audience</span>
              <select name="audience" value={filters.audience} onChange={updateFilter}>
                <option value="">All</option>
                {(meta.audiences ?? Object.keys(audienceLabels)).map((audience) => <option key={audience} value={audience}>{audienceLabels[audience]}</option>)}
              </select>
            </label>
            <label>
              <span>Status</span>
              <select name="status" value={filters.status} onChange={updateFilter}>
                <option value="">All</option>
                {(meta.statuses ?? Object.keys(statusLabels)).map((status) => <option key={status} value={status}>{statusLabels[status]}</option>)}
              </select>
            </label>
          </div>

          <div className="admin-table-scroll">
            <table className="admin-data-table announcement-table">
              <thead>
                <tr>
                  <th>Title</th>
                  <th>Type</th>
                  <th>Audience</th>
                  <th>Status</th>
                  <th>Publish Date</th>
                  <th>Expiry Date</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {isLoading ? <tr><td colSpan="7" aria-label="Loading announcements..." /></tr> : null}
                {!isLoading && announcements.length === 0 ? (
                  <tr>
                    <td colSpan="7">
                      {hasAnyAnnouncements ? 'No announcements match the current filters.' : 'No announcements have been created yet.'}
                    </td>
                  </tr>
                ) : null}
                {!isLoading ? visibleAnnouncements.map((announcement) => (
                  <tr key={announcement.id}>
                    <td className="announcement-title-cell">
                      <strong>{announcement.title}</strong>
                      <small>{announcement.content.slice(0, 80)}{announcement.content.length > 80 ? '...' : ''}</small>
                    </td>
                    <td><span className={`admin-status-pill announcement-type-${announcement.type}`}>{typeLabels[announcement.type]}</span></td>
                    <td><span className={`admin-status-pill announcement-audience-${announcement.audience}`}>{audienceLabels[announcement.audience]}</span></td>
                    <td><span className={`admin-status-pill status-${announcement.status}`}>{statusLabels[announcement.status]}</span></td>
                    <td>{formatDateTime(announcement.publish_at)}</td>
                    <td>{formatDateTime(announcement.expires_at)}</td>
                    <td>
                      <div className="admin-row-actions">
                        <button type="button" onClick={() => selectAnnouncement(announcement)}>View</button>
                        {announcement.status !== 'published' && announcement.status !== 'archived' ? (
                          <button type="button" onClick={() => quickStatus(announcement, 'published')} disabled={pendingActionId === announcement.id}>Publish</button>
                        ) : null}
                        {announcement.status === 'published' ? (
                          <button type="button" onClick={() => quickStatus(announcement, 'draft')} disabled={pendingActionId === announcement.id}>Unpublish</button>
                        ) : null}
                        {announcement.status !== 'archived' ? (
                          <button type="button" onClick={() => quickStatus(announcement, 'archived')} disabled={pendingActionId === announcement.id}>Archive</button>
                        ) : null}
                      </div>
                    </td>
                  </tr>
                )) : null}
              </tbody>
            </table>
          </div>
          {!isLoading && announcements.length > 0 ? <PaginationControls page={currentPage} pageCount={pageCount} total={announcements.length} pageSize={pageSize} label="announcements" onPageChange={setCurrentPage} /> : null}
        </div>
      </section>

      {isDrawerOpen ? (
        <>
          <button
            className="admin-announcement-drawer-backdrop"
            type="button"
            aria-label="Close announcement panel"
            onClick={closeDrawer}
          />
          <aside className="admin-form-panel admin-announcement-drawer" aria-label="Announcement form">
          <form className="admin-embedded-form" onSubmit={handleSubmit} noValidate>
            <div className="admin-announcement-drawer-header">
              <div>
                <p className="eyebrow">{selectedAnnouncement ? 'Announcement Details' : 'New Announcement'}</p>
                <h2>{selectedAnnouncement ? selectedAnnouncement.title : 'Create announcement'}</h2>
              </div>
              <button type="button" aria-label="Close announcement panel" onClick={closeDrawer}>x</button>
            </div>

            <label>
              <span>Title</span>
              <input name="title" value={formValues.title} onChange={updateFormField} />
              {fieldErrors.title ? <small role="alert">{fieldErrors.title}</small> : null}
            </label>

            <label>
              <span>Content / Message</span>
              <textarea name="content" rows="5" value={formValues.content} onChange={updateFormField} />
              {fieldErrors.content ? <small role="alert">{fieldErrors.content}</small> : null}
            </label>

            <div className="admin-form-row">
              <label>
                <span>Type</span>
                <select name="type" value={formValues.type} onChange={updateFormField}>
                  {Object.keys(typeLabels).map((type) => <option key={type} value={type}>{typeLabels[type]}</option>)}
                </select>
              </label>
              <label>
                <span>Audience</span>
                <select name="audience" value={formValues.audience} onChange={updateFormField}>
                  {Object.keys(audienceLabels).map((audience) => <option key={audience} value={audience}>{audienceLabels[audience]}</option>)}
                </select>
              </label>
            </div>

            <div className="admin-form-row">
              <label>
                <span>Status</span>
                <select name="status" value={formValues.status} onChange={updateFormField} disabled={selectedAnnouncement?.status === 'archived'}>
                  {Object.keys(statusLabels).map((status) => <option key={status} value={status}>{statusLabels[status]}</option>)}
                </select>
                {fieldErrors.status ? <small role="alert">{fieldErrors.status}</small> : null}
              </label>
              <label>
                <span>Publish At</span>
                <input name="publish_at" type="datetime-local" value={formValues.publish_at} onChange={updateFormField} />
              </label>
            </div>

            <label>
              <span>Expires At</span>
              <input name="expires_at" type="datetime-local" value={formValues.expires_at} onChange={updateFormField} />
              {fieldErrors.expires_at ? <small role="alert">{fieldErrors.expires_at}</small> : null}
            </label>

            <div className="admin-form-actions">
              <button className="primary-button" type="submit" disabled={isSaving || selectedAnnouncement?.status === 'archived'}>
                {isSaving ? 'Saving...' : selectedAnnouncement ? 'Save Changes' : 'Create Announcement'}
              </button>
              <button className="outline-button" type="button" onClick={startCreate}>Clear</button>
            </div>
          </form>
          </aside>
        </>
      ) : null}
    </>
  )
}

function toDateTimeInput(value) {
  if (!value) return ''

  const date = new Date(value)
  const timezoneOffset = date.getTimezoneOffset() * 60000

  return new Date(date.getTime() - timezoneOffset).toISOString().slice(0, 16)
}

export default AdminAnnouncements
