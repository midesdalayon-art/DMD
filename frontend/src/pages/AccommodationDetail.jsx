import LoadingButton from '../components/LoadingButton'
import { ArrowRight, BedDouble, CheckCircle2, ChevronDown, Clock3, Flame, HeartHandshake, LogIn, Sparkles, UserRound, Waves, Wind, Wifi, X } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import EmptyState from '../components/EmptyState'
import AuthModal from '../components/AuthModal'
import { ToastMessage } from '../context/ToastContext'
import GuestStepper from '../components/GuestStepper'
import ImageGallery from '../components/ImageGallery'
import LoadingState from '../components/LoadingState'
import PaymentCancelledModal from '../components/PaymentCancelledModal'
import { useAuth } from '../hooks/useAuth'
import { useResortBranding } from '../hooks/useResortBranding'
import { buildArrivalTimeOptions, formatTimeDisplay } from '../utils/bookingTime'
import {
  createReservation,
  createGuestReservation,
  abandonGuestReservation,
  createPayMongoCheckout,
  createGuestPayMongoCheckout,
  getAccommodation,
} from '../lib/api'

function todayDate() {
  return new Date().toISOString().slice(0, 10)
}

const paymentReturnReservationKey = (reservationId) => `dmd-payment-return-reservation:${reservationId}`

function normalizeGuestInput(value, minimum) {
  const digits = String(value).replace(/\D/g, '')

  if (!digits) {
    return String(minimum)
  }

  return String(Math.max(minimum, Number(digits)))
}

function validateBooking(values, accommodation, arrivalTimeOptions = []) {
  const errors = {}
  const adults = Number(values.adults)
  const children = Number(values.children)
  const infants = Number(values.infants)
  const stayDays = Number(values.stay_days)
  const occupancy = adults + children
  const isFunctionHall = accommodation?.type === 'function_hall'
  const isExclusiveResort = accommodation?.type === 'exclusive_resort'
  const isRoom = accommodation?.type === 'room'
  const isCottage = accommodation?.type === 'cottage'

  if (isFunctionHall || isExclusiveResort) {
    if (!values.event_date) {
      errors.event_date = 'Event date is required.'
    }

    if (!values.stay_days || !Number.isInteger(stayDays) || stayDays < 1) {
      errors.stay_days = 'Days must be at least 1.'
    }
  } else if (isRoom) {
    if (!values.check_in) {
      errors.check_in = 'Check-in date is required.'
    }

    if (!values.check_in_time) {
      errors.check_in_time = 'Check-in time is required.'
    }

    if (!values.stay_days || !Number.isInteger(stayDays) || stayDays < 1) {
      errors.stay_days = 'Length of stay must be at least 1 day.'
    }
  } else if (isCottage) {
    if (!values.check_in) {
      errors.check_in = 'Date is required.'
    }

    if (!values.cottage_period_type || !['day_use', 'overnight'].includes(values.cottage_period_type)) {
      errors.cottage_period_type = 'Choose a valid cottage period.'
    }

    const periodCount = Number(values.cottage_period_count)
    if (!values.cottage_period_count || !Number.isInteger(periodCount) || periodCount < 1) {
      errors.cottage_period_count = 'Periods must be at least 1.'
    }
  } else {
    if (!values.check_in) {
      errors.check_in = 'Check-in date is required.'
    }

    if (!values.check_out) {
      errors.check_out = 'Check-out date is required.'
    }

    if (values.check_in && values.check_out && values.check_out <= values.check_in) {
      errors.check_out = 'Check-out must be after check-in.'
    }
  }

  if (!values.adults || adults < 1) {
    errors.adults = 'At least one adult is required.'
  }

  if (children < 0) {
    errors.children = 'Children cannot be negative.'
  }

  if (infants < 0) {
    errors.infants = 'Infants cannot be negative.'
  }

  if (accommodation?.capacity && occupancy > accommodation.capacity) {
    errors.guests = `This accommodation allows up to ${accommodation.capacity} guests, excluding infants.`
  }

    if (!isFunctionHall && !isExclusiveResort && !isRoom && !isCottage && values.expected_arrival_time && !arrivalTimeOptions.includes(values.expected_arrival_time)) {
      errors.expected_arrival_time = 'Choose a valid arrival time from the resort window.'
    }

    if (!isFunctionHall && !isExclusiveResort && !isRoom && !isCottage && values.expected_departure_time && !arrivalTimeOptions.includes(values.expected_departure_time)) {
      errors.expected_departure_time = 'Choose a valid departure time from the resort window.'
    }

  return errors
}

function isExpiredReservationError(error) {
  const responseErrors = error?.response?.data?.errors ?? {}
  const messages = [
    error?.response?.data?.message,
    ...(Array.isArray(responseErrors.reservation_id) ? responseErrors.reservation_id : [responseErrors.reservation_id]),
    ...(Array.isArray(responseErrors.reservation) ? responseErrors.reservation : [responseErrors.reservation]),
  ].filter(Boolean)

  return messages.some((message) => /reservation.*expired|hold.*expired|expired.*hold/i.test(String(message)))
}

function isAvailabilityConflictError(error) {
  if (error?.response?.status === 409) {
    return true
  }

  const messages = error?.response?.data?.errors?.check_in
  const normalizedMessages = Array.isArray(messages) ? messages : [messages]

  return normalizedMessages
    .filter(Boolean)
    .some((message) => /unavailable for the selected stay length|already booked/i.test(String(message)))
}

function formatSelectedRange(checkIn, checkInTime, stayDays) {
  if (!checkIn || !checkInTime) {
    return null
  }

  const start = new Date(`${checkIn}T${checkInTime}:00`)
  const end = new Date(start)
  end.setHours(end.getHours() + (Number(stayDays || 1) * 24))

  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
    return null
  }

  const formatter = new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })

  return `${formatter.format(start)} → ${formatter.format(end)}`
}

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

const emptyGuestDetails = {
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
}

function validateGuestDetails(details) {
  const guestErrors = {}

  if (!details.first_name.trim()) guestErrors.first_name = 'First name is required.'
  if (!details.last_name.trim()) guestErrors.last_name = 'Last name is required.'
  if (!details.email.trim()) {
    guestErrors.email = 'Email address is required.'
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(details.email.trim())) {
    guestErrors.email = 'Enter a valid email address.'
  }
  if (!details.phone.trim()) {
    guestErrors.phone = 'Contact number is required.'
  } else if (!/^\+?[0-9\s().-]{7,20}$/.test(details.phone.trim())) {
    guestErrors.phone = 'Enter a valid contact number.'
  }

  return guestErrors
}

