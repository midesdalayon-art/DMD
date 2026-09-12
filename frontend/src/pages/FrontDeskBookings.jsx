import { useCallback, useEffect, useMemo, useState } from 'react'
import { CalendarDays } from 'lucide-react'
import FrontDeskLayout from '../components/FrontDeskLayout'
import PaginationControls from '../components/PaginationControls'
import { ToastMessage } from '../context/ToastContext'
import {
  getFrontDeskReservation,
  getFrontDeskReservations,
  recordFrontDeskReservationPayment,
  updateFrontDeskReservationStatus,
} from '../lib/api'

const statusLabels = {
  pending: 'Pending',
  expired: 'Expired',
  confirmed: 'Confirmed',
  cancelled: 'Cancelled',
  checked_in: 'Checked In',
  completed: 'Completed',
  checked_out: 'Checked Out',
}

const statusTone = {
  pending: 'amber',
  confirmed: 'green',
  cancelled: 'muted',
  checked_in: 'blue',
  completed: 'slate',
  checked_out: 'slate',
}

const paymentTone = {
  paid: 'green',
  pending: 'amber',
  unpaid: 'muted',
  partial: 'slate',
  partially_paid: 'amber',
  fully_paid: 'green',
}

function guestBreakdownText(reservation) {
  return `Adults: ${reservation.adults ?? reservation.guests}, Children: ${reservation.children ?? 0}, Infants: ${reservation.infants ?? 0}`
}

function formatCurrency(value) {
  const numericValue = Number(value)

  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
  }).format(Number.isFinite(numericValue) ? numericValue : 0)
}

