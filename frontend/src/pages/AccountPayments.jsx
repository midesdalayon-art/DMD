import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { Banknote, Globe2, WalletCards } from 'lucide-react'
import EmptyState from '../components/EmptyState'
import LoadingState from '../components/LoadingState'
import { getPaymentHistory } from '../lib/api'
import CustomerAccountLayout from './CustomerAccountLayout'

function AccountPayments() {
  const [payments, setPayments] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [errorMessage, setErrorMessage] = useState('')
  const [searchTerm, setSearchTerm] = useState('')
  const [methodFilter, setMethodFilter] = useState('all')
  const [statusFilter, setStatusFilter] = useState('all')
  const [currentPage, setCurrentPage] = useState(1)
  const isMountedRef = useRef(false)
  const requestIdRef = useRef(0)
  const loadPayments = useCallback(async (force = false) => {
    const requestId = ++requestIdRef.current
    setIsLoading(true)
    setErrorMessage('')

    try {
      const data = await getPaymentHistory({ force })
      if (isMountedRef.current && requestId === requestIdRef.current) {
        setPayments(Array.isArray(data) ? data : [])
      }
    } catch {
      if (isMountedRef.current && requestId === requestIdRef.current) {
        setErrorMessage('Unable to load payment history right now. Please try again.')
      }
    } finally {
      if (isMountedRef.current && requestId === requestIdRef.current) setIsLoading(false)
    }
  }, [])

  useEffect(() => {
    isMountedRef.current = true
    loadPayments()

    return () => {
      isMountedRef.current = false
      requestIdRef.current += 1
    }
  }, [loadPayments])

  const paymentRows = useMemo(() => payments, [payments])
  const paidPayments = useMemo(() => paymentRows.filter((payment) => payment.status === 'paid'), [paymentRows])
  const paymentSummary = useMemo(() => ({
    totalPaid: paidPayments.reduce((total, payment) => total + paymentAmount(payment), 0),
    online: paidPayments.filter((payment) => payment.provider === 'paymongo').reduce((total, payment) => total + paymentAmount(payment), 0),
    cash: paidPayments.filter((payment) => payment.provider === 'manual' && payment.payment_method === 'cash').reduce((total, payment) => total + paymentAmount(payment), 0),
  }), [paidPayments])
  const filteredPayments = useMemo(() => {
    const query = searchTerm.trim().toLowerCase()

    return paymentRows.filter((payment) => {
      const matchesSearch = !query
        || String(payment.booking_reference || '').toLowerCase().includes(query)
        || paymentPurposeLabel(payment.purpose).toLowerCase().includes(query)
      const matchesMethod = methodFilter === 'all'
        || (methodFilter === 'online' && payment.provider === 'paymongo')
        || (methodFilter === 'cash' && payment.provider === 'manual' && payment.payment_method === 'cash')
      const matchesStatus = statusFilter === 'all' || payment.status === statusFilter

      return matchesSearch && matchesMethod && matchesStatus
    })
  }, [methodFilter, paymentRows, searchTerm, statusFilter])
  const pageSize = 5
  const totalPages = Math.max(1, Math.ceil(filteredPayments.length / pageSize))
  const visiblePayments = filteredPayments.slice((currentPage - 1) * pageSize, currentPage * pageSize)
  const pageStart = filteredPayments.length === 0 ? 0 : (currentPage - 1) * pageSize + 1
  const pageEnd = Math.min(currentPage * pageSize, filteredPayments.length)

  useEffect(() => {
    setCurrentPage(1)
  }, [methodFilter, searchTerm, statusFilter])

  useEffect(() => {
    if (currentPage > totalPages) setCurrentPage(totalPages)
  }, [currentPage, totalPages])

  return (
    <CustomerAccountLayout title="Payments">
      {isLoading ? <LoadingState message="Loading payments..." /> : null}
      {errorMessage ? (
        <EmptyState title="Unable to load payments" message={errorMessage}>
          <button className="solid-button" type="button" onClick={() => loadPayments(true)} disabled={isLoading}>
            Try Again
          </button>
        </EmptyState>
      ) : null}
      {!isLoading && !errorMessage && paymentRows.length === 0 ? (
        <div className="account-empty-state">
          <p className="eyebrow">No Payments Yet</p>
          <h2>Your payment history will appear here</h2>
          <p>Payment records will show up after you place a reservation and complete checkout.</p>
          <Link className="solid-button" to="/rooms">Browse Accommodations</Link>
        </div>
      ) : null}
      {paymentRows.length > 0 ? (
        <>
          <section className="payments-summary" aria-label="Payment summary">
            <article className="payments-summary-card"><span className="payments-summary-icon"><WalletCards size={16} aria-hidden="true" /></span><div><span>Total Paid</span><strong>{formatCurrency(paymentSummary.totalPaid, 'PHP')}</strong><small>Across {paidPayments.length} paid transactions</small></div></article>
            <article className="payments-summary-card"><span className="payments-summary-icon"><Globe2 size={16} aria-hidden="true" /></span><div><span>Online Payments</span><strong>{formatCurrency(paymentSummary.online, 'PHP')}</strong><small>PayMongo transactions</small></div></article>
            <article className="payments-summary-card"><span className="payments-summary-icon"><Banknote size={16} aria-hidden="true" /></span><div><span>Cash Payments</span><strong>{formatCurrency(paymentSummary.cash, 'PHP')}</strong><small>Front Desk transactions</small></div></article>
          </section>
          <section className="payments-history-card" aria-labelledby="payment-history-heading">
            <div className="payments-history-heading"><h2 id="payment-history-heading">Payment History</h2><span>{filteredPayments.length} of {paymentRows.length} transactions</span></div>
            <div className="payments-toolbar" aria-label="Payment history filters">
              <label className="payments-search"><span className="sr-only">Search booking or payment</span><input type="search" value={searchTerm} onChange={(event) => setSearchTerm(event.target.value)} placeholder="Search booking or payment..." /></label>
              <label className="payments-select"><span className="sr-only">Filter by payment method</span><select value={methodFilter} onChange={(event) => setMethodFilter(event.target.value)}><option value="all">All methods</option><option value="online">PayMongo / Online</option><option value="cash">Cash / Front Desk</option></select></label>
              <label className="payments-select"><span className="sr-only">Filter by payment status</span><select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}><option value="all">All statuses</option><option value="paid">Paid</option><option value="pending">Pending</option><option value="failed">Failed</option><option value="cancelled">Cancelled</option></select></label>
            </div>
            <div className="payments-table-header" role="row"><span>Payment</span><span>Booking</span><span>Method</span><span>Date</span><span>Amount</span><span>Status</span><span>Action</span></div>
            <div className="payment-list">
              {visiblePayments.length > 0 ? visiblePayments.map((payment) => (
                <article className="payment-card" key={payment.id}>
                  <h3>{paymentPurposeLabel(payment.purpose)}</h3>
                    <span className="payment-booking-reference" title={payment.booking_reference || ''}>{payment.booking_reference || 'Unavailable'}</span>
                    <span className="payment-source">{paymentSourceLabel(payment)}</span>
                    <small className="payment-date">{formatPaymentDate(payment)}</small>
                    <strong className="payment-amount">{formatCurrency(payment.amount, payment.currency)}</strong>
                    <span className={`payment-status-badge status-${payment.status || 'pending'}`}>{formatStatus(payment.status)}</span>
                    <Link className="text-link inline-link" to={`/account/bookings/${payment.reservation_id}`}>View Booking <span aria-hidden="true">→</span></Link>
                </article>
              )) : <div className="payments-filter-empty">No payments match your search or filters.</div>}
            </div>
            <div className="payments-pagination"><span>Showing {pageStart}–{pageEnd} of {filteredPayments.length}</span><div className="payments-pagination-controls"><button type="button" onClick={() => setCurrentPage((page) => page - 1)} disabled={currentPage === 1}>Previous</button>{Array.from({ length: totalPages }, (_, index) => index + 1).map((page) => <button className={page === currentPage ? 'is-active' : ''} type="button" key={page} onClick={() => setCurrentPage(page)} aria-current={page === currentPage ? 'page' : undefined}>{page}</button>)}<button type="button" onClick={() => setCurrentPage((page) => page + 1)} disabled={currentPage === totalPages}>Next</button></div></div>
          </section>
        </>
      ) : null}
    </CustomerAccountLayout>
  )
}

