import { useCallback, useEffect, useRef, useState } from 'react'
import { Camera, CalendarCheck2, CircleCheckBig, LogIn, LogOut, ScanLine, Users } from 'lucide-react'
import FrontDeskLayout from '../components/FrontDeskLayout'
import { ToastMessage } from '../context/ToastContext'
import {
  getFrontDeskReservations,
  verifyFrontDeskBookingQr,
  updateFrontDeskReservationStatus,
} from '../lib/api'

const statusLabels = {
  pending: 'Pending',
  expired: 'Expired',
  confirmed: 'Confirmed',
  checked_in: 'Checked In',
  checked_out: 'Checked Out',
  cancelled: 'Cancelled',
}

const statusTone = {
  pending: 'amber',
  confirmed: 'green',
  checked_in: 'blue',
  checked_out: 'slate',
  cancelled: 'muted',
}

const RESORT_TIMEZONE = 'Asia/Manila'

function dateKeyInResortTimezone(value = new Date()) {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: RESORT_TIMEZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(value)
  const dateParts = Object.fromEntries(parts.map(({ type, value: partValue }) => [type, partValue]))

  return `${dateParts.year}-${dateParts.month}-${dateParts.day}`
}

function reservationDateKey(value) {
  if (!value) return ''

  return dateKeyInResortTimezone(new Date(value))
}

