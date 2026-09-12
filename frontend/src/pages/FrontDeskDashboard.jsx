import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  CalendarCheck,
  CheckCircle2,
  Clock3,
  Hotel,
  LogIn,
  LogOut,
  UserCheck,
} from 'lucide-react'
import FrontDeskLayout from '../components/FrontDeskLayout'
import { getFrontDeskDashboardSummary } from '../lib/api'

const statusLabels = {
  pending: 'Pending',
  expired: 'Expired',
  confirmed: 'Confirmed',
  checked_in: 'Checked In',
  checked_out: 'Checked Out',
  cancelled: 'Cancelled',
}

const paymentLabels = {
  paid: 'Paid',
  pending: 'Pending',
  unpaid: 'Unpaid',
  partial: 'Partial',
}

const cardStyles = [
  {
    tone: 'green',
    iconTone: 'green',
  },
  {
    tone: 'teal',
    iconTone: 'teal',
  },
  {
    tone: 'amber',
    iconTone: 'amber',
  },
  {
    tone: 'green',
    iconTone: 'green',
  },
  {
    tone: 'slate',
    iconTone: 'slate',
  },
  {
    tone: 'teal',
    iconTone: 'teal',
  },
]

function FrontDeskDashboard() {
  const [dashboard, setDashboard] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [pageError, setPageError] = useState('')

  useEffect(() => {
    let isMounted = true

    async function loadDashboard() {
      setIsLoading(true)
      setPageError('')

      try {
        const data = await getFrontDeskDashboardSummary()

        if (isMounted) {
          setDashboard(data)
        }
      } catch {
        if (isMounted) {
          setPageError('Unable to load front desk dashboard data.')
        }
      } finally {
        if (isMounted) {
          setIsLoading(false)
        }
      }
    }

    loadDashboard()

    return () => {
      isMounted = false
    }
  }, [])

  const summary = dashboard?.summary ?? {}
  const cards = [
    ['Today\'s Check-Ins', summary.todays_check_ins, LogIn, 'Arrivals expected today'],
    ['Today\'s Check-Outs', summary.todays_check_outs, LogOut, 'Departures due today'],
    ['Pending Bookings', summary.pending_bookings, Clock3, 'Awaiting confirmation'],
    ['Available Accommodations', summary.available_accommodations, Hotel, 'Ready for assignment'],
    ['Occupied Accommodations', summary.occupied_accommodations, CheckCircle2, 'Currently occupied'],
    ['Guests Currently Checked In', summary.guests_checked_in, UserCheck, 'In-house guests'],
  ]

  const arrivalRows = dashboard?.today_arrivals ?? []
  const departureRows = dashboard?.today_departures ?? []
  const bookingRows = dashboard?.recent_pending_bookings ?? []
  const recentBookingRows = bookingRows.slice(0, 5)

  const accommodationRows = useMemo(
    () => Object.entries(dashboard?.accommodation_status ?? {}),
    [dashboard],
  )

  function formatValue(value) {
    if (isLoading) {
      return '...'
    }

    if (pageError || value === undefined || value === null) {
      return '0'
    }

    return value.toLocaleString()
  }

  return (
    <FrontDeskLayout
      eyebrow="Front Desk"
      title="Dashboard"
    >
      {pageError ? (
        <div className="admin-inline-alert dashboard-alert" role="alert">
          {pageError}
        </div>
      ) : null}

      <section className="dashboard-kpi-grid frontdesk-kpi-grid" aria-label="Front desk summary">
        {cards.map(([label, value, Icon, note], index) => (
          <article
            key={label}
            className={`dashboard-kpi-card frontdesk-kpi-card frontdesk-kpi-tone-${cardStyles[index].tone}`}
          >
            <span className={`dashboard-kpi-icon frontdesk-kpi-icon tone-${cardStyles[index].iconTone}`}>
              <Icon size={24} strokeWidth={2.5} aria-hidden="true" />
            </span>
            <div>
              <p>{label}</p>
              <strong>{formatValue(value)}</strong>
              <small>{note}</small>
            </div>
          </article>
        ))}
      </section>

      <section className="dashboard-analytics-grid frontdesk-dashboard-grid">
        <DashboardTable
          title="Today's Arrivals"
          rows={arrivalRows}
          emptyLabel="No arrivals scheduled for today."
          isLoading={isLoading}
        />

        <DashboardTable
          title="Today's Departures"
          rows={departureRows}
          emptyLabel="No departures scheduled for today."
          isLoading={isLoading}
        />
      </section>

      <section className="dashboard-bottom-grid frontdesk-dashboard-bottom-grid">
        <article className="dashboard-card frontdesk-booking-card">
          <div className="dashboard-card-header">
            <h2>Recent / Pending Bookings</h2>
          </div>

          {recentBookingRows.length > 0 ? (
            <div className="dashboard-mini-table-wrap">
              <table className="dashboard-mini-table frontdesk-booking-table">
                <thead>
                  <tr>
                    <th>Guest</th>
                    <th>Accommodation</th>
                    <th>Stay</th>
                    <th>Status</th>
                    <th>Payment</th>
                  </tr>
                </thead>
                <tbody>
                  {recentBookingRows.map((reservation) => (
                    <tr key={reservation.id}>
                      <td>
                        <strong>{reservation.guest_name ?? reservation.guest?.name ?? 'Guest'}</strong>
                        <small>{reservation.guest_email ?? reservation.guest?.email ?? reservation.booking_reference}</small>
                      </td>
                      <td>{reservation.accommodation_name ?? reservation.accommodation?.name ?? 'Accommodation'}</td>
                      <td>
                        <strong>{reservation.check_in ?? 'TBD'}</strong>
                        <small>to {reservation.check_out ?? 'TBD'}</small>
                      </td>
                      <td>
                        <span className={`admin-status-pill status-${reservation.status}`}>
                          {statusLabels[reservation.status] ?? reservation.status.replaceAll('_', ' ')}
                        </span>
                      </td>
                      <td>{formatPaymentStatus(reservation.payment_status)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <div className="dashboard-empty-state">
              <CalendarCheck size={42} aria-hidden="true" />
              <p>No recent or pending bookings.</p>
            </div>
          )}

          <Link className="dashboard-card-link" to="/frontdesk/bookings">
            View Bookings
          </Link>
        </article>

        <article className="dashboard-card frontdesk-dashboard-accommodation-card">
          <div className="dashboard-card-header">
            <h2>Accommodation Status</h2>
          </div>
          <div className="dashboard-status-list">
            {accommodationRows.length > 0 ? (
              accommodationRows.map(([label, value]) => (
                <div key={label}>
                  <span>{label.replaceAll('_', ' ')}</span>
                  <strong>{isLoading ? '...' : value}</strong>
                </div>
              ))
            ) : (
              <p>No accommodation data yet.</p>
            )}
          </div>
          <Link className="dashboard-card-link" to="/frontdesk/accommodations">
            View accommodations
          </Link>
        </article>
      </section>
    </FrontDeskLayout>
  )
}

function DashboardTable({ title, rows, emptyLabel, isLoading }) {
  return (
    <article className="dashboard-card frontdesk-ops-card">
      <div className="dashboard-card-header">
        <h2>{title}</h2>
      </div>

      {rows.length > 0 ? (
        <div className="dashboard-mini-table-wrap">
          <table className="dashboard-mini-table frontdesk-ops-table">
            <thead>
              <tr>
                <th>Guest</th>
                <th>Accommodation</th>
                <th>Check-in</th>
                <th>Check-out</th>
                <th>Status</th>
                <th>Payment</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((reservation) => (
                <tr key={reservation.id}>
                  <td>
                    <strong>{reservation.guest_name ?? reservation.guest?.name ?? 'Guest'}</strong>
                    <small>{reservation.booking_reference}</small>
                  </td>
                  <td>{reservation.accommodation_name ?? reservation.accommodation?.name ?? 'Accommodation'}</td>
                  <td>{reservation.check_in ?? 'TBD'}</td>
                  <td>{reservation.check_out ?? 'TBD'}</td>
                  <td>
                    <span className={`admin-status-pill status-${reservation.status}`}>
                      {statusLabels[reservation.status] ?? reservation.status.replaceAll('_', ' ')}
                    </span>
                  </td>
                  <td>{formatPaymentStatus(reservation.payment_status)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="dashboard-empty-state">
          <Clock3 size={42} aria-hidden="true" />
          <p>{isLoading ? <span className="sr-only">Loading...</span> : emptyLabel}</p>
        </div>
      )}
    </article>
  )
}

function formatPaymentStatus(paymentStatus) {
  if (!paymentStatus || paymentStatus === 'Not implemented') {
    return 'N/A'
  }

  return paymentLabels[paymentStatus] ?? paymentStatus.replaceAll('_', ' ')
}

export default FrontDeskDashboard