export default AccountPayments

function paymentPurposeLabel(value) {
  return { deposit: 'Reservation Deposit', balance: 'Remaining Balance', full: 'Full Payment' }[value] ?? 'Payment'
}

function paymentAmount(payment) {
  const amount = Number(payment.amount)
  return Number.isFinite(amount) ? amount : 0
}

function paymentSourceLabel(payment) {
  if (payment.provider === 'paymongo') return ['PayMongo', 'Online'].join(' / ')
  if (payment.provider === 'manual' && payment.payment_method === 'cash') return ['Cash', 'Front Desk'].join(' / ')
  if (payment.provider === 'paymongo') return 'PayMongo • Online'
  if (payment.provider === 'manual' && payment.payment_method === 'cash') return 'Cash • Front Desk'
  if (payment.provider === 'manual') return 'Manual payment'
  return payment.provider ? formatStatus(payment.provider) : 'Payment source unavailable'
}

function formatPaymentDate(payment) {
  const value = payment.paid_at || payment.created_at
  if (!value) return 'Date unavailable'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return 'Date unavailable'
  return date.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })
}

function formatCurrency(amount, currency = 'PHP') {
  return `${currency === 'PHP' ? String.fromCharCode(8369) : currency} ${Number(amount ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
  return `${currency === 'PHP' ? '₱' : currency} ${Number(amount ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function formatStatus(value) {
  if (!value) return 'Pending'
  return String(value).replace(/_/g, ' ').replace(/\b\w/g, (match) => match.toUpperCase())
}
