import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { FileSpreadsheet, FileText, Table2 } from 'lucide-react'
import { downloadAdminReportExport, getAdminReports } from '../lib/api'

const reservationStatuses = ['pending', 'confirmed', 'cancelled', 'checked_in', 'checked_out', 'expired']
const housekeepingStatuses = ['pending', 'assigned', 'in_progress', 'completed', 'cancelled']
const readinessStatuses = ['ready', 'needs_cleaning', 'cleaning', 'maintenance']
const statusLabels = { pending: 'Pending', confirmed: 'Confirmed', cancelled: 'Cancelled', checked_in: 'Checked In', checked_out: 'Checked Out', expired: 'Expired', in_progress: 'In Progress', available: 'Available', unavailable: 'Unavailable', maintenance: 'Maintenance', completed: 'Completed', assigned: 'Assigned', ready: 'Ready', needs_cleaning: 'Needs Cleaning', cleaning: 'In Progress' }
const statusColors = { confirmed: '#108b3f', pending: '#d99510', cancelled: '#c94a4a', checked_in: '#2878d8', checked_out: '#87938c', completed: '#108b3f', in_progress: '#2878d8', assigned: '#47a96c' }

function AdminReports() {
  const [reports, setReports] = useState(null)
  const [filters, setFilters] = useState({ preset: 'this_month', start_date: '', end_date: '' })
  const [activeTab, setActiveTab] = useState('overview')
  const [transactionPage, setTransactionPage] = useState(1)
  const [refundPage, setRefundPage] = useState(1)
  const [isLoading, setIsLoading] = useState(true)
  const [pageError, setPageError] = useState('')
  const [isExporting, setIsExporting] = useState(false)
  const [exportMenuOpen, setExportMenuOpen] = useState(false)
  const reportsRequestSequence = useRef(0)
  const filterParams = useMemo(() => ({ preset: filters.preset, start_date: filters.preset === 'custom' ? filters.start_date : undefined, end_date: filters.preset === 'custom' ? filters.end_date : undefined }), [filters])
  const loadReports = useCallback(async () => {
    const requestId = ++reportsRequestSequence.current
    setIsLoading(true); setPageError('')
    try {
      const result = await getAdminReports(filterParams)
      if (requestId !== reportsRequestSequence.current) return
      setReports(result)
    } catch {
      if (requestId !== reportsRequestSequence.current) return
      setPageError('Unable to load reports right now.')
    } finally {
      if (requestId === reportsRequestSequence.current) setIsLoading(false)
    }
  }, [filterParams])
  useEffect(() => {
    loadReports()
    return () => { reportsRequestSequence.current += 1 }
  }, [loadReports])
  useEffect(() => { setTransactionPage(1) }, [filterParams])
  useEffect(() => { setRefundPage(1) }, [filterParams])
  function updateFilter(event) { const { name, value } = event.target; setFilters((current) => ({ ...current, [name]: value })) }
  async function exportReport(format) {
    setExportMenuOpen(false)
    setIsExporting(true)
    setPageError('')
    try { await downloadAdminReportExport(format, filterParams) } catch { setPageError('Unable to generate the report right now. Please try again.') } finally { setIsExporting(false) }
  }

  const financial = reports?.financial ?? {}
  const reservationSummary = reports?.reservations?.summary ?? {}
  const mood = reports?.guest_mood ?? {}
  const hasMood = Number(mood.response_count ?? 0) > 0
  const transactions = financial.payment_transactions ?? []
  const pageSize = 10
  const pageCount = Math.max(1, Math.ceil(transactions.length / pageSize))
  const visibleTransactions = transactions.slice((transactionPage - 1) * pageSize, transactionPage * pageSize)
  const refunds = financial.refund_activity ?? []
  const refundPageSize = 5
  const refundPageCount = Math.max(1, Math.ceil(refunds.length / refundPageSize))
  const visibleRefunds = refunds.slice((refundPage - 1) * refundPageSize, refundPage * refundPageSize)
  const periodLabel = reports?.period?.label ?? 'Selected period'
  const topAccommodations = useMemo(() => (reports?.reservations?.top_accommodations ?? []).filter((row) => Number(row.reservations_count) > 0).slice(0, 5), [reports])
  const bookedNights = Math.round(Number(reports?.accommodations?.booked_nights ?? 0))
  const capacityNights = Math.round(Number(reports?.accommodations?.capacity_nights ?? 0))
  const occupancyDetail = `${bookedNights} booked night${bookedNights === 1 ? '' : 's'}${capacityNights ? ` / ${capacityNights} available accommodation-nights` : ''}`

  return <div className="reports-page" data-active-tab={activeTab}>
    <header className="reports-page-header"><div className="reports-page-heading"><span className="reports-eyebrow">Management dashboard</span><h1>Reports &amp; Analytics</h1><p>Monitor resort performance, financial activity, and guest satisfaction.</p></div><div className="reports-toolbar-actions">
      <label className="reports-period-control"><span>Report period</span><select name="preset" value={filters.preset} onChange={updateFilter}><option value="today">Today</option><option value="this_week">This Week</option><option value="this_month">This Month</option><option value="custom">Custom range</option></select></label>
      {filters.preset === 'custom' ? <><label className="reports-period-control"><span>From</span><input name="start_date" type="date" value={filters.start_date} onChange={updateFilter} /></label><label className="reports-period-control"><span>To</span><input name="end_date" type="date" value={filters.end_date} onChange={updateFilter} /></label></> : null}
      <div className="reports-export-menu"><button className="reports-export-link" type="button" aria-haspopup="menu" aria-expanded={exportMenuOpen} onClick={() => setExportMenuOpen((current) => !current)} disabled={isExporting}>{isExporting ? 'Generating report...' : 'Export ▾'}</button>{exportMenuOpen ? <div className="reports-export-options" role="menu"><button role="menuitem" type="button" onClick={() => exportReport('pdf')}><FileText size={15} /> PDF Report</button><button role="menuitem" type="button" onClick={() => exportReport('excel')}><FileSpreadsheet size={15} /> Excel Workbook</button><button role="menuitem" type="button" onClick={() => exportReport('csv')}><Table2 size={15} /> CSV Data</button></div> : null}</div>
     </div></header>
     {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}
     <nav className="reports-tabs" aria-label="Report sections">
       {[['overview', 'Overview'], ['financial', 'Financial'], ['guest', 'Guest Experience'], ['operations', 'Operations']].map(([value, label]) => <button key={value} className={activeTab === value ? 'is-active' : ''} type="button" aria-current={activeTab === value ? 'page' : undefined} onClick={() => setActiveTab(value)}>{label}</button>)}
     </nav>

    <SectionLabel text="Overview" /><section className="reports-primary-grid" aria-label="Primary management metrics"><MetricCard label="Collected Revenue" value={isLoading ? '...' : formatMoney(financial.collected_revenue)} detail={periodLabel} /><MetricCard label="Total Reservations" value={isLoading ? '...' : reservationSummary.total ?? 0} detail={periodLabel} /><MetricCard label="Occupancy" value={isLoading ? '...' : `${reports?.accommodations?.occupancy_rate ?? 0}%`} detail={occupancyDetail} /><MetricCard label="Guest Mood Score" value={isLoading ? '...' : hasMood ? `${mood.mood?.emoji ?? ''} ${mood.mood_score}%` : '—'} detail={hasMood ? `${mood.mood?.label ?? ''} · ${mood.response_count} reviews` : 'No feedback yet'} /></section>

    <SectionLabel text="Performance" /><section className="reports-section-grid reports-visual-grid reports-grid-top"><ReportCard className="reports-card--chart" title="Revenue Trend" subtitle="Verified collected payments"><RevenueTrendChart data={financial.revenue_trend ?? []} /></ReportCard><ReportCard className="reports-card--compact" title="Reservation Status" subtitle={periodLabel}><ReservationStatusSummary counts={reservationSummary} /></ReportCard></section>
    <SectionLabel text="Management Summary" /><section className="reports-section-grid reports-management-grid reports-grid-top"><ReportCard className="reports-card--compact" title="Financial Summary" subtitle={periodLabel}><FinancialSummary financial={financial} reservationSummary={reservationSummary} /></ReportCard><ReportCard className="reports-card--compact" title="Payment Sources" subtitle="Successful payments only"><BreakdownList rows={(financial.payment_method_breakdown ?? []).map((row) => ({ label: row.source, value: formatMoney(row.amount) }))} /></ReportCard><ReportCard className="reports-card--compact" title="Top Accommodations" subtitle="Reservations with activity"><SimpleTable rows={topAccommodations} /></ReportCard></section>
    <SectionLabel text="Guest Experience" /><section className="reports-section-grid reports-guest-grid reports-grid-top"><GuestMoodCard mood={{ ...mood, comments: [] }} /><ReportCard className="reports-card--compact" title="Reservations by Accommodation Type" subtitle="Reservations created in this period"><BreakdownList rows={(reports?.reservations?.by_type ?? []).filter((row) => Number(row.count) > 0).map((row) => ({ label: humanize(row.type), value: row.count }))} /></ReportCard><RecentComments comments={mood.comments ?? []} /></section>
    <SectionLabel text="Financial Activity" /><section className="reports-financial-stack"><ReportCard title="Payment Transactions" subtitle={`${transactions.length} recent records in this report`}><PaymentTransactions rows={visibleTransactions} />{transactions.length > pageSize ? <Pagination page={transactionPage} pageCount={pageCount} onChange={setTransactionPage} /> : null}</ReportCard><section className="reports-section-grid reports-financial-mini-grid reports-grid-top"><ReportCard className="reports-card--compact" title="Outstanding Balances" subtitle="Active reservations with unpaid balance"><OutstandingBalances rows={financial.outstanding_balances ?? []} /></ReportCard><ReportCard className="reports-card--compact" title="Refund Activity" subtitle="Recorded refunds only"><RefundActivity rows={visibleRefunds} />{refunds.length > refundPageSize ? <Pagination page={refundPage} pageCount={refundPageCount} onChange={setRefundPage} /> : null}</ReportCard><ReportCard className="reports-card--compact" title="Operations Summary" subtitle="Accommodations and inventory"><OperationsSummary reports={reports} /></ReportCard></section></section>
    <SectionLabel text="Operations" /><section className="reports-section-grid reports-secondary-grid reports-grid-top"><ReportCard title="Accommodation Readiness" subtitle="Current cleaning workflow"><BreakdownList rows={readinessStatuses.map((status) => ({ label: statusLabels[status], value: reports?.accommodations?.housekeeping_summary?.[status] ?? 0 }))} /></ReportCard><ReportCard title="Cleaning Tasks" subtitle="Historical task records"><BreakdownList rows={housekeepingStatuses.map((status) => ({ label: statusLabels[status], value: reports?.housekeeping?.summary?.[status] ?? 0 }))} /></ReportCard><ReportCard title="Attendance by Role"><RoleTable rows={reports?.attendance?.by_role ?? []} /></ReportCard></section>
  </div>
}