function FrontDeskCheckIn() {
  const [reservations, setReservations] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [pageError, setPageError] = useState('')
  const [pageMessage, setPageMessage] = useState('')
  const [actionReservation, setActionReservation] = useState(null)
  const [actionType, setActionType] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [qrResult, setQrResult] = useState(null)
  const [qrError, setQrError] = useState('')
  const [isQrVerifying, setIsQrVerifying] = useState(false)
  const [isQrCheckInSubmitting, setIsQrCheckInSubmitting] = useState(false)
  const [qrCheckInError, setQrCheckInError] = useState('')
  const [qrCheckInSuccess, setQrCheckInSuccess] = useState(null)
  const [isCameraActive, setIsCameraActive] = useState(false)
  const [isCameraRequested, setIsCameraRequested] = useState(false)
  const [isCameraSupported, setIsCameraSupported] = useState(false)
  const videoRef = useRef(null)
  const cameraStreamRef = useRef(null)
  const cameraSessionRef = useRef(0)
  const scanTimerRef = useRef(null)
  const usbScanBufferRef = useRef('')
  const usbScanLastKeyAtRef = useRef(0)
  const usbScanCooldownRef = useRef({ payload: '', until: 0 })
  const qrScanLockedRef = useRef(false)
  const isQrVerifyingRef = useRef(false)
  const verifyQrPayloadRef = useRef(null)
  const qrResetTimerRef = useRef(null)

  const loadReservations = useCallback(async () => {
    setIsLoading(true)
    setPageError('')

    try {
      const result = await getFrontDeskReservations()
      setReservations(result.reservations)
    } catch {
      setPageError('Unable to load check-in data right now.')
    } finally {
      setIsLoading(false)
    }
  }, [])

  useEffect(() => {
    loadReservations()
  }, [loadReservations])

  useEffect(() => {
    setIsCameraSupported(Boolean(navigator.mediaDevices?.getUserMedia && 'BarcodeDetector' in window))
    return () => {
      if (qrResetTimerRef.current) window.clearTimeout(qrResetTimerRef.current)
      stopQrCamera()
    }
  }, [])

  useEffect(() => {
    usbScanBufferRef.current = ''
    usbScanLastKeyAtRef.current = 0

    function handleUsbScannerKeyDown(event) {
      const now = Date.now()

      const target = event.target
      if (target instanceof HTMLElement && (target.closest('input, select, textarea, [contenteditable="true"], [role="dialog"], form') || target.isContentEditable)) {
        usbScanBufferRef.current = ''
        usbScanLastKeyAtRef.current = 0
        return
      }

      if (event.key === 'Enter') {
        const candidate = usbScanBufferRef.current.trim()
        usbScanBufferRef.current = ''
        usbScanLastKeyAtRef.current = 0

        if (!candidate || candidate.length < 8) return

        const payload = getBookingQrPayload(candidate)
        if (payload) {
          const cooldown = usbScanCooldownRef.current
          if (cooldown.payload === payload && cooldown.until > now) return

          usbScanCooldownRef.current = { payload, until: now + 1500 }
        }
        event.preventDefault()
        verifyQrPayloadRef.current?.(candidate)
        return
      }

      if (event.key.length !== 1) return

      if (now - usbScanLastKeyAtRef.current > 120) {
        usbScanBufferRef.current = ''
      }

      usbScanLastKeyAtRef.current = now
      usbScanBufferRef.current += event.key

      if (usbScanBufferRef.current.length > 500) {
        usbScanBufferRef.current = ''
        usbScanLastKeyAtRef.current = 0
      }
    }

    window.addEventListener('keydown', handleUsbScannerKeyDown)
    return () => {
      window.removeEventListener('keydown', handleUsbScannerKeyDown)
      usbScanBufferRef.current = ''
      usbScanLastKeyAtRef.current = 0
    }
  }, [])

  const todayKey = dateKeyInResortTimezone()

  const arrivalsToday = reservations.filter(
    (reservation) =>
      reservation.check_in === todayKey && reservation.status === 'confirmed',
  )

  const checkedInReservations = reservations.filter(
    (reservation) => reservation.status === 'checked_in',
  )

  const departuresToday = reservations.filter(
    (reservation) =>
      reservation.status === 'checked_in' && reservation.check_out === todayKey,
  )

  const completedToday = reservations.filter(
    (reservation) => reservation.status === 'checked_out' && reservationDateKey(reservation.check_out_at) === todayKey,
  )

  const summaryCards = [
    {
      label: 'Arrivals Today',
      value: arrivalsToday.length,
      icon: LogIn,
      tone: 'green',
      note: 'Scheduled arrivals',
    },
    {
      label: 'Currently Checked In',
      value: checkedInReservations.length,
      icon: Users,
      tone: 'teal',
      note: 'Guests in house',
    },
    {
      label: 'Departures Today',
      value: departuresToday.length,
      icon: LogOut,
      tone: 'blue',
      note: 'Expected departures',
    },
    {
      label: 'Completed Today',
      value: completedToday.length,
      icon: CircleCheckBig,
      tone: 'amber',
      note: 'Checked out today',
    },
  ]

  async function submitStatusChange() {
    if (!actionReservation) {
      return
    }

    setIsSubmitting(true)
    setPageError('')
    setPageMessage('')

    const nextStatus = actionType === 'check_in' ? 'checked_in' : 'checked_out'

    try {
      const result = await updateFrontDeskReservationStatus(actionReservation.id, {
        status: nextStatus,
      })

      setReservations((current) =>
        current.map((reservation) =>
          reservation.id === result.reservation.id ? result.reservation : reservation,
        ),
      )
      setPageMessage(
        nextStatus === 'checked_in'
          ? `Checked in ${result.reservation.booking_reference}.`
          : `Checked out ${result.reservation.booking_reference}.`,
      )
      setActionReservation(null)
      setActionType('')
    } catch (error) {
      setPageError(getErrorMessage(error, 'Unable to update reservation status.'))
    } finally {
      setIsSubmitting(false)
    }
  }

  async function checkInVerifiedGuest() {
    if (!qrResult?.reservation_id || !qrResult.check_in_eligible || isQrCheckInSubmitting) return

    setIsQrCheckInSubmitting(true)
    setQrCheckInError('')

    try {
      const result = await updateFrontDeskReservationStatus(qrResult.reservation_id, {
        status: 'checked_in',
      })
      const updatedReservation = result.reservation

      setReservations((current) => current.map((reservation) => (
        reservation.id === updatedReservation.id ? updatedReservation : reservation
      )))
      setQrResult((current) => current ? {
        ...current,
        reservation_status: 'checked_in',
        check_in_eligible: false,
        check_in_block_reason: 'This reservation has already been checked in.',
        checked_in_at: updatedReservation.checked_in_at ?? null,
      } : current)
      setQrCheckInSuccess({
        guest: qrResult.primary_guest?.name || 'Guest',
        accommodation: qrResult.accommodation?.name || 'Accommodation',
        checkedInAt: updatedReservation.checked_in_at,
      })
      setPageMessage('Guest checked in successfully.')
      await loadReservations()
      qrResetTimerRef.current = window.setTimeout(() => {
        setQrResult(null)
        setQrCheckInSuccess(null)
        qrScanLockedRef.current = false
      }, 1800)
    } catch (error) {
      setQrCheckInError(getErrorMessage(error, 'Unable to check in this guest.'))
    } finally {
      setIsQrCheckInSubmitting(false)
    }
  }

  function openAction(reservation, type) {
    setActionReservation(reservation)
    setActionType(type)
  }

  function closeQrScanner() {
    if (qrResetTimerRef.current) window.clearTimeout(qrResetTimerRef.current)
    setQrResult(null)
    setQrError('')
    setQrCheckInError('')
    setQrCheckInSuccess(null)
    qrScanLockedRef.current = false
    setIsCameraRequested(false)
    stopQrCamera()
  }

  function stopQrCamera() {
    cameraSessionRef.current += 1
    if (scanTimerRef.current) {
      window.clearInterval(scanTimerRef.current)
      scanTimerRef.current = null
    }
    cameraStreamRef.current?.getTracks().forEach((track) => track.stop())
    cameraStreamRef.current = null
    setIsCameraActive(false)
  }

  async function verifyQrPayload(payload) {
    const value = getBookingQrPayload(payload)
    if (qrScanLockedRef.current || isQrVerifyingRef.current) return

    if (!value) {
      stopQrCamera()
      qrScanLockedRef.current = true
      setQrResult(null)
      setQrError('Invalid booking QR. Please try again.')
      return
    }

    stopQrCamera()
    qrScanLockedRef.current = true
    isQrVerifyingRef.current = true
    setIsQrVerifying(true)
    setQrError('')
    setQrResult(null)
    setQrCheckInError('')
    setQrCheckInSuccess(null)

    try {
      const result = await verifyFrontDeskBookingQr(value)
      setQrResult(result.verification)
    } catch (error) {
      setQrError(error?.response?.status === 404
        ? 'Invalid booking QR. Please try again.'
        : error?.response?.data?.message || 'Unable to verify this booking QR.')
    } finally {
      isQrVerifyingRef.current = false
      setIsQrVerifying(false)
    }
  }

  verifyQrPayloadRef.current = verifyQrPayload

  async function startQrCamera() {
    setIsCameraRequested(true)
    if (!isCameraSupported) {
      return
    }

    const session = ++cameraSessionRef.current

    try {
      const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } } })
      if (session !== cameraSessionRef.current) {
        stream.getTracks().forEach((track) => track.stop())
        return
      }
      cameraStreamRef.current = stream
      setIsCameraActive(true)
      if (videoRef.current) {
        videoRef.current.srcObject = stream
        await videoRef.current.play()
      }

      const detector = new window.BarcodeDetector({ formats: ['qr_code'] })
      scanTimerRef.current = window.setInterval(async () => {
        if (!videoRef.current || videoRef.current.readyState < 2 || qrScanLockedRef.current || isQrVerifyingRef.current) return
        try {
          const codes = await detector.detect(videoRef.current)
          const value = codes[0]?.rawValue
          if (value) {
            await verifyQrPayloadRef.current?.(value)
          }
        } catch {
          // Keep the camera active; transient detector frames can fail.
        }
      }, 300)
    } catch {
      setIsCameraActive(false)
      setIsCameraRequested(false)
    }
  }

  return (
    <FrontDeskLayout eyebrow="Check-In / Check-Out" title="Operations">
      <ToastMessage message={pageMessage} />
      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}

      <section className="frontdesk-checkin-header">
        <div className="frontdesk-checkin-heading">
          <h2>Check-In / Check-Out</h2>
          <p className="frontdesk-checkin-subtitle">Manage today's arrivals, in-house guests, and departures.</p>
        </div>
      </section>

      <section className="frontdesk-checkin-toolbar" aria-label="Front desk QR scanner">
        <BookingQrScannerModal
          qrResult={qrResult}
          qrError={qrError}
          isVerifying={isQrVerifying}
          isCheckInSubmitting={isQrCheckInSubmitting}
          checkInError={qrCheckInError}
          checkInSuccess={qrCheckInSuccess}
          isCameraActive={isCameraActive}
          videoRef={videoRef}
          isCameraRequested={isCameraRequested}
          isCameraSupported={isCameraSupported}
          onCheckIn={checkInVerifiedGuest}
          onClose={closeQrScanner}
          onUseCamera={startQrCamera}
        />
      </section>

      <section className="dashboard-kpi-grid frontdesk-kpi-grid frontdesk-checkin-kpi-grid" aria-label="Check in summary">
        {summaryCards.map(({ label, value, icon: Icon, tone, note }) => (
          <article key={label} className={`dashboard-kpi-card frontdesk-kpi-card frontdesk-kpi-tone-${tone}`}>
            <span className={`dashboard-kpi-icon frontdesk-kpi-icon tone-${tone}`}>
              <Icon size={24} strokeWidth={2.5} aria-hidden="true" />
            </span>
            <div>
              <p>{label}</p>
              <strong>{isLoading ? '...' : value.toLocaleString()}</strong>
              <small>{note}</small>
            </div>
          </article>
        ))}
      </section>

      <section className="frontdesk-checkin-grid">
        <ReservationSection
          title="Today's Arrivals"
          icon={CalendarCheck2}
          emptyTitle="No arrivals scheduled for today."
          emptyBody="Reservations matching today will appear here."
          isLoading={isLoading}
          reservations={arrivalsToday}
          mobileLabel="arrivals"
          renderColumns={(reservation) => (
            <>
              <Cell title="Booking Reference" value={reservation.booking_reference} />
              <Cell title="Guest" value={reservation.guest_name ?? reservation.guest?.name ?? 'Guest'} sub={reservation.guest_email ?? reservation.guest?.email} />
              <Cell title="Accommodation" value={reservation.accommodation_name ?? reservation.accommodation?.name ?? 'Accommodation'} />
              <Cell title="Check-In" value={reservation.check_in} />
              <Cell title="Check-Out" value={reservation.check_out} />
              <Cell title="Guests" value={reservation.guests} center />
              <Cell title="Payment Status" badge value={formatPaymentStatus(reservation.payment_state ?? reservation.payment_status)} badgeTone={paymentToneFor(reservation.payment_state ?? reservation.payment_status)} />
              <Cell title="Reservation Status" badge value={statusLabel(reservation.status)} badgeTone={statusTone[reservation.status] ?? 'muted'} />
              <Cell
                title="Action"
                value={canCheckIn(reservation) ? (
                  <button type="button" className="primary-button frontdesk-action-button" onClick={() => openAction(reservation, 'check_in')}>
                    Check In
                  </button>
                ) : (
                  <span className="frontdesk-action-muted">{checkInUnavailableMessage(reservation)}</span>
                )}
              />
            </>
          )}
        />

        <ReservationSection
          title="Currently Checked In"
          icon={Users}
          emptyTitle="No guests are currently checked in."
          emptyBody="Checked-in guests will appear here."
          isLoading={isLoading}
          reservations={checkedInReservations}
          mobileLabel="checked-in guests"
          renderColumns={(reservation) => (
            <>
              <Cell title="Booking Reference" value={reservation.booking_reference} />
              <Cell title="Guest" value={reservation.guest_name ?? reservation.guest?.name ?? 'Guest'} sub={reservation.guest_email ?? reservation.guest?.email} />
              <Cell title="Accommodation" value={reservation.accommodation_name ?? reservation.accommodation?.name ?? 'Accommodation'} />
              <Cell title="Check-In" value={reservation.check_in} />
              <Cell title="Check-Out" value={reservation.check_out} />
              <Cell title="Guests" value={reservation.guests} center />
              <Cell title="Reservation Status" badge value={statusLabel(reservation.status)} badgeTone={statusTone[reservation.status] ?? 'muted'} />
              <Cell title="Action" value={(
                <button type="button" className="primary-button frontdesk-action-button" onClick={() => openAction(reservation, 'check_out')}>
                  Check Out
                </button>
              )} />
            </>
          )}
        />

        <ReservationSection
          title="Today's Departures"
          icon={LogOut}
          emptyTitle="No departures scheduled for today."
          emptyBody="Checked-in departures due today will appear here."
          isLoading={isLoading}
          reservations={departuresToday}
          mobileLabel="departures"
          renderColumns={(reservation) => (
            <>
              <Cell title="Booking Reference" value={reservation.booking_reference} />
              <Cell title="Guest" value={reservation.guest_name ?? reservation.guest?.name ?? 'Guest'} sub={reservation.guest_email ?? reservation.guest?.email} />
              <Cell title="Accommodation" value={reservation.accommodation_name ?? reservation.accommodation?.name ?? 'Accommodation'} />
              <Cell title="Check-In" value={reservation.check_in} />
              <Cell title="Check-Out" value={reservation.check_out} />
              <Cell title="Guests" value={reservation.guests} center />
              <Cell title="Reservation Status" badge value={statusLabel(reservation.status)} badgeTone={statusTone[reservation.status] ?? 'muted'} />
              <Cell title="Action" value={(
                <button type="button" className="primary-button frontdesk-action-button" onClick={() => openAction(reservation, 'check_out')}>
                  Check Out
                </button>
              )} />
            </>
          )}
        />

        <ReservationSection
          title="Completed Today"
          icon={CircleCheckBig}
          emptyTitle="No reservations completed today."
          emptyBody="Completed departures will appear here."
          isLoading={isLoading}
          reservations={completedToday}
          mobileLabel="completed reservations"
          renderColumns={(reservation) => (
            <>
              <Cell title="Booking Reference" value={reservation.booking_reference} />
              <Cell title="Guest" value={reservation.guest_name ?? reservation.guest?.name ?? 'Guest'} sub={reservation.guest_email ?? reservation.guest?.email} />
              <Cell title="Accommodation" value={reservation.accommodation_name ?? reservation.accommodation?.name ?? 'Accommodation'} />
              <Cell title="Check-In" value={reservation.check_in} />
              <Cell title="Check-Out" value={reservation.check_out} />
              <Cell title="Guests" value={reservation.guests} center />
              <Cell title="Reservation Status" badge value={statusLabel(reservation.status)} badgeTone={statusTone[reservation.status] ?? 'muted'} />
            </>
          )}
        />
      </section>

      {actionReservation ? (
        <ConfirmationModal
          reservation={actionReservation}
          actionType={actionType}
          isSubmitting={isSubmitting}
          onCancel={() => {
            setActionReservation(null)
            setActionType('')
          }}
          onConfirm={submitStatusChange}
        />
      ) : null}

    </FrontDeskLayout>
  )
}

