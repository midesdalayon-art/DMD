import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { CheckCircle2, ChevronDown, X } from 'lucide-react'
import LoadingState from '../components/LoadingState'
import { useResortBranding } from '../hooks/useResortBranding'
import { getGuestBookingPaymentStatus, getGuestReservationPaymentStatus } from '../lib/api'
import { formatReservationDateTime, formatReservationStaySummary } from '../utils/bookingTime'

function BookingPaymentSuccess() {
  const [params] = useSearchParams()
  const reservationId = params.get('reservation_id')
  const [isGuestCheckout] = useState(() => Boolean(
    reservationId && window.sessionStorage.getItem(`dmd-guest-checkout-token:${reservationId}`),
  ))
  const [hasGuestAccess, setHasGuestAccess] = useState(() => Boolean(
    reservationId && window.sessionStorage.getItem(`dmd-guest-access-token:${reservationId}`),
  ))
  const [guestAccessToken, setGuestAccessToken] = useState(() => (
    reservationId ? window.sessionStorage.getItem(`dmd-guest-access-token:${reservationId}`) : null
  ))
  const [confirmationEmailStatus, setConfirmationEmailStatus] = useState(null)
  const [reservation, setReservation] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [message, setMessage] = useState('Verifying your payment...')
  const [error, setError] = useState('')
  const [isTimedOut, setIsTimedOut] = useState(false)
  const [verifyToken, setVerifyToken] = useState(0)
  const { publicSettings } = useResortBranding()
  const bookingSettings = publicSettings?.booking ?? {}

  useEffect(() => {
    let timer = null
    let timeoutTimer = null
    let isMounted = true
    const startedAt = Date.now()
    const maxWaitMs = 20000

    async function loadStatus() {
      if (!reservationId) {
        if (isMounted) {
          if (timeoutTimer) {
            window.clearTimeout(timeoutTimer)
          }
          setError('Missing reservation reference.')
          setIsLoading(false)
        }
        return
      }

      if (Date.now() - startedAt > maxWaitMs) {
        if (isMounted) {
          if (timeoutTimer) {
            window.clearTimeout(timeoutTimer)
          }
          setIsTimedOut(true)
          setIsLoading(false)
          setMessage('Payment confirmation is taking longer than expected.')
        }
        return
      }

      try {
        const guestAccessToken = window.sessionStorage.getItem(`dmd-guest-access-token:${reservationId}`)
        const guestCheckoutToken = window.sessionStorage.getItem(`dmd-guest-checkout-token:${reservationId}`)
        const data = guestAccessToken
          ? await getGuestBookingPaymentStatus(reservationId, guestAccessToken)
          : await getGuestReservationPaymentStatus(reservationId, guestCheckoutToken)
        if (!isMounted) {
          return
        }

        setReservation(data.reservation ?? null)
        if (data.guest_confirmation_email_status) {
          setConfirmationEmailStatus(data.guest_confirmation_email_status)
        }

        if (data.guest_access_token) {
          window.sessionStorage.setItem(`dmd-guest-access-token:${reservationId}`, data.guest_access_token)
          setHasGuestAccess(true)
          setGuestAccessToken(data.guest_access_token)
        }

        if (data.reservation_status === 'expired' || data.source === 'reconciliation_required') {
          if (timeoutTimer) {
            window.clearTimeout(timeoutTimer)
          }
          setError(data.message ?? 'This reservation hold expired before payment could be confirmed.')
          setMessage(data.source === 'reconciliation_required'
            ? 'Your payment requires resort staff review.'
            : 'This reservation was not paid within the allowed payment period.')
          setIsTimedOut(false)
          setIsLoading(false)
          return
        }

        if (data.payment_status === 'paid' || data.reservation_status === 'confirmed') {
          if (timeoutTimer) {
            window.clearTimeout(timeoutTimer)
          }
          setMessage(
            data.payment_purpose === 'balance' || data.reservation?.payment_purpose === 'balance'
              ? 'Your remaining reservation balance has been successfully paid.'
              : data.payment_state === 'partially_paid' || data.reservation?.payment_state === 'partially_paid'
                ? 'Your 30% reservation deposit was successfully paid.'
                : 'Your full payment was successfully completed.',
          )
          setError('')
          setIsTimedOut(false)
          setIsLoading(false)
          window.sessionStorage.removeItem(`dmd-guest-checkout-token:${reservationId}`)
          window.sessionStorage.removeItem(`dmd-payment-return-reservation:${reservationId}`)
          return
        }

        if (data.payment_status === 'failed' || data.payment_status === 'cancelled') {
          if (timeoutTimer) {
            window.clearTimeout(timeoutTimer)
          }
          setError('Payment was not completed.')
          setMessage('Your reservation has not been charged.')
          setIsLoading(false)
          return
        }

        setMessage('Payment is being verified.')
        timer = window.setTimeout(loadStatus, 2000)
      } catch (requestError) {
        if (isMounted) {
          if (Date.now() - startedAt < maxWaitMs) {
            timer = window.setTimeout(loadStatus, 2500)
            return
          }

          if (timeoutTimer) {
            window.clearTimeout(timeoutTimer)
          }
          setError('We are still waiting for payment confirmation.')
          setMessage('Your payment may already have succeeded.')
          setIsTimedOut(true)
          setIsLoading(false)
        }
      }
    }

    loadStatus()

    timeoutTimer = window.setTimeout(() => {
      if (!isMounted) {
        return
      }

      if (isLoading) {
        setIsTimedOut(true)
        setIsLoading(false)
        setMessage('Payment confirmation is taking longer than expected.')
      }
    }, maxWaitMs + 500)

    return () => {
      isMounted = false
      if (timer) {
        window.clearTimeout(timer)
      }
      if (timeoutTimer) {
        window.clearTimeout(timeoutTimer)
      }
    }
  }, [reservationId, verifyToken])

  if (isLoading) {
    return (
      <section className="site-shell page-section">
        <LoadingState message={message} />
      </section>
    )
  }

  if (!error && !isTimedOut && reservation) {
    return (
      <PaymentSuccessModal
        reservation={reservation}
        bookingSettings={bookingSettings}
        message={message}
        isGuestCheckout={isGuestCheckout}
        hasGuestAccess={hasGuestAccess}
        guestAccessToken={guestAccessToken}
      />
    )
  }

  return (
    <section className="site-shell page-section narrow-page">
      <h1>{reservation?.status === 'expired' ? 'Reservation Hold Expired' : 'Payment verification'}</h1>
      <p>{error || message}</p>
      {!error && reservation?.status === 'confirmed' && isGuestCheckout ? (
        <p>
          {confirmationEmailStatus === 'sent' || confirmationEmailStatus === 'queued'
            ? 'We sent your booking details and secure access link to your email.'
            : 'Booking confirmed. Keep this booking page available while we prepare your confirmation email.'}
        </p>
      ) : null}
      {isTimedOut ? (
        <p>{isGuestCheckout ? 'Try checking again in a moment, or return to the booking page.' : 'Try checking again in a moment, or open My Bookings to see the latest status.'}</p>
      ) : null}
      {reservation ? <p>Reference: {reservation.booking_reference}</p> : null}
      <div className="form-actions">
        {error || isTimedOut ? (
          <button
            className="solid-button"
            type="button"
            onClick={() => {
              setError('')
              setIsTimedOut(false)
              setIsLoading(true)
              setMessage('Verifying your payment...')
              setVerifyToken((current) => current + 1)
            }}
          >
            Check Again
          </button>
        ) : null}
        {hasGuestAccess ? (
          <Link className="solid-button" to={guestAccessToken ? `/guest/booking/${guestAccessToken}` : '/guest/booking'}>
            Open Guest Booking
          </Link>
        ) : isGuestCheckout ? (
          <Link className="solid-button" to="/rooms">
            Return to Booking
          </Link>
        ) : (
          <Link className="solid-button" to="/account/bookings">
            View My Bookings
          </Link>
        )}
        <Link className="outline-button" to="/">
          Return Home
        </Link>
      </div>
    </section>
  )
}

