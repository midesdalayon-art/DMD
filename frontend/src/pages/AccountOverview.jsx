import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { ClipboardList, CreditCard, Hotel, LogIn, Settings, WalletCards } from 'lucide-react'
import CustomerAccountLayout from './CustomerAccountLayout'
import { useAuth } from '../hooks/useAuth'
import { getReservations } from '../lib/api'

function AccountOverview() {
  const { user } = useAuth()
  const firstName = user?.first_name ?? 'Guest'
  const [reservations, setReservations] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [recentPage, setRecentPage] = useState(1)

  useEffect(() => {
    let isMounted = true
    async function loadReservations() {
      setIsLoading(true)
      try {
        const data = await getReservations()
        if (isMounted) setReservations(data)
      } finally {
        if (isMounted) setIsLoading(false)
      }
    }
    loadReservations()
    return () => { isMounted = false }
  }, [])

  const upcomingBooking = useMemo(() => [...reservations]
    .filter((reservation) => reservation.status === 'confirmed' && isFutureStay(reservation))
    .sort((a, b) => getStayStart(a).getTime() - getStayStart(b).getTime())[0], [reservations])

  const activeBookings = useMemo(
    () => reservations.filter((reservation) => isActiveReservation(reservation)).length,
    [reservations],
  )

  const recentBookings = useMemo(() => [...reservations]
    .filter((reservation) => reservation.status !== 'expired')
    .sort((a, b) => {
      const priorityDifference = dashboardBookingPriority(a) - dashboardBookingPriority(b)
      if (priorityDifference !== 0) return priorityDifference
      return new Date(b.created_at ?? 0).getTime() - new Date(a.created_at ?? 0).getTime()
    }), [reservations])

  const pageSize = 5
  const recentTotalPages = Math.max(1, Math.ceil(recentBookings.length / pageSize))
  const visibleRecentBookings = recentBookings.slice((recentPage - 1) * pageSize, recentPage * pageSize)
  const recentPageStart = recentBookings.length === 0 ? 0 : (recentPage - 1) * pageSize + 1
  const recentPageEnd = Math.min(recentPage * pageSize, recentBookings.length)

  useEffect(() => {
    setRecentPage(1)
  }, [reservations])

  useEffect(() => {
    if (recentPage > recentTotalPages) setRecentPage(recentTotalPages)
  }, [recentPage, recentTotalPages])

  return (
    <CustomerAccountLayout title="Dashboard">
      <div className="account-dashboard">
        <section className="account-dashboard-greeting"><h2>Welcome back, {firstName}</h2></section>

        <section className="account-dashboard-feature-grid" aria-label="Stay and quick actions">
          <article className="account-upcoming-card">
            <div className="account-dashboard-card-heading">
              <div><p className="eyebrow">Upcoming Stay</p><h2>{upcomingBooking?.accommodation?.name ?? 'No upcoming stay'}</h2></div>
              {upcomingBooking ? <span className="reservation-status-pill">{formatStatus(upcomingBooking.status)}</span> : null}
            </div>
            {upcomingBooking ? (
              <>
                <p className="account-upcoming-dates">{formatRange(upcomingBooking)}</p>
                <div className="account-upcoming-footer"><strong>{formatPaymentState(upcomingBooking.payment_state)}</strong><Link className="text-link inline-link" to={`/account/bookings/${upcomingBooking.id}`}>View Booking</Link></div>
              </>
            ) : (
              <div className="account-upcoming-empty"><p>Your next resort stay starts here.</p><Link className="solid-button" to="/rooms">Book a Stay</Link></div>
            )}
          </article>

          <section className="account-actions-panel" aria-label="Quick actions">
            <div className="account-dashboard-card-heading"><h2>Quick Actions</h2></div>
            <div className="account-actions-grid">
              <Link className="account-action-card" to="/rooms"><span className="account-action-icon"><WalletCards size={16} aria-hidden="true" /></span><strong>Book a Stay</strong></Link>
              <Link className="account-action-card" to="/account/bookings"><span className="account-action-icon"><LogIn size={16} aria-hidden="true" /></span><strong>My Bookings</strong></Link>
              <Link className="account-action-card" to="/account/payments"><span className="account-action-icon"><CreditCard size={16} aria-hidden="true" /></span><strong>Payments</strong></Link>
              <Link className="account-action-card" to="/account/settings"><span className="account-action-icon"><Settings size={16} aria-hidden="true" /></span><strong>Settings</strong></Link>
            </div>
          </section>
        </section>

        <section className="dashboard-kpi-grid account-summary-grid" aria-label="Guest account summary">
          <article className="dashboard-kpi-card account-summary-card"><span className="dashboard-kpi-icon tone-green"><ClipboardList size={18} aria-hidden="true" /></span><div><span className="account-card-label">Active Reservations</span><strong>{isLoading ? '—' : activeBookings}</strong></div></article>
          <article className="dashboard-kpi-card account-summary-card"><span className="dashboard-kpi-icon tone-green"><CreditCard size={18} aria-hidden="true" /></span><div><span className="account-card-label">Payment Status</span><strong>{upcomingBooking ? formatPaymentState(upcomingBooking.payment_state) : '—'}</strong></div></article>
          <article className="dashboard-kpi-card account-summary-card"><span className="dashboard-kpi-icon tone-green"><Hotel size={18} aria-hidden="true" /></span><div><span className="account-card-label">Total Bookings</span><strong>{isLoading ? '—' : reservations.length}</strong></div></article>
        </section>

        <section className="account-recent-section" aria-labelledby="account-recent-bookings-heading">
          <div className="account-section-heading"><h2 id="account-recent-bookings-heading">Recent Bookings</h2><Link className="text-link inline-link" to="/account/bookings">View All <span aria-hidden="true">→</span></Link></div>
          {isLoading ? <div className="account-inline-loading" role="status" aria-label="Loading your bookings..." /> : null}
          {!isLoading && recentBookings.length === 0 ? <div className="account-empty-state account-overview-empty"><p className="eyebrow">No bookings yet</p><h2>Your next resort stay starts here.</h2><Link className="solid-button" to="/rooms">Book a Stay</Link></div> : null}
          {!isLoading && recentBookings.length > 0 ? (
            <div className="account-booking-table-card">
              <div className="account-booking-table-head"><span>Reference</span><span>Accommodation</span><span>Stay Dates</span><span>Status</span><span>Payment</span><span>Action</span></div>
              <div className="account-booking-table-body">
                {visibleRecentBookings.map((reservation) => (
                  <article className="account-booking-table-row" key={reservation.id}>
                    <span data-label="Reference">{reservation.booking_reference}</span><span data-label="Accommodation">{reservation.accommodation?.name ?? 'Accommodation'}</span><span data-label="Stay Dates">{formatRange(reservation)}</span><span data-label="Status"><span className="account-booking-status-badge">{formatStatus(reservation.status)}</span></span><span data-label="Payment"><span className="account-booking-status-badge is-payment">{formatPaymentState(reservation.payment_state)}</span></span><Link className="text-link inline-link" to={`/account/bookings/${reservation.id}`}>View</Link>
                  </article>
                ))}
              </div>
              <div className="account-overview-pagination" aria-label="Recent bookings pagination">
                <span>Showing {recentPageStart}–{recentPageEnd} of {recentBookings.length}</span>
                <div className="account-overview-pagination-controls">
                  <button type="button" onClick={() => setRecentPage((page) => page - 1)} disabled={recentPage === 1}>Previous</button>
                  {Array.from({ length: recentTotalPages }, (_, index) => index + 1).map((page) => (
                    <button key={page} className={page === recentPage ? 'is-active' : ''} type="button" onClick={() => setRecentPage(page)} aria-current={page === recentPage ? 'page' : undefined}>{page}</button>
                  ))}
                  <button type="button" onClick={() => setRecentPage((page) => page + 1)} disabled={recentPage === recentTotalPages}>Next</button>
                </div>
              </div>
            </div>
          ) : null}
        </section>
      </div>
    </CustomerAccountLayout>
  )
}