function ReservationSection({ title, icon: Icon, reservations, emptyTitle, emptyBody, isLoading, mobileLabel, renderColumns }) {
  return (
    <article className="dashboard-card frontdesk-checkin-section">
      <div className="dashboard-card-header frontdesk-checkin-section-header">
        <h2>
          <Icon size={16} aria-hidden="true" />
          {title}
        </h2>
        <span>{reservations.length.toLocaleString()} {mobileLabel}</span>
      </div>

      {isLoading ? <div className="frontdesk-section-state" role="status" aria-label="Loading check-in records..." /> : null}

      {!isLoading && reservations.length === 0 ? (
        <div className="frontdesk-empty-state frontdesk-checkin-empty">
          <CalendarCheck2 size={28} aria-hidden="true" />
          <div>
            <strong>{emptyTitle}</strong>
            <p>{emptyBody}</p>
          </div>
        </div>
      ) : null}

      {!isLoading && reservations.length > 0 ? (
        <>
          <div className="frontdesk-checkin-table-wrap frontdesk-checkin-desktop">
            <table className="dashboard-mini-table frontdesk-checkin-table">
              <thead>
                <tr>
                  <th>Reference</th>
                  <th>Guest</th>
                  <th>Accommodation</th>
                  <th>Check-In</th>
                  <th>Check-Out</th>
                  {title !== "Today's Arrivals" ? <th>Guests</th> : null}
                  {title === "Today's Arrivals" ? <th>Payment</th> : null}
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                {reservations.map((reservation) => (
                  <tr key={reservation.id}>
                    {renderColumns(reservation)}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="frontdesk-checkin-mobile">
            {reservations.map((reservation) => (
              <article key={reservation.id} className="frontdesk-mobile-card">
                <div className="frontdesk-mobile-card-head">
                  <strong>{reservation.guest_name ?? reservation.guest?.name ?? 'Guest'}</strong>
                  <span className={`frontdesk-pill frontdesk-pill-status tone-${statusTone[reservation.status] ?? 'muted'}`}>{statusLabel(reservation.status)}</span>
                </div>
                <div className="frontdesk-mobile-card-grid">
                  <span><label>Booking Reference</label><strong>{reservation.booking_reference}</strong></span>
                  <span><label>Accommodation</label><strong>{reservation.accommodation_name ?? reservation.accommodation?.name ?? 'Accommodation'}</strong></span>
                  <span><label>Check-In</label><strong>{reservation.check_in}</strong></span>
                  <span><label>Check-Out</label><strong>{reservation.check_out}</strong></span>
                  <span><label>Guests</label><strong>{reservation.guests}</strong></span>
                  {title === "Today's Arrivals" ? <span><label>Payment</label><strong><span className={`frontdesk-pill frontdesk-pill-payment tone-${paymentToneFor(reservation.payment_state ?? reservation.payment_status)}`}>{formatPaymentStatus(reservation.payment_state ?? reservation.payment_status)}</span></strong></span> : null}
                </div>
                <div className="frontdesk-mobile-card-actions">
                  {title === "Today's Arrivals" ? (
                    canCheckIn(reservation) ? (
                      <button type="button" className="primary-button frontdesk-action-button" onClick={() => openAction(reservation, 'check_in')}>Check In</button>
                    ) : (
                      <span className="frontdesk-action-muted">{checkInUnavailableMessage(reservation)}</span>
                    )
                  ) : title === 'Completed Today' ? (
                    <span className="frontdesk-action-muted">Completed</span>
                  ) : (
                    <button type="button" className="primary-button frontdesk-action-button" onClick={() => openAction(reservation, 'check_out')}>Check Out</button>
                  )}
                </div>
              </article>
            ))}
          </div>
        </>
      ) : null}
    </article>
  )
}

function BookingQrScannerModal({
  qrResult,
  qrError,
  isVerifying,
  isCheckInSubmitting,
  checkInError,
  checkInSuccess,
  isCameraActive,
  videoRef,
  isCameraRequested,
  isCameraSupported,
  onCheckIn,
  onClose,
  onUseCamera,
}) {
  return (
    <section className="frontdesk-qr-scanner-panel" aria-labelledby="frontdesk-qr-title">
      <div className="frontdesk-qr-scanner-header">
        <div>
          <h2 id="frontdesk-qr-title"><ScanLine size={16} aria-hidden="true" />Guest QR Scanner</h2>
          <p className="frontdesk-qr-scanner-help">Scan the guest's booking QR code.</p>
        </div>
        <div className="frontdesk-qr-scanner-actions">
          <span className={`frontdesk-qr-live-status ${isVerifying ? 'is-verifying' : qrError ? 'is-error' : 'is-ready'}`}>
            {isVerifying ? 'Verifying...' : qrError ? 'Invalid QR' : qrResult ? 'QR Verified' : 'Scanner Ready'}
          </span>
          {!isCameraRequested && isCameraSupported ? <button type="button" className="outline-button frontdesk-camera-button" onClick={onUseCamera}><Camera size={15} aria-hidden="true" />Use Camera</button> : null}
        </div>
      </div>

      {isCameraActive ? <video ref={videoRef} className="frontdesk-qr-camera" muted playsInline /> : null}
      {!isCameraActive && isCameraRequested ? <div className="frontdesk-qr-camera-placeholder"><Camera size={24} aria-hidden="true" />Camera unavailable. USB scanner remains ready.</div> : null}
      {qrResult ? (
        <QrVerificationModal
          verification={qrResult}
          isCheckInSubmitting={isCheckInSubmitting}
          checkInError={checkInError}
          checkInSuccess={checkInSuccess}
          onCheckIn={onCheckIn}
          onClose={onClose}
        />
      ) : null}

      {!qrResult && qrError ? <QrVerificationFailure message={qrError} onClose={onClose} /> : null}
    </section>
  )
}

function QrVerificationModal({ verification, isCheckInSubmitting, checkInError, checkInSuccess, onCheckIn, onClose }) {
  const status = verification.reservation_status
  const isAlreadyCheckedIn = status === 'checked_in'

  return (
    <>
      <button className="frontdesk-modal-backdrop" type="button" aria-label="Close QR verification" onClick={onClose} />
      <section className="frontdesk-modal frontdesk-qr-verification-modal" role="dialog" aria-modal="true" aria-labelledby="frontdesk-qr-result-title">
        <div className="frontdesk-modal-header">
          <div>
            <p className="eyebrow">Booking verification</p>
            <h3 id="frontdesk-qr-result-title">{checkInSuccess ? 'Checked In Successfully ✓' : 'QR Verified ✓'}</h3>
          </div>
          <button type="button" onClick={onClose} aria-label="Close QR verification">×</button>
        </div>
        <dl className="frontdesk-modal-list frontdesk-qr-verification-details">
          <div><dt>Booking Reference</dt><dd>{verification.booking_reference}</dd></div>
          <div><dt>Primary Guest</dt><dd>{verification.primary_guest?.name || 'Guest'}</dd></div>
          <div><dt>Accommodation</dt><dd>{verification.accommodation?.name || 'Accommodation'}</dd></div>
          <div><dt>Guests</dt><dd>{verification.guests}</dd></div>
          <div><dt>Check-In</dt><dd>{verification.check_in || '—'}</dd></div>
          <div><dt>Check-Out</dt><dd>{verification.check_out || '—'}</dd></div>
          <div><dt>Payment Status</dt><dd>{formatPaymentStatus(verification.payment_state)}</dd></div>
          <div><dt>Remaining Balance</dt><dd>{formatCurrency(verification.balance_due)}</dd></div>
          <div><dt>Reservation Status</dt><dd><span className={`frontdesk-pill frontdesk-pill-status tone-${statusTone[status] ?? 'muted'}`}>{statusLabel(status)}</span></dd></div>
          {checkInSuccess ? <div><dt>Check-In Time</dt><dd>{formatDateTime(checkInSuccess.checkedInAt)}</dd></div> : null}
        </dl>
        {checkInSuccess ? (
          <p className="frontdesk-qr-result-note">Guest: {checkInSuccess.guest}. Accommodation: {checkInSuccess.accommodation}.</p>
        ) : (
          <>
            {isAlreadyCheckedIn ? <p className="frontdesk-qr-already-checked-in">Already Checked In</p> : null}
            <p className="frontdesk-qr-result-note">
              {isAlreadyCheckedIn
                ? 'Guest already checked in. A second check-in is not allowed.'
                : verification.check_in_eligible
                  ? 'Booking is valid and ready for front desk processing.'
                  : verification.check_in_block_reason || 'This booking is not eligible for check-in.'}
            </p>
            {checkInError ? <div className="admin-inline-alert" role="alert">{checkInError}</div> : null}
            {verification.check_in_eligible ? (
              <button type="button" className="primary-button frontdesk-qr-check-in-button" onClick={onCheckIn} disabled={isCheckInSubmitting}>
                {isCheckInSubmitting ? 'Checking In...' : 'Check In Guest'}
              </button>
            ) : null}
          </>
        )}
      </section>
    </>
  )
}

function QrVerificationFailure({ message, onClose }) {
  return (
    <>
      <button className="frontdesk-modal-backdrop" type="button" aria-label="Close QR verification failure" onClick={onClose} />
      <section className="frontdesk-modal frontdesk-qr-verification-modal frontdesk-qr-verification-failure" role="alertdialog" aria-modal="true" aria-labelledby="frontdesk-qr-failure-title" aria-describedby="frontdesk-qr-failure-message">
        <div className="frontdesk-modal-header">
          <div>
            <p className="eyebrow">Booking verification</p>
            <h3 id="frontdesk-qr-failure-title">QR Verification Failed</h3>
          </div>
          <button type="button" onClick={onClose} aria-label="Close QR verification failure">×</button>
        </div>
        <p id="frontdesk-qr-failure-message" className="frontdesk-qr-result-note">{message}</p>
        <div className="frontdesk-modal-actions">
          <button type="button" className="outline-button" onClick={onClose}>Close</button>
        </div>
      </section>
    </>
  )
}

function Cell({ value, sub, badge, badgeTone = 'muted', center }) {
  return (
    <td className={center ? 'frontdesk-center-cell' : ''}>
      {badge ? <span className={`frontdesk-pill frontdesk-table-badge frontdesk-pill-status tone-${badgeTone}`}>{value}</span> : <strong>{value}</strong>}
      {sub ? <small className="frontdesk-email" title={sub}>{sub}</small> : null}
    </td>
  )
}

function ConfirmationModal({ reservation, actionType, isSubmitting, onCancel, onConfirm }) {
  const isCheckIn = actionType === 'check_in'

  return (
    <>
      <button className="frontdesk-modal-backdrop" type="button" aria-label="Close confirmation dialog" onClick={onCancel} />
      <section className="frontdesk-modal" role="dialog" aria-modal="true" aria-labelledby="frontdesk-modal-title">
        <div className="frontdesk-modal-header">
          <div>
            <p className="eyebrow">Confirm {isCheckIn ? 'Check In' : 'Check Out'}</p>
            <h3 id="frontdesk-modal-title">{reservation.guest_name ?? reservation.guest?.name ?? 'Guest'}</h3>
          </div>
          <button type="button" onClick={onCancel} aria-label="Close dialog">X</button>
        </div>
        <dl className="frontdesk-modal-list">
          <div><dt>Accommodation</dt><dd>{reservation.accommodation_name ?? reservation.accommodation?.name ?? 'Accommodation'}</dd></div>
          <div><dt>Booking Reference</dt><dd>{reservation.booking_reference}</dd></div>
          <div><dt>Check-In</dt><dd>{reservation.check_in}</dd></div>
          <div><dt>Check-Out</dt><dd>{reservation.check_out}</dd></div>
          <div><dt>Guests</dt><dd>{reservation.guests}</dd></div>
        </dl>
        <div className="frontdesk-modal-actions">
          <button type="button" className="outline-button" onClick={onCancel}>Cancel</button>
          <button type="button" className="primary-button" onClick={onConfirm} disabled={isSubmitting}>
            {isSubmitting ? 'Processing...' : isCheckIn ? 'Confirm Check In' : 'Confirm Check Out'}
          </button>
        </div>
      </section>
    </>
  )
}

function canCheckIn(reservation) {
  return reservation.status === 'confirmed'
    && reservation.payment_state === 'fully_paid'
    && Number(reservation.balance_due ?? 0) <= 0
}

function checkInUnavailableMessage(reservation) {
  if (reservation.status === 'confirmed' && Number(reservation.balance_due ?? 0) > 0) {
    return `Balance due: ${formatCurrency(reservation.balance_due)}`
  }

  return 'Unavailable'
}

function formatCurrency(value) {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
  }).format(Number(value ?? 0))
}

function formatDateTime(value) {
  if (!value) return 'Recorded by server'

  return new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value))
}

