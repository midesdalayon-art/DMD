import { useCallback, useEffect, useMemo, useState } from 'react'
import ManagerLayout from '../components/ManagerLayout'
import { getManagerAttendanceRecords } from '../lib/api'
import PaginationControls from '../components/PaginationControls'

const roleLabels = {
  admin: 'Admin',
  manager: 'Manager',
  front_desk_staff: 'Front Desk',
  housekeeping_staff: 'Legacy Staff',
}

function ManagerAttendance() {
  const [records, setRecords] = useState([])
  const [summary, setSummary] = useState({})
  const [meta, setMeta] = useState({ statuses: [], verification_methods: [], staff_roles: [] })
  const [filters, setFilters] = useState({
    search: '',
    role: '',
    attendance_date: '',
    status: '',
    verification_method: '',
  })
  const [isLoading, setIsLoading] = useState(true)
  const [pageError, setPageError] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  const pageSize = 10

  const filterParams = useMemo(
    () => Object.fromEntries(Object.entries(filters).map(([key, value]) => [key, value || undefined])),
    [filters],
  )

  const loadRecords = useCallback(async () => {
    setIsLoading(true)
    setPageError('')

    try {
      const result = await getManagerAttendanceRecords(filterParams)
      setRecords(result.records)
      setCurrentPage(1)
      setSummary(result.summary)
      setMeta(result.meta)
    } catch {
      setPageError('Unable to load attendance records.')
    } finally {
      setIsLoading(false)
    }
  }, [filterParams])

  useEffect(() => {
    const timeoutId = window.setTimeout(loadRecords, 250)

    return () => window.clearTimeout(timeoutId)
  }, [loadRecords])

  function updateFilter(event) {
    const { name, value } = event.target
    setFilters((current) => ({ ...current, [name]: value }))
  }

  const hasActiveFilters = Object.values(filters).some(Boolean)
  const pageCount = Math.max(1, Math.ceil(records.length / pageSize))
  const visibleRecords = records.slice((currentPage - 1) * pageSize, currentPage * pageSize)

  const cards = [
    ['Present Today', summary.present_today],
    ['Late Today', summary.late_today],
    ['Absent Today', summary.absent_today],
    ['Incomplete', summary.incomplete],
  ]

  return (
    <ManagerLayout eyebrow="Attendance" title="Attendance Monitoring">
      <section className="admin-summary-grid attendance-summary-grid manager-attendance-summary-grid" aria-label="Attendance summary">
        {cards.map(([label, value]) => (
          <article className="admin-summary-card" key={label}>
            <span>{value ?? 0}</span>
            <strong>{label}</strong>
          </article>
        ))}
      </section>
      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}

      <section className="admin-table-panel">
        <div className="admin-filter-bar attendance-filter-bar manager-attendance-filter-bar">
          <label><span>Employee</span><input name="search" value={filters.search} onChange={updateFilter} /></label>
          <label><span>Role</span><select name="role" value={filters.role} onChange={updateFilter}><option value="">All roles</option>{meta.staff_roles.map((role) => <option key={role} value={role}>{roleLabels[role] ?? role}</option>)}</select></label>
          <label><span>Date</span><input name="attendance_date" type="date" value={filters.attendance_date} onChange={updateFilter} /></label>
          <label><span>Status</span><select name="status" value={filters.status} onChange={updateFilter}><option value="">All statuses</option>{meta.statuses.map((status) => <option key={status} value={status}>{status.replaceAll('_', ' ')}</option>)}</select></label>
          <label><span>Verification</span><select name="verification_method" value={filters.verification_method} onChange={updateFilter}><option value="">All methods</option>{meta.verification_methods.map((method) => <option key={method} value={method}>{method}</option>)}</select></label>
        </div>
        <div className="admin-table-scroll">
          <table className="admin-data-table attendance-table manager-attendance-table">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Role</th>
                <th>Date</th>
                <th>Time In</th>
                <th>Time Out</th>
                <th>Verification</th>
                <th>Status</th>
                <th>Remarks</th>
              </tr>
            </thead>
            <tbody>
              {isLoading ? <tr><td colSpan="8" aria-label="Loading attendance..." /></tr> : null}
              {!isLoading && records.length === 0 ? (
                <tr>
                  <td colSpan="8">
                    {hasActiveFilters ? 'No attendance records match the current filters.' : 'No attendance records have been recorded yet.'}
                  </td>
                </tr>
              ) : null}
              {!isLoading ? visibleRecords.map((record) => (
                <tr key={record.id}>
                  <td><strong>{record.staff?.name ?? 'Staff'}</strong><small>{record.staff?.email}</small></td>
                  <td>{roleLabels[record.staff?.role] ?? record.staff?.role}</td>
                  <td>{record.attendance_date}</td>
                  <td>{record.time_in ?? '-'}</td>
                  <td>{record.time_out ?? '-'}</td>
                  <td><span className={`admin-status-pill verification-${record.verification_method}`}>{record.verification_method}</span></td>
                  <td><span className={`admin-status-pill status-${record.status}`}>{record.status}</span></td>
                  <td>{record.remarks ?? '-'}</td>
                </tr>
              )) : null}
            </tbody>
          </table>
        </div>
        {!isLoading && records.length > 0 ? <PaginationControls page={currentPage} pageCount={pageCount} total={records.length} pageSize={pageSize} label="attendance records" onPageChange={setCurrentPage} /> : null}
      </section>
    </ManagerLayout>
  )
}

export default ManagerAttendance
