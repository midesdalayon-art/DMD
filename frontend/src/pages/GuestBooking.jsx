import { useEffect, useMemo, useState } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import QRCode from 'qrcode'
import LoadingState from '../components/LoadingState'
import PaymentCancelledModal from '../components/PaymentCancelledModal'
import { ToastMessage } from '../context/ToastContext'
import { createGuestBookingBalanceCheckout, getGuestBooking, submitGuestBookingFeedback } from '../lib/api'

const accessTokenKey = (reservationId) => `dmd-guest-access-token:${reservationId}`

function GuestBooking() {
  const { token: routeToken } = useParams()
  const navigate = useNavigate()
  const location = useLocation()
  const queryToken = new URLSearchParams(location.search).get('token')
  const urlToken = routeToken || queryToken
  const [reservation, setReservation] = useState(null)
  const [accessToken, setAccessToken] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [isPaying, setIsPaying] = useState(false)
  const [isPaymentCancelledModalOpen, setIsPaymentCancelledModalOpen] = useState(() => Boolean(location.state?.paymentCancelled))
  const [error, setError] = useState('')
  const [paymentError, setPaymentError] = useState('')
  const [feedbackRating, setFeedbackRating] = useState(0)
  const [feedbackComment, setFeedbackComment] = useState('')
  const [feedbackError, setFeedbackError] = useState('')
  const [feedbackSuccess, setFeedbackSuccess] = useState('')
  const [isSubmittingFeedback, setIsSubmittingFeedback] = useState(false)

  useEffect(() => {
    if (location.state?.paymentCancelled) {
      navigate(location.pathname, { replace: true, state: null })
    }
  }, [location.pathname, location.state, navigate])

  useEffect(() => {
    const previousReferrer = document.querySelector('meta[name="referrer"]')
    const meta = previousReferrer ?? document.createElement('meta')
    const previousContent = previousReferrer?.content
    meta.name = 'referrer'
    meta.content = 'no-referrer'
    if (!previousReferrer) document.head.appendChild(meta)

    return () => {
      if (previousReferrer) {
        previousReferrer.content = previousContent
      } else {
        meta.remove()
      }
    }
  }, [])

  useEffect(() => {
    const storedTokenKey = Object.keys(sessionStorage).find((key) => key.startsWith('dmd-guest-access-token:'))
    const storedToken = urlToken
      || sessionStorage.getItem('dmd-guest-access-token:pending')
      || (storedTokenKey ? sessionStorage.getItem(storedTokenKey) : null)

    if (!storedToken) {
      setError('This guest booking link is invalid or has expired.')
      setIsLoading(false)
      return undefined
    }

    sessionStorage.removeItem('dmd-guest-access-token:pending')
    setError('')
    setIsLoading(true)
    setAccessToken(storedToken)

    let mounted = true
    getGuestBooking(storedToken)
      .then((data) => {
        if (mounted) {
          sessionStorage.setItem(accessTokenKey(data.id), storedToken)
          setReservation(data)
        }
      })
      .catch(() => {
        if (mounted) setError('This guest booking link is invalid or has expired.')
      })
      .finally(() => {
        if (mounted) setIsLoading(false)
      })

    return () => {
      mounted = false
    }
  }, [urlToken])

  const summary = useMemo(() => reservation ? formatStay(reservation) : null, [reservation])
  const isPartiallyPaid = reservation?.status === 'confirmed'
    && reservation?.payment_state === 'partially_paid'
    && Number(reservation?.balance_due ?? 0) > 0
  const isFullyPaid = reservation?.payment_state === 'fully_paid'

  async function handlePayBalance() {
    if (!accessToken || isPaying || !isPartiallyPaid) return

    setIsPaying(true)
    setPaymentError('')

    try {
      const checkout = await createGuestBookingBalanceCheckout(accessToken)
      if (checkout?.payment?.checkout_url) {
        window.location.assign(checkout.payment.checkout_url)
        return
      }
      setPaymentError('Unable to prepare the payment checkout right now.')
    } catch (requestError) {
      setPaymentError(requestError?.response?.data?.message || 'Unable to prepare the payment checkout right now.')
    } finally {
      setIsPaying(false)
    }
  }

  async function handleFeedbackSubmit(event) {
    event.preventDefault()
    if (!accessToken || !feedbackRating || isSubmittingFeedback || reservation?.status !== 'checked_out') return
    setIsSubmittingFeedback(true)
    setFeedbackError('')
    try {
      const result = await submitGuestBookingFeedback(accessToken, { rating: feedbackRating, comment: feedbackComment.trim() || undefined })
      setReservation((current) => ({ ...current, feedback: result.data }))
      setFeedbackSuccess(result.message || 'Thanks for your feedback!')
    } catch (requestError) {
      setFeedbackError(requestError?.response?.data?.message || 'Unable to submit feedback right now.')
    } finally {
      setIsSubmittingFeedback(false)
    }
  }

  if (isLoading) {
    return <section className="site-shell page-section"><LoadingState message="Loading your guest booking..." /></section>
  }

  if (error || !reservation) {
    return (
      <section className="site-shell page-section narrow-page guest-booking-error">
        <p className="eyebrow">Guest booking</p>
        <h1>Booking link unavailable</h1>
        <p>{error || 'This guest booking link is invalid or has expired.'}</p>
        <Link className="solid-button" to="/rooms">Book a Stay</Link>
      </section>
    )
  }

  return (
    <section className="site-shell page-section guest-booking-page">
      <div className="guest-booking-header">
        <div>
          <p className="eyebrow">DMD Family Resort</p>
          <h1>{reservation.status === 'cancelled' ? 'Booking Cancelled' : 'Booking Confirmed'}</h1>
          <p>Booking reference: <strong>{reservation.booking_reference}</strong></p>
        </div>
        <Link className="outline-button" to="/rooms">Book Another Stay</Link>
      </div>

      <div className="guest-booking-grid">
        <section className="guest-booking-card guest-booking-stay-card">
          <div className="guest-booking-card-heading">
            <div>
              <p className="eyebrow">Your reservation</p>
              <h2>{reservation.accommodation?.name || 'Resort accommodation'}</h2>
            </div>
            <span className={`guest-booking-status is-${reservation.status}`}>{formatLabel(reservation.status)}</span>
          </div>
          <div className="guest-booking-stay-details">
            <div><span>Check-in</span><strong>{summary?.checkIn || '—'}</strong></div>
            <div><span>Check-out</span><strong>{summary?.checkOut || '—'}</strong></div>
            <div><span>Guests</span><strong>{reservation.guests || 0}</strong></div>
          </div>
        </section>

        <section className="guest-booking-card guest-booking-payment-card">
          <div className="guest-booking-card-heading">
            <div><p className="eyebrow">Payment</p><h2>{formatLabel(reservation.payment_state)}</h2></div>
            <span className="guest-booking-payment-state">{formatLabel(reservation.payment_state)}</span>
          </div>
          <div className="guest-booking-financials">
            <div><span>Total</span><strong>{formatMoney(reservation.total_amount)}</strong></div>
            <div><span>Paid</span><strong>{formatMoney(reservation.total_paid)}</strong></div>
            <div><span>Balance</span><strong>{formatMoney(reservation.balance_due)}</strong></div>
          </div>
          {paymentError ? <div className="form-alert compact-alert" role="alert">{paymentError}</div> : null}
          {isPartiallyPaid ? (
            <button className="solid-button full-width" type="button" onClick={handlePayBalance} disabled={isPaying}>
              {isPaying ? 'Preparing checkout...' : 'Pay Remaining Balance'}
            </button>
          ) : isFullyPaid ? (
            <p className="guest-booking-paid-note">Your reservation is fully paid.</p>
          ) : null}
        </section>
      </div>

      {reservation.status === 'confirmed' && reservation.booking_qr?.payload ? (
        <GuestBookingQr payload={reservation.booking_qr.payload} />
      ) : null}

      {reservation.status === 'checked_out' ? (
        <GuestStayFeedback
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

      {Array.isArray(reservation.payment_history) && reservation.payment_history.length > 0 ? (
        <section className="guest-booking-card guest-booking-history-card">
          <div className="guest-booking-card-heading"><div><p className="eyebrow">Transactions</p><h2>Payment History</h2></div></div>
          <div className="guest-booking-history-list">
            {reservation.payment_history.map((payment) => (
              <div className="guest-booking-history-row" key={payment.id}>
                <div><strong>{formatLabel(payment.purpose)}</strong><span>{formatProvider(payment.provider, payment.payment_method)}</span></div>
                <div><strong>{formatMoney(payment.amount)}</strong><span>{formatLabel(payment.status)}</span></div>
              </div>
            ))}
          </div>
        </section>
      ) : null}

      {isPaymentCancelledModalOpen ? (
        <PaymentCancelledModal
          onClose={() => setIsPaymentCancelledModalOpen(false)}
          onContinue={() => setIsPaymentCancelledModalOpen(false)}
        />
      ) : null}
    </section>
  )
}

function GuestBookingQr({ payload }) {
  const [imageUrl, setImageUrl] = useState('')

  useEffect(() => {
    let mounted = true
    QRCode.toDataURL(payload, {
      width: 320,
      margin: 2,
      errorCorrectionLevel: 'M',
    }).then((url) => {
      if (mounted) setImageUrl(url)
    }).catch(() => {
      if (mounted) setImageUrl('')
    })

    return () => {
      mounted = false
    }
  }, [payload])

  return (
    <section className="guest-booking-card guest-booking-qr-card">
      <div className="guest-booking-card-heading">
        <div>
          <p className="eyebrow">Digital Booking Pass</p>
          <h2>Guest Booking Verification QR</h2>
        </div>
      </div>
      <div className="guest-booking-qr-content">
        {imageUrl ? <img src={imageUrl} alt="Guest booking verification QR code" /> : <span className="guest-booking-qr-loading sr-only" role="status">Preparing QR code...</span>}
        <div>
          <p>Present this QR to the Front Desk when you arrive.</p>
          <small>This QR verifies your live reservation. It does not automatically grant entry.</small>
        </div>
      </div>
    </section>
  )
}

function formatStay(reservation) {
  const format = (value) => {
    if (!value) return null
    const date = new Date(value)
    return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat('en-US', {
      month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
    }).format(date)
  }

  return {
    checkIn: format(reservation.check_in_at) || format(reservation.check_in),
    checkOut: format(reservation.check_out_at) || format(reservation.check_out),
  }
}

function formatMoney(value) {
  return `₱${Number(value ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function formatLabel(value) {
  return String(value || 'Unknown').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
}

function formatProvider(provider, method) {
  if (provider === 'paymongo') return 'PayMongo · Online'
  if (provider === 'manual' && method === 'cash') return 'Cash · Front Desk'
  return formatLabel(provider)
}

const feedbackMoods = [
  ['😡', 'Awful', 1], ['🙁', 'Bad', 2], ['😐', 'Okay', 3], ['🙂', 'Good', 4], ['😍', 'Amazing', 5],
]

function GuestStayFeedback({ feedback, rating, setRating, comment, setComment, error, success, isSubmitting, onSubmit }) {
  if (feedback) {
    const mood = feedbackMoods.find(([, , value]) => value === Number(feedback.rating))
    return <section className="guest-booking-card stay-feedback"><p className="eyebrow">Stay completed ✓</p><p>Feedback submitted ✓</p><strong>Your experience: {mood?.[0]} {mood?.[1]}</strong></section>
  }

  return (
    <section className="guest-booking-card stay-feedback">
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

export default GuestBooking