function statusLabel(status) {
  return statusLabels[status] ?? status
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

  if (paymentStatus === 'pending') {
    return 'amber'
  }

  if (paymentStatus === 'paid') {
    return 'green'
  }

  if (paymentStatus === 'fully_paid') {
    return 'green'
  }

  if (paymentStatus === 'partially_paid') {
    return 'amber'
  }

  return 'slate'
}

function getBookingQrPayload(value) {
  const candidate = String(value ?? '').trim()
  if (!candidate) return ''

  let normalizedCandidate = candidate
  try {
    normalizedCandidate = decodeURIComponent(candidate)
  } catch {
    return ''
  }

  if (/^[A-Za-z0-9]{64}$/.test(normalizedCandidate)) {
    return normalizedCandidate
  }

  try {
    const url = new URL(normalizedCandidate, window.location.origin)
    const match = url.pathname.match(/(?:^|\/)verify-booking\/([A-Za-z0-9]{64})\/?$/)
    return match?.[1] ?? ''
  } catch {
    return ''
  }
}

function getErrorMessage(error, fallback) {
  if (error?.response?.status === 403) {
    return 'You are not allowed to perform this action.'
  }

  if (error?.response?.status === 404) {
    return 'The reservation could not be found.'
  }

  if (error?.response?.data?.message) {
    return error.response.data.message
  }

  return fallback
}

export default FrontDeskCheckIn
