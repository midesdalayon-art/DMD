import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Activity, Search, Settings, ShieldCheck, UserRound, X } from 'lucide-react'
import { getAdminSystemLog, getAdminSystemLogs } from '../lib/api'

const roleLabels = {
  admin: 'Admin',
  manager: 'Manager',
  front_desk_staff: 'Front Desk',
  housekeeping_staff: 'Legacy Staff',
  guest: 'Guest',
}

const summaryCards = [
  { key: 'activity_today', label: 'Activity Today', icon: Activity, tone: 'activity' },
  { key: 'user_actions', label: 'User Actions', icon: UserRound, tone: 'users' },
  { key: 'security_events', label: 'Security/Auth Events', icon: ShieldCheck, tone: 'security' },
  { key: 'system_changes', label: 'System Changes', icon: Settings, tone: 'changes' },
]

const sensitiveMetadataKey = /(password|secret|token|api.?key|private.?key|authorization|cookie|hash|credential)/i

function safeMetadata(value) {
  if (Array.isArray(value)) return value.map((item) => safeMetadata(item))
  if (!value || typeof value !== 'object') return value

  return Object.fromEntries(Object.entries(value).map(([key, item]) => [
    key,
    sensitiveMetadataKey.test(key) ? '[redacted]' : safeMetadata(item),
  ]))
}

