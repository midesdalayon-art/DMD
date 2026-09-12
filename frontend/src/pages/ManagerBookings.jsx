import { useCallback, useEffect, useMemo, useState } from 'react'
import ManagerLayout from '../components/ManagerLayout'
import { ToastMessage } from '../context/ToastContext'
import {
  getManagerReservation,
  getManagerReservations,
  updateManagerReservationStatus,
} from '../lib/api'

const statusLabels = {
  pending: 'Pending',
  expired: 'Expired',
  confirmed: 'Confirmed',
  cancelled: 'Cancelled',
  checked_in: 'Checked In',
  checked_out: 'Checked Out',
}

function guestBreakdownText(reservation) {
  return `Adults: ${reservation.adults ?? reservation.guests}, Children: ${
    reservation.children ?? 0
  }, Infants: ${reservation.infants ?? 0}`
}

function ManagerBookings() {
  const [reservations, setReservations] = useState([])
  const [selectedReservation, setSelectedReservation] = useState(null)
  const [meta, setMeta] = useState({ statuses: [], transitions: {} })
  const [filters, setFilters] = useState({
    search: '',
    status: '',
    date_from: '',
    date_to: '',
  })
  const [filterDraft, setFilterDraft] = useState(filters)
  const [page, setPage] = useState(1)
  const [statusForm, setStatusForm] = useState({ status: '', cancellation_reason: '' })
  const [isLoading, setIsLoading] = useState(true)
  const [isDetailLoading, setIsDetailLoading] = useState(false)
  const [isUpdatingStatus, setIsUpdatingStatus] = useState(false)
  const [pageMessage, setPageMessage] = useState('')
  const [pageError, setPageError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const selectedReservationId = selectedReservation?.id

  const filterParams = useMemo(
    () => ({
      search: filters.search.trim() || undefined,
      status: filters.status || undefined,
      date_from: filters.date_from || undefined,
      date_to: filters.date_to || undefined,
      page,
    }),
    [filters, page],
  )

  const loadReservations = useCallback(async () => {
    setIsLoading(true)
    setPageError('')

    try {
      const result = await getManagerReservations(filterParams)
      setReservations(result.reservations)
      setMeta(result.meta)

      if (selectedReservationId) {
        const refreshed = result.reservations.find((item) => item.id === selectedReservationId)
        setSelectedReservation(refreshed ?? null)
      }
    } catch {
      setPageError('Unable to load reservations right now.')
    } finally {
      setIsLoading(false)
    }
  }, [filterParams, selectedReservationId])

  useEffect(() => {
    const timeoutId = window.setTimeout(loadReservations, 250)

    return () => window.clearTimeout(timeoutId)
  }, [loadReservations])

  useEffect(() => {
    setStatusForm({
      status: selectedReservation?.status ?? '',
      cancellation_reason: '',
    })
    setFieldErrors({})
  }, [selectedReservation])

  function updateFilter(event) {
    const { name, value } = event.target
    setFilterDraft((current) => ({ ...current, [name]: value }))
  }

  function applyFilters(event) {
    event.preventDefault()
    setPage(1)
    setFilters(filterDraft)
  }

  function resetFilters() {
    const emptyFilters = { search: '', status: '', date_from: '', date_to: '' }
    setFilterDraft(emptyFilters)
    setFilters(emptyFilters)
    setPage(1)
  }

  async function selectReservation(reservation) {
    setIsDetailLoading(true)
    setPageError('')
    setPageMessage('')

    try {
      const result = await getManagerReservation(reservation.id)
      setSelectedReservation(result.reservation)
      setMeta(result.meta)
    } catch {
      setPageError('Unable to load booking details.')
    } finally {
      setIsDetailLoading(false)
    }
  }

  function updateStatusField(event) {
    const { name, value } = event.target
    setStatusForm((current) => ({ ...current, [name]: value }))
    setFieldErrors((current) => ({ ...current, [name]: '', status: '' }))
    setPageError('')
    setPageMessage('')
  }

  async function handleStatusSubmit(event) {
    event.preventDefault()

    if (!selectedReservation) {
      return
    }

    setIsUpdatingStatus(true)
    setFieldErrors({})
    setPageError('')
    setPageMessage('')

    const payload = {
      status: statusForm.status,
      ...(statusForm.status === 'cancelled'
        ? { cancellation_reason: statusForm.cancellation_reason.trim() }
        : {}),
    }

    try {
      const result = await updateManagerReservationStatus(selectedReservation.id, payload)
      setSelectedReservation(result.reservation)
      setReservations((current) =>
        current.map((item) => (item.id === result.reservation.id ? result.reservation : item)),
      )
      setPageMessage(result.message)
    } catch (error) {
      const errors = error?.response?.data?.errors

      if (errors) {
        setFieldErrors(Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, value[0]])))
      } else {
        setPageError('Unable to update reservation status.')
      }
    } finally {
      setIsUpdatingStatus(false)
    }
  }

  function formatCurrency(value) {
    return new Intl.NumberFormat('en-PH', {
      style: 'currency',
      currency: 'PHP',
    }).format(Number(value ?? 0))
  }

  function formatDate(value) {
    if (!value) {
      return 'Not set'
    }

    if (typeof value === 'string' && value.includes('T')) {
      const dateTime = new Date(value)

      if (!Number.isNaN(dateTime.getTime())) {
        return new Intl.DateTimeFormat('en-PH', {
          year: 'numeric',
          month: 'short',
          day: 'numeric',
          hour: 'numeric',
          minute: '2-digit',
        }).format(dateTime)
      }
    }

    return new Intl.DateTimeFormat('en-PH', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    }).format(new Date(value))
  }

  function statusName(status) {
    return statusLabels[status] ?? status
  }

  function possibleStatuses() {
    if (!selectedReservation) {
      return []
    }

    return [selectedReservation.status, ...(selectedReservation.allowed_statuses ?? [])]
  }

  return (
    <ManagerLayout eyebrow="Bookings" title="Booking Operations">
      <ToastMessage message={pageMessage} />
      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}

      <section className="admin-management-grid admin-booking-grid manager-booking-grid">
        <div className="admin-table-panel">
          <form className="admin-filter-bar admin-booking-filter-bar manager-booking-filter-form" onSubmit={applyFilters}>
            <label>
              <span>Search bookings</span>
              <input name="search" value={filterDraft.search} onChange={updateFilter} placeholder="Reference, guest name, email, or accommodation..." />
            </label>
            <label>
              <span>Status</span>
              <select name="status" value={filterDraft.status} onChange={updateFilter}>
                <option value="">All statuses</option>
                {(meta.statuses ?? Object.keys(statusLabels)).map((status) => (
                  <option key={status} value={status}>{statusName(status)}</option>
                ))}
              </select>
            </label>
            <label>
              <span>Check-in From</span>
              <input name="date_from" type="date" value={filterDraft.date_from} onChange={updateFilter} />
            </label>
            <label>
              <span>Check-in To</span>
              <input name="date_to" type="date" value={filterDraft.date_to} onChange={updateFilter} />
            </label>
            <div className="manager-booking-filter-actions">
              <button className="manager-booking-reset" type="button" onClick={resetFilters}>Reset</button>
              <button className="primary-button" type="submit">Apply</button>
            </div>
          </form>

          <div className="manager-booking-results-heading">
            <strong>Reservations</strong>
            <span>{meta.pagination?.total ?? 0} booking{meta.pagination?.total === 1 ? '' : 's'}</span>
          </div>

          <div className="admin-table-scroll">
            <table className="admin-data-table admin-booking-table">
              <thead>
                <tr>
                  <th>Reference</th>
                  <th>Guest</th>
                  <th>Accommodation</th>
                  <th>Check-in</th>
                  <th>Check-out</th>
                  <th>Guests</th>
                  <th>Total</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                {isLoading ? <tr><td colSpan="9" aria-label="Loading reservations..." /></tr> : null}
                {!isLoading && reservations.length === 0 ? <tr><td colSpan="9"><div className="manager-booking-empty"><strong>No bookings found</strong><span>Try adjusting your search or filters.</span></div></td></tr> : null}
                {!isLoading ? reservations.map((reservation) => (
                  <tr key={reservation.id}>
                    <td><strong>{reservation.booking_reference}</strong></td>
                    <td><strong>{reservation.guest?.name ?? 'Guest'}</strong><small>{reservation.guest?.email}</small></td>
                    <td>{reservation.accommodation?.name ?? 'Accommodation removed'}</td>
                    <td>{formatDate(reservation.check_in_at ?? reservation.check_in)}</td>
                    <td>{formatDate(reservation.check_out_at ?? reservation.check_out)}</td>
                    <td>{reservation.guests}</td>
                    <td>{formatCurrency(reservation.total_amount)}</td>
                    <td><span className={`admin-status-pill status-${reservation.status}`}>{statusName(reservation.status)}</span></td>
                    <td><button className="manager-booking-view-button" type="button" onClick={() => selectReservation(reservation)}>View / Manage</button></td>
                  </tr>
                )) : null}
              </tbody>
            </table>
            {!isLoading && reservations.length > 0 ? (
              <Pagination meta={meta.pagination} page={page} onPageChange={setPage} />
            ) : null}
          </div>

          <div className="manager-booking-mobile-list">
            {isLoading ? <div className="manager-booking-mobile-loading" aria-label="Loading reservations" /> : null}
            {!isLoading && reservations.length === 0 ? <div className="manager-booking-empty"><strong>No bookings found</strong><span>Try adjusting your search or filters.</span></div> : null}
            {!isLoading ? reservations.map((reservation) => (
              <article className="manager-booking-mobile-card" key={reservation.id}>
                <div className="manager-booking-mobile-card-header"><strong>{reservation.booking_reference}</strong><span className={`admin-status-pill status-${reservation.status}`}>{statusName(reservation.status)}</span></div>
                <dl>
                  <div><dt>Guest</dt><dd>{reservation.guest?.name ?? 'Guest'}<small>{reservation.guest?.email}</small></dd></div>
                  <div><dt>Accommodation</dt><dd>{reservation.accommodation?.name ?? 'Accommodation removed'}</dd></div>
                  <div><dt>Check-in</dt><dd>{formatDate(reservation.check_in_at ?? reservation.check_in)}</dd></div>
                  <div><dt>Total</dt><dd>{formatCurrency(reservation.total_amount)}</dd></div>
                </dl>
                <button className="manager-booking-view-button" type="button" onClick={() => selectReservation(reservation)}>View / Manage</button>
              </article>
            )) : null}
            {!isLoading && reservations.length > 0 ? <Pagination meta={meta.pagination} page={page} onPageChange={setPage} /> : null}
          </div>
        </div>
      </section>

      {selectedReservation ? (
        <>
          <button
            className="manager-booking-drawer-backdrop"
            type="button"
            aria-label="Close booking details"
            onClick={() => setSelectedReservation(null)}
          />
          <aside
            className="admin-form-panel admin-booking-detail-panel manager-booking-drawer"
            aria-label="Booking details"
          >
            <div className="manager-booking-drawer-header">
              <div>
                <p className="eyebrow">Booking Details</p>
                <h2>{selectedReservation.booking_reference}</h2>
              </div>
              <button type="button" onClick={() => setSelectedReservation(null)} aria-label="Close details">
                X
              </button>
            </div>
            {isDetailLoading ? <p className="sr-only" role="status">Loading details...</p> : null}
            <dl className="admin-detail-list">
              <div><dt>Guest</dt><dd>{selectedReservation.guest?.name ?? 'Guest'}<small>{selectedReservation.guest?.email}</small></dd></div>
              <div><dt>Accommodation</dt><dd>{selectedReservation.accommodation?.name ?? 'Accommodation removed'}</dd></div>
              <div><dt>Check-in</dt><dd>{formatDate(selectedReservation.check_in_at ?? selectedReservation.check_in)}</dd></div>
              <div><dt>Check-out</dt><dd>{formatDate(selectedReservation.check_out_at ?? selectedReservation.check_out)}</dd></div>
              <div><dt>Guests</dt><dd>{selectedReservation.guests}<small>{guestBreakdownText(selectedReservation)}</small></dd></div>
              <div><dt>Total Amount</dt><dd>{formatCurrency(selectedReservation.total_amount)}</dd></div>
              <div><dt>Amount Paid</dt><dd>{formatCurrency(selectedReservation.total_paid)}</dd></div>
              <div><dt>Remaining Balance</dt><dd>{formatCurrency(selectedReservation.balance_due)}</dd></div>
              <div><dt>Payment Status</dt><dd>{formatPaymentState(selectedReservation.payment_state)}</dd></div>
              <div><dt>Reservation Status</dt><dd>{statusName(selectedReservation.status)}</dd></div>
            </dl>

            <PaymentHistory payments={selectedReservation.payment_history} />

            <form className="admin-status-form" onSubmit={handleStatusSubmit} noValidate>
              <label>
                <span>Reservation status</span>
                <select name="status" value={statusForm.status} onChange={updateStatusField} disabled={possibleStatuses().length <= 1}>
                  {possibleStatuses().map((status) => <option key={status} value={status}>{statusName(status)}</option>)}
                </select>
                {fieldErrors.status ? <small role="alert">{fieldErrors.status}</small> : null}
              </label>
              {statusForm.status === 'cancelled' ? (
                <label>
                  <span>Cancellation reason</span>
                  <textarea name="cancellation_reason" rows="4" value={statusForm.cancellation_reason} onChange={updateStatusField} />
                  {fieldErrors.cancellation_reason ? <small role="alert">{fieldErrors.cancellation_reason}</small> : null}
                </label>
              ) : null}
              <button className="primary-button" type="submit" disabled={isUpdatingStatus || !selectedReservation || statusForm.status === selectedReservation.status}>
                {isUpdatingStatus ? 'Updating...' : 'Update Status'}
              </button>
            </form>
          </aside>
        </>
      ) : null}
    </ManagerLayout>
  )
}

