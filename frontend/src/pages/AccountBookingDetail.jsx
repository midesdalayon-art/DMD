import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import EmptyState from '../components/EmptyState'
import LoadingState from '../components/LoadingState'
import { ToastMessage } from '../context/ToastContext'
import { cancelReservation, createPayMongoCheckout, getReservation, submitReservationFeedback } from '../lib/api'
import CustomerAccountLayout from './CustomerAccountLayout'
import { useResortBranding } from '../hooks/useResortBranding'
import { formatReservationDateTime, formatReservationStaySummary } from '../utils/bookingTime'

function extractServerErrors(error) {
  const responseErrors = error?.response?.data?.errors

  if (!responseErrors) {
    return {}
  }

  return Object.fromEntries(
    Object.entries(responseErrors).map(([field, messages]) => [
      field,
      Array.isArray(messages) ? messages[0] : messages,
    ]),
  )
}

function AccountBookingDetail() {
  const { id } = useParams()
  const [reservation, setReservation] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  const [reason, setReason] = useState('')
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState('')
  const [successMessage, setSuccessMessage] = useState('')
  const [isCancelling, setIsCancelling] = useState(false)
  const [isPayingBalance, setIsPayingBalance] = useState(false)
  const [feedbackRating, setFeedbackRating] = useState(0)
  const [feedbackComment, setFeedbackComment] = useState('')
  const [feedbackError, setFeedbackError] = useState('')
  const [feedbackSuccess, setFeedbackSuccess] = useState('')
  const [isSubmittingFeedback, setIsSubmittingFeedback] = useState(false)
  const [holdExpired, setHoldExpired] = useState(false)
  const { resortName, publicSettings } = useResortBranding()
  const bookingSettings = publicSettings?.booking ?? {}

  useEffect(() => {
    let isMounted = true

    async function loadReservation() {
      setIsLoading(true)
      setLoadError('')

      try {
        const data = await getReservation(id)
        if (isMounted) {
          setReservation(data)
        }
      } catch (error) {
        if (isMounted) {
          setLoadError(
            error?.response?.status === 404
              ? 'This booking could not be found.'
              : 'Unable to load this booking right now.',
          )
        }
      } finally {
        if (isMounted) {
          setIsLoading(false)
        }
      }
    }

    loadReservation()

    return () => {
      isMounted = false
    }
  }, [id])

  useEffect(() => {
    if (!reservation || reservation.status !== 'pending' || reservation.payment_state !== 'unpaid' || !reservation.expires_at) {
      setHoldExpired(false)
      return undefined
    }

    const updateHold = () => {
      setHoldExpired(new Date(reservation.expires_at).getTime() <= Date.now())
    }

    updateHold()
    const timer = window.setInterval(updateHold, 1000)

    return () => window.clearInterval(timer)
  }, [reservation])

  async function handleCancel(event) {
    event.preventDefault()
    setErrors({})
    setFormError('')
    setSuccessMessage('')

    if (!reason.trim()) {
      setErrors({ reason: 'Cancellation reason is required.' })
      return
    }

    setIsCancelling(true)

    try {
      const result = await cancelReservation(id, reason.trim())
      setReservation(result.reservation)
      setSuccessMessage(result.message ?? 'Reservation cancelled.')
      setReason('')
    } catch (error) {
      if (error?.response?.status === 422) {
        const serverErrors = extractServerErrors(error)
        setErrors(serverErrors)
        setFormError(serverErrors.reservation ?? 'Please review the cancellation details.')
      } else {
        setFormError('Unable to cancel this reservation right now. Please try again.')
      }
    } finally {
      setIsCancelling(false)
    }
  }

  async function handlePayBalance() {
    if (isPayingBalance || !reservation) return

    setIsPayingBalance(true)
    setFormError('')

    try {
      const checkout = await createPayMongoCheckout(reservation.id, 'balance')
      if (checkout?.payment?.checkout_url) {
        window.location.href = checkout.payment.checkout_url
        return
      }
      setFormError('Unable to prepare the balance payment checkout right now.')
    } catch (error) {
      if (error?.response?.status === 422) {
        try {
          setReservation(await getReservation(id))
          setFormError('This booking was updated. Its payment details have been refreshed.')
        } catch {
          setFormError('This booking was updated. Please refresh the page to see its latest payment details.')
        }
      } else {
        setFormError(error?.response?.data?.message || 'Unable to prepare the balance payment checkout right now. Please try again.')
      }
    } finally {
      setIsPayingBalance(false)
    }
  }

  async function handleFeedbackSubmit(event) {
    event.preventDefault()
    if (!feedbackRating || isSubmittingFeedback) return
    setIsSubmittingFeedback(true)
    setFeedbackError('')
    try {
      const result = await submitReservationFeedback(id, { rating: feedbackRating, comment: feedbackComment.trim() || undefined })
      setReservation((current) => ({ ...current, feedback: result.data }))
      setFeedbackSuccess(result.message || 'Thanks for your feedback!')
    } catch (error) {
      setFeedbackError(error?.response?.data?.message || 'Unable to submit feedback right now.')
    } finally {
      setIsSubmittingFeedback(false)
    }
  }

  if (isLoading) {
    return (
      <CustomerAccountLayout title="Booking Details">
        <LoadingState message="Loading booking details..." />
      </CustomerAccountLayout>
    )
  }

  if (loadError || !reservation) {
    return (
      <CustomerAccountLayout title="Booking Details">
        <EmptyState title="Booking not found" message={loadError} />
      </CustomerAccountLayout>
    )
  }

  return (
    <CustomerAccountLayout
      title="Booking Details"
      description={`Review your ${resortName} reservation request and cancellation eligibility.`}
    >
      <div className="booking-detail">
        <Link className="back-link" to="/account/bookings">
          Back to My Bookings
        </Link>

        <section className="booking-detail-panel">
          <div>
            <p className="eyebrow">{reservation.booking_reference}</p>
            <h2>{reservation.accommodation?.name ?? 'Accommodation'}</h2>
            <p>{reservation.accommodation?.description}</p>
          </div>

          <dl className="booking-detail-grid">
            <ReservationStaySummary reservation={reservation} bookingSettings={bookingSettings} />
            <div>
              <dt>Guests</dt>
              <dd>
                {reservation.guests} occupying guest{reservation.guests === 1 ? '' : 's'}
                <small>
                  Adults: {reservation.adults ?? reservation.guests}, Children: {reservation.children ?? 0}, Infants:{' '}
                  {reservation.infants ?? 0}
                </small>
              </dd>
            </div>
            <div>
              <dt>Total Amount</dt>
              <dd>₱ {Number(reservation.total_amount).toLocaleString()}</dd>
            </div>
            <div>
              <dt>Amount Paid</dt>
              <dd>₱ {Number(reservation.total_paid ?? 0).toLocaleString()}</dd>
            </div>
            <div>
              <dt>Remaining Balance</dt>
              <dd>₱ {Number(reservation.balance_due ?? 0).toLocaleString()}</dd>
            </div>
            <div>
              <dt>Reservation Status</dt>
              <dd>{formatStatus(reservation.status)}</dd>
            </div>
            <div>
              <dt>Payment Status</dt>
              <dd>{formatStatus(reservation.payment_state ?? reservation.payment_status)}</dd>
            </div>
          </dl>

          {reservation.status === 'pending' && reservation.payment_state === 'unpaid' && reservation.expires_at && !holdExpired ? (
            <PendingPaymentHold expiresAt={reservation.expires_at} />
          ) : null}

          {(reservation.status === 'expired' || holdExpired) ? (
            <div className="cancelled-note booking-expired-note">
              <strong>Reservation Hold Expired</strong>
              <p>This reservation was not paid within the allowed payment period.</p>
              <Link className="solid-button" to="/rooms">Book Again</Link>
            </div>
          ) : null}

          {reservation.status === 'confirmed'
          && reservation.payment_state === 'partially_paid'
          && Number(reservation.balance_due ?? 0) > 0 ? (
            <div className="booking-balance-action">
              <div>
                <strong>Remaining Balance</strong>
                <p>Complete your reservation payment securely through PayMongo.</p>
              </div>
              <button className="solid-button" type="button" onClick={handlePayBalance} disabled={isPayingBalance}>
                {isPayingBalance ? 'Preparing Checkout...' : 'Pay Remaining Balance'}
              </button>
            </div>
          ) : null}

          {reservation.status === 'cancelled' ? (
            <div className="cancelled-note">
              <strong>Cancellation reason</strong>
              <p>{reservation.cancellation_reason}</p>
              <CancellationRefundSummary reservation={reservation} />
            </div>
          ) : null}
        </section>

        <section className="booking-detail-panel">
          <h2>Payment History</h2>
          {reservation.payment_history?.length ? (
            <div className="booking-payment-history">
              {reservation.payment_history.map((payment) => (
                <article className="booking-payment-history-row" key={payment.id}>
                  <div>
                    <strong>{paymentPurposeLabel(payment.purpose)}</strong>
                    <span>{payment.provider ?? 'Payment Provider'}{payment.payment_method ? ` / ${payment.payment_method}` : ''}</span>
                  </div>
                  <div>
                    <strong>{formatCurrency(payment.amount, payment.currency)}</strong>
                    <span className={`payment-history-status payment-history-status-${payment.status}`}>{formatStatus(payment.status)}</span>
                    <small>{formatPaymentDate(payment.paid_at ?? payment.created_at)}</small>
                  </div>
                </article>
              ))}
            </div>
          ) : <p>No payment transactions have been recorded for this booking yet.</p>}
        </section>

        {reservation.status === 'checked_out' ? (
          <StayFeedback
            feedback={reservation.feedback}
            rating={feedbackRating}
            setRating={setFeedbackRating}
            comment={feedbackComment}
            setComment={setFeedbackComment}
            error={feedbackError}
            success={feedbackSuccess}
            isSubmitting={isSubmittingFeedback}
            onSubmit={handleFeedbackSubmit}
          />
        ) : null}

        <section className="booking-detail-panel">
          <h2>Cancel Reservation</h2>
          <p>
            Eligible pending or confirmed reservations can be cancelled before check-in. Cancelled reservations remain
            in your booking history.
          </p>
          <CancellationPolicy deadline={reservation.cancellation_deadline_at} />

          {formError ? (
            <div className="form-alert" role="alert">
              {formError}
            </div>
          ) : null}

          <ToastMessage message={successMessage} />

          {reservation.can_cancel ? (
            <form className="account-form cancellation-form" noValidate onSubmit={handleCancel}>
              <label className="form-field" htmlFor="reason">
                <span>Cancellation Reason</span>
                <textarea
                  id="reason"
                  name="reason"
                  rows="4"
                  value={reason}
                  onChange={(event) => {
                    setReason(event.target.value)
                    setErrors({})
                    setFormError('')
                    setSuccessMessage('')
                  }}
                  aria-invalid={Boolean(errors.reason)}
                  aria-describedby={errors.reason ? 'reason-error' : undefined}
                  disabled={isCancelling}
                  required
                />
                {errors.reason ? (
                  <small id="reason-error" className="field-error">
                    {errors.reason}
                  </small>
                ) : null}
              </label>

              <button className="outline-button danger-button" type="submit" disabled={isCancelling}>
                {isCancelling ? 'Cancelling...' : 'Cancel Reservation'}
              </button>
            </form>
          ) : (
            <div className="locked-account-fields">
              <strong>This reservation is not eligible for cancellation.</strong>
              <p>Cancellation is only available for pending or confirmed reservations before check-in.</p>
            </div>
          )}
        </section>
      </div>
    </CustomerAccountLayout>
  )
}