function AdminSystemLogs() {
  const [logs, setLogs] = useState([])
  const [summary, setSummary] = useState(null)
  const [meta, setMeta] = useState({ modules: [], actions: [], roles: [], users: [] })
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [selectedLog, setSelectedLog] = useState(null)
  const [filters, setFilters] = useState({
    search: '',
    user_id: '',
    role: '',
    module: '',
    action: '',
    start_date: '',
    end_date: '',
    page: 1,
  })
  const [isLoading, setIsLoading] = useState(true)
  const [pageError, setPageError] = useState('')
  const [isDrawerOpen, setIsDrawerOpen] = useState(false)
  const logsRequestSequence = useRef(0)

  const filterParams = useMemo(
    () => ({
      search: filters.search.trim() || undefined,
      user_id: filters.user_id || undefined,
      role: filters.role || undefined,
      module: filters.module || undefined,
      action: filters.action || undefined,
      start_date: filters.start_date || undefined,
      end_date: filters.end_date || undefined,
      page: filters.page,
    }),
    [filters],
  )

  const hasActiveFilters = Object.entries(filters).some(([key, value]) => key !== 'page' && String(value).trim() !== '')
  const pageSize = 20

  const loadLogs = useCallback(async () => {
    const requestId = ++logsRequestSequence.current
    setIsLoading(true)
    setPageError('')

    try {
      const result = await getAdminSystemLogs(filterParams)
      if (requestId !== logsRequestSequence.current) return
      setLogs(result.logs)
      setPagination(result.pagination)
      setSummary(result.summary)
      setMeta(result.meta)
    } catch {
      if (requestId !== logsRequestSequence.current) return
      setPageError('Unable to load system logs right now.')
    } finally {
      if (requestId === logsRequestSequence.current) setIsLoading(false)
    }
  }, [filterParams])

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      loadLogs()
    }, 250)

    return () => {
      window.clearTimeout(timeoutId)
      logsRequestSequence.current += 1
    }
  }, [loadLogs])

  function updateFilter(event) {
    const { name, value } = event.target
    setFilters((current) => ({ ...current, [name]: value, page: 1 }))
  }

  async function selectLog(log) {
    setPageError('')

    try {
      setSelectedLog(await getAdminSystemLog(log.id))
      setIsDrawerOpen(true)
    } catch {
      setPageError('Unable to load audit details.')
    }
  }

  function closeDrawer() {
    setIsDrawerOpen(false)
  }

  function clearFilters() {
    setFilters({
      search: '',
      user_id: '',
      role: '',
      module: '',
      action: '',
      start_date: '',
      end_date: '',
      page: 1,
    })
  }

  function changePage(nextPage) {
    setFilters((current) => ({ ...current, page: nextPage }))
  }

  useEffect(() => {
    if (!isDrawerOpen) return undefined

    function handleKeyDown(event) {
      if (event.key === 'Escape') closeDrawer()
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [isDrawerOpen])

  return (
    <>
      <section className="admin-summary-grid system-log-summary-grid" aria-label="System log summary">
        {summaryCards.map(({ key, label, icon: Icon, tone }) => (
          <article className={`admin-summary-card system-log-summary-card is-${tone}`} key={key}>
            <div className="system-log-summary-label"><span className="system-log-summary-icon"><Icon size={17} aria-hidden="true" /></span><span>{label}</span></div>
            <strong>{isLoading ? '...' : (summary?.[key] ?? 0).toLocaleString()}</strong>
          </article>
        ))}
      </section>

      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}

      <section className="admin-management-grid system-log-management-grid">
        <div className="admin-table-panel">
          <div className="admin-table-heading">
            <div>
              <h2>Audit Trail</h2>
              <p>{isLoading ? <span className="sr-only">Loading recorded events...</span> : `${pagination.total.toLocaleString()} recorded events`} <span>· Review meaningful security and system activity.</span></p>
            </div>
            <div className="admin-table-heading-actions">
              {hasActiveFilters ? (
                <button className="outline-button" type="button" onClick={clearFilters}>
                  Clear Filters
                </button>
              ) : null}
            </div>
          </div>

          <div className="admin-filter-bar system-log-filter-bar">
            <label>
              <span>Search</span>
              <div className="system-log-search-input"><Search size={15} aria-hidden="true" /><input name="search" value={filters.search} onChange={updateFilter} placeholder="Action, module, user" /></div>
            </label>
            <label>
              <span>User</span>
              <select name="user_id" value={filters.user_id} onChange={updateFilter}>
                <option value="">All</option>
                {(meta.users ?? []).map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
              </select>
            </label>
            <label>
              <span>Role</span>
              <select name="role" value={filters.role} onChange={updateFilter}>
                <option value="">All</option>
                {(meta.roles ?? []).map((role) => <option key={role} value={role}>{roleLabels[role] ?? role}</option>)}
              </select>
            </label>
            <label>
              <span>Module</span>
              <select name="module" value={filters.module} onChange={updateFilter}>
                <option value="">All</option>
                {(meta.modules ?? []).map((module) => <option key={module} value={module}>{formatLabel(module)}</option>)}
              </select>
            </label>
            <label>
              <span>Action</span>
              <select name="action" value={filters.action} onChange={updateFilter}>
                <option value="">All</option>
                {(meta.actions ?? []).map((action) => <option key={action} value={action}>{formatLabel(action)}</option>)}
              </select>
            </label>
          </div>

          <div className="admin-table-scroll">
            <table className="admin-data-table system-log-table">
              <thead>
                <tr>
                  <th>Date & Time</th>
                  <th>User</th>
                  <th>Role</th>
                  <th>Module</th>
                  <th>Action</th>
                  <th>Details</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {isLoading ? <tr><td colSpan="7" aria-label="Loading system logs..." /></tr> : null}
                {!isLoading && logs.length === 0 ? (
                  <tr>
                    <td colSpan="7">{hasActiveFilters ? 'No system logs match the current filters.' : 'No system activity has been recorded yet.'}</td>
                  </tr>
                ) : null}
                {!isLoading ? logs.map((log) => (
                  <tr key={log.id}>
                    <td className="system-log-date-cell"><strong>{formatDateTime(log.created_at)}</strong><small>{formatRelativeDate(log.created_at)}</small></td>
                    <td className="system-log-user-cell">
                      <strong>{log.actor?.name ?? 'System'}</strong>
                      <small>{log.actor?.email ?? 'No authenticated actor'}</small>
                    </td>
                    <td><span className={`admin-status-pill system-log-role-${log.actor?.role ?? 'system'}`}>{roleLabels[log.actor?.role] ?? log.actor?.role ?? 'System'}</span></td>
                    <td><span className={`admin-status-pill system-log-module-${toClassName(log.module)}`}>{formatLabel(log.module)}</span></td>
                    <td><span className={`admin-status-pill system-log-action-${toClassName(log.action)}`}>{formatLabel(log.action)}</span></td>
                    <td className="system-log-details-cell" title={log.description ?? 'No details'}>{truncateText(log.description ?? 'No details', 84)}</td>
                    <td><button className="system-log-view-button" type="button" onClick={() => selectLog(log)}>View</button></td>
                  </tr>
                )) : null}
              </tbody>
            </table>
          </div>

          <div className="admin-pagination-summary system-log-pagination">
            <span>Showing {pagination.total === 0 ? 0 : ((pagination.current_page - 1) * pageSize) + 1}–{Math.min(pagination.current_page * pageSize, pagination.total)} of {pagination.total}</span>
            <div className="admin-row-actions">
              <button type="button" disabled={pagination.current_page <= 1} onClick={() => changePage(pagination.current_page - 1)}>Previous</button>
              <strong>Page {pagination.current_page} of {pagination.last_page}</strong>
              <button type="button" disabled={pagination.current_page >= pagination.last_page} onClick={() => changePage(pagination.current_page + 1)}>Next</button>
            </div>
          </div>
        </div>
      </section>

      {isDrawerOpen ? (
        <>
          <button
            className="system-log-drawer-backdrop"
            type="button"
            aria-label="Close audit details"
            onClick={closeDrawer}
          />
          <aside className="admin-form-panel system-log-drawer" aria-label="Audit details">
          <div className="admin-embedded-form">
            <div className="system-log-drawer-header">
              <div>
                <p className="eyebrow">Audit Details</p>
                <h2>{selectedLog ? formatLabel(selectedLog.action) : 'System Log'}</h2>
              </div>
              <button type="button" aria-label="Close audit details" onClick={closeDrawer}><X size={17} aria-hidden="true" /></button>
            </div>

            {selectedLog ? (
              <>
                <dl className="admin-detail-list">
                  <div><dt>Date</dt><dd>{formatDateTime(selectedLog.created_at)}</dd></div>
                  <div><dt>User</dt><dd>{selectedLog.actor?.name ?? 'System'}</dd></div>
                  <div><dt>Role</dt><dd>{roleLabels[selectedLog.actor?.role] ?? selectedLog.actor?.role ?? 'System'}</dd></div>
                  <div><dt>Module</dt><dd><span className={`admin-status-pill system-log-module-${toClassName(selectedLog.module)}`}>{formatLabel(selectedLog.module)}</span></dd></div>
                  <div><dt>Action</dt><dd>{formatLabel(selectedLog.action)}</dd></div>
                  <div><dt>Entity</dt><dd>{selectedLog.entity_type ?? 'None'} #{selectedLog.entity_id ?? '-'}</dd></div>
                  <div><dt>IP</dt><dd>{selectedLog.ip_address ?? 'Not captured'}</dd></div>
                  <div><dt>Method</dt><dd>{selectedLog.request_method ?? 'Not captured'}</dd></div>
                </dl>
                <label>
                  <span>Description</span>
                  <textarea readOnly rows="3" value={selectedLog.description ?? ''} />
                </label>
                {Object.keys(safeMetadata(selectedLog.metadata ?? {})).length > 0 ? (
                  <div className="system-log-metadata">
                    <span>Safe metadata</span>
                    <pre>{JSON.stringify(safeMetadata(selectedLog.metadata ?? {}), null, 2)}</pre>
                  </div>
                ) : null}
              </>
            ) : (
              <p className="reports-empty">Choose a log record to inspect before and after values.</p>
            )}
          </div>
          </aside>
        </>
      ) : null}
    </>
  )
}

function formatLabel(value) {
  if (!value) return 'Not set'
  return String(value)
    .replaceAll('_', ' ')
    .replace(/\b\w/g, (character) => character.toUpperCase())
}

function toClassName(value) {
  return String(value ?? 'unknown').toLowerCase().replace(/[^a-z0-9]+/g, '-')
}

function truncateText(value, maxLength) {
  const text = String(value)
  return text.length > maxLength ? `${text.slice(0, maxLength - 1).trimEnd()}…` : text
}

function formatDateTime(value) {
  if (!value) return 'Not set'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return 'Date unavailable'
  return new Intl.DateTimeFormat('en-PH', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(date)
}

function formatRelativeDate(value) {
  if (!value) return 'Date unavailable'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return 'Date unavailable'
  return date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
}

export default AdminSystemLogs