function AccommodationDetail() {
  const { id } = useParams()
  const location = useLocation()
  const navigate = useNavigate()
  const { isAuthenticated, user } = useAuth()
  const { publicSettings } = useResortBranding()
  const [accommodation, setAccommodation] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  const [availabilityConflict, setAvailabilityConflict] = useState(null)
  const [values, setValues] = useState({
    check_in: '',
    check_out: '',
    check_in_time: '',
    stay_days: '1',
    cottage_period_type: 'day_use',
    cottage_period_count: '1',
    event_date: '',
    adults: '1',
    children: '0',
    infants: '0',
    expected_arrival_time: '',
    expected_departure_time: '',
    guests: '1',
  })
  const [errors, setErrors] = useState({})
  const [bookingError, setBookingError] = useState('')
  const [successMessage, setSuccessMessage] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [pendingReservation, setPendingReservation] = useState(null)
  const [paymentChoice, setPaymentChoice] = useState(null)
  const [isPendingPaymentModalOpen, setIsPendingPaymentModalOpen] = useState(false)
  const [isPaymentCancelledModalOpen, setIsPaymentCancelledModalOpen] = useState(false)
  const [paymentCancelledDescription, setPaymentCancelledDescription] = useState('')
  const [paymentCancelledActionLabel, setPaymentCancelledActionLabel] = useState('Continue to Payment')
  const [paymentCancelledSessionInvalid, setPaymentCancelledSessionInvalid] = useState(false)
  const [isStartingPayment, setIsStartingPayment] = useState(false)
  const [isAbandoningPendingReservation, setIsAbandoningPendingReservation] = useState(false)
  const [paymentError, setPaymentError] = useState('')
  const [isPaymentHoldExpired, setIsPaymentHoldExpired] = useState(false)
  const [guestChoiceOpen, setGuestChoiceOpen] = useState(false)
  const [guestInfoOpen, setGuestInfoOpen] = useState(false)
  const [guestDetails, setGuestDetails] = useState(emptyGuestDetails)
  const [guestErrors, setGuestErrors] = useState({})
  const [isGuestReservation, setIsGuestReservation] = useState(false)
  const [guestCheckoutToken, setGuestCheckoutToken] = useState(null)
  const [isRulesExpanded, setIsRulesExpanded] = useState(false)
  const [authModalMode, setAuthModalMode] = useState(null)
  const authButtonRef = useRef(null)
  const bookingSettings = publicSettings?.booking ?? {}
  const accommodationType = accommodation?.type
  const detailBackLink = {
    room: { to: '/rooms', label: 'rooms' },
    cottage: { to: '/rooms?type=cottage', label: 'cottages' },
    function_hall: { to: '/rooms?type=function_hall', label: 'function halls' },
    exclusive_resort: { to: '/exclusive-resort', label: 'exclusive resort' },
  }[accommodationType] ?? { to: '/rooms', label: 'rooms' }
  const isFunctionHall = accommodationType === 'function_hall'
  const isExclusiveResort = accommodationType === 'exclusive_resort'
  const isRoom = accommodationType === 'room'
  const isCottage = accommodationType === 'cottage'
  const formattedDescription = useMemo(() => formatDescription(accommodation?.description), [accommodation?.description])
  const houseRules = useMemo(
    () => buildHouseRules(bookingSettings, accommodationType),
    [bookingSettings, accommodationType],
  )
  const arrivalTimeOptions = useMemo(() => buildArrivalTimeOptions(bookingSettings), [bookingSettings])
  const officialCheckInTime = formatTimeDisplay(bookingSettings.house_rules_check_in_time, '2:00 PM')
  const officialCheckOutTime = formatTimeDisplay(bookingSettings.house_rules_check_out_time, '12:00 PM')

  useEffect(() => {
    const restoredReservation = location.state?.paymentCancelledReservation
    if (!restoredReservation || String(restoredReservation.accommodation?.id) !== String(id)) {
      return
    }

    const guestCheckoutToken = window.sessionStorage.getItem(`dmd-guest-checkout-token:${restoredReservation.id}`)
    const expiresAt = restoredReservation.expires_at ? Date.parse(restoredReservation.expires_at) : NaN
    const holdIsValid = restoredReservation.status === 'pending'
      && (!Number.isFinite(expiresAt) || expiresAt > Date.now())

    setPendingReservation(restoredReservation)
    setIsGuestReservation(Boolean(guestCheckoutToken))
    setGuestCheckoutToken(guestCheckoutToken)
    setIsPaymentHoldExpired(!holdIsValid)
    setPaymentChoice(null)
    setIsPendingPaymentModalOpen(false)
    setPaymentError('')
    setPaymentCancelledDescription(location.state?.paymentCancelledDescription ?? '')
    setPaymentCancelledActionLabel(location.state?.paymentCancelledActionLabel ?? 'Continue to Payment')
    setPaymentCancelledSessionInvalid(Boolean(location.state?.paymentCancelledSessionInvalid))
    setIsPaymentCancelledModalOpen(holdIsValid)

    navigate(location.pathname, { replace: true, state: null })
  }, [id, location.pathname, location.state, navigate])

  useEffect(() => {
    if (!pendingReservation) {
      setIsPaymentHoldExpired(false)
      return undefined
    }

    const updateExpiredState = () => {
      const statusIsExpired = pendingReservation.status === 'expired'
      const expiresAt = pendingReservation.expires_at ? Date.parse(pendingReservation.expires_at) : NaN
      const deadlineHasPassed = Number.isFinite(expiresAt) && expiresAt <= Date.now()

      setIsPaymentHoldExpired(statusIsExpired || deadlineHasPassed)
    }

    updateExpiredState()

    if (!pendingReservation.expires_at || pendingReservation.status === 'expired') {
      return undefined
    }

    const intervalId = window.setInterval(updateExpiredState, 1000)
    return () => window.clearInterval(intervalId)
  }, [pendingReservation])

  useEffect(() => {
    if (isPendingPaymentModalOpen && isPaymentHoldExpired && pendingReservation) {
      setIsPendingPaymentModalOpen(false)
      setPaymentChoice(pendingReservation)
    }
  }, [isPaymentHoldExpired, isPendingPaymentModalOpen, pendingReservation])

  useEffect(() => {
    let isMounted = true

    async function loadAccommodation() {
      setIsLoading(true)
      setLoadError('')

      try {
        const data = await getAccommodation(id)
        if (isMounted) {
          setAccommodation(data)
          const draft = readBookingDraft(id)
          setValues((current) => ({
            ...current,
            ...(draft ?? {}),
            adults: draft?.adults ?? '1',
            event_date: draft?.event_date ?? '',
            check_in_time: draft?.check_in_time ?? '',
            stay_days: draft?.stay_days ?? '1',
            cottage_period_type: draft?.cottage_period_type ?? 'day_use',
            cottage_period_count: draft?.cottage_period_count ?? '1',
            expected_arrival_time: draft?.expected_arrival_time ?? '',
            expected_departure_time: draft?.expected_departure_time ?? '',
            guests: draft?.guests ?? '1',
          }))
        }
      } catch (error) {
        if (isMounted) {
          setLoadError(
            error?.response?.status === 404
              ? 'This accommodation is not available.'
              : 'Unable to load this accommodation right now.',
          )
        }
      } finally {
        if (isMounted) {
          setIsLoading(false)
        }
      }
    }

    loadAccommodation()

    return () => {
      isMounted = false
    }
  }, [id])

  const nights = useMemo(() => {
    if (isFunctionHall || isExclusiveResort) {
      return values.event_date ? Math.max(1, Number(values.stay_days || 1)) : 0
    }

    if (isRoom) {
      return values.check_in && values.check_in_time ? Math.max(1, Number(values.stay_days || 1)) : 0
    }

    if (isCottage) {
      return values.check_in && values.cottage_period_type ? Math.max(1, Number(values.cottage_period_count || 1)) : 0
    }

    if (!values.check_in || !values.check_out || values.check_out <= values.check_in) {
      return 0
    }

    const checkIn = new Date(values.check_in)
    const checkOut = new Date(values.check_out)

    return Math.round((checkOut - checkIn) / 86400000)
  }, [isFunctionHall, isExclusiveResort, isRoom, isCottage, values.check_in, values.check_out, values.check_in_time, values.event_date, values.stay_days, values.cottage_period_type, values.cottage_period_count])

  const estimatedTotal = useMemo(() => {
    if (!accommodation || nights <= 0) {
      return null
    }

    return accommodation.price_per_night * nights
  }, [accommodation, nights])

  const occupancy = (isFunctionHall || isExclusiveResort)
    ? Number(values.guests || 0)
    : Number(values.adults || 0) + Number(values.children || 0)
  const assignedAmenities = Array.isArray(accommodation?.amenities)
    ? accommodation.amenities.filter((amenity) => amenity && (amenity.name || typeof amenity === 'string'))
    : []
  const rateLabel = formatRate(accommodation?.price_per_night)
  const roomCheckoutPreview = useMemo(() => {
    if (!isRoom || !values.check_in || !values.check_in_time) {
      return null
    }

    const preview = new Date(`${values.check_in}T${values.check_in_time}:00`)

    if (Number.isNaN(preview.getTime())) {
      return null
    }

    preview.setHours(preview.getHours() + (Number(values.stay_days || 1) * 24))

    return preview
  }, [isRoom, values.check_in, values.check_in_time, values.stay_days])

  const roomStayDays = Math.max(1, Number(values.stay_days || 1))
  const roomTotalLabel = useMemo(
    () => formatMoney((accommodation?.price_per_night ?? 0) * roomStayDays),
    [accommodation?.price_per_night, roomStayDays],
  )
  const roomCheckoutDate = useMemo(() => {
    if (!roomCheckoutPreview) {
      return null
    }

    return new Intl.DateTimeFormat('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    }).format(roomCheckoutPreview)
  }, [roomCheckoutPreview])
  const roomCheckoutTime = useMemo(() => {
    if (!roomCheckoutPreview) {
      return null
    }

    return new Intl.DateTimeFormat('en-US', {
      hour: 'numeric',
      minute: '2-digit',
    }).format(roomCheckoutPreview)
  }, [roomCheckoutPreview])

  const functionHallDays = Math.max(1, Number(values.stay_days || 1))
  const functionHallReservedRange = useMemo(() => {
    if (!(isFunctionHall || isExclusiveResort) || !values.event_date) {
      return null
    }

    const start = new Date(`${values.event_date}T00:00:00`)

    if (Number.isNaN(start.getTime())) {
      return null
    }

    const end = new Date(start)
    end.setDate(end.getDate() + (functionHallDays - 1))

    return { start, end }
  }, [isFunctionHall, isExclusiveResort, values.event_date, functionHallDays])

  const functionHallReservedStart = useMemo(() => {
    if (!functionHallReservedRange) {
      return null
    }

    return new Intl.DateTimeFormat('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    }).format(functionHallReservedRange.start)
  }, [functionHallReservedRange])

  const functionHallReservedEnd = useMemo(() => {
    if (!functionHallReservedRange) {
      return null
    }

    return new Intl.DateTimeFormat('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    }).format(functionHallReservedRange.end)
  }, [functionHallReservedRange])

  const functionHallTotalLabel = useMemo(
    () => formatMoney((accommodation?.price_per_night ?? 0) * functionHallDays),
    [accommodation?.price_per_night, functionHallDays],
  )

  const cottagePeriodCount = Math.max(1, Number(values.cottage_period_count || 1))
  const cottageCheckoutPreview = useMemo(() => {
    if (!isCottage || !values.check_in || !values.cottage_period_type) {
      return null
    }

    const startTime = values.cottage_period_type === 'overnight' ? '18:00' : '06:00'
    const preview = new Date(`${values.check_in}T${startTime}:00`)

    if (Number.isNaN(preview.getTime())) {
      return null
    }

    preview.setHours(preview.getHours() + (cottagePeriodCount * 12))

    return preview
  }, [isCottage, values.check_in, values.cottage_period_type, cottagePeriodCount])
  const cottageTotalLabel = useMemo(
    () => formatMoney((accommodation?.price_per_night ?? 0) * cottagePeriodCount),
    [accommodation?.price_per_night, cottagePeriodCount],
  )
  const cottageCheckoutDate = useMemo(() => {
    if (!cottageCheckoutPreview) {
      return null
    }

    return new Intl.DateTimeFormat('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    }).format(cottageCheckoutPreview)
  }, [cottageCheckoutPreview])
  const cottageCheckoutTime = useMemo(() => {
    if (!cottageCheckoutPreview) {
      return null
    }

    return new Intl.DateTimeFormat('en-US', {
      hour: 'numeric',
      minute: '2-digit',
    }).format(cottageCheckoutPreview)
  }, [cottageCheckoutPreview])
  function updateField(event) {
    const { name, value } = event.target
  const nextValue =
      name === 'adults' || name === 'guests' || name === 'stay_days' || name === 'cottage_period_count'
        ? normalizeGuestInput(value, 1)
        : name === 'children' || name === 'infants'
          ? normalizeGuestInput(value, 0)
          : value

    setValues((current) => ({
      ...current,
      [name]: nextValue,
    }))
    setErrors((current) => ({
      ...current,
      [name]: '',
    }))
    setBookingError('')
    setSuccessMessage('')
    setAvailabilityConflict(null)
  }

  function buildReservationPayload() {
    return (isFunctionHall || isExclusiveResort)
      ? {
          accommodation_id: accommodation.id,
          event_date: values.event_date,
          stay_days: Number(values.stay_days || 1),
          guests: Number(values.guests),
        }
      : isRoom
        ? {
            accommodation_id: accommodation.id,
            check_in: values.check_in,
            check_in_time: values.check_in_time,
            stay_days: Number(values.stay_days || 1),
            adults: Number(values.adults),
            children: Number(values.children),
            infants: Number(values.infants),
            guests: occupancy,
          }
        : isCottage
          ? {
              accommodation_id: accommodation.id,
              check_in: values.check_in,
              cottage_period_type: values.cottage_period_type,
              cottage_period_count: Number(values.cottage_period_count || 1),
              adults: Number(values.adults),
              children: Number(values.children),
              infants: Number(values.infants),
              guests: occupancy,
            }
          : {
              accommodation_id: accommodation.id,
              check_in: values.check_in,
              check_out: values.check_out,
              adults: Number(values.adults),
              children: Number(values.children),
              infants: Number(values.infants),
              expected_arrival_time: values.expected_arrival_time || undefined,
              expected_departure_time: values.expected_departure_time || undefined,
              guests: occupancy,
            }
  }

  async function submitReservation(guestReservationDetails = null) {
    if (isSubmitting) {
      return
    }

    setIsSubmitting(true)
    setBookingError('')
    setSuccessMessage('')
    setAvailabilityConflict(null)
    // A new reservation must always start a fresh payment flow. This also
    // clears any expired reservation state left by the previous hold.
    setPaymentChoice(null)
    setIsPendingPaymentModalOpen(false)
    setPendingReservation(null)
    setPaymentError('')
    setIsPaymentHoldExpired(false)
    setIsStartingPayment(false)

    try {
      const payload = buildReservationPayload()
      const result = guestReservationDetails
        ? await createGuestReservation({ ...payload, ...guestReservationDetails })
        : await createReservation(payload)
      setPaymentError('')
      setIsPaymentHoldExpired(false)
      setPendingReservation(result.reservation)
      window.sessionStorage.setItem(
        paymentReturnReservationKey(result.reservation.id),
        JSON.stringify(result.reservation),
      )
      setIsGuestReservation(Boolean(guestReservationDetails))
      setGuestCheckoutToken(result.guestCheckoutToken ?? null)
      if (guestReservationDetails && result.guestCheckoutToken) {
        window.sessionStorage.setItem(`dmd-guest-checkout-token:${result.reservation.id}`, result.guestCheckoutToken)
      }
      if (guestReservationDetails) {
        setIsPendingPaymentModalOpen(true)
      } else {
        setPaymentChoice(result.reservation)
      }
      setGuestChoiceOpen(false)
      setGuestInfoOpen(false)

      setSuccessMessage(`${result.message} Reference: ${result.reservation.booking_reference}`)
      clearBookingDraft(id)
    } catch (error) {
      if (error?.response?.status === 422 || error?.response?.status === 409) {
        const serverErrors = extractServerErrors(error)

        if (isAvailabilityConflictError(error)) {
          const nonAvailabilityErrors = Object.fromEntries(
            Object.entries(serverErrors).filter(([field]) => field !== 'check_in'),
          )
          setErrors(nonAvailabilityErrors)
          setBookingError('')
          setAvailabilityConflict({
            accommodationName: accommodation?.name || 'This accommodation',
            selectedRange: formatSelectedRange(values.check_in, values.check_in_time, values.stay_days),
            wasSecuredByAnotherGuest: error?.response?.status === 409,
          })
          setGuestChoiceOpen(false)
          setGuestInfoOpen(false)
        } else {
          setErrors(serverErrors)
          setGuestErrors(serverErrors)
          setBookingError(
            error?.response?.data?.message
            || 'This reservation cannot be submitted. Please review the details.',
          )
        }
      } else if (error?.response?.status === 401) {
        setAuthModalMode('signin')
      } else {
        setBookingError('Unable to submit the reservation right now. Please try again.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  async function handleBookingSubmit(event) {
    event.preventDefault()

    const nextErrors = validateBooking(values, accommodation, arrivalTimeOptions)
    setErrors(nextErrors)
    setBookingError('')
    setSuccessMessage('')

    if (Object.keys(nextErrors).length > 0) {
      return
    }

    if (pendingReservation && !isPaymentHoldExpired) {
      if (isGuestReservation) {
        setIsPendingPaymentModalOpen(true)
      } else if (isAuthenticated) {
        setPaymentChoice(pendingReservation)
        setPaymentError('')
      }
      return
    }

    if (!isAuthenticated) {
      saveBookingDraft(id, values)
      setGuestChoiceOpen(true)
      setGuestInfoOpen(false)
      setGuestDetails(emptyGuestDetails)
      setGuestErrors({})
      setBookingError('')
      return
    }

    if (user?.role !== 'guest') {
      setBookingError('Only guest accounts can submit reservations from the public website.')
      return
    }

    await submitReservation()
  }

  function handleContinueAsGuest() {
    setGuestChoiceOpen(false)
    setGuestInfoOpen(true)
    setGuestErrors({})
    setBookingError('')
  }

  function handleSignInFromGuestChoice() {
    setGuestChoiceOpen(false)
    setGuestDetails(emptyGuestDetails)
    setGuestErrors({})
    setAuthModalMode('signin')
  }

  function updateGuestDetail(event) {
    const { name, value } = event.target
    setGuestDetails((current) => ({ ...current, [name]: value }))
    setGuestErrors((current) => ({ ...current, [name]: '' }))
    setBookingError('')
  }

  async function handleGuestReservationSubmit(event) {
    event.preventDefault()

    const nextErrors = validateGuestDetails(guestDetails)
    setGuestErrors(nextErrors)
    setBookingError('')

    if (Object.keys(nextErrors).length > 0) {
      return
    }

    await submitReservation({
      first_name: guestDetails.first_name.trim(),
      last_name: guestDetails.last_name.trim(),
      email: guestDetails.email.trim(),
      phone: guestDetails.phone.trim(),
    })
  }

  async function handlePaymentChoice(purpose) {
    if (!paymentChoice || isPaymentHoldExpired || isStartingPayment) {
      return
    }

    setIsStartingPayment(true)
    setPaymentError('')

    try {
      const checkout = isGuestReservation
        ? await createGuestPayMongoCheckout(paymentChoice.id, purpose, guestCheckoutToken)
        : await createPayMongoCheckout(paymentChoice.id, purpose)

      if (checkout?.payment?.checkout_url) {
        window.location.href = checkout.payment.checkout_url
        return
      }

      setPaymentError('Unable to prepare the payment checkout right now.')
    } catch (error) {
      if (isExpiredReservationError(error)) {
        setIsPaymentHoldExpired(true)
        setPendingReservation((current) => current ? { ...current, status: 'expired' } : current)
        setPaymentError('This reservation hold has expired. Please create a new booking.')
      } else {
        setPaymentError(
          error?.response?.data?.message
          || 'Unable to prepare the payment checkout right now. Please try again.',
        )
      }
    } finally {
      setIsStartingPayment(false)
    }
  }

  async function handleClosePendingPaymentFlow() {
    if (!pendingReservation || !isGuestReservation || isAbandoningPendingReservation || isStartingPayment) {
      return
    }

    setIsAbandoningPendingReservation(true)
    setPaymentError('')

    try {
      await abandonGuestReservation(pendingReservation.id, guestCheckoutToken)
      window.sessionStorage.removeItem(`dmd-guest-checkout-token:${pendingReservation.id}`)
      window.sessionStorage.removeItem(paymentReturnReservationKey(pendingReservation.id))
      setIsPendingPaymentModalOpen(false)
      setPaymentChoice(null)
      setPendingReservation(null)
      setIsPaymentHoldExpired(false)
      setIsGuestReservation(false)
      setGuestCheckoutToken(null)
      setGuestDetails(emptyGuestDetails)
      setGuestErrors({})
      setGuestChoiceOpen(false)
      setGuestInfoOpen(false)
      setBookingError('')
      setPaymentError('')
      setSuccessMessage('')
    } catch (error) {
      setPaymentError(
        error?.response?.data?.message
        || 'Unable to close this pending reservation right now. Please try again.',
      )
    } finally {
      setIsAbandoningPendingReservation(false)
    }
  }

  function handleContinueAfterPaymentCancellation() {
    setIsPaymentCancelledModalOpen(false)
    if (paymentCancelledSessionInvalid) {
      setPaymentCancelledDescription('')
      setPaymentCancelledActionLabel('Continue to Payment')
      setPaymentCancelledSessionInvalid(false)
      return
    }

    if (pendingReservation && !isPaymentHoldExpired) {
      setPaymentChoice(pendingReservation)
      setPaymentError('')
    }
  }

  function handleCreateNewBooking() {
    if (isStartingPayment || isSubmitting) {
      return
    }

    setPaymentChoice(null)
    setIsPendingPaymentModalOpen(false)
    setPendingReservation(null)
    setPaymentError('')
    setIsPaymentHoldExpired(false)
    setIsStartingPayment(false)
    setIsGuestReservation(false)
    setGuestCheckoutToken(null)
    setGuestDetails(emptyGuestDetails)
    setGuestErrors({})
    setGuestChoiceOpen(false)
    setGuestInfoOpen(false)
    setSuccessMessage('')
  }

  function focusCheckInControls() {
    setAvailabilityConflict(null)

    window.requestAnimationFrame(() => {
      const checkInTime = document.getElementById('check_in_time')
      const checkInDate = document.getElementById('check_in')
      const target = checkInTime || checkInDate

      target?.scrollIntoView({ behavior: 'smooth', block: 'center' })
      target?.focus()
    })
  }

  if (isLoading) {
    return (
      <section className="accommodation-detail-loading-screen" aria-label="Loading accommodation">
        <LoadingState message="Loading accommodation..." />
      </section>
    )
  }

  if (loadError || !accommodation) {
    return (
      <section className="site-shell page-section">
        <Link className="back-link" to="/rooms">
          Back to rooms
        </Link>
        <EmptyState title="Accommodation not found" message={loadError} />
      </section>
    )
  }

  return (
    <section className="site-shell detail-page">
      <header className="detail-header">
        <Link className="back-link" to={detailBackLink.to}>
          {/*
          ← Back to rooms
          */}
          &larr; Back to {detailBackLink.label}
        </Link>

        <h1>{accommodation.name}</h1>
      </header>

      <div className="detail-layout">
        <article className="detail-content">
          <ImageGallery
            className={isCottage ? 'cottage-detail-gallery' : undefined}
            imagePath={accommodation.primary_image_url ?? accommodation.image_path}
            images={accommodation.gallery_images ?? accommodation.images ?? []}
            title={accommodation.name}
          />

          <section>
            <h2>Description</h2>
            <p>{formattedDescription}</p>
          </section>

          {assignedAmenities.length > 0 ? (
            <section>
              <h2>What this accommodation offers</h2>
              <ul className="amenities-list">
                {assignedAmenities.map((amenity) => {
                  const amenityName = typeof amenity === 'string' ? amenity : amenity.name

                  return (
                    <li key={typeof amenity === 'string' ? amenity : amenity.id}>
                      {getAmenityIcon(amenityName)}
                      <span>{amenityName}</span>
                    </li>
                  )
                })}
              </ul>
            </section>
          ) : null}

          <section className="house-rules-section">
            <div className="house-rules-header">
              <h2>House rules</h2>
              <p>Simple resort guidelines for a safe, comfortable stay.</p>
            </div>
            <ul className="house-rules-list" aria-label="House rules">
              {houseRules.primary.map((rule) => (
                <li key={rule.label} className="house-rule-item">
                  <rule.icon aria-hidden="true" />
                  <span>{rule.label}</span>
                </li>
              ))}
            </ul>
            <div className={`house-rules-more ${isRulesExpanded ? 'is-open' : ''}`}>
              <ul className="house-rules-list is-secondary">
                {houseRules.secondary.map((rule) => (
                  <li key={rule.label} className="house-rule-item">
                    <rule.icon aria-hidden="true" />
                    <span>{rule.label}</span>
                  </li>
                ))}
              </ul>
            </div>
            <button
              className="house-rules-toggle"
              type="button"
              onClick={() => setIsRulesExpanded((current) => !current)}
              aria-expanded={isRulesExpanded}
            >
              {isRulesExpanded ? 'Show fewer' : 'Show all rules'}
              <ArrowRight aria-hidden="true" />
            </button>
          </section>
        </article>

        <aside className="booking-panel room-booking-panel function-hall-booking-panel" aria-label={isExclusiveResort ? 'Exclusive Resort reservation form' : 'Function Hall reservation form'}>
          <header className={(isFunctionHall || isExclusiveResort) ? 'function-hall-rate-header' : 'room-booking-rate-header'}>
            <p className="booking-rate-label">{(isFunctionHall || isExclusiveResort) ? 'Daily rate' : 'Rate'}</p>
            <strong className="booking-rate">
              <span className="booking-rate-value">{rateLabel}</span>
              <span className="booking-rate-unit">
                {(isFunctionHall || isExclusiveResort) ? ' / day' : isRoom ? ' / 24 hours' : ' / 12-hour period'}
              </span>
            </strong>
            <span className="booking-rate-note">
              {(isFunctionHall || isExclusiveResort)
                ? 'Reserve for one or more whole days.'
                : isRoom
                  ? 'One fixed 24-hour stay.'
                : 'Day: 6 AM–6 PM · Overnight: 6 PM–6 AM'}
            </span>
          </header>

          <form
            className={[
              'reservation-form',
              'room-reservation-form',
              (isFunctionHall || isExclusiveResort) ? 'function-hall-reservation-form' : '',
              isCottage ? 'cottage-booking-panel' : '',
            ].filter(Boolean).join(' ')}
            noValidate
            onSubmit={handleBookingSubmit}
          >
            {(isFunctionHall || isExclusiveResort) ? (
              <>
                <section className="function-hall-booking-section">
                  <div className="room-booking-section-heading">
                    <span className="room-booking-step">
                      <span className="room-booking-step-badge">1</span>
                      <span>{isExclusiveResort ? 'Start date' : 'Start date'}</span>
                    </span>
                  </div>
                  <label className="room-booking-field function-hall-date-field" htmlFor="event_date">
                    <span className="sr-only">Start Date</span>
                    <input
                      id="event_date"
                      name="event_date"
                      type="date"
                      min={todayDate()}
                      value={values.event_date}
                      onChange={updateField}
                      aria-invalid={Boolean(errors.event_date)}
                      aria-describedby={errors.event_date ? 'event-date-error' : undefined}
                      disabled={isSubmitting}
                      required
                    />
                    <small className="field-hint">Whole-day reservation</small>
                    {errors.event_date ? <small id="event-date-error" className="field-error">{errors.event_date}</small> : null}
                  </label>
                </section>

                <section className="function-hall-booking-section">
                  <div className="room-booking-section-heading">
                    <span className="room-booking-step">
                      <span className="room-booking-step-badge">2</span>
                      <span>Length of rental</span>
                    </span>
                  </div>
                  <div className="function-hall-guest-row function-hall-days-row">
                    <span className="room-booking-row-label">Days</span>
                    <GuestStepper
                      label="Days"
                      value={values.stay_days}
                      min={1}
                      hideLabel
                      onChange={(nextValue) =>
                        updateField({
                          target: {
                            name: 'stay_days',
                            value: nextValue,
                          },
                        })
                      }
                      disabled={isSubmitting}
                      ariaDescribedBy={errors.stay_days ? 'stay-days-error' : undefined}
                      decreaseLabel="Decrease days"
                      increaseLabel="Increase days"
                    />
                  </div>
                  {errors.stay_days ? <small id="stay-days-error" className="field-error">{errors.stay_days}</small> : null}
                </section>

                <section className="function-hall-booking-section">
                  <div className="room-booking-section-heading">
                    <span className="room-booking-step">
                      <span className="room-booking-step-badge">3</span>
                      <span>Reserved dates</span>
                    </span>
                  </div>
                  <div className="function-hall-reserved-card" aria-label="Reserved dates">
                    {functionHallReservedStart && functionHallReservedEnd ? (
                      <>
                        <span className="function-hall-reserved-range">
                          📅 {functionHallReservedStart}
                          {functionHallReservedStart === functionHallReservedEnd ? '' : ` – ${functionHallReservedEnd}`}
                        </span>
                        <small className="function-hall-reserved-note">
                          ✓ {functionHallDays} whole day{functionHallDays === 1 ? '' : 's'}
                        </small>
                      </>
                    ) : (
                      <>
                        <span className="function-hall-reserved-empty">Select a start date to see reserved dates.</span>
                        <small className="function-hall-reserved-note">Consecutive whole days</small>
                      </>
                    )}
                  </div>
                </section>

                <section className="function-hall-booking-section">
                  <div className="room-booking-section-heading">
                    <span className="room-booking-step">
                      <span className="room-booking-step-badge">4</span>
                      <span>Guests</span>
                    </span>
                  </div>
                  <div className="function-hall-guest-row">
                    <span className="room-booking-row-label">Guests</span>
                    <GuestStepper
                      label="Guests"
                      value={values.guests}
                      min={1}
                      max={accommodation.capacity}
                      hideLabel
                      onChange={(nextValue) =>
                        updateField({
                          target: {
                            name: 'guests',
                            value: nextValue,
                          },
                        })
                      }
                      disabled={isSubmitting}
                      ariaDescribedBy={errors.guests ? 'guests-error' : undefined}
                    />
                  </div>
                  {errors.guests ? <small id="guests-error" className="field-error">{errors.guests}</small> : null}
                </section>
              </>
            ) : isCottage ? (
              <>
                <section className="room-booking-section">
                  <div className="room-booking-section-heading">
                    <span className="room-booking-step">
                      <span className="room-booking-step-badge">1</span>
                      <span>Select date</span>
                    </span>
                  </div>

                  <div className="room-booking-input-list">
                    <label className="room-booking-field" htmlFor="check_in">
                      <span>Check-in date</span>
                      <div className="input-with-icon">
                        <input
                          id="check_in"
                          name="check_in"
                          type="date"
                          min={todayDate()}
                          value={values.check_in}
                          onChange={updateField}
                          aria-invalid={Boolean(errors.check_in)}
                          aria-describedby={errors.check_in ? 'check-in-error' : undefined}
                          disabled={isSubmitting}
                          required
                        />
                      </div>
                      {errors.check_in ? <small id="check-in-error" className="field-error">{errors.check_in}</small> : null}
                    </label>
                  </div>
                </section>

                <section className="room-booking-section">
                  <div className="room-booking-section-heading">
                    <span className="room-booking-step">
                      <span className="room-booking-step-badge">2</span>
                      <span>Select period</span>
                    </span>
                  </div>

                  <div className="cottage-period-options" role="radiogroup" aria-label="Cottage rental period">
                    {[
                      {
                        value: 'day_use',
                        label: 'Day Use',
                        range: '6:00 AM – 6:00 PM',
                      },
                      {
                        value: 'overnight',
                        label: 'Overnight',
                        range: '6:00 PM – 6:00 AM',
                      },
                    ].map((option) => (
                      <label key={option.value} className={`cottage-period-option ${values.cottage_period_type === option.value ? 'is-selected' : ''}`}>
                        <input
                          type="radio"
                          name="cottage_period_type"
                          value={option.value}
                          checked={values.cottage_period_type === option.value}
                          onChange={updateField}
                          disabled={isSubmitting}
                        />
                        <span>
                          <strong>{option.label}</strong>
                          <small>{option.range}</small>
                        </span>
                      </label>
                    ))}
                  </div>
                  {errors.cottage_period_type ? <small className="field-error">{errors.cottage_period_type}</small> : null}
                </section>

                <section className="room-booking-section">
                  <div className="room-booking-section-heading">
                    <span className="room-booking-step">
                      <span className="room-booking-step-badge">3</span>
                      <span>Length of stay</span>
                    </span>
                  </div>

                  <div className="room-booking-stay-row">
                    <span className="room-booking-row-label">Periods</span>
                    <GuestStepper
                      label="Periods"
                      value={values.cottage_period_count}
                      min={1}
                      hideLabel
                      onChange={(nextValue) =>
                        updateField({
                          target: {
                            name: 'cottage_period_count',
                            value: nextValue,
                          },
                        })
                      }
                      disabled={isSubmitting}
                      ariaDescribedBy={errors.cottage_period_count ? 'cottage-periods-error' : undefined}
                      decreaseLabel="Decrease periods"
                      increaseLabel="Increase periods"
                    />
                  </div>
                  {errors.cottage_period_count ? <small id="cottage-periods-error" className="field-error">{errors.cottage_period_count}</small> : null}
                </section>

                <section className="room-booking-section">
                  <div className="room-booking-section-heading">
                    <span className="room-booking-step">
                      <span className="room-booking-step-badge">4</span>
                      <span>Check-out</span>
                    </span>
                  </div>

                    <div className="room-booking-checkout-card" aria-label="Calculated checkout">
                    <div className="room-booking-checkout-card-inner">
                      {cottageCheckoutDate && cottageCheckoutTime ? (
                        <div className="room-booking-checkout-topline">
                          <span className="room-booking-checkout-value room-booking-checkout-date">📅 {cottageCheckoutDate}</span>
                          <span className="room-booking-checkout-divider" aria-hidden="true" />
                          <span className="room-booking-checkout-value room-booking-checkout-time">🕒 {cottageCheckoutTime}</span>
                        </div>
                      ) : (
                        <div className="room-booking-checkout-topline">
                          <span className="room-booking-checkout-empty">Select a date to calculate checkout.</span>
                        </div>
                      )}
                      <small>{cottageCheckoutPreview ? `✓ Automatically calculated · ${cottagePeriodCount} period${cottagePeriodCount === 1 ? '' : 's'} / ${cottagePeriodCount * 12} hours` : '12-hour rental periods'}</small>
                    </div>
                  </div>
                </section>

                <section className="room-booking-section">
                  <div className="room-booking-section-heading">
                    <span className="room-booking-step">
                      <span className="room-booking-step-badge">5</span>
                      <span>Guests</span>
                    </span>
                  </div>

                  <div className="room-booking-guest-list">
                    <div className="room-booking-guest-row">
                      <span className="room-booking-row-label">Adults</span>
                      <GuestStepper
                        label="Adults"
                        value={values.adults}
                        min={1}
                        max={accommodation.capacity}
                        hideLabel
                        onChange={(nextValue) =>
                          updateField({
                            target: {
                              name: 'adults',
                              value: nextValue,
                            },
                          })
                        }
                        disabled={isSubmitting}
                        ariaDescribedBy={errors.adults ? 'adults-error' : undefined}
                        decreaseLabel="Decrease adults"
                        increaseLabel="Increase adults"
                      />
                    </div>
                    {errors.adults ? <small id="adults-error" className="field-error">{errors.adults}</small> : null}

                    <div className="room-booking-guest-row">
                      <span className="room-booking-row-label">Children</span>
                      <GuestStepper
                        label="Children"
                        value={values.children}
                        min={0}
                        max={accommodation.capacity}
                        hideLabel
                        onChange={(nextValue) =>
                          updateField({
                            target: {
                              name: 'children',
                              value: nextValue,
                            },
                          })
                        }
                        disabled={isSubmitting}
                        ariaDescribedBy={errors.children || errors.guests ? 'children-error' : undefined}
                        decreaseLabel="Decrease children"
                        increaseLabel="Increase children"
                      />
                    </div>
                    {errors.children ? <small id="children-error" className="field-error">{errors.children}</small> : null}

                    <div className="room-booking-guest-row">
                      <span className="room-booking-row-label">Infants</span>
                      <GuestStepper
                        label="Infants"
                        value={values.infants}
                        min={0}
                        max={accommodation.capacity}
                        hideLabel
                        onChange={(nextValue) =>
                          updateField({
                            target: {
                              name: 'infants',
                              value: nextValue,
                            },
                          })
                        }
                        disabled={isSubmitting}
                        ariaDescribedBy={errors.infants ? 'infants-error' : undefined}
                        decreaseLabel="Decrease infants"
                        increaseLabel="Increase infants"
                      />
                    </div>
                    {errors.infants ? <small id="infants-error" className="field-error">{errors.infants}</small> : null}
                  </div>
                </section>
              </>
            ) : null}

            {isRoom ? (
              <>
                <section className="room-booking-section">
                  <div className="room-booking-section-heading">
                    <span className="room-booking-step">
                      <span className="room-booking-step-badge">1</span>
                      <span>Select check-in</span>
                    </span>
                  </div>

                  <div className="room-booking-input-list">
                    <label className="room-booking-field" htmlFor="check_in">
                      <span>Check-in date</span>
                      <div className="input-with-icon">
                        <input
                          id="check_in"
                          name="check_in"
                          type="date"
                          min={todayDate()}
                          value={values.check_in}
                          onChange={updateField}
                          aria-invalid={Boolean(errors.check_in)}
                          aria-describedby={errors.check_in ? 'check-in-error' : undefined}
                          disabled={isSubmitting}
                          required
                        />
                      </div>
                      {errors.check_in ? <small id="check-in-error" className="field-error">{errors.check_in}</small> : null}
                    </label>

                    <label className="room-booking-field" htmlFor="check_in_time">
                      <span>Check-in time</span>
                      <div className="input-with-icon">
                        <input
                          id="check_in_time"
                          name="check_in_time"
                          type="time"
                          step="900"
                          value={values.check_in_time}
                          onChange={updateField}
                          aria-invalid={Boolean(errors.check_in_time)}
                          aria-describedby={errors.check_in_time ? 'check-in-time-error' : undefined}
                          disabled={isSubmitting}
                          required
                        />
                      </div>
                      {errors.check_in_time ? <small id="check-in-time-error" className="field-error">{errors.check_in_time}</small> : null}
                    </label>
                  </div>
                </section>

                <section className="room-booking-section">
                  <div className="room-booking-section-heading">
                    <span className="room-booking-step">
                      <span className="room-booking-step-badge">2</span>
                      <span>Length of stay</span>
                    </span>
                  </div>

                  <div className="room-booking-stay-row">
                    <span className="room-booking-row-label">Days</span>
                    <GuestStepper
                      label="Days"
                      value={values.stay_days}
                      min={1}
                      hideLabel
                      onChange={(nextValue) =>
                        updateField({
                          target: {
                            name: 'stay_days',
                            value: nextValue,
                          },
                        })
                      }
                      disabled={isSubmitting}
                      ariaDescribedBy={errors.stay_days ? 'stay-days-error' : undefined}
                      decreaseLabel="Decrease stay days"
                      increaseLabel="Increase stay days"
                    />
                  </div>
                  {errors.stay_days ? <small id="stay-days-error" className="field-error">{errors.stay_days}</small> : null}
                </section>

                <section className="room-booking-section">
                  <div className="room-booking-section-heading">
                    <span className="room-booking-step">
                      <span className="room-booking-step-badge">3</span>
                      <span>Check-out</span>
                    </span>
                  </div>

                  <div className="room-booking-checkout-card" aria-label="Calculated checkout">
                    <div className="room-booking-checkout-card-inner">
                      {roomCheckoutDate && roomCheckoutTime ? (
                        <div className="room-booking-checkout-topline">
                          <span className="room-booking-checkout-value room-booking-checkout-date">📅 {roomCheckoutDate}</span>
                          <span className="room-booking-checkout-divider" aria-hidden="true" />
                          <span className="room-booking-checkout-value room-booking-checkout-time">🕒 {roomCheckoutTime}</span>
                        </div>
                      ) : (
                        <div className="room-booking-checkout-topline">
                          <span className="room-booking-checkout-empty">Select your check-in date and time to see your checkout.</span>
                        </div>
                      )}
                      <small>{roomCheckoutPreview ? `✓ Automatically calculated (${roomStayDays} day${roomStayDays === 1 ? '' : 's'} / ${roomStayDays * 24} hours)` : '24-hour stay'}</small>
                    </div>
                  </div>
                </section>

                <section className="room-booking-section">
                  <div className="room-booking-section-heading">
                    <span className="room-booking-step">
                      <span className="room-booking-step-badge">5</span>
                      <span>Guests</span>
                    </span>
                  </div>

                  <div className="room-booking-guest-list">
                    <div className="room-booking-guest-row">
                      <span className="room-booking-row-label">Adults</span>
                      <GuestStepper
                        label="Adults"
                        value={values.adults}
                        min={1}
                        max={accommodation.capacity}
                        hideLabel
                        onChange={(nextValue) =>
                          updateField({
                            target: {
                              name: 'adults',
                              value: nextValue,
                            },
                          })
                        }
                        disabled={isSubmitting}
                        ariaDescribedBy={errors.adults ? 'adults-error' : undefined}
                        decreaseLabel="Decrease adults"
                        increaseLabel="Increase adults"
                      />
                    </div>
                    {errors.adults ? <small id="adults-error" className="field-error">{errors.adults}</small> : null}

                    <div className="room-booking-guest-row">
                      <span className="room-booking-row-label">Children</span>
                      <GuestStepper
                        label="Children"
                        value={values.children}
                        min={0}
                        max={accommodation.capacity}
                        hideLabel
                        onChange={(nextValue) =>
                          updateField({
                            target: {
                              name: 'children',
                              value: nextValue,
                            },
                          })
                        }
                        disabled={isSubmitting}
                        ariaDescribedBy={errors.children || errors.guests ? 'children-error' : undefined}
                        decreaseLabel="Decrease children"
                        increaseLabel="Increase children"
                      />
                    </div>
                    {errors.children ? <small id="children-error" className="field-error">{errors.children}</small> : null}

                    <div className="room-booking-guest-row">
                      <span className="room-booking-row-label">Infants</span>
                      <GuestStepper
                        label="Infants"
                        value={values.infants}
                        min={0}
                        max={accommodation.capacity}
                        hideLabel
                        onChange={(nextValue) =>
                          updateField({
                            target: {
                              name: 'infants',
                              value: nextValue,
                            },
                          })
                        }
                        disabled={isSubmitting}
                        ariaDescribedBy={errors.infants ? 'infants-error' : undefined}
                        decreaseLabel="Decrease infants"
                        increaseLabel="Increase infants"
                      />
                    </div>
                    {errors.infants ? <small id="infants-error" className="field-error">{errors.infants}</small> : null}
                  </div>
                </section>
              </>
            ) : null}

            {bookingError ? (
              <div className="form-alert compact-alert" role="alert">
                {bookingError}
              </div>
            ) : null}

            <ToastMessage message={successMessage} />

            {pendingReservation && !paymentChoice && !isPendingPaymentModalOpen ? (
              isPaymentHoldExpired ? (
                <div className="form-alert compact-alert pending-payment-notice" role="alert">
                  <strong>Reservation Hold Expired</strong>
                  <span>Your payment time has expired, so this reservation is no longer being held.</span>
                  <button className="outline-button" type="button" onClick={handleCreateNewBooking}>
                    Create New Booking
                  </button>
                </div>
              ) : (
                <div className="pending-payment-reopen" role="status">
                  <span>Payment pending · {pendingReservation.booking_reference}</span>
                  <button className="text-link" type="button" onClick={() => setIsPendingPaymentModalOpen(true)}>
                    Continue to Payment
                  </button>
                </div>
              )
            ) : null}

            {errors.guests ? (
              <small id="guests-error" className="field-error">
                {errors.guests}
              </small>
            ) : null}

            {isRoom ? (
              <div className="room-booking-summary">
                <div className="room-booking-total">
                  <span>Total</span>
                  <div>
                    <strong>{roomTotalLabel}</strong>
                    <small>{`${roomStayDays} day${roomStayDays === 1 ? '' : 's'} / ${roomStayDays * 24} hours`}</small>
                  </div>
                </div>
                <LoadingButton
                  className="solid-button full-width"
                  type="submit"
                  isLoading={isSubmitting}
                  loadingLabel="Preparing payment..."
                  ref={(node) => {
                    authButtonRef.current = node
                  }}
                >
                  Reserve Now
                </LoadingButton>
                <p className="room-booking-trust-line">🔒 Secure booking</p>
              </div>
            ) : isCottage ? (
              <div className="room-booking-summary">
                <div className="room-booking-total">
                  <span>Total</span>
                  <div>
                    <strong>{cottageTotalLabel}</strong>
                    <small>{`${cottagePeriodCount} period${cottagePeriodCount === 1 ? '' : 's'} / ${cottagePeriodCount * 12} hours`}</small>
                  </div>
                </div>
                <LoadingButton
                  className="solid-button full-width"
                  type="submit"
                  isLoading={isSubmitting}
                  loadingLabel="Preparing payment..."
                  ref={(node) => {
                    authButtonRef.current = node
                  }}
                >
                  Reserve Now
                </LoadingButton>
                <p className="room-booking-trust-line">🔒 Secure booking</p>
              </div>
      ) : (isFunctionHall || isExclusiveResort) ? (
              <div className="function-hall-booking-summary">
                  <div className="room-booking-total">
                    <span>Total</span>
                    <div>
                      <strong>{functionHallTotalLabel}</strong>
                      <small>{functionHallDays === 1 ? '1 whole day' : `${functionHallDays} whole days`}</small>
                    </div>
                  </div>
                <LoadingButton
                  className="solid-button full-width"
                  type="submit"
                  isLoading={isSubmitting}
                  loadingLabel="Preparing payment..."
                  ref={(node) => {
                    authButtonRef.current = node
                  }}
                >
                  Reserve Now
                </LoadingButton>
                <p className="room-booking-trust-line">🔒 Secure booking</p>
              </div>
            ) : estimatedTotal ? (
              <div className="booking-estimate">
                <span>{(isFunctionHall || isExclusiveResort) ? `${nights} day${nights === 1 ? '' : 's'}` : `${nights} night${nights === 1 ? '' : 's'}`}</span>
                <strong>{formatMoney(estimatedTotal)}</strong>
              </div>
            ) : null}
          </form>
        </aside>
      </div>

      {authModalMode ? (
        <AuthModal
          initialMode={authModalMode}
          onClose={() => setAuthModalMode(null)}
          returnFocusRef={authButtonRef}
          subtitle="Continue your reservation"
        />
      ) : null}

      {guestChoiceOpen ? (
        <GuestBookingChoiceModal
          onContinueAsGuest={handleContinueAsGuest}
          onSignIn={handleSignInFromGuestChoice}
          onClose={() => setGuestChoiceOpen(false)}
        />
      ) : null}

      {guestInfoOpen ? (
        <GuestInformationModal
          values={guestDetails}
          errors={guestErrors}
          errorMessage={bookingError}
          isSubmitting={isSubmitting}
          onChange={updateGuestDetail}
          onSubmit={handleGuestReservationSubmit}
          onBack={() => {
            if (!isSubmitting) {
              setGuestInfoOpen(false)
              setGuestChoiceOpen(true)
            }
          }}
          onClose={() => {
            if (!isSubmitting) {
              setGuestInfoOpen(false)
              setGuestDetails(emptyGuestDetails)
              setGuestErrors({})
              setBookingError('')
            }
          }}
        />
      ) : null}

      {availabilityConflict ? (
        <AvailabilityConflictModal
          accommodationName={availabilityConflict.accommodationName}
          selectedRange={availabilityConflict.selectedRange}
          wasSecuredByAnotherGuest={availabilityConflict.wasSecuredByAnotherGuest}
          onChangeCheckInTime={focusCheckInControls}
          onClose={() => setAvailabilityConflict(null)}
        />
      ) : null}

      {isPaymentCancelledModalOpen ? (
        <PaymentCancelledModal
          onClose={() => setIsPaymentCancelledModalOpen(false)}
          onContinue={handleContinueAfterPaymentCancellation}
          continueLabel={paymentCancelledActionLabel}
          description={paymentCancelledDescription || undefined}
        />
      ) : null}

      {isPendingPaymentModalOpen && pendingReservation && !paymentChoice ? (
        <PendingPaymentModal
          reservation={pendingReservation}
          onContinue={() => {
            setPaymentError('')
            setPaymentChoice(pendingReservation)
          }}
          errorMessage={paymentError}
          isAbandoning={isAbandoningPendingReservation}
          onClose={handleClosePendingPaymentFlow}
        />
      ) : null}

      {paymentChoice ? (
        <PaymentChoiceModal
          key={`payment-choice-${paymentChoice.id}`}
          reservation={paymentChoice}
          isExpired={isPaymentHoldExpired}
          isLoading={isStartingPayment}
          isAbandoning={isAbandoningPendingReservation}
          errorMessage={paymentError}
          onChoose={handlePaymentChoice}
          onCreateNewBooking={handleCreateNewBooking}
          onClose={() => {
            if (isGuestReservation) {
              void handleClosePendingPaymentFlow()
            } else if (!isStartingPayment) {
              setPaymentChoice(null)
              setPaymentError('')
              setIsPaymentHoldExpired(false)
              setIsStartingPayment(false)
              setSuccessMessage('')
            }
          }}
        />
      ) : null}
    </section>
  )
}

export default AccommodationDetail

function AvailabilityConflictModal({ accommodationName, selectedRange, wasSecuredByAnotherGuest, onChangeCheckInTime, onClose }) {
  useEffect(() => {
    function handleKeyDown(event) {
      if (event.key === 'Escape') {
        onClose()
      }
    }

    document.addEventListener('keydown', handleKeyDown)
    return () => document.removeEventListener('keydown', handleKeyDown)
  }, [onClose])

  return (
    <div
      className="availability-conflict-backdrop"
      role="presentation"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) {
          onClose()
        }
      }}
    >
      <section
        className="availability-conflict-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="availability-conflict-title"
        onMouseDown={(event) => event.stopPropagation()}
      >
        <div className="availability-conflict-accent" aria-hidden="true" />
        <div className="availability-conflict-content">
          <p className="eyebrow">Availability update</p>
          <h2 id="availability-conflict-title">
            {wasSecuredByAnotherGuest ? 'That time was just booked' : 'That time is already booked'}
          </h2>
          <p>
            {wasSecuredByAnotherGuest
              ? 'Another guest secured this time while you were booking. Please choose another check-in time or date.'
              : `${accommodationName} isn't available for your selected stay.`}
          </p>
          {selectedRange ? <p className="availability-conflict-range">{selectedRange}</p> : null}
          <p>Choose another check-in time or date to continue.</p>
        </div>
        <div className="availability-conflict-actions">
          <button className="solid-button" type="button" onClick={onChangeCheckInTime}>
            Change Check-in Time
          </button>
          <button className="outline-button" type="button" onClick={onClose}>
            Close
          </button>
        </div>
      </section>
    </div>
  )
}

function GuestBookingChoiceModal({ onContinueAsGuest, onSignIn, onClose }) {
  return (
    <div className="guest-booking-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
      <section className="guest-booking-modal guest-booking-choice-modal" role="dialog" aria-modal="true" aria-labelledby="guest-booking-choice-title">
        <button className="modal-close-button" type="button" onClick={onClose} aria-label="Close booking options"><X size={18} strokeWidth={2} aria-hidden="true" /></button>
        <p className="eyebrow">Continue your booking</p>
        <h2 id="guest-booking-choice-title">How would you like to continue?</h2>
        <p className="guest-booking-modal-copy">Reserve this accommodation as a guest or sign in to use your account.</p>
        <div className="guest-booking-choice-actions">
          <button className="solid-button" type="button" onClick={onContinueAsGuest}><UserRound size={17} strokeWidth={2} aria-hidden="true" />Continue as Guest</button>
          <button className="outline-button" type="button" onClick={onSignIn}><LogIn size={17} strokeWidth={2} aria-hidden="true" />Sign In</button>
        </div>
      </section>
    </div>
  )
}

function GuestInformationModal({ values, errors, errorMessage, isSubmitting, onChange, onSubmit, onBack, onClose }) {
  return (
    <div className="guest-booking-backdrop" role="presentation">
      <section className="guest-booking-modal guest-information-modal" role="dialog" aria-modal="true" aria-labelledby="guest-information-title">
        <button className="modal-close-button" type="button" onClick={onClose} disabled={isSubmitting} aria-label="Close guest information"><X size={18} strokeWidth={2} aria-hidden="true" /></button>
        <p className="eyebrow">Guest details</p>
        <h2 id="guest-information-title">Continue as Guest</h2>
        <p className="guest-booking-modal-copy">Enter your contact information to submit this reservation. No account is required.</p>
        <form className="guest-information-form" onSubmit={onSubmit} noValidate>
          <div className="guest-information-grid">
            <GuestInformationField label="First Name" name="first_name" value={values.first_name} error={errors.first_name} onChange={onChange} />
            <GuestInformationField label="Last Name" name="last_name" value={values.last_name} error={errors.last_name} onChange={onChange} />
          </div>
          <GuestInformationField label="Email" name="email" type="email" value={values.email} error={errors.email} onChange={onChange} />
          <GuestInformationField label="Contact Number" name="phone" type="tel" value={values.phone} error={errors.phone} onChange={onChange} />
          {errorMessage ? <div className="form-alert compact-alert" role="alert">{errorMessage}</div> : null}
          <div className="guest-information-actions">
            <button className="outline-button" type="button" onClick={onBack} disabled={isSubmitting}>Back</button>
            <LoadingButton className="solid-button" type="submit" isLoading={isSubmitting} loadingLabel="Submitting...">Reserve as Guest</LoadingButton>
          </div>
        </form>
      </section>
    </div>
  )
}

function GuestInformationField({ label, name, type = 'text', value, error, onChange }) {
  const errorId = `${name}-guest-error`

  return (
    <label className="guest-information-field" htmlFor={`${name}-guest`}>
      <span>{label} <b aria-hidden="true">*</b></span>
      <input id={`${name}-guest`} name={name} type={type} value={value} onChange={onChange} aria-invalid={Boolean(error)} aria-describedby={error ? errorId : undefined} autoComplete={name === 'email' ? 'email' : name === 'phone' ? 'tel' : 'given-name'} />
      {error ? <small id={errorId} className="field-error">{error}</small> : null}
    </label>
  )
}

function PendingPaymentModal({ reservation, errorMessage, isAbandoning, onContinue, onClose }) {
  return (
    <div className="pending-payment-backdrop" role="presentation">
      <section className="pending-payment-modal" role="dialog" aria-modal="true" aria-labelledby="pending-payment-title" aria-describedby="pending-payment-description">
        <button className="modal-close-button" type="button" onClick={onClose} disabled={isAbandoning} aria-label="Close payment pending notice">×</button>
        <div className="pending-payment-modal-header">
          <div className="pending-payment-modal-status"><Clock3 size={18} aria-hidden="true" /><span>Reservation created</span></div>
          <h2 id="pending-payment-title">Payment pending</h2>
          <p id="pending-payment-description">Complete your payment within the remaining reservation hold time to secure this booking.</p>
        </div>
        <div className="pending-payment-modal-summary">
          <span>Booking reference</span>
          <strong>{reservation.booking_reference}</strong>
        </div>
        {errorMessage ? <div className="form-alert compact-alert" role="alert">{errorMessage}</div> : null}
        <button className="solid-button full-width pending-payment-modal-action" type="button" onClick={onContinue} disabled={isAbandoning}>
          Continue to Payment
        </button>
      </section>
    </div>
  )
}

function PaymentChoiceModal({ reservation, isExpired, isLoading, isAbandoning, errorMessage, onChoose, onCreateNewBooking, onClose }) {
  const total = Number(reservation.total_amount ?? 0)
  const deposit = Number(reservation.deposit_amount ?? 0)
  const depositBalance = Number(reservation.deposit_balance_due ?? Math.max(total - deposit, 0))

  return (
    <div className="payment-choice-backdrop" role="presentation">
      <section className={`payment-choice-modal${isExpired ? ' payment-choice-modal--expired' : ''}`} role="dialog" aria-modal="true" aria-labelledby="payment-choice-title">
        <div className="payment-choice-header">
          <div>
            <p className="eyebrow">{isExpired ? 'Payment Pending' : 'Reservation Created'}</p>
            <h2 id="payment-choice-title">{isExpired ? 'Reservation Hold Expired' : 'Choose Payment Option'}</h2>
            <p>
              {isExpired
                ? 'Your payment time has expired, so this reservation is no longer being held. Please create a new booking to continue.'
                : 'Secure your reservation with a 30% deposit or pay the full amount now.'}
            </p>
          </div>
          <button className="modal-close-button" type="button" onClick={onClose} disabled={isLoading || isAbandoning} aria-label="Close payment options">
            ×
          </button>
        </div>

        {isExpired ? (
          <div className="payment-choice-expired">
            <button className="solid-button full-width" type="button" onClick={onCreateNewBooking}>
              Create New Booking
            </button>
          </div>
        ) : (
          <>
            <div className="payment-choice-total">
              <span>Reservation Total</span>
              <strong>{formatMoney(total)}</strong>
            </div>

            {errorMessage ? <div className="form-alert compact-alert" role="alert">{errorMessage}</div> : null}

            <div className="payment-choice-options">
              <button className="payment-choice-card" type="button" onClick={() => onChoose('deposit')} disabled={isLoading}>
                <span className="payment-choice-card-label">Pay 30% Deposit</span>
                <strong>Pay {formatMoney(deposit)} now</strong>
                <small>Secures and confirms your reservation.</small>
                <span>Remaining after payment: {formatMoney(depositBalance)}</span>
              </button>

              <button className="payment-choice-card payment-choice-card-full" type="button" onClick={() => onChoose('full')} disabled={isLoading}>
                <span className="payment-choice-card-label">Pay Full Amount</span>
                <strong>Pay {formatMoney(total)} now</strong>
                <small>Complete your payment with no remaining balance.</small>
                <span>Remaining after payment: {formatMoney(0)}</span>
              </button>
            </div>

            <div className="payment-choice-reference">
              <span>Booking reference</span>
              <strong>{reservation.booking_reference}</strong>
            </div>
            <CancellationPolicyNotice deadline={reservation.cancellation_deadline_at} />
            {isLoading ? <p className="payment-choice-loading sr-only" role="status">Preparing secure checkout...</p> : null}
          </>
        )}
      </section>
    </div>
  )
}

function CancellationPolicyNotice({ deadline }) {
  const [isExpanded, setIsExpanded] = useState(false)

  return (
    <div className={`cancellation-policy-note${isExpanded ? ' is-expanded' : ''}`}>
      <button
        className="cancellation-policy-trigger"
        type="button"
        aria-expanded={isExpanded}
        aria-controls="cancellation-policy-content"
        onClick={() => setIsExpanded((current) => !current)}
      >
        <strong>Cancellation &amp; Refund Policy</strong>
        <ChevronDown size={17} aria-hidden="true" />
      </button>
      <div className="cancellation-policy-content" id="cancellation-policy-content" role="region" aria-hidden={!isExpanded}>
        <div className="cancellation-policy-content-inner">
          <p>A 30% down payment is required to secure your reservation. If you cancel at least 3 days (72 hours) before your scheduled check-in, 50% of the down payment paid is refundable and the remaining 50% is non-refundable. Cancellations made less than 72 hours before check-in are non-refundable.</p>
          {deadline ? <small>Cancellation refund deadline: {new Date(deadline).toLocaleString()}</small> : null}
        </div>
      </div>
    </div>
  )
}

function formatRate(value) {
  return formatMoney(value)
}

function getAmenityIcon(name) {
  const normalized = String(name).toLowerCase()

  if (normalized.includes('wi-fi') || normalized.includes('wifi')) return <Wifi aria-hidden="true" />
  if (normalized.includes('bed')) return <BedDouble aria-hidden="true" />
  if (normalized.includes('fan') || normalized.includes('air')) return <Wind aria-hidden="true" />
  if (normalized.includes('bath') || normalized.includes('shower')) return <Waves aria-hidden="true" />
  return <CheckCircle2 aria-hidden="true" />
}

function formatMoney(value) {
  const amount = Number(value ?? 0)

  return `₱${new Intl.NumberFormat('en-PH', {
    minimumFractionDigits: Number.isInteger(amount) ? 0 : 2,
    maximumFractionDigits: Number.isInteger(amount) ? 0 : 2,
  }).format(amount)}`
}

function formatBookingDate(value) {
  const date = new Date(`${value}T00:00:00`)

  if (Number.isNaN(date.getTime())) {
    return value
  }

  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(date)
}

function formatDescription(value) {
  if (!value) {
    return ''
  }

  const text = String(value)
  const parts = []
  const pattern = /\*\*(.+?)\*\*/g
  let lastIndex = 0
  let match = null

  while ((match = pattern.exec(text)) !== null) {
    if (match.index > lastIndex) {
      parts.push(text.slice(lastIndex, match.index))
    }

    parts.push(<strong key={`${match.index}-${match[1]}`}>{match[1]}</strong>)
    lastIndex = pattern.lastIndex
  }

  if (lastIndex < text.length) {
    parts.push(text.slice(lastIndex))
  }

  if (parts.length === 0) {
    return text
  }

  return parts
}

function buildHouseRules(bookingSettings = {}, accommodationType = null) {
  const formatTime = (value, fallback) => {
    if (!value) {
      return fallback
    }

    const [hours, minutes] = String(value).split(':').map(Number)

    if (Number.isNaN(hours) || Number.isNaN(minutes)) {
      return value
    }

    const normalizedHours = ((hours % 24) + 24) % 24
    const period = normalizedHours >= 12 ? 'PM' : 'AM'
    const displayHours = normalizedHours % 12 || 12
    const paddedMinutes = String(minutes).padStart(2, '0')

    return `${displayHours}:${paddedMinutes} ${period}`
  }

  if (accommodationType === 'function_hall') {
    return {
      primary: [
        {
          icon: Clock3,
          label: 'Whole-day use is reserved per event date',
        },
        {
          icon: HeartHandshake,
          label: bookingSettings.house_rules_capacity_rule || 'Respect maximum guest capacity',
        },
        {
          icon: Flame,
          label: bookingSettings.house_rules_smoking_policy || 'No smoking inside the function hall',
        },
      ],
      secondary: [
        {
          icon: Waves,
          label: bookingSettings.house_rules_pool_safety_rule || 'Follow resort safety rules',
        },
        {
          icon: Sparkles,
          label: bookingSettings.house_rules_cleanliness_rule || 'Keep the venue clean after use',
        },
      ],
    }
  }

  if (accommodationType === 'room') {
    return {
      primary: [
        {
          icon: Clock3,
          label: `Room stays are valid for 24 hours from your selected check-in time.`,
        },
        {
          icon: HeartHandshake,
          label: bookingSettings.house_rules_capacity_rule || 'Respect maximum guest capacity',
        },
        {
          icon: Flame,
          label: bookingSettings.house_rules_smoking_policy || 'No smoking indoors',
        },
      ],
      secondary: [
        {
          icon: Waves,
          label: bookingSettings.house_rules_pool_safety_rule || 'Follow resort safety rules',
        },
        {
          icon: Sparkles,
          label: bookingSettings.house_rules_cleanliness_rule || 'Keep the accommodation clean',
        },
      ],
    }
  }

  return {
    primary: [
      {
        icon: Clock3,
        label: `Check-in after ${formatTime(bookingSettings.house_rules_check_in_time, '2:00 PM')}`,
      },
      {
        icon: Clock3,
        label: `Check-out before ${formatTime(bookingSettings.house_rules_check_out_time, '12:00 PM')}`,
      },
      {
        icon: Wind,
        label: `Quiet hours: ${formatTime(bookingSettings.house_rules_quiet_hours_start, '10:00 PM')} â€“ ${formatTime(bookingSettings.house_rules_quiet_hours_end, '7:00 AM')}`,
      },
      {
        icon: Flame,
        label: bookingSettings.house_rules_smoking_policy || 'No smoking indoors',
      },
      {
        icon: HeartHandshake,
        label: bookingSettings.house_rules_capacity_rule || 'Respect maximum guest capacity',
      },
      {
        icon: Waves,
        label: bookingSettings.house_rules_pool_safety_rule || 'Follow resort safety rules',
      },
    ],
    secondary: [
      {
        icon: Sparkles,
        label: bookingSettings.house_rules_cleanliness_rule || 'Keep the accommodation clean',
      },
      {
        icon: Clock3,
        label: bookingSettings.house_rules_damage_rule || 'Guests may be responsible for damaged or lost resort property',
      },
    ],
  }
}

function readBookingDraft(accommodationId) {
  if (typeof window === 'undefined') {
    return null
  }

  try {
    const rawValue = window.sessionStorage.getItem(`booking-draft-${accommodationId}`)
    return rawValue ? JSON.parse(rawValue) : null
  } catch {
    return null
  }
}

function saveBookingDraft(accommodationId, values) {
  if (typeof window === 'undefined') {
    return
  }

  try {
    window.sessionStorage.setItem(`booking-draft-${accommodationId}`, JSON.stringify(values))
  } catch {
    //
  }
}

function clearBookingDraft(accommodationId) {
  if (typeof window === 'undefined') {
    return
  }

  try {
    window.sessionStorage.removeItem(`booking-draft-${accommodationId}`)
  } catch {
    //
  }
}