function MetricCard({ label, value, detail }) { return <article className="reports-metric-card"><span>{label}</span><strong>{value}</strong><small>{detail}</small></article> }
function ReportCard({ title, subtitle, className = '', children }) { return <article className={`reports-card ${className}`.trim()}><header><div><h2>{title}</h2>{subtitle ? <p>{subtitle}</p> : null}</div></header>{children}</article> }
function SectionLabel({ text }) { return <h2 className={`reports-section-label reports-section-label--${text.toLowerCase().replaceAll(' ', '-')}`}>{text}</h2> }
function SummaryRow({ label, value }) { return <div><span>{label}</span><strong>{value}</strong></div> }
function FinancialSummary({ financial, reservationSummary }) {
  return <div className="reports-summary-groups"><div><h3>Financial</h3><div className="reports-summary-list"><SummaryRow label="Booking Value" value={formatMoney(financial.booking_value)} /><SummaryRow label="Collected Revenue" value={formatMoney(financial.collected_revenue)} /><SummaryRow label="Outstanding Balance" value={formatMoney(financial.outstanding_balance)} /><SummaryRow label="Refunds Processed" value={formatMoney(financial.refunds_processed)} /><SummaryRow label="Net Collected Revenue" value={formatMoney(financial.net_collected_revenue)} /></div></div><div><h3>Booking Payments</h3><div className="reports-summary-list"><SummaryRow label="Fully Paid" value={financial.fully_paid_bookings ?? 0} /><SummaryRow label="Partially Paid" value={financial.partially_paid_bookings ?? 0} /><SummaryRow label="Unpaid" value={financial.unpaid_bookings ?? 0} /><SummaryRow label="Pending Reservations" value={reservationSummary.pending ?? 0} /></div></div></div>
}
function OperationsSummary({ reports }) {
  const accommodations = reports?.accommodations ?? {}
  const inventory = reports?.inventory?.summary ?? {}
  return <div className="reports-operations-groups"><div><h3>Accommodations</h3><div className="reports-summary-list"><SummaryRow label="Available" value={accommodations.available ?? 0} /><SummaryRow label="Needs Cleaning" value={accommodations.cleaning_required ?? 0} /><SummaryRow label="Maintenance" value={accommodations.maintenance ?? 0} /></div></div><div><h3>Inventory</h3><div className="reports-summary-list"><SummaryRow label="Total Assets" value={inventory.total_assets ?? 0} /></div></div></div>
}
function BreakdownList({ rows }) {
  if (!rows.length) return <div className="reports-zero-state">No data for this period.</div>
  const max = Math.max(...rows.map((row) => Number(String(row.value).replace(/[^0-9.]/g, '')) || 0), 1)
  return <div className="reports-breakdown-list">{rows.map((row) => <div key={row.label}><div className="reports-breakdown-label"><span>{row.label}</span><strong>{row.value}</strong></div><span className="reports-bar"><i style={{ width: `${Math.min(100, ((Number(String(row.value).replace(/[^0-9.]/g, '')) || 0) / max) * 100)}%` }} /></span></div>)}</div>
}
function GuestMoodCard({ mood }) {
  const hasData = Number(mood?.response_count ?? 0) > 0
  return <ReportCard className={`reports-card--compact ${hasData ? '' : 'reports-card--empty'}`} title="Guest Mood Score" subtitle="Completed-stay feedback">{hasData ? <div className="reports-mood-layout"><div className="reports-mood-score"><strong>{mood.mood?.emoji} {mood.mood_score}%</strong><b>{mood.mood?.label}</b><small>Based on {mood.response_count} responses</small></div><div className="reports-mood-distribution">{(mood.distribution ?? []).map((item) => <div key={item.rating}><span>{item.emoji} {item.label}</span><strong>{item.count}</strong></div>)}</div></div> : <div className="reports-compact-empty-state"><span aria-hidden="true">🙂</span><div><strong>No guest feedback yet</strong><small>Mood analytics will appear after checked-out guests submit feedback.</small></div></div>}{hasData && (mood.comments ?? []).length ? <div className="reports-comments"><h3>Recent internal comments</h3>{mood.comments.map((comment) => <p key={comment.id}><strong>{comment.booking_reference}</strong> — {comment.comment}</p>)}</div> : null}</ReportCard>
}
function RevenueTrendChart({ data }) {
  if (!data.length || data.every((point) => Number(point.amount) === 0)) return <div className="reports-empty-state"><span aria-hidden="true">—</span><div><strong>No collected revenue for this period</strong></div></div>
  const max = Math.max(...data.map((point) => Number(point.amount)), 1)
  const axisSteps = [0.33, 0.66, 1]
  return <div className="reports-chart-shell"><div className="reports-chart-axis" aria-hidden="true">{axisSteps.slice().reverse().map((step) => <span key={step}>{formatCompactMoney(max * step)}</span>)}</div><div className="reports-revenue-chart" role="img" aria-label="Collected revenue trend">{axisSteps.map((step) => <i className="reports-chart-gridline" key={step} style={{ bottom: `${step * 100}%` }} />)}{data.map((point, index) => { const amount = Number(point.amount) || 0; return <div className="reports-revenue-column" key={point.date} title={`${point.label}: ${formatMoney(amount)}`}><span className={amount === 0 ? 'is-zero' : ''} style={{ height: `${amount === 0 ? 0 : (amount / max) * 100}%` }} /><small>{shouldShowDateLabel(data.length, index) ? point.label : ''}</small></div> })}</div></div>
}
function PaymentTransactions({ rows }) { if (!rows.length) return <div className="reports-zero-state">No payment transactions for this period.</div>; return <div className="reports-table-scroll"><table className="reports-table"><thead><tr><th>Date</th><th>Booking</th><th>Purpose</th><th>Source</th><th>Amount</th><th>Status</th></tr></thead><tbody>{rows.map((row) => <tr key={row.id}><td>{row.payment_date ? new Date(row.payment_date).toLocaleDateString() : '—'}</td><td>{row.booking_reference ?? '—'}</td><td>{paymentPurpose(row.purpose)}</td><td>{row.source}</td><td>{formatMoney(row.amount)}</td><td><span className={`reports-badge ${row.status === 'paid' ? 'is-success' : ''}`}>{humanize(row.status)}</span></td></tr>)}</tbody></table></div> }
function OutstandingBalances({ rows }) { if (!rows.length) return <div className="reports-compact-empty-state"><span aria-hidden="true">✓</span><div><strong>No outstanding balances</strong><small>Nothing is due for this period.</small></div></div>; return <div className="reports-table-scroll"><table className="reports-table"><thead><tr><th>Booking</th><th>Guest</th><th>Balance</th></tr></thead><tbody>{rows.map((row) => <tr key={row.booking_reference}><td>{row.booking_reference}</td><td>{row.customer ?? 'Guest'}</td><td>{formatMoney(row.balance_due)}</td></tr>)}</tbody></table></div> }
function RefundActivity({ rows }) { if (!rows.length) return <div className="reports-compact-empty-state"><span aria-hidden="true">✓</span><div><strong>No processed refunds</strong><small>No refund activity for this period.</small></div></div>; return <div className="reports-table-scroll"><table className="reports-table"><thead><tr><th>Date</th><th>Booking</th><th>Refund</th><th>Status</th></tr></thead><tbody>{rows.map((row) => <tr key={`${row.booking_reference}-${row.date}`}><td>{row.date ? new Date(row.date).toLocaleDateString() : '—'}</td><td>{row.booking_reference}</td><td>{formatMoney(row.refund_amount)}</td><td><span className="reports-badge">{humanize(row.status)}</span></td></tr>)}</tbody></table></div> }
function ReservationStatusSummary({ counts = {} }) { const total = reservationStatuses.reduce((sum, status) => sum + Number(counts[status] ?? 0), 0); if (!total) return <div className="reports-zero-state">No reservation data for this period.</div>; return <div><div className="reports-status-bar" aria-label="Reservation status distribution">{reservationStatuses.filter((status) => Number(counts[status] ?? 0) > 0).map((status) => <span key={status} style={{ flexBasis: `${(Number(counts[status]) / total) * 100}%`, background: statusColors[status] ?? '#9aa3a8' }} title={`${statusLabels[status]}: ${counts[status]}`} />)}</div><div className="reports-status-list">{reservationStatuses.map((status) => <div className={Number(counts[status] ?? 0) === 0 ? 'is-zero' : ''} key={status}><span><i style={{ background: statusColors[status] ?? '#9aa3a8' }} />{statusLabels[status]}</span><strong>{counts[status] ?? 0}</strong></div>)}</div></div> }
function SimpleTable({ rows }) { if (!rows.length) return <div className="reports-zero-state">No reservation data for this period.</div>; return <div className="reports-table-scroll"><table className="reports-table"><thead><tr><th>Accommodation</th><th>Type</th><th>Reservations</th></tr></thead><tbody>{rows.map((row) => <tr key={row.id}><td>{row.name}</td><td>{humanize(row.type)}</td><td>{row.reservations_count}</td></tr>)}</tbody></table></div> }
function RoleTable({ rows }) { return <div className="reports-table-scroll"><table className="reports-table"><thead><tr><th>Role</th><th>Present</th><th>Late</th><th>Absent</th></tr></thead><tbody>{rows.length ? rows.map((row) => <tr key={row.role}><td>{humanize(row.role)}</td><td>{row.present}</td><td>{row.late}</td><td>{row.absent}</td></tr>) : <tr><td colSpan="4">No attendance data.</td></tr>}</tbody></table></div> }
function Pagination({ page, pageCount, onChange }) { return <div className="reports-pagination"><button type="button" disabled={page === 1} onClick={() => onChange(page - 1)}>Previous</button><span>Page {page} of {pageCount}</span><button type="button" disabled={page === pageCount} onClick={() => onChange(page + 1)}>Next</button></div> }
function formatMoney(value) { return `₱${Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` }
function formatCompactMoney(value) { return `₱${Math.round(Number(value ?? 0)).toLocaleString()}` }
function humanize(value) { return String(value ?? '').replaceAll('_', ' ').replace(/\b\w/g, (character) => character.toUpperCase()) }
function paymentPurpose(value) { return { deposit: 'Deposit', full: 'Full Payment', balance: 'Balance' }[value] ?? humanize(value) }
function RecentComments({ comments }) {
  const [page, setPage] = useState(1)
  const pageSize = 3
  const pageCount = Math.max(1, Math.ceil(comments.length / pageSize))
  const visibleComments = comments.slice((page - 1) * pageSize, page * pageSize)
  useEffect(() => { setPage(1) }, [comments])
  return <ReportCard className="reports-card--compact" title="Recent Guest Comments" subtitle={`${comments.length} internal comments`}>{comments.length ? <div className="reports-comments reports-comments--standalone">{visibleComments.map((comment) => <p key={comment.id}><strong>{comment.booking_reference}</strong> - {comment.comment}</p>)}{comments.length > pageSize ? <Pagination page={page} pageCount={pageCount} onChange={setPage} /> : null}</div> : <div className="reports-compact-empty-state"><span aria-hidden="true">—</span><div><strong>No guest comments yet</strong><small>Internal comments will appear after guests submit feedback.</small></div></div>}</ReportCard>
}
function shouldShowDateLabel(count, index) { return count <= 10 || index === 0 || index === count - 1 || index % Math.ceil(count / 6) === 0 }

export default AdminReports