function PaymentSuccessModal({ reservation, bookingSettings, message, isGuestCheckout, hasGuestAccess, guestAccessToken }) {
  const summary = formatReservationStaySummary(reservation, bookingSettings)
  const fallbackPath = isGuestCheckout ? '/rooms' : '/account/bookings'
  const fallbackLabel = isGuestCheckout ? 'Return to Booking' : 'View My Bookings'

  return (
    <div className="payment-success-backdrop" role="presentation">
      <section
        className="payment-success-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="payment-success-title"
      >
        <button className="payment-success-close" type="button" onClick={() => { window.location.href = '/' }} aria-label="Close confirmation">
          <X size={18} aria-hidden="true" />
        </button>

        <div className="payment-success-content">
          <div className="payment-success-heading">
            <span className="payment-success-icon" aria-hidden="true"><CheckCircle2 size={30} /></span>
            <div>
              <p className="payment-success-eyebrow">Payment successful</p>
              <h1 id="payment-success-title">Reservation Confirmed</h1>
              <p>{message}</p>
            </div>
          </div>

          <div className="payment-success-reference">
            <span>Booking Reference</span>
            <strong>{reservation.booking_reference}</strong>
          </div>

          <BookingPaymentSummary reservation={reservation} />

          <dl className="payment-success-details">
            <PaymentDetail label="Check-in" value={summary ? formatReservationDateTime(summary.primaryValue, null, null) : '—'} />
            <PaymentDetail label="Check-out" value={summary ? formatReservationDateTime(summary.secondaryValue, null, null) : '—'} />
            <PaymentDetail label="Duration" value={formatDuration(summary?.durationHours)} />
          </dl>

          <CancellationPolicyNotice deadline={reservation.cancellation_deadline_at} />

          <div className="payment-success-actions">
            {hasGuestAccess ? (
              <Link className="solid-button" to={guestAccessToken ? `/guest/booking/${guestAccessToken}` : '/guest/booking'}>
                Open Guest Booking
              </Link>
            ) : (
              <Link className="solid-button" to={fallbackPath}>{fallbackLabel}</Link>
            )}
            <Link className="outline-button" to="/">Return Home</Link>
          </div>
        </div>
      </section>
    </div>
  )
}

