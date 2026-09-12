import { useCallback, useEffect, useMemo, useState } from 'react'
import ManagerLayout from '../components/ManagerLayout'
import { getManagerReports } from '../lib/api'

const labels = {
  total: 'Total',
  pending: 'Pending',
  confirmed: 'Confirmed',
  cancelled: 'Cancelled',
  checked_in: 'Checked In',
  checked_out: 'Checked Out',
  present: 'Present',
  late: 'Late',
  absent: 'Absent',
  incomplete: 'Incomplete',
  assigned: 'Assigned',
  in_progress: 'In Progress',
  completed: 'Completed',
  maintenance_related: 'Maintenance Tasks',
  available: 'Available',
  unavailable: 'Unavailable',
  maintenance: 'Maintenance',
  total_assets: 'Total Assets',
  damaged: 'Damaged',
  retired: 'Retired',
}

const colors = {
  confirmed: '#108b3f',
  pending: '#f2ad16',
  cancelled: '#ef4444',
  checked_in: '#2878d8',
  checked_out: '#9aa3a8',
  present: '#108b3f',
  late: '#f2ad16',
  absent: '#ef4444',
  incomplete: '#2878d8',
  completed: '#108b3f',
  in_progress: '#2878d8',
  assigned: '#47a96c',
}

function ManagerReports() {
  const [reports, setReports] = useState(null)
  const [filters, setFilters] = useState({ preset: 'this_month', start_date: '', end_date: '' })
  const [isLoading, setIsLoading] = useState(true)
  const [pageError, setPageError] = useState('')

  const filterParams = useMemo(
    () => ({
      preset: filters.preset,
      start_date: filters.preset === 'custom' ? filters.start_date : undefined,
      end_date: filters.preset === 'custom' ? filters.end_date : undefined,
    }),
    [filters],
  )

  const loadReports = useCallback(async () => {
    setIsLoading(true)
    setPageError('')

    try {
      setReports(await getManagerReports(filterParams))
    } catch {
      setPageError('Unable to load reports right now.')
    } finally {
      setIsLoading(false)
    }
  }, [filterParams])

  useEffect(() => {
    loadReports()
  }, [loadReports])

  function updateFilter(event) {
    const { name, value } = event.target
    setFilters((current) => ({ ...current, [name]: value }))
  }

  const topCards = [
    ['Total Reservations', reports?.reservations?.summary?.total],
    ['Pending Reservations', reports?.reservations?.summary?.pending],
    ['Occupancy Usage', `${reports?.accommodations?.occupancy_rate ?? 0}%`],
    ['Cleaning Pending', reports?.housekeeping?.summary?.pending],
  ]
  const financial = reports?.financial ?? {}

  return (
    <ManagerLayout eyebrow="Reports" title="Operational Reports">
      <section className="admin-welcome-panel admin-management-hero reports-toolbar">
        <div className="reports-filters">
          <label><span>Range</span><select name="preset" value={filters.preset} onChange={updateFilter}><option value="today">Today</option><option value="this_week">This Week</option><option value="this_month">This Month</option><option value="custom">Custom</option></select></label>
          {filters.preset === 'custom' ? (
            <>
              <label><span>Start</span><input name="start_date" type="date" value={filters.start_date} onChange={updateFilter} /></label>
              <label><span>End</span><input name="end_date" type="date" value={filters.end_date} onChange={updateFilter} /></label>
            </>
          ) : null}
        </div>
      </section>

      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}

      <section className="dashboard-kpi-grid" aria-label="Report summary">
        {topCards.map(([label, value]) => (
          <article className="dashboard-kpi-card" key={label}>
            <div>
              <p>{label}</p>
              <strong>{isLoading ? '...' : value ?? 0}</strong>
              <small>{reports?.period?.label ?? 'Filtered period'}</small>
            </div>
          </article>
        ))}
      </section>

      <section className="dashboard-kpi-grid reports-kpi-grid" aria-label="Financial summary">
        {[
          ['Booking Value', financial.booking_value],
          ['Collected Revenue', financial.collected_revenue],
          ['Outstanding Balance', financial.outstanding_balance],
          ['Fully Paid Bookings', financial.fully_paid_bookings],
          ['Partially Paid Bookings', financial.partially_paid_bookings],
          ['Unpaid Bookings', financial.unpaid_bookings],
        ].map(([label, value]) => (
          <article className="dashboard-kpi-card" key={label}><div><p>{label}</p><strong>{isLoading ? '...' : label.includes('Bookings') ? value ?? 0 : formatMoney(value)}</strong><small>{reports?.period?.label ?? 'Filtered period'}</small></div></article>
        ))}
      </section>

      <section className="reports-grid">
        <GuestMoodCard mood={reports?.guest_mood} />
        <ReportCard title="Collection by Payment Source"><BreakdownList counts={Object.fromEntries((financial.payment_method_breakdown ?? []).map((row) => [row.source, row.amount]))} statuses={(financial.payment_method_breakdown ?? []).map((row) => row.source)} money /></ReportCard>
        <ReportCard title="Payment Transactions"><PaymentTransactions rows={financial.payment_transactions ?? []} /></ReportCard>
        <ReportCard title="Outstanding Balances"><OutstandingBalances rows={financial.outstanding_balances ?? []} /></ReportCard>
        <ReportCard title="Reservation Trend"><MultiLineChart data={reports?.reservations?.trend ?? []} statuses={['confirmed', 'pending', 'expired', 'cancelled']} /></ReportCard>
        <ReportCard title="Reservation Status"><BreakdownList counts={reports?.reservations?.summary} statuses={['pending', 'confirmed', 'expired', 'cancelled', 'checked_in', 'checked_out']} /></ReportCard>
        <ReportCard title="Attendance Trend"><MultiLineChart data={reports?.attendance?.trend ?? []} statuses={['present', 'late', 'absent']} /></ReportCard>
        <ReportCard title="Cleaning Status"><BreakdownList counts={reports?.housekeeping?.summary} statuses={['pending', 'assigned', 'in_progress', 'completed', 'cancelled', 'maintenance_related']} /></ReportCard>
        <ReportCard title="Inventory Assets"><BreakdownList counts={reports?.inventory?.summary} statuses={['total_assets', 'available', 'assigned', 'maintenance', 'damaged', 'retired']} /></ReportCard>
        <ReportCard title="Accommodation Status"><BreakdownList counts={reports?.accommodations} statuses={['available', 'unavailable', 'maintenance']} /></ReportCard>
      </section>

      <section className="dashboard-bottom-grid reports-bottom-grid">
        <ReportCard title="Top Accommodations">
          <table className="dashboard-mini-table">
            <thead><tr><th>Accommodation</th><th>Type</th><th>Reservations</th></tr></thead>
            <tbody>
              {(reports?.reservations?.top_accommodations ?? []).length > 0 ? reports.reservations.top_accommodations.map((accommodation) => (
                <tr key={accommodation.id}><td>{accommodation.name}</td><td>{accommodation.type}</td><td>{accommodation.reservations_count}</td></tr>
              )) : <tr><td colSpan="3">No reservation data in this period.</td></tr>}
            </tbody>
          </table>
        </ReportCard>
        <ReportCard title="Attendance by Role"><RoleTable rows={reports?.attendance?.by_role ?? []} /></ReportCard>
        <ReportCard title="Inventory by Category"><SimpleQuantityTable rows={reports?.inventory?.by_category ?? []} labelKey="category" /></ReportCard>
      </section>
    </ManagerLayout>
  )
}