export default AccountOverview

function dashboardBookingPriority(reservation) {
  if (reservation.status === 'confirmed' && isFutureStay(reservation)) return 0
  if (reservation.status === 'checked_in' || (reservation.status === 'confirmed' && isActiveReservation(reservation))) return 1
  if (reservation.status === 'pending' && isActiveReservation(reservation)) return 2
  return 3
}

function formatStatus(value) {
  if (!value) return 'Pending'
  return String(value).replace(/_/g, ' ').replace(/\b\w/g, (match) => match.toUpperCase())
}

function getStayStart(reservation) {
  if (reservation?.check_in_at) return new Date(reservation.check_in_at)
  if (reservation?.check_in && reservation?.check_in_time) return new Date(`${reservation.check_in}T${reservation.check_in_time}:00`)
  return new Date(`${reservation?.check_in ?? ''}T00:00:00`)
}

function isFutureStay(reservation) {
  const start = getStayStart(reservation)
  return !Number.isNaN(start.getTime()) && start.getTime() >= Date.now()
}

function isActiveReservation(reservation) {
  if (['confirmed', 'checked_in'].includes(reservation?.status)) return true
  if (reservation?.status !== 'pending') return false
  if (!reservation.expires_at) return true
  const expiresAt = new Date(reservation.expires_at)
  return !Number.isNaN(expiresAt.getTime()) && expiresAt.getTime() > Date.now()
}

function formatPaymentState(value) {
  if (value === 'unpaid') return 'Unpaid'
  if (value === 'partially_paid') return 'Partially Paid'
  if (value === 'fully_paid') return 'Fully Paid'
  return '—'
}

function formatRange(reservation) {
  if (!reservation?.check_in || !reservation?.check_out) return '—'
  const hasTime = Boolean(reservation.check_in_at || reservation.check_out_at)
  const formatter = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', ...(hasTime ? { hour: 'numeric', minute: '2-digit' } : {}) })
  const checkIn = reservation.check_in_at ? new Date(reservation.check_in_at) : new Date(`${reservation.check_in}T00:00:00`)
  const checkOut = reservation.check_out_at ? new Date(reservation.check_out_at) : new Date(`${reservation.check_out}T00:00:00`)
  return `${formatter.format(checkIn)} – ${formatter.format(checkOut)}`
}
