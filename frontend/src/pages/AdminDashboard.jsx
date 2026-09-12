import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  ArrowUpRight,
  BedDouble,
  CalendarCheck,
  CheckCircle2,
  Clock3,
  CreditCard,
  CircleAlert,
  Package,
  Users,
  WalletCards,
} from 'lucide-react'
import {
  getAdminAccommodations,
  getAdminDashboardSummary,
  getAdminReservations,
} from '../lib/api'

const statusLabels = {
  confirmed: 'Confirmed',
  pending: 'Pending',
  expired: 'Expired',
  cancelled: 'Cancelled',
  checked_in: 'Checked In',
  checked_out: 'Checked Out',
}

const statusColors = {
  confirmed: '#108b3f',
  pending: '#f2ad16',
  expired: '#9a7b2f',
  cancelled: '#ef4444',
  checked_in: '#2878d8',
  checked_out: '#9aa3a8',
}

function AdminDashboard() {
  const [summary, setSummary] = useState(null)
  const [reservations, setReservations] = useState([])
  const [accommodations, setAccommodations] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [dashboardError, setDashboardError] = useState('')

  useEffect(() => {
    let isMounted = true

    async function loadDashboard() {
      setIsLoading(true)
      setDashboardError('')

      try {
        const [summaryResult, reservationsResult, accommodationResult] = await Promise.allSettled([
          getAdminDashboardSummary(),
          getAdminReservations(),
          getAdminAccommodations(),
        ])

        if (summaryResult.status === 'rejected') {
          throw summaryResult.reason
        }

        if (isMounted) {
          setSummary(summaryResult.value)
          setReservations(reservationsResult.status === 'fulfilled' ? reservationsResult.value.reservations : [])
          setAccommodations(accommodationResult.status === 'fulfilled' ? accommodationResult.value : [])
        }
      } catch {
        if (isMounted) {
          setDashboardError('Unable to load dashboard data.')
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

  const statusCounts = useMemo(() => {
    return Object.keys(statusLabels).reduce((counts, status) => {
      counts[status] = reservations.filter((reservation) => reservation.status === status).length
      return counts
    }, {})
  }, [reservations])

  const reservationTrend = useMemo(() => {
    const days = recentDays(7)

    return days.map((day) => {
      const dayKey = toDateKey(day.date)

      return {
        label: day.label,
        confirmed: countReservationsForDay(reservations, dayKey, 'confirmed'),
        pending: countReservationsForDay(reservations, dayKey, 'pending'),
        cancelled: countReservationsForDay(reservations, dayKey, 'cancelled'),
      }
    })
  }, [reservations])

  const topAccommodations = useMemo(() => {
    return [...accommodations]
      .sort((left, right) => (right.reservations_count ?? 0) - (left.reservations_count ?? 0))
      .slice(0, 5)
  }, [accommodations])

  const recentReservations = useMemo(() => reservations.slice(0, 5), [reservations])

  const kpiCards = [
    {
      label: 'Total Reservations',
      value: summary?.total_reservations,
      note: 'All time reservations',
      icon: CalendarCheck,
      tone: 'blue',
    },
    {
      label: 'Pending Reservations',
      value: summary?.pending_reservations,
      note: 'Awaiting action',
      icon: Clock3,
      tone: 'amber',
    },
    {
      label: 'Available Accommodations',
      value: summary?.available_accommodations,
      note: 'Ready for booking',
      icon: CheckCircle2,
      tone: 'green',
    },
    {
      label: 'Cleaning Tasks',
      value: summary?.housekeeping_tasks,
      note: 'Pending or active work',
      icon: CheckCircle2,
      tone: 'teal',
    },
    {
      label: 'Booking Value',
      value: summary?.financial?.booking_value,
      note: 'Reservation totals',
      icon: WalletCards,
      tone: 'green',
      money: true,
    },
    {
      label: 'Collected Revenue',
      value: summary?.financial?.collected_revenue,
      note: 'Paid transactions only',
      icon: CreditCard,
      tone: 'green',
      money: true,
    },
    {
      label: 'Outstanding Balance',
      value: summary?.financial?.outstanding_balance,
      note: 'Active reservation balances',
      icon: CircleAlert,
      tone: 'red',
      money: true,
    },
    {
      label: 'Assets in Maintenance',
      value: summary?.assets_in_maintenance,
      note: 'Inventory quantity',
      icon: Package,
      tone: 'amber',
    },
  ]

  function formatValue(value) {
    if (isLoading) {
      return '...'
    }

    if (dashboardError || value === undefined || value === null) {
      return '-'
    }

    return value.toLocaleString()
  }

  return (
    <>
      {dashboardError ? (
        <div className="admin-inline-alert dashboard-alert" role="alert">
          {dashboardError}
        </div>
      ) : null}

      <section className="dashboard-kpi-grid" aria-label="Dashboard summary">
        {kpiCards.map((card) => {
          const Icon = card.icon

          return (
            <article className={`dashboard-kpi-card dashboard-kpi-tone-${card.tone}`} key={card.label}>
              <span className={`dashboard-kpi-icon tone-${card.tone}`}>
                <Icon size={27} strokeWidth={2.5} aria-hidden="true" />
              </span>
              <div>
                <p>{card.label}</p>
              <strong>{card.money ? (isLoading ? '...' : formatMoney(card.value)) : formatValue(card.value)}</strong>
                <small>{card.note}</small>
              </div>
            </article>
          )
        })}
      </section>

      <section className="dashboard-analytics-grid">
        <article className="dashboard-card dashboard-chart-card">
          <div className="dashboard-card-header">
            <h2>Reservations Overview</h2>
            <select aria-label="Reservations overview time range" defaultValue="this_month">
              <option value="this_month">This Month</option>
            </select>
          </div>
          <ChartLegend statuses={['confirmed', 'pending', 'cancelled']} />
          <ReservationLineChart data={reservationTrend} />
        </article>

        <article className="dashboard-card dashboard-status-card">
          <div className="dashboard-card-header">
            <h2>Reservation Status</h2>
          </div>
          <div className="dashboard-donut-layout">
            <ReservationDonut counts={statusCounts} />
            <StatusBreakdown counts={statusCounts} />
          </div>
        </article>
      </section>

      <section className="dashboard-bottom-grid">
        <article className="dashboard-card">
          <div className="dashboard-card-header">
            <h2>Top Accommodations</h2>
          </div>
          <table className="dashboard-mini-table">
            <thead>
              <tr>
                <th>Accommodation</th>
                <th>Reservations</th>
              </tr>
            </thead>
            <tbody>
              {topAccommodations.length > 0 ? (
                topAccommodations.map((accommodation) => (
                  <tr key={accommodation.id}>
                    <td>{accommodation.name}</td>
                    <td>{accommodation.reservations_count ?? 0}</td>
                  </tr>
                ))
              ) : (
                <tr>
                  <td colSpan="2">No accommodations yet.</td>
                </tr>
              )}
            </tbody>
          </table>
          <Link className="dashboard-card-link" to="/admin/accommodations">
            View all accommodations <ArrowUpRight size={16} aria-hidden="true" />
          </Link>
        </article>

        <article className="dashboard-card">
          <div className="dashboard-card-header">
            <h2>Recent Reservations</h2>
          </div>
          {recentReservations.length > 0 ? (
            <div className="dashboard-recent-list">
              {recentReservations.map((reservation) => (
                <div key={reservation.id} className="dashboard-recent-item">
                  <div>
                    <strong>{reservation.booking_reference}</strong>
                    <small>{reservation.guest?.name ?? reservation.guest?.email ?? 'Guest'}</small>
                  </div>
                  <span className={`admin-status-pill status-${reservation.status}`}>
                    {statusLabels[reservation.status] ?? reservation.status}
                  </span>
                </div>
              ))}
            </div>
          ) : (
            <div className="dashboard-empty-state">
              <CalendarCheck size={42} aria-hidden="true" />
              <p>No reservations yet.</p>
            </div>
          )}
          <Link className="dashboard-card-link" to="/admin/bookings">
            View all reservations <ArrowUpRight size={16} aria-hidden="true" />
          </Link>
        </article>

        <article className="dashboard-card">
          <div className="dashboard-card-header">
            <h2>Quick Actions</h2>
          </div>
          <div className="dashboard-actions-grid">
            <Link to="/admin/accommodations">
              <BedDouble size={23} aria-hidden="true" />
              Add Accommodation
            </Link>
            <Link to="/admin/bookings">
              <CalendarCheck size={23} aria-hidden="true" />
              New Reservation
            </Link>
            <Link to="/admin/users">
              <Users size={23} aria-hidden="true" />
              Users
            </Link>
            <Link to="/admin/inventory">
              <Package size={23} aria-hidden="true" />
              Inventory
            </Link>
          </div>
        </article>
      </section>
    </>
  )
}

function ChartLegend({ statuses }) {
  return (
    <div className="dashboard-chart-legend">
      {statuses.map((status) => (
        <span key={status}>
          <i style={{ backgroundColor: statusColors[status] }} />
          {statusLabels[status]}
        </span>
      ))}
    </div>
  )
}

function ReservationLineChart({ data }) {
  const width = 640
  const height = 235
  const padding = {
    top: 18,
    right: 22,
    bottom: 32,
    left: 42,
  }
const statuses = ['confirmed', 'pending', 'expired', 'cancelled']
  const values = data.flatMap((point) => statuses.map((status) => point[status] ?? 0))
  const highestValue = Math.max(0, ...values)
  const hasData = highestValue > 0
  const yTicks = getCountTicks(highestValue)
  const maxValue = yTicks[yTicks.length - 1] || 1
  const chartWidth = width - padding.left - padding.right
  const chartHeight = height - padding.top - padding.bottom

  if (!hasData) {
    return (
      <div className="dashboard-chart-empty" role="status">
        <CalendarCheck size={28} aria-hidden="true" />
        <span>No reservation activity in this period.</span>
      </div>
    )
  }

  function pointFor(value, index) {
    const x = padding.left + (index * chartWidth) / Math.max(data.length - 1, 1)
    const y = padding.top + chartHeight - ((value ?? 0) / maxValue) * chartHeight
    return [x, y]
  }

  return (
    <svg className="dashboard-line-chart" viewBox={`0 0 ${width} ${height}`} role="img">
      <title>Reservations overview by status</title>
      {yTicks.map((tick) => {
        const y = padding.top + chartHeight - (tick / maxValue) * chartHeight

        return (
          <g key={tick}>
            <line
              x1={padding.left}
              x2={width - padding.right}
              y1={y}
              y2={y}
              className="chart-grid-line"
            />
            <text className="chart-y-label" x={padding.left - 10} y={y + 4} textAnchor="end">
              {tick}
            </text>
          </g>
        )
      })}
      <line
        x1={padding.left}
        x2={width - padding.right}
        y1={padding.top + chartHeight}
        y2={padding.top + chartHeight}
        className="chart-axis-line"
      />
      {statuses.map((status) => {
        const points = data.map((point, index) => pointFor(point[status], index))
        const path = points.map(([x, y], index) => `${index === 0 ? 'M' : 'L'} ${x} ${y}`).join(' ')

        return (
          <g key={status}>
            <path d={path} fill="none" stroke={statusColors[status]} strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />
            {points.map(([x, y], index) => (
              <circle key={`${status}-${data[index].label}`} cx={x} cy={y} r="4.2" fill={statusColors[status]}>
                <title>{`${statusLabels[status]} on ${data[index].label}: ${data[index][status] ?? 0}`}</title>
              </circle>
            ))}
          </g>
        )
      })}
      {data.map((point, index) => {
        const [x] = pointFor(0, index)

        return (
          <text key={point.label} x={x} y={height - 9} textAnchor="middle">
            {point.label}
          </text>
        )
      })}
    </svg>
  )
}

function getCountTicks(maxCount) {
  if (maxCount <= 0) {
    return [0]
  }

  if (maxCount <= 4) {
    return Array.from({ length: maxCount + 1 }, (_, index) => index)
  }

  const roughStep = Math.ceil(maxCount / 4)
  const magnitude = 10 ** Math.floor(Math.log10(roughStep))
  const normalized = roughStep / magnitude
  const niceStep = (
    normalized <= 1 ? 1
      : normalized <= 2 ? 2
        : normalized <= 5 ? 5
          : 10
  ) * magnitude
  const niceMax = Math.ceil(maxCount / niceStep) * niceStep

  return Array.from({ length: Math.floor(niceMax / niceStep) + 1 }, (_, index) => index * niceStep)
}

function ReservationDonut({ counts }) {
  const total = Object.values(counts).reduce((sum, count) => sum + count, 0)
  const segments = Object.keys(statusLabels)
  let offset = 0

  return (
    <div
      className="dashboard-donut"
      style={{
        background:
          total === 0
            ? '#e4e7e4'
            : `conic-gradient(${segments
                .map((status) => {
                  const percent = (counts[status] / total) * 100
                  const start = offset
                  offset += percent
                  return `${statusColors[status]} ${start}% ${offset}%`
                })
                .join(', ')})`,
      }}
      aria-label={`Total reservations ${total}`}
    >
      <div>
        <span>Total</span>
        <strong>{total}</strong>
      </div>
    </div>
  )
}

function StatusBreakdown({ counts }) {
  const total = Object.values(counts).reduce((sum, count) => sum + count, 0)

  return (
    <div className="dashboard-status-list">
      {Object.keys(statusLabels).map((status) => {
        const count = counts[status] ?? 0
        const percent = total > 0 ? Math.round((count / total) * 100) : 0

        return (
          <div key={status}>
            <span>
              <i style={{ backgroundColor: statusColors[status] }} />
              {statusLabels[status]}
            </span>
            <strong>
              {count} ({percent}%)
            </strong>
          </div>
        )
      })}
    </div>
  )
}

function recentDays(count) {
  return Array.from({ length: count }, (_, index) => {
    const date = new Date()
    date.setDate(date.getDate() - (count - index - 1))

    return {
      date,
      label: new Intl.DateTimeFormat('en-PH', {
        month: 'short',
        day: 'numeric',
      }).format(date),
    }
  })
}

function toDateKey(date) {
  return date.toISOString().slice(0, 10)
}

function countReservationsForDay(reservations, dayKey, status) {
  return reservations.filter((reservation) => {
    const createdAt = reservation.created_at?.slice(0, 10)

    return createdAt === dayKey && reservation.status === status
  }).length
}

export default AdminDashboard

function formatMoney(value) {
  return `₱ ${Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}