function ReportCard({ title, children }) {
  return <article className="dashboard-card reports-card"><div className="dashboard-card-header"><h2>{title}</h2></div>{children}</article>
}

function GuestMoodCard({ mood }) {
  const hasData = Number(mood?.response_count ?? 0) > 0
  return <ReportCard title="Guest Mood Score">{hasData ? <><div className="guest-mood-score"><strong>{mood.mood?.emoji} {mood.mood_score}%</strong><span>{mood.mood?.label}</span><small>Based on {mood.response_count} completed-stay responses</small></div><div className="dashboard-status-list reports-breakdown">{(mood.distribution ?? []).map((item) => <div key={item.rating}><span>{item.emoji} {item.label}</span><strong>{item.count}</strong></div>)}</div><div className="guest-mood-comments">{(mood.comments ?? []).map((comment) => <p key={comment.id}><strong>{comment.booking_reference}</strong> — {comment.comment}</p>)}</div></> : <div className="reports-zero-state">No guest feedback yet.</div>}</ReportCard>
}

function formatMoney(value) {
  return `₱ ${Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function PaymentTransactions({ rows }) {
  return rows.length ? <div className="admin-table-scroll"><table className="dashboard-mini-table"><thead><tr><th>Date</th><th>Booking</th><th>Purpose</th><th>Source</th><th>Amount</th><th>Status</th></tr></thead><tbody>{rows.map((row) => <tr key={row.id}><td>{row.payment_date ? new Date(row.payment_date).toLocaleDateString() : '—'}</td><td>{row.booking_reference}</td><td>{String(row.purpose ?? '').replace(/_/g, ' ')}</td><td>{row.source}</td><td>{formatMoney(row.amount)}</td><td>{String(row.status ?? '').replace(/_/g, ' ')}</td></tr>)}</tbody></table></div> : <div className="reports-zero-state">No payment transactions for this range.</div>
}

function OutstandingBalances({ rows }) {
  return rows.length ? <div className="admin-table-scroll"><table className="dashboard-mini-table"><thead><tr><th>Booking</th><th>Customer</th><th>Total</th><th>Paid</th><th>Balance</th></tr></thead><tbody>{rows.map((row) => <tr key={row.booking_reference}><td>{row.booking_reference}</td><td>{row.customer ?? '—'}</td><td>{formatMoney(row.booking_value)}</td><td>{formatMoney(row.amount_paid)}</td><td>{formatMoney(row.balance_due)}</td></tr>)}</tbody></table></div> : <div className="reports-zero-state">No outstanding balances for this range.</div>
}

function BreakdownList({ counts = {}, statuses, money = false }) {
  const total = statuses.reduce((sum, status) => sum + Number(counts?.[status] ?? 0), 0)

  return (
    <div className="dashboard-status-list reports-breakdown">
      {statuses.map((status) => (
        <div key={status}>
          <span><i style={{ backgroundColor: colors[status] ?? '#9aa3a8' }} />{labels[status] ?? status.replaceAll('_', ' ')}</span>
          <strong>{money ? formatMoney(counts?.[status]) : counts?.[status] ?? 0}</strong>
        </div>
      ))}
      {total === 0 ? <p className="reports-empty">No data for this range.</p> : null}
    </div>
  )
}

function RoleTable({ rows }) {
  return (
    <table className="dashboard-mini-table">
      <thead><tr><th>Role</th><th>Present</th><th>Late</th><th>Absent</th></tr></thead>
      <tbody>{rows.map((row) => <tr key={row.role}><td>{row.role.replaceAll('_', ' ')}</td><td>{row.present}</td><td>{row.late}</td><td>{row.absent}</td></tr>)}</tbody>
    </table>
  )
}

function SimpleQuantityTable({ rows, labelKey }) {
  return (
    <table className="dashboard-mini-table">
      <thead><tr><th>Name</th><th>Qty</th></tr></thead>
      <tbody>{rows.length > 0 ? rows.map((row) => <tr key={row[labelKey]}><td>{String(row[labelKey]).replaceAll('_', ' ')}</td><td>{row.quantity}</td></tr>) : <tr><td colSpan="2">No inventory data.</td></tr>}</tbody>
    </table>
  )
}

function MultiLineChart({ data, statuses }) {
  const width = 640
  const height = 180
  const padding = 28
  const maxValue = Math.max(4, ...data.flatMap((point) => statuses.map((status) => point[status] ?? 0)))

  function pointFor(value, index) {
    const x = padding + (index * (width - padding * 2)) / Math.max(data.length - 1, 1)
    const y = height - padding - (value / maxValue) * (height - padding * 2)
    return [x, y]
  }

  if (!data.length) {
    return <p className="reports-empty">No chart data for this range.</p>
  }

  return (
    <svg className="reports-line-chart" viewBox={`0 0 ${width} ${height}`} role="img">
      <title>Report trend</title>
      {[0, 1, 2, 3].map((tick) => {
        const y = padding + (tick * (height - padding * 2)) / 3
        return <line key={tick} x1={padding} x2={width - padding} y1={y} y2={y} className="chart-grid-line" />
      })}
      {statuses.map((status) => {
        const points = data.map((point, index) => pointFor(point[status] ?? 0, index))
        const path = points.map(([x, y], index) => `${index === 0 ? 'M' : 'L'} ${x} ${y}`).join(' ')

        return <path key={status} d={path} fill="none" stroke={colors[status] ?? '#108b3f'} strokeWidth="3" />
      })}
      {data.map((point, index) => {
        const [x] = pointFor(0, index)
        return <text key={point.date} x={x} y={height - 7} textAnchor="middle">{point.label}</text>
      })}
    </svg>
  )
}

export default ManagerReports
