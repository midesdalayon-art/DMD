import { useEffect } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { getGuestBookingPaymentStatus, getGuestReservationPaymentStatus, getReservation } from '../lib/api'

const paymentReturnReservationKey = (reservationId) => `dmd-payment-return-reservation:${reservationId}`
const sessionRecoveryMessage = 'We couldn’t verify your booking session. Please return to your booking and try again.'

function readReservationSnapshot(reservationId) {
  try {
    const snapshot = window.sessionStorage.getItem(paymentReturnReservationKey(reservationId))
    return snapshot ? JSON.parse(snapshot) : null
  } catch {
    return null
  }
}

function accommodationPath(accommodation) {
  if (!accommodation?.id) return null

  return accommodation.type === 'exclusive_resort'
    ? `/exclusive-resort/${accommodation.id}`
    : `/rooms/${accommodation.id}`
}

function BookingPaymentCancel() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const reservationId = params.get('reservation_id')

  useEffect(() => {
    let isMounted = true

    async function returnToBooking() {
      if (!reservationId) {
        navigate('/rooms', { replace: true })
        return
      }

      const guestCheckoutToken = window.sessionStorage.getItem(`dmd-guest-checkout-token:${reservationId}`)
      const guestAccessToken = window.sessionStorage.getItem(`dmd-guest-access-token:${reservationId}`)
      const snapshot = readReservationSnapshot(reservationId)

      try {
        if (guestAccessToken) {
          await getGuestBookingPaymentStatus(reservationId, guestAccessToken)
          navigate('/guest/booking', { replace: true, state: { paymentCancelled: true } })
          return
        }

        const result = guestCheckoutToken
          ? await getGuestReservationPaymentStatus(reservationId, guestCheckoutToken)
          : await getReservation(reservationId)
        const reservation = result?.reservation ?? result
        const path = accommodationPath(reservation?.accommodation)

        if (!path) {
          throw new Error('The reservation accommodation could not be found.')
        }

        navigate(path, {
          replace: true,
          state: { paymentCancelledReservation: reservation },
        })
      } catch (requestError) {
        if (!isMounted) return

        const fallbackPath = accommodationPath(snapshot?.accommodation)
        if (fallbackPath) {
          const isTemporaryGuestReservation = fallbackReservation?.guest?.id == null
          const credentialRejected = isTemporaryGuestReservation
            && (!guestCheckoutToken || [401, 403].includes(requestError?.response?.status))
          navigate(fallbackPath, {
            replace: true,
            state: {
              paymentCancelledReservation: snapshot,
              paymentCancelledDescription: credentialRejected ? sessionRecoveryMessage : undefined,
              paymentCancelledActionLabel: credentialRejected ? 'Return to Booking' : 'Continue to Payment',
              paymentCancelledSessionInvalid: credentialRejected,
            },
          })
          return
        }

        // Do not render a standalone error page or trust unverified query
        // parameters when there is no safe accommodation context available.
        navigate('/rooms', { replace: true })
      }
    }

    returnToBooking()

    return () => {
      isMounted = false
    }
  }, [navigate, reservationId])

  return null
}

export default BookingPaymentCancel