export default ManagerBookings

function Pagination({ meta, page, onPageChange }) {
  const lastPage = Number(meta?.last_page ?? 1)
  const from = Number(meta?.from ?? 0)
  const to = Number(meta?.to ?? 0)
  const total = Number(meta?.total ?? 0)

  if (lastPage <= 1) {
    return <div className="manager-booking-pagination-summary">Showing {from || 0}–{to || 0} of {total} bookings</div>
  }

  return (
    <div className="manager-booking-pagination">
      <span>Showing {from}–{to} of {total} bookings</span>
      <div>
        <button type="button" disabled={page <= 1} onClick={() => onPageChange(page - 1)}>Previous</button>
        <strong>Page {page} of {lastPage}</strong>
        <button type="button" disabled={page >= lastPage} onClick={() => onPageChange(page + 1)}>Next</button>
      </div>
    </div>
  )
}

function formatPaymentState(value) {
  return String(value ?? 'unpaid').replace(/_/g, ' ').replace(/\b\w/g, (match) => match.toUpperCase())
}

function PaymentHistory({ payments = [] }) {
  return (
    <section className="staff-payment-history">
      <h3>Payment History</h3>
      {payments.length ? payments.map((payment) => (
        <div className="staff-payment-history-row" key={payment.id}>
          <div><strong>{paymentPurposeLabel(payment.purpose)}</strong><small>{payment.provider === 'manual' ? 'Cash' : `${payment.provider ?? 'Online'}${payment.payment_method ? ` / ${payment.payment_method}` : ''}`}</small></div>
          <div><strong>{formatCurrencyValue(payment.amount, payment.currency)}</strong><small>{formatPaymentState(payment.status)}</small>{payment.recorded_by ? <small>Recorded by: {payment.recorded_by.name}</small> : null}<small>{payment.paid_at ? new Date(payment.paid_at).toLocaleString() : 'Not paid yet'}</small></div>
        </div>
      )) : <p>No payment transactions recorded.</p>}
    </section>
  )
}

function paymentPurposeLabel(value) {
  return { deposit: 'Reservation Deposit', balance: 'Remaining Balance', full: 'Full Payment' }[value] ?? 'Payment'
}

function formatCurrencyValue(value, currency = 'PHP') {
  return `${currency === 'PHP' ? '₱' : currency} ${Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}
