import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  getAdminReservation,
  getAdminReservations,
  recordAdminReservationRefund,
  updateAdminReservationStatus,
} from '../lib/api'
import { CalendarDays, CreditCard, Hotel, UserRound, Users, X } from 'lucide-react'
import { useResortBranding } from '../hooks/useResortBranding'
import { ToastMessage } from '../context/ToastContext'
import { formatReservationDateTime } from '../utils/bookingTime'

const statusLabels = {
  pending: 'Pending',
  expired: 'Expired',
  confirmed: 'Confirmed',
  cancelled: 'Cancelled',
  checked_in: 'Checked In',
  checked_out: 'Checked Out',
}

function formatReservationCheckIn(reservation, bookingSettings) {
  return reservation.accommodation?.type === 'room'
    ? formatReservationDateTime(reservation.check_in_at ?? reservation.check_in, null, null)
    : formatReservationDateTime(reservation.check_in, bookingSettings.house_rules_check_in_time, '2:00 PM')
}

function formatReservationCheckOut(reservation, bookingSettings) {
  return reservation.accommodation?.type === 'room'
    ? formatReservationDateTime(reservation.check_out_at ?? reservation.check_out, null, null)
    : formatReservationDateTime(reservation.check_out, bookingSettings.house_rules_check_out_time, '12:00 PM')
}