function FrontDeskBookings() {
  const [reservations, setReservations] = useState([])
  const [selectedReservation, setSelectedReservation] = useState(null)
  const [meta, setMeta] = useState({ statuses: [], transitions: {} })
  const [filters, setFilters] = useState({
    booking_reference: '',
    guest: '',
    accommodation: '',
    status: '',
    date_from: '',
    date_to: '',
  })
  const [statusForm, setStatusForm] = useState({ status: '', cancellation_reason: '' })
  const [isLoading, setIsLoading] = useState(true)
  const [isDetailLoading, setIsDetailLoading] = useState(false)
  const [isUpdatingStatus, setIsUpdatingStatus] = useState(false)
  const [isPaymentModalOpen, setIsPaymentModalOpen] = useState(false)
  const [isRecordingPayment, setIsRecordingPayment] = useState(false)
  const [paymentAmount, setPaymentAmount] = useState('')
  const [pageMessage, setPageMessage] = useState('')
  const [pageError, setPageError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const [currentPage, setCurrentPage] = useState(1)
  const pageSize = 10
  const selectedReservationId = selectedReservation?.id
  const pageCount = Math.max(1, Math.ceil(reservations.length / pageSize))
  const visibleReservations = reservations.slice((currentPage - 1) * pageSize, currentPage * pageSize)

  const filterParams = useMemo(
    () => ({
      booking_reference: filters.booking_reference.trim() || undefined,
      guest: filters.guest.trim() || undefined,
      accommodation: filters.accommodation.trim() || undefined,
      status: filters.status || undefined,
      date_from: filters.date_from || undefined,
      date_to: filters.date_to || undefined,
    }),
    [filters],
  )

  const loadReservations = useCallback(async () => {
    setIsLoading(true)
    setPageError('')

    try {
      const result = await getFrontDeskReservations(filterParams)
      setReservations(result.reservations)
      setMeta(result.meta)

      if (selectedReservationId) {
        const refreshed = result.reservations.find((item) => item.id === selectedReservationId)
        setSelectedReservation(refreshed ?? null)
      }
    } catch (error) {
      setPageError(getApiErrorMessage(error, 'Unable to load reservations right now.'))
    } finally {
      setIsLoading(false)
    }
  }, [filterParams, selectedReservationId])

  useEffect(() => {
    setCurrentPage(1)
  }, [filterParams])

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
    setFilters((current) => ({ ...current, [name]: value }))
  }

  async function selectReservation(reservation) {
    setIsDetailLoading(true)
    setPageError('')
    setPageMessage('')

    try {
      const result = await getFrontDeskReservation(reservation.id)
      setSelectedReservation(result.reservation)
      setMeta(result.meta)
    } catch (error) {
      setPageError(getApiErrorMessage(error, 'Unable to load booking details.'))
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

  function openPaymentModal() {
    if (!selectedReservation) return
    setPaymentAmount(Number(selectedReservation.balance_due ?? 0).toFixed(2))
    setPageError('')
    setPageMessage('')
    setIsPaymentModalOpen(true)
  }

  async function handleRecordPayment(event) {
    event.preventDefault()
    if (!selectedReservation || isRecordingPayment) return

    setIsRecordingPayment(true)
    setPageError('')
    setPageMessage('')

    try {
      const result = await recordFrontDeskReservationPayment(selectedReservation.id, {
        amount: paymentAmount,
        payment_method: 'cash',
      })
      setSelectedReservation(result.reservation)
      setReservations((current) => current.map((item) => item.id === result.reservation.id ? result.reservation : item))
      setIsPaymentModalOpen(false)
      setPageMessage(result.message)
    } catch (error) {
      const errors = error?.response?.data?.errors
      if (errors) {
        setPageError(Object.values(errors).flat()[0] ?? 'Unable to record payment.')
      } else {
        setPageError(getApiErrorMessage(error, 'Unable to record payment.'))
      }
    } finally {
      setIsRecordingPayment(false)
    }
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
      const result = await updateFrontDeskReservationStatus(selectedReservation.id, payload)
      setSelectedReservation(result.reservation)
      setReservations((current) =>
        current.map((item) => (item.id === result.reservation.id ? result.reservation : item)),
      )
      setPageMessage(result.message)
    } catch (error) {
      const errors = error?.response?.data?.errors

      if (errors) {
        setFieldErrors(Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, value[0]])))
        if (error.response.status === 403) {
          setPageError('You are not allowed to make this status change.')
        }
      } else if (error?.response?.status === 403) {
        setPageError('You are not allowed to make this status change.')
      } else {
        setPageError(getApiErrorMessage(error, 'Unable to update reservation status.'))
      }
    } finally {
      setIsUpdatingStatus(false)
    }
  }

  function formatDate(value) {
    if (!value) {
      return 'Not set'
    }

    const dateTime = new Date(value)

    if (Number.isNaN(dateTime.getTime())) {
      return 'Not set'
    }

    if (typeof value === 'string' && value.includes('T')) {
      return new Intl.DateTimeFormat('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
      }).format(dateTime)
    }

    return new Intl.DateTimeFormat('en-PH', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    }).format(dateTime)
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
    <FrontDeskLayout eyebrow="Bookings" title="Booking Operations">
      <ToastMessage message={pageMessage} />
      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}

      <section className="frontdesk-bookings-header">
        <div>
          <p className="eyebrow">Bookings</p>
          <h2>Reservations</h2>
        </div>
        <strong>{reservations.length.toLocaleString()} bookings</strong>
      </section>

      <section className="admin-management-grid admin-booking-grid manager-booking-grid frontdesk-booking-grid">
        <div className="admin-table-panel">
          <div className="admin-filter-bar admin-booking-filter-bar frontdesk-booking-filter-bar">
            <label>
              <span>Reference</span>
              <input name="booking_reference" value={filters.booking_reference} onChange={updateFilter} placeholder="DMD-..." />
            </label>
            <label>
              <span>Guest</span>
              <input name="guest" value={filters.guest} onChange={updateFilter} />
            </label>
            <label>
              <span>Accommodation</span>
              <input name="accommodation" value={filters.accommodation} onChange={updateFilter} />
            </label>
            <label>
              <span>Status</span>
              <select name="status" value={filters.status} onChange={updateFilter}>
                <option value="">All statuses</option>
                {(meta.statuses ?? Object.keys(statusLabels)).map((status) => (
                  <option key={status} value={status}>{statusName(status)}</option>
                ))}
              </select>
            </label>
            <label>
              <span>Check-in From</span>
              <input name="date_from" type="date" value={filters.date_from} onChange={updateFilter} />
            </label>
            <label>
              <span>Check-in To</span>
              <input name="date_to" type="date" value={filters.date_to} onChange={updateFilter} />
            </label>
          </div>

          <div className="admin-table-scroll">
            <table className="admin-data-table admin-booking-table frontdesk-booking-table frontdesk-reservations-table">
              <thead>
                <tr>
                  <th>Reference</th>
                  <th>Guest</th>
                  <th>Accommodation</th>
                  <th>Dates</th>
                  <th>Guests</th>
                  <th>Total</th>
                  <th>Payment</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                {isLoading ? <tr><td colSpan="9" aria-label="Loading reservations..." /></tr> : null}
                {!isLoading && reservations.length === 0 ? (
                  <tr>
                    <td colSpan="9" className="frontdesk-empty-cell">
                      {hasActiveFilters(filters) ? (
                        <div className="frontdesk-empty-state">
                          <CalendarDays size={28} aria-hidden="true" />
                          <div>
                            <strong>No bookings found</strong>
                            <p>Reservations matching your filters will appear here.</p>
                          </div>
                        </div>
                      ) : (
                        <div className="frontdesk-empty-state">
                          <CalendarDays size={28} aria-hidden="true" />
                          <div>
                            <strong>No reservations yet</strong>
                            <p>New guest reservations will appear here.</p>
                          </div>
                        </div>
                      )}
                    </td>
                  </tr>
                ) : null}
                {!isLoading ? visibleReservations.map((reservation) => (
                  <tr key={reservation.id}>
                    <td className="frontdesk-reference-cell"><strong>{reservation.booking_reference}</strong></td>
                    <td><strong>{reservation.guest?.name ?? 'Guest'}</strong><small>{reservation.guest?.email}</small></td>
                    <td>{reservation.accommodation?.name ?? 'Accommodation removed'}</td>
                    <td>{formatDate(reservation.check_in)}<small>{formatDate(reservation.check_out)}</small></td>
                    <td className="frontdesk-guest-count">{reservation.guests}</td>
                    <td className="frontdesk-money-cell">{formatCurrency(reservation.total_amount)}</td>
                    <td><span className={`frontdesk-pill frontdesk-pill-payment tone-${paymentToneFor(reservation.payment_state ?? reservation.payment_status)}`}>{formatPaymentStatus(reservation.payment_state ?? reservation.payment_status)}</span></td>
                    <td><span className={`frontdesk-pill frontdesk-pill-status tone-${statusTone[reservation.status] ?? 'muted'}`}>{statusName(reservation.status)}</span></td>
                    <td><button type="button" onClick={() => selectReservation(reservation)}>View</button></td>
                  </tr>
                )) : null}
              </tbody>
            </table>
          </div>
          {!isLoading && reservations.length > 0 ? <PaginationControls page={currentPage} pageCount={pageCount} total={reservations.length} pageSize={pageSize} label="bookings" onPageChange={setCurrentPage} /> : null}
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
              <div><dt>Total Amount</dt><dd className="frontdesk-money-cell">{formatCurrency(selectedReservation.total_amount)}</dd></div>
              <div><dt>Amount Paid</dt><dd className="frontdesk-money-cell">{formatCurrency(selectedReservation.total_paid)}</dd></div>
              <div><dt>Remaining Balance</dt><dd className="frontdesk-money-cell">{formatCurrency(selectedReservation.balance_due)}</dd></div>
              <div><dt>Payment Status</dt><dd><span className={`frontdesk-pill frontdesk-pill-payment tone-${paymentToneFor(selectedReservation.payment_state)}`}>{formatPaymentStatus(selectedReservation.payment_state)}</span></dd></div>
              <div><dt>Booking Status</dt><dd><span className={`frontdesk-pill frontdesk-pill-status tone-${statusTone[selectedReservation.status] ?? 'muted'}`}>{statusName(selectedReservation.status)}</span></dd></div>
            </dl>

            {selectedReservation.status === 'confirmed'
            && selectedReservation.payment_state === 'partially_paid'
            && Number(selectedReservation.balance_due ?? 0) > 0 ? (
              <button type="button" className="primary-button" onClick={openPaymentModal}>Record Payment</button>
            ) : null}

            <section className="frontdesk-payment-history">
              <h3>Payment History</h3>
              {(selectedReservation.payment_history ?? []).length ? selectedReservation.payment_history.map((payment) => (
                <div className="frontdesk-payment-history-row" key={payment.id}>
                  <div><strong>{paymentPurposeLabel(payment.purpose)}</strong><small>{payment.provider === 'manual' ? 'Cash' : `${payment.provider ?? 'Online'}${payment.payment_method ? ` / ${payment.payment_method}` : ''}`}</small></div>
                  <div><strong>{formatCurrency(payment.amount)}</strong><small>{formatPaymentStatus(payment.status)}</small>{payment.recorded_by ? <small>Recorded by: {payment.recorded_by.name}</small> : null}<small>{formatDate(payment.paid_at ?? payment.created_at)}</small></div>
                </div>
              )) : <p>No payment transactions recorded.</p>}
            </section>

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

      {isPaymentModalOpen && selectedReservation ? (
        <PaymentModal
          reservation={selectedReservation}
          amount={paymentAmount}
          isSubmitting={isRecordingPayment}
          onAmountChange={setPaymentAmount}
          onCancel={() => setIsPaymentModalOpen(false)}
          onSubmit={handleRecordPayment}
        />
      ) : null}
    </FrontDeskLayout>
  )
}