export default AccountBookingDetail

function ReservationStaySummary({ reservation, bookingSettings }) {
  const summary = formatReservationStaySummary(reservation, bookingSettings)

  if (!summary) {
    return null
  }

  const isCottage = reservation?.accommodation?.type === 'cottage'

  return (
    <>
      <div>
        <dt>{summary.primaryLabel}</dt>
        <dd>
          {summary.durationHours ? formatReservationDateTime(summary.primaryValue, null, null) : summary.primaryValue}
          {summary.durationHours ? <small>{summary.durationHours} hours</small> : null}
          {summary.primaryTimeLabel ? (
            <small>
              {summary.primaryTimeLabel}:{' '}
              {isCottage
                ? summary.primaryTimeValue
                : reservation.expected_arrival_time ?? reservation.preferred_arrival_time ?? 'Not specified'}
            </small>
          ) : null}
        </dd>
      </div>
      <div>
        <dt>{summary.secondaryLabel}</dt>
        <dd>
          {summary.durationHours ? formatReservationDateTime(summary.secondaryValue, null, null) : summary.secondaryValue}
          {summary.secondaryTimeLabel ? (
            <small>
              {summary.secondaryTimeLabel}:{' '}
              {isCottage ? summary.secondaryTimeValue : reservation.expected_departure_time ?? 'Not specified'}
            </small>
          ) : null}
        </dd>
      </div>
    </>
  )
}