function AdminBookings() {
  const { publicSettings } = useResortBranding()
  const bookingSettings = publicSettings?.booking ?? {}
  const [reservations, setReservations] = useState([])
  const [selectedReservation, setSelectedReservation] = useState(null)
  const [meta, setMeta] = useState({ statuses: [], transitions: {} })
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0, per_page: 10 })
  const [filters, setFilters] = useState({
    booking_reference: '',
    guest: '',
    accommodation: '',
    status: '',
    date_from: '',
    date_to: '',
  })
  const [statusForm, setStatusForm] = useState({
    status: '',
    cancellation_reason: '',
  })
  const [isLoading, setIsLoading] = useState(true)
  const [isDetailLoading, setIsDetailLoading] = useState(false)
  const [isUpdatingStatus, setIsUpdatingStatus] = useState(false)
  const [isRecordingRefund, setIsRecordingRefund] = useState(false)
  const [refundReference, setRefundReference] = useState('')
  const [pageMessage, setPageMessage] = useState('')
  const [pageError, setPageError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const [currentPage, setCurrentPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(10)
  const selectedReservationId = selectedReservation?.id
  const reservationsRequestSequence = useRef(0)

  const filterParams = useMemo(
    () => ({
      booking_reference: filters.booking_reference.trim() || undefined,
      guest: filters.guest.trim() || undefined,
      accommodation: filters.accommodation.trim() || undefined,
      status: filters.status || undefined,
      date_from: filters.date_from || undefined,
      date_to: filters.date_to || undefined,
      page: currentPage,
      per_page: rowsPerPage,
    }),
    [currentPage, filters, rowsPerPage],
  )

  const loadReservations = useCallback(async () => {
    const requestId = ++reservationsRequestSequence.current
    setIsLoading(true)
    setPageError('')

    try {
      const result = await getAdminReservations(filterParams)
      if (requestId !== reservationsRequestSequence.current) return
      setReservations(result.reservations)
      setMeta(result.meta)
      setPagination(result.pagination)

      if (selectedReservationId) {
        const refreshed = result.reservations.find((item) => item.id === selectedReservationId)
        setSelectedReservation(refreshed ?? null)
      }
    } catch {
      if (requestId !== reservationsRequestSequence.current) return
      setPageError('Unable to load reservations right now.')
    } finally {
      if (requestId === reservationsRequestSequence.current) setIsLoading(false)
    }
  }, [filterParams, selectedReservationId])

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      loadReservations()
    }, 250)

    return () => {
      window.clearTimeout(timeoutId)
      reservationsRequestSequence.current += 1
    }
  }, [loadReservations])

  useEffect(() => {
    setStatusForm({
      status: selectedReservation?.status ?? '',
      cancellation_reason: '',
    })
    setFieldErrors({})
    setRefundReference('')
  }, [selectedReservation])

  function updateFilter(event) {
    const { name, value } = event.target
    setCurrentPage(1)
    setFilters((current) => ({
      ...current,
      [name]: value,
    }))
  }

  function changeRowsPerPage(event) {
    setRowsPerPage(Number(event.target.value))
    setCurrentPage(1)
  }

  function clearFilters() {
    setCurrentPage(1)
    setFilters({
      booking_reference: '',
      guest: '',
      accommodation: '',
      status: '',
      date_from: '',
      date_to: '',
    })
  }

  function closeReservationDrawer() {
    setSelectedReservation(null)
  }

  async function selectReservation(reservation) {
    setIsDetailLoading(true)
    setPageError('')
    setPageMessage('')

    try {
      const result = await getAdminReservation(reservation.id)
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
    setStatusForm((current) => ({
      ...current,
      [name]: value,
    }))
    setFieldErrors((current) => ({
      ...current,
      [name]: '',
      status: '',
    }))
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
      const result = await updateAdminReservationStatus(selectedReservation.id, payload)
      setSelectedReservation(result.reservation)
      setReservations((current) =>
        current.map((item) => (item.id === result.reservation.id ? result.reservation : item)),
      )
      setPageMessage(result.message)
    } catch (error) {
      const errors = error?.response?.data?.errors

      if (errors) {
        setFieldErrors(
          Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, value[0]])),
        )
      } else {
        setPageError('Unable to update reservation status.')
      }
    } finally {
      setIsUpdatingStatus(false)
    }
  }

  async function handleRefundSubmit(event) {
    event.preventDefault()
    if (!selectedReservation || isRecordingRefund) return
    setIsRecordingRefund(true)
    setPageError('')
    setPageMessage('')
    try {
      const result = await recordAdminReservationRefund(selectedReservation.id, refundReference.trim())
      setSelectedReservation(result.reservation)
      setReservations((current) => current.map((item) => item.id === result.reservation.id ? result.reservation : item))
      setPageMessage(result.message)
    } catch (error) {
      setPageError(error?.response?.data?.message ?? 'Unable to record the refund.')
    } finally {
      setIsRecordingRefund(false)
    }
  }

  function formatCurrency(value) {
    return new Intl.NumberFormat('en-PH', {
      style: 'currency',
      currency: 'PHP',
    }).format(Number(value ?? 0))
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

  const hasActiveFilters = Object.values(filters).some(Boolean)
  const hasAnyReservations = pagination.total > 0
  const pageSize = rowsPerPage
  const totalPages = Math.max(1, pagination.last_page)

  useEffect(() => {
    setCurrentPage(1)
  }, [filters.booking_reference, filters.guest, filters.accommodation, filters.status, filters.date_from, filters.date_to])

  useEffect(() => {
    setCurrentPage((page) => Math.min(page, Math.max(1, pagination.last_page)))
  }, [pagination.last_page])

  return (
    <>
      <ToastMessage message={pageMessage} />
      {pageError ? (
        <div className="admin-inline-alert" role="alert">
          {pageError}
        </div>
      ) : null}

      <section className="admin-management-grid admin-booking-grid admin-booking-management-grid">
        <div className="admin-table-panel admin-booking-table-panel">
          <div className="admin-table-heading admin-booking-toolbar">
            <h2>Reservations ({pagination.total.toLocaleString()})</h2>
            {hasActiveFilters ? (
              <button type="button" onClick={clearFilters}>
                Clear filters
              </button>
            ) : null}
          </div>
          <div className="admin-filter-bar admin-booking-filter-bar">
            <label>
              <span>Reference</span>
              <input
                name="booking_reference"
                value={filters.booking_reference}
                onChange={updateFilter}
                placeholder="DMD-..."
              />
            </label>
            <label>
              <span>Guest</span>
              <input name="guest" value={filters.guest} onChange={updateFilter} />
            </label>
            <label>
              <span>Accommodation</span>
              <input
                name="accommodation"
                value={filters.accommodation}
                onChange={updateFilter}
              />
            </label>
            <label>
              <span>Status</span>
              <select name="status" value={filters.status} onChange={updateFilter}>
                <option value="">All statuses</option>
                {(meta.statuses ?? Object.keys(statusLabels)).map((status) => (
                  <option key={status} value={status}>
                    {statusName(status)}
                  </option>
                ))}
              </select>
            </label>
            <label>
              <span>Check-in From</span>
              <input
                name="date_from"
                type="date"
                value={filters.date_from}
                onChange={updateFilter}
              />
            </label>
            <label>
              <span>Check-in To</span>
              <input name="date_to" type="date" value={filters.date_to} onChange={updateFilter} />
            </label>
          </div>

          <div className="admin-table-scroll admin-booking-table-scroll">
            <table className="admin-data-table admin-booking-table">
              <thead>
                <tr>
                  <th>Reference</th>
                  <th>Guest</th>
                  <th>Accommodation</th>
                  <th>Stay</th>
                  <th>Guests</th>
                  <th>Status</th>
                  <th>Payment</th>
                  <th>Balance</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {isLoading ? (
                  <tr>
                    <td colSpan="9" aria-label="Loading reservations..." />
                  </tr>
                ) : null}
                {!isLoading && reservations.length === 0 ? (
                  <tr>
                    <td colSpan="9">
                      {hasActiveFilters && hasAnyReservations
                        ? 'No reservations match the current filters.'
                        : 'No reservations have been submitted yet.'}
                    </td>
                  </tr>
                ) : null}
                {!isLoading
                  ? reservations.map((reservation) => (
                      <tr key={reservation.id}>
                        <td>
                          <strong title={reservation.booking_reference}>{reservation.booking_reference}</strong>
                        </td>
                        <td>
                          <strong title={reservation.guest?.name ?? 'Guest'}>{reservation.guest?.name ?? 'Guest'}</strong>
                          <small title={reservation.guest?.email ?? ''}>{reservation.guest?.email}</small>
                        </td>
                        <td>
                          <span title={reservation.accommodation?.name ?? 'Accommodation removed'}>{reservation.accommodation?.name ?? 'Accommodation removed'}</span>
                        </td>
                        <td>
                          <div className="admin-booking-stay-cell">
                            <span><small>In</small>{formatReservationCheckIn(reservation, bookingSettings)}</span>
                            <span><small>Out</small>{formatReservationCheckOut(reservation, bookingSettings)}</span>
                          </div>
                        </td>
                        <td>{reservation.guests}</td>
                        <td>
                          <span className={`admin-status-pill status-${reservation.status}`}>
                            {statusName(reservation.status)}
                          </span>
                        </td>
                        <td>
                          <div className="admin-booking-payment-cell">
                            <span><small>Value</small>{formatCurrency(reservation.total_amount)}</span>
                            <span><small>Paid</small>{formatCurrency(reservation.total_paid)}</span>
                          </div>
                        </td>
                        <td className="admin-booking-money-cell">{formatCurrency(reservation.balance_due)}</td>
                        <td>
                          <button className="admin-booking-view-button" type="button" onClick={() => selectReservation(reservation)}>
                            Manage
                          </button>
                        </td>
                      </tr>
                    ))
                  : null}
              </tbody>
            </table>
          </div>
          {!isLoading && pagination.total > 0 ? (
            <div className="admin-booking-pagination" aria-label="Reservation pagination">
              <span>Showing {((pagination.current_page - 1) * (pagination.per_page ?? rowsPerPage)) + 1}–{Math.min(pagination.current_page * (pagination.per_page ?? rowsPerPage), pagination.total)} of {pagination.total} bookings</span>
              <label className="admin-booking-page-size">
                <span>Rows</span>
                <select value={rowsPerPage} onChange={changeRowsPerPage} aria-label="Bookings per page">
                  {[10, 25, 50].map((size) => <option key={size} value={size}>{size}</option>)}
                </select>
              </label>
              <div>
                <button type="button" onClick={() => setCurrentPage((page) => Math.max(1, page - 1))} disabled={currentPage === 1}>Previous</button>
                <div className="admin-booking-page-numbers">
                  {Array.from({ length: totalPages }, (_, index) => index + 1).map((page) => (
                    <button type="button" key={page} className={page === currentPage ? 'is-active' : ''} aria-current={page === currentPage ? 'page' : undefined} onClick={() => setCurrentPage(page)}>
                      {page}
                    </button>
                  ))}
                </div>
                <button type="button" onClick={() => setCurrentPage((page) => Math.min(totalPages, page + 1))} disabled={currentPage === totalPages}>Next</button>
              </div>
            </div>
          ) : null}
        </div>
      </section>

      {selectedReservation ? (
        <>
          <button
            className="admin-booking-drawer-backdrop"
            type="button"
            aria-label="Close booking details"
            onClick={closeReservationDrawer}
          />
          <aside className="admin-form-panel admin-booking-detail-panel admin-booking-drawer" aria-label="Booking details">
            <div className="admin-booking-drawer-header">
              <div>
                <p className="eyebrow">Booking Details</p>
                <div className="admin-booking-drawer-reference">
                  <h2>{selectedReservation.booking_reference}</h2>
                  <span className={`admin-status-pill status-${selectedReservation.status}`}>
                    {statusName(selectedReservation.status)}
                  </span>
                </div>
              </div>
              <button type="button" onClick={closeReservationDrawer} aria-label="Close booking details" title="Close">
                <X size={17} aria-hidden="true" />
              </button>
            </div>

            <div className="admin-booking-drawer-content">
              {isDetailLoading ? <p className="admin-booking-detail-loading" role="status" aria-label="Loading details..." /> : null}

              <section className="admin-booking-detail-section">
                <h3><UserRound size={15} aria-hidden="true" /> Guest information</h3>
                <dl className="admin-detail-list admin-detail-list-guest">
                  <div><dt>Name</dt><dd>{selectedReservation.guest?.name || 'Guest'}</dd></div>
                  {selectedReservation.guest?.email ? <div><dt>Email</dt><dd>{selectedReservation.guest.email}</dd></div> : null}
                  {selectedReservation.guest?.phone ? <div><dt>Phone</dt><dd>{selectedReservation.guest.phone}</dd></div> : null}
                </dl>
              </section>

              <section className="admin-booking-detail-section">
                <h3><Hotel size={15} aria-hidden="true" /> Accommodation</h3>
                <dl className="admin-detail-list admin-detail-list-compact">
                  <div><dt>Stay</dt><dd>{selectedReservation.accommodation?.name ?? 'Accommodation removed'}<small>{selectedReservation.accommodation?.type_label ?? selectedReservation.accommodation?.type}</small></dd></div>
                </dl>
              </section>

              <section className="admin-booking-detail-section">
                <h3><CalendarDays size={15} aria-hidden="true" /> Stay details</h3>
                <div className="admin-booking-detail-stay">
                  <div><span>Check-in</span><strong>{formatReservationCheckIn(selectedReservation, bookingSettings)}</strong></div>
                  <div><span>Check-out</span><strong>{formatReservationCheckOut(selectedReservation, bookingSettings)}</strong></div>
                </div>
                <p className="admin-booking-detail-duration">Duration: {selectedReservation.duration_hours ? `${selectedReservation.duration_hours} hours` : `${selectedReservation.stay_days ?? 1} day${selectedReservation.stay_days === 1 ? '' : 's'}`}</p>
              </section>

              <section className="admin-booking-detail-section">
                <h3><Users size={15} aria-hidden="true" /> Guest breakdown</h3>
                <div className="admin-booking-guest-breakdown">
                  <div><strong>{selectedReservation.guests ?? 0}</strong><span>Total</span></div>
                  <div><strong>{selectedReservation.adults ?? selectedReservation.guests ?? 0}</strong><span>Adults</span></div>
                  <div><strong>{selectedReservation.children ?? 0}</strong><span>Children</span></div>
                  <div><strong>{selectedReservation.infants ?? 0}</strong><span>Infants</span></div>
                </div>
              </section>

              <section className="admin-booking-detail-section admin-booking-payment-summary">
                <h3><CreditCard size={15} aria-hidden="true" /> Payment summary</h3>
                <dl className="admin-detail-list admin-detail-list-payment">
                  <div><dt>Total amount</dt><dd>{formatCurrency(selectedReservation.total_amount)}</dd></div>
                  <div><dt>Amount paid</dt><dd>{formatCurrency(selectedReservation.total_paid)}</dd></div>
                  <div><dt>Balance</dt><dd>{formatCurrency(selectedReservation.balance_due)}</dd></div>
                  <div><dt>Payment status</dt><dd>{formatPaymentState(selectedReservation.payment_state)}</dd></div>
                </dl>
              </section>

              <PaymentHistory payments={selectedReservation.payment_history} />
              <CancellationRefundSummary reservation={selectedReservation} />
            </div>

            <div className="admin-booking-drawer-footer">
              {selectedReservation.status === 'cancelled' && selectedReservation.refund_status === 'pending' ? (
                <form className="admin-status-form" onSubmit={handleRefundSubmit}>
                  <label>
                    <span>Refund reference (optional)</span>
                    <input value={refundReference} onChange={(event) => setRefundReference(event.target.value)} maxLength="120" />
                  </label>
                  <button className="primary-button" type="submit" disabled={isRecordingRefund}>
                    {isRecordingRefund ? 'Recording...' : 'Record Refund'}
                  </button>
                </form>
              ) : null}

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
            </div>
          </aside>
        </>
      ) : null}
    </>
  )
}