function PaymentDetail({ label, value }) {
  return <div><dt>{label}</dt><dd>{value}</dd></div>
}

function formatCurrency(value) {
  return `₱ ${Number(value ?? 0).toLocaleString()}`
}

function formatDuration(durationHours) {
  if (!durationHours) return '—'

  const hours = Number(durationHours)
  if (hours % 24 === 0) {
    const days = hours / 24
    return `${days} day${days === 1 ? '' : 's'}`
  }

  return `${hours} hours`
}

function BookingPaymentSummary({ reservation }) {
  const paymentState = reservation.payment_state ?? reservation.payment_status
  const isPartial = paymentState === 'partially_paid'

  return (
    <div className="payment-success-payment-summary" aria-label={`Payment summary total ${formatCurrency(reservation.total_amount)}`}>
      <div><span>Total</span><strong>₱ {Number(reservation.total_amount ?? 0).toLocaleString()}</strong></div>
      <div><span>Paid</span><strong>₱ {Number(reservation.total_paid ?? 0).toLocaleString()}</strong></div>
      <div><span>Remaining Balance</span><strong>₱ {Number(reservation.balance_due ?? 0).toLocaleString()}</strong></div>
      <div><span>Payment</span><strong>{isPartial ? 'Partially Paid' : 'Fully Paid'}</strong></div>
    </div>
  )
}

function CancellationPolicyNotice({ deadline }) {
  return (
    <details className="payment-success-policy">
      <summary><span>Cancellation &amp; Refund Policy</span><ChevronDown size={16} aria-hidden="true" /></summary>
      <div>
        <p>A 30% down payment is required to secure your reservation. Cancel on or before the deadline to qualify for a 50% refund of the eligible down payment paid; the remaining 50% is non-refundable. Cancellations after the deadline are non-refundable.</p>
        {deadline ? <p>Cancellation refund deadline: {new Date(deadline).toLocaleString()}</p> : null}
      </div>
    </details>
  )
}

export default BookingPaymentSuccess