function formatStatus(value) {
  if (!value) {
    return 'Pending'
  }

  return String(value)
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (match) => match.toUpperCase())
}

function paymentPurposeLabel(value) {
  return { deposit: 'Reservation Deposit', balance: 'Remaining Balance', full: 'Full Payment' }[value] ?? 'Payment'
}

function formatCurrency(amount, currency = 'PHP') {
  return `${currency === 'PHP' ? '₱' : currency} ${Number(amount ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function formatPaymentDate(value) {
  return value ? new Date(value).toLocaleString() : 'Not paid yet'
}

function PendingPaymentHold({ expiresAt }) {
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(timer)
  }, [])

  const remainingSeconds = Math.max(0, Math.ceil((new Date(expiresAt).getTime() - now) / 1000))
  const minutes = Math.floor(remainingSeconds / 60).toString().padStart(2, '0')
  const seconds = (remainingSeconds % 60).toString().padStart(2, '0')

  return (
    <div className="booking-payment-hold" role="status">
      <strong>Payment Pending</strong>
      <p>Complete your payment within: <b>{minutes}:{seconds}</b></p>
      <span>Your accommodation is temporarily reserved while payment is pending.</span>
    </div>
  )
}

const feedbackMoods = [
  ['😡', 'Awful', 1], ['🙁', 'Bad', 2], ['😐', 'Okay', 3], ['🙂', 'Good', 4], ['😍', 'Amazing', 5],
]

function StayFeedback({ feedback, rating, setRating, comment, setComment, error, success, isSubmitting, onSubmit }) {
  if (feedback) {
    const mood = feedbackMoods.find(([, , value]) => value === Number(feedback.rating))
    return <section className="booking-detail-panel stay-feedback"><h2>Stay completed ✓</h2><p>Feedback submitted ✓</p><strong>Your experience: {mood?.[0]} {mood?.[1]}</strong></section>
  }

  return (
    <section className="booking-detail-panel stay-feedback">
      <h2>How was your stay? 🌴</h2>
      <form onSubmit={onSubmit}>
        <div className="feedback-mood-options" role="radiogroup" aria-label="Stay satisfaction">
          {feedbackMoods.map(([emoji, label, value]) => (
            <button key={value} type="button" className={rating === value ? 'is-selected' : ''} role="radio" aria-checked={rating === value} aria-label={label} onClick={() => setRating(value)}>
              <span aria-hidden="true">{emoji}</span><small>{label}</small>
            </button>
          ))}
        </div>
        <label className="form-field"><span>Optional comment</span><textarea rows="3" maxLength="2000" value={comment} onChange={(event) => setComment(event.target.value)} /></label>
        {error ? <div className="form-alert" role="alert">{error}</div> : null}
        <ToastMessage message={success} />
        <button className="solid-button" type="submit" disabled={!rating || isSubmitting}>{isSubmitting ? 'Submitting...' : 'Submit Feedback'}</button>
      </form>
    </section>
  )
}

function CancellationPolicy({ deadline }) {
  return (
    <div className="cancellation-policy-note">
      <strong>Cancellation &amp; Refund Policy</strong>
      <p>A 30% down payment is required to secure your reservation. If you cancel at least 3 days (72 hours) before your scheduled check-in, 50% of the down payment paid is refundable and the remaining 50% is non-refundable. Cancellations made less than 72 hours before check-in are non-refundable.</p>
      {deadline ? <small>Cancellation refund deadline: {new Date(deadline).toLocaleString()}</small> : null}
    </div>
  )
}

function CancellationRefundSummary({ reservation }) {
  if (!reservation.cancellation_requested_at) return null
  return (
    <div className="cancellation-refund-summary">
      <strong>{reservation.refund_eligible ? 'Refund eligible' : 'Non-refundable'}</strong>
      <p>Eligible down payment: {formatCurrency(reservation.eligible_down_payment_amount)}</p>
      <p>Estimated refundable: {formatCurrency(reservation.estimated_refund_amount)}</p>
      <p>Retained / non-refundable: {formatCurrency(reservation.estimated_retained_amount)}</p>
      <p>Refund status: {formatStatus(reservation.refund_status)}</p>
      {reservation.refund_status === 'refunded' ? <p>Refunded amount: {formatCurrency(reservation.refunded_amount)}</p> : null}
    </div>
  )
}