export default AdminBookings

function formatPaymentState(value) {
  return String(value ?? 'unpaid').replace(/_/g, ' ').replace(/\b\w/g, (match) => match.toUpperCase())
}

function PaymentHistory({ payments }) {
  const paymentRows = Array.isArray(payments) ? payments : []

  return (
    <section className="staff-payment-history">
      <h3>Payment History</h3>
      {paymentRows.length ? paymentRows.map((payment) => (
        <div className="staff-payment-history-row" key={payment.id}>
          <div><strong>{paymentPurposeLabel(payment.purpose)}</strong><small>{payment.provider === 'manual' ? 'Cash' : `${payment.provider ?? 'Online'}${payment.payment_method ? ` / ${payment.payment_method}` : ''}`}</small></div>
          <div><strong>{formatCurrencyValue(payment.amount, payment.currency)}</strong><small>{formatPaymentState(payment.status)}</small>{payment.recorded_by ? <small>Recorded by: {payment.recorded_by.name}</small> : null}<small>{payment.paid_at ? new Date(payment.paid_at).toLocaleString() : 'Not paid yet'}</small></div>
        </div>
      )) : <p>No payment transactions recorded.</p>}
    </section>
  )
}

function CancellationRefundSummary({ reservation }) {
  if (!reservation.cancellation_requested_at && reservation.status !== 'cancelled') return null

  const money = (value) => formatCurrencyValue(value, 'PHP')
  return (
    <section className="staff-payment-history cancellation-refund-summary">
      <h3>Cancellation &amp; Refund</h3>
      <p><strong>{reservation.refund_eligible ? 'REFUND ELIGIBLE' : 'NON-REFUNDABLE'}</strong></p>
      <small>Cancellation requested: {reservation.cancellation_requested_at ? new Date(reservation.cancellation_requested_at).toLocaleString() : 'Not recorded'}</small>
      <small>Refund deadline: {reservation.cancellation_deadline_at ? new Date(reservation.cancellation_deadline_at).toLocaleString() : 'Not available'}</small>
      <small>Eligible down payment: {money(reservation.eligible_down_payment_amount)}</small>
      <small>Estimated refundable: {money(reservation.estimated_refund_amount)}</small>
      <small>Retained / non-refundable: {money(reservation.estimated_retained_amount)}</small>
      <small>Refund status: {formatPaymentState(reservation.refund_status)}</small>
    </section>
  )
}

function paymentPurposeLabel(value) {
  return { deposit: 'Reservation Deposit', balance: 'Remaining Balance', full: 'Full Payment' }[value] ?? 'Payment'
}

function formatCurrencyValue(value, currency = 'PHP') {
  return `${currency === 'PHP' ? '₱' : currency} ${Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}