function hasActiveFilters(filters) {
  return Object.values(filters).some((value) => Boolean(value))
}

function formatPaymentStatus(paymentStatus) {
  if (!paymentStatus || paymentStatus === 'Not implemented') {
    return 'N/A'
  }

  return paymentStatus
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ')
}

function paymentToneFor(paymentStatus) {
  if (!paymentStatus || paymentStatus === 'Not implemented') {
    return 'muted'
  }

  return paymentTone[paymentStatus] ?? 'slate'
}

function paymentPurposeLabel(value) {
  return { deposit: 'Reservation Deposit', balance: 'Remaining Balance', full: 'Full Payment' }[value] ?? 'Payment'
}

function PaymentModal({ reservation, amount, isSubmitting, onAmountChange, onCancel, onSubmit }) {
  return (
    <>
      <button className="frontdesk-modal-backdrop" type="button" aria-label="Close payment dialog" onClick={onCancel} />
      <section className="frontdesk-modal frontdesk-payment-modal" role="dialog" aria-modal="true" aria-labelledby="frontdesk-payment-title">
        <div className="frontdesk-modal-header">
          <div><p className="eyebrow">Record Payment</p><h3 id="frontdesk-payment-title">{reservation.booking_reference}</h3></div>
          <button type="button" onClick={onCancel} aria-label="Close dialog">X</button>
        </div>
        <dl className="frontdesk-modal-list">
          <div><dt>Total</dt><dd>{formatCurrency(reservation.total_amount)}</dd></div>
          <div><dt>Already Paid</dt><dd>{formatCurrency(reservation.total_paid)}</dd></div>
          <div><dt>Remaining Balance</dt><dd>{formatCurrency(reservation.balance_due)}</dd></div>
        </dl>
        <form className="frontdesk-payment-form" onSubmit={onSubmit}>
          <label><span>Payment Method</span><select value="cash" disabled><option value="cash">Cash</option></select></label>
          <label><span>Amount Received</span><input value={amount} onChange={(event) => onAmountChange(event.target.value)} inputMode="decimal" required /></label>
          <div className="frontdesk-modal-actions"><button type="button" className="outline-button" onClick={onCancel}>Cancel</button><button type="submit" className="primary-button" disabled={isSubmitting}>{isSubmitting ? 'Recording...' : 'Record Payment'}</button></div>
        </form>
      </section>
    </>
  )
}

function getApiErrorMessage(error, fallback) {
  return error?.response?.status === 404
    ? 'The requested booking could not be found.'
    : error?.response?.status === 403
      ? 'You are not allowed to access this booking.'
      : fallback
}

export default FrontDeskBookings
