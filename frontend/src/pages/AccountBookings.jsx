import { useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowRight, CalendarClock, ChevronRight, Filter, Search, SlidersHorizontal, UserRound, X } from 'lucide-react'
import EmptyState from '../components/EmptyState'
import LoadingState from '../components/LoadingState'
import { useResortBranding } from '../hooks/useResortBranding'
import { getReservations } from '../lib/api'
import CustomerAccountLayout from './CustomerAccountLayout'
import { formatReservationDateTime, formatTimeDisplay } from '../utils/bookingTime'

const filterTabs = [
  { key: 'all', label: 'All' },
  { key: 'upcoming', label: 'Upcoming' },
  { key: 'pending', label: 'Pending' },
  { key: 'completed', label: 'Completed' },
  { key: 'cancelled', label: 'Cancelled' },
  { key: 'expired', label: 'Expired' },
]

const statusFilterOptions = [
  { value: 'all', label: 'All statuses' },
  { value: 'pending', label: 'Pending' },
  { value: 'confirmed', label: 'Confirmed' },
  { value: 'checked_in', label: 'Checked In' },
  { value: 'checked_out', label: 'Checked Out' },
  { value: 'cancelled', label: 'Cancelled' },
  { value: 'expired', label: 'Expired' },
]

function AccountBookings() {
  const { publicSettings } = useResortBranding()
  const [reservations, setReservations] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [errorMessage, setErrorMessage] = useState('')
  const [activeFilter, setActiveFilter] = useState('all')
  const [searchValue, setSearchValue] = useState('')
  const [statusValue, setStatusValue] = useState('all')
  const [currentPage, setCurrentPage] = useState(1)
  const [isMobileStatusOpen, setIsMobileStatusOpen] = useState(false)
  const statusMenuRef = useRef(null)

  useEffect(() => {
    let isMounted = true

    async function loadReservations() {
      setIsLoading(true)
      setErrorMessage('')

      try {
        const data = await getReservations()
        if (isMounted) {
          setReservations(data)
        }
      } catch {
        if (isMounted) {
          setErrorMessage('We could not load your bookings right now. Please try again.')
        }
      } finally {
        if (isMounted) {
          setIsLoading(false)
        }
      }
    }

    loadReservations()

    return () => {
      isMounted = false
    }
  }, [])

  useEffect(() => {
    function handleDocumentClick(event) {
      if (!statusMenuRef.current?.contains(event.target)) {
        setIsMobileStatusOpen(false)
      }
    }

    function handleKeyDown(event) {
      if (event.key === 'Escape') {
        setIsMobileStatusOpen(false)
      }
    }

    document.addEventListener('mousedown', handleDocumentClick)
    document.addEventListener('keydown', handleKeyDown)

    return () => {
      document.removeEventListener('mousedown', handleDocumentClick)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [])

  const filteredReservations = useMemo(() => {
    const query = searchValue.trim().toLowerCase()

    return reservations.filter((reservation) => {
      const matchesSearch =
        !query ||
        reservation.booking_reference?.toLowerCase().includes(query) ||
        reservation.accommodation?.name?.toLowerCase().includes(query)

      const matchesStatus =
        statusValue === 'all' ||
        (statusValue === 'upcoming' && isUpcomingReservation(reservation)) ||
        (statusValue === 'completed' && ['checked_out', 'completed'].includes(reservation.status)) ||
        (statusValue === 'pending' && isValidPendingReservation(reservation)) ||
        (statusValue === 'cancelled' && reservation.status === 'cancelled') ||
        (statusValue === 'expired' && reservation.status === 'expired') ||
        (!['upcoming', 'completed', 'pending', 'cancelled', 'expired'].includes(statusValue) && reservation.status === statusValue)

      const matchesTab =
        activeFilter === 'all' ||
        (activeFilter === 'upcoming' && isUpcomingReservation(reservation)) ||
        (activeFilter === 'pending' && isValidPendingReservation(reservation)) ||
        (activeFilter === 'completed' && ['checked_out', 'completed'].includes(reservation.status)) ||
        (activeFilter === 'cancelled' && reservation.status === 'cancelled') ||
        (activeFilter === 'expired' && reservation.status === 'expired')

      return matchesSearch && matchesStatus && matchesTab
    })
  }, [activeFilter, reservations, searchValue, statusValue])

  const counts = useMemo(() => {
    const upcoming = reservations.filter((reservation) => isUpcomingReservation(reservation)).length
    const pending = reservations.filter((reservation) => isValidPendingReservation(reservation)).length
    const completed = reservations.filter((reservation) => ['checked_out', 'completed'].includes(reservation.status)).length
    const cancelled = reservations.filter((reservation) => reservation.status === 'cancelled').length
    const expired = reservations.filter((reservation) => reservation.status === 'expired').length

    return {
      all: reservations.length,
      upcoming,
      pending,
      completed,
      cancelled,
      expired,
    }
  }, [reservations])

  const bookingSettings = publicSettings?.booking ?? {}
  const checkInTime = formatTimeDisplay(bookingSettings.house_rules_check_in_time, '2:00 PM')
  const checkOutTime = formatTimeDisplay(bookingSettings.house_rules_check_out_time, '12:00 PM')
  const selectedStatusLabel = statusFilterOptions.find((option) => option.value === statusValue)?.label ?? 'All statuses'

  const hasBookings = filteredReservations.length > 0
  const isEmptyList = !isLoading && !errorMessage && !hasBookings
  const pageSize = 5
  const totalPages = Math.max(1, Math.ceil(filteredReservations.length / pageSize))
  const visibleReservations = filteredReservations.slice((currentPage - 1) * pageSize, currentPage * pageSize)
  const pageStart = filteredReservations.length === 0 ? 0 : (currentPage - 1) * pageSize + 1
  const pageEnd = Math.min(currentPage * pageSize, filteredReservations.length)

  useEffect(() => {
    setCurrentPage(1)
  }, [activeFilter, searchValue, statusValue])

  useEffect(() => {
    if (currentPage > totalPages) setCurrentPage(totalPages)
  }, [currentPage, totalPages])

  return (
    <CustomerAccountLayout title="My Bookings">
      <section className="account-bookings-page">
        <section className="account-bookings-toolbar-card">
          <header className="account-bookings-hero">
            <div>
              <p className="eyebrow">My Bookings</p>
              <h2>View and manage your reservations.</h2>
              <p>Track upcoming stays, payment status, and booking details in one place.</p>
            </div>
            <Link className="solid-button account-bookings-book-button" to="/rooms">
              + Book a Stay
            </Link>
          </header>

          <div className="account-bookings-filters" role="tablist" aria-label="Booking filters">
          {filterTabs.map((tab) => (
            <button
              key={tab.key}
              type="button"
              className={`account-filter-tab ${activeFilter === tab.key ? 'is-active' : ''}`}
              onClick={() => setActiveFilter(tab.key)}
            >
              <span>{tab.label}</span>
              <strong>{counts[tab.key] ?? counts.all}</strong>
            </button>
          ))}
          </div>

          <div className="account-bookings-controls">
          <label className="account-bookings-search">
            <Search size={16} aria-hidden="true" />
            <input
              type="search"
              placeholder="Search booking/reference..."
              value={searchValue}
              onChange={(event) => setSearchValue(event.target.value)}
            />
          </label>

          <label className="account-bookings-status account-bookings-status-desktop">
            <span className="sr-only">Status filter</span>
            <SlidersHorizontal size={16} aria-hidden="true" />
            <select value={statusValue} onChange={(event) => setStatusValue(event.target.value)}>
              {statusFilterOptions.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>

          <div className="account-bookings-status-mobile" ref={statusMenuRef}>
            <button
              type="button"
              className={`account-bookings-status-button ${statusValue !== 'all' ? 'is-active' : ''}`}
              aria-label={`Filter bookings by status. Current filter: ${selectedStatusLabel}`}
              aria-haspopup="menu"
              aria-expanded={isMobileStatusOpen}
              onClick={() => setIsMobileStatusOpen((current) => !current)}
            >
              {statusValue !== 'all' ? <span className="status-active-dot" aria-hidden="true" /> : null}
              {isMobileStatusOpen ? <X size={18} aria-hidden="true" /> : <Filter size={18} aria-hidden="true" />}
            </button>

            {isMobileStatusOpen ? (
              <div className="account-bookings-status-menu" role="menu" aria-label="Filter bookings by status">
                {statusFilterOptions.map((option) => {
                  const isSelected = statusValue === option.value

                  return (
                    <button
                      key={option.value}
                      type="button"
                      className={isSelected ? 'is-selected' : ''}
                      role="menuitemradio"
                      aria-checked={isSelected}
                      onClick={() => {
                        setStatusValue(option.value)
                        setIsMobileStatusOpen(false)
                      }}
                    >
                      <span>{option.label}</span>
                    </button>
                  )
                })}
              </div>
            ) : null}
          </div>
          </div>
        </section>

        {isLoading ? <LoadingState message="Loading bookings..." /> : null}
        {errorMessage ? <EmptyState title="Unable to load bookings" message={errorMessage} /> : null}

        {isEmptyList ? (
          <div className="account-empty-state account-bookings-empty">
            <CalendarClock size={34} aria-hidden="true" />
            <h2>No bookings found.</h2>
            <p>You don't have any bookings yet.</p>
            <Link className="solid-button" to="/rooms">
              Book a Stay
            </Link>
          </div>
        ) : null}

        {!isLoading && !errorMessage && hasBookings ? (
          <>
            <div className="account-bookings-table-card">
              <div className="account-bookings-table-head">
                <span>Booking</span>
                <span>Accommodation</span>
                <span>Stay</span>
                <span>Guests</span>
                <span>Status</span>
                <span>Payment</span>
                <span>Action</span>
              </div>

              <div className="account-bookings-table-body">
                {visibleReservations.map((reservation) => (
                  <article className="account-bookings-table-row" key={reservation.id}>
                    <div className="account-booking-ref">
                      <span>{reservation.booking_reference}</span>
                    </div>

                    <div className="account-booking-accommodation">
                      <strong>{reservation.accommodation?.name ?? 'Accommodation'}</strong>
                      <span>{formatAccommodationType(reservation.accommodation)}</span>
                    </div>

                    <div className="account-booking-stay">
                      <div>
                        <span>{formatReservationDateTime(
                          reservation.check_in_at ?? reservation.check_in,
                          reservation.accommodation?.type === 'room' ? null : bookingSettings.house_rules_check_in_time,
                          checkInTime,
                        )}</span>
                        {reservation.accommodation?.type !== 'room' ? (
                          <small>{formatTimeDisplay(bookingSettings.house_rules_check_in_time, '2:00 PM')}</small>
                        ) : null}
                      </div>
                      <span className="account-booking-stay-arrow" aria-hidden="true">
                        <ArrowRight size={16} />
                      </span>
                      <div>
                        <span>{formatReservationDateTime(
                          reservation.check_out_at ?? reservation.check_out,
                          reservation.accommodation?.type === 'room' ? null : bookingSettings.house_rules_check_out_time,
                          checkOutTime,
                        )}</span>
                        {reservation.accommodation?.type !== 'room' ? (
                          <small>{formatTimeDisplay(bookingSettings.house_rules_check_out_time, '12:00 PM')}</small>
                        ) : null}
                      </div>
                    </div>

                    <div className="account-booking-guests">
                      <UserRound size={14} aria-hidden="true" />
                      <span>{reservation.guests}</span>
                    </div>

                    <div className="account-booking-statuses">
                      <span className={`reservation-status-pill status-${reservation.status ?? 'pending'}`}>
                        {formatStatus(reservation.status)}
                      </span>
                    </div>

                    <div className="account-booking-statuses">
                      <span className={`reservation-payment-pill status-${reservation.payment_state ?? reservation.payment_status ?? 'unpaid'}`}>
                        {formatStatus(reservation.payment_state ?? reservation.payment_status)}
                      </span>
                    </div>

                    <Link className="account-bookings-details-link text-link inline-link" to={`/account/bookings/${reservation.id}`}>
                      View Details
                    </Link>
                  </article>
                ))}
              </div>
            </div>

            <div className="account-bookings-mobile-cards">
              {visibleReservations.map((reservation) => (
                <article className="account-booking-mobile-card" key={reservation.id}>
                  <div className="account-booking-mobile-top">
                    <div>
                      <p className="eyebrow">{formatAccommodationType(reservation.accommodation)}</p>
                      <h3>{reservation.accommodation?.name ?? 'Accommodation'}</h3>
                    </div>
                    <span className="account-booking-reference">{reservation.booking_reference}</span>
                  </div>

                  <div className="account-booking-mobile-stay">
                    <div>
                      <span>{formatReservationDateTime(
                        reservation.check_in_at ?? reservation.check_in,
                        reservation.accommodation?.type === 'room' ? null : bookingSettings.house_rules_check_in_time,
                        checkInTime,
                      )}</span>
                      {reservation.accommodation?.type !== 'room' ? (
                        <small>{formatTimeDisplay(bookingSettings.house_rules_check_in_time, '2:00 PM')}</small>
                      ) : null}
                    </div>
                    <span className="account-booking-mobile-arrow" aria-hidden="true">
                      <ChevronRight size={16} />
                    </span>
                    <div>
                      <span>{formatReservationDateTime(
                        reservation.check_out_at ?? reservation.check_out,
                        reservation.accommodation?.type === 'room' ? null : bookingSettings.house_rules_check_out_time,
                        checkOutTime,
                      )}</span>
                      {reservation.accommodation?.type !== 'room' ? (
                        <small>{formatTimeDisplay(bookingSettings.house_rules_check_out_time, '12:00 PM')}</small>
                      ) : null}
                    </div>
                  </div>

                  <div className="account-booking-mobile-meta">
                    <span className="account-booking-mobile-guests">
                      <UserRound size={14} aria-hidden="true" />
                      <span>{reservation.guests} guest{Number(reservation.guests) === 1 ? '' : 's'}</span>
                    </span>
                    <div className="account-booking-badges">
                      <span className={`reservation-status-pill status-${reservation.status ?? 'pending'}`}>
                        {formatStatus(reservation.status)}
                      </span>
                      <span className={`reservation-payment-pill status-${reservation.payment_state ?? reservation.payment_status ?? 'unpaid'}`}>
                        {formatStatus(reservation.payment_state ?? reservation.payment_status)}
                      </span>
                    </div>
                  </div>

                  <div className="account-booking-reference-row">
                    <span className="account-card-label">Booking Reference</span>
                    <span>{reservation.booking_reference}</span>
                  </div>

                  <Link className="account-booking-details-button outline-button" to={`/account/bookings/${reservation.id}`}>
                    View Details
                  </Link>
                </article>
              ))}
            </div>

            <div className="account-bookings-pagination" aria-label="Bookings pagination">
              <span>Showing {pageStart}–{pageEnd} of {filteredReservations.length}</span>
              <div className="account-bookings-pagination-controls">
                <button type="button" onClick={() => setCurrentPage((page) => page - 1)} disabled={currentPage === 1}>Previous</button>
                {Array.from({ length: totalPages }, (_, index) => index + 1).map((page) => (
                  <button key={page} className={page === currentPage ? 'is-active' : ''} type="button" onClick={() => setCurrentPage(page)} aria-current={page === currentPage ? 'page' : undefined}>{page}</button>
                ))}
                <button type="button" onClick={() => setCurrentPage((page) => page + 1)} disabled={currentPage === totalPages}>Next</button>
              </div>
            </div>
          </>
        ) : null}
      </section>
    </CustomerAccountLayout>
  )
}

export default AccountBookings

function getReservationEnd(reservation) {
  if (reservation?.check_out_at) {
    return new Date(reservation.check_out_at)
  }

  return new Date(`${reservation?.check_out ?? ''}T23:59:59`)
}

function isValidPendingReservation(reservation) {
  if (reservation?.status !== 'pending') {
    return false
  }

  if (!reservation.expires_at) {
    return true
  }

  const expiresAt = new Date(reservation.expires_at)
  return !Number.isNaN(expiresAt.getTime()) && expiresAt.getTime() > Date.now()
}

function isUpcomingReservation(reservation) {
  if (!['pending', 'confirmed', 'checked_in'].includes(reservation?.status)) {
    return false
  }

  if (reservation.status === 'pending' && !isValidPendingReservation(reservation)) {
    return false
  }

  const end = getReservationEnd(reservation)
  return !Number.isNaN(end.getTime()) && end.getTime() >= Date.now()
}

function formatStatus(value) {
  if (!value) {
    return 'Pending'
  }

  return String(value)
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (match) => match.toUpperCase())
}

function formatAccommodationType(accommodation) {
  const type = String(accommodation?.type ?? accommodation?.category ?? '').toLowerCase()

  if (type === 'room') return 'Room'
  if (type === 'cottage') return 'Cottage'
  if (type === 'function_hall') return 'Function Hall'
  if (type === 'exclusive_resort') return 'Exclusive Resort Rental'

  return 'Accommodation'
}
