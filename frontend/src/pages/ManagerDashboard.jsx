import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  ArrowRight,
  PhilippinePeso,
  BedDouble,
  CalendarCheck,
  ClipboardCheck,
  Clock3,
  Megaphone,
} from 'lucide-react'
import ManagerLayout from '../components/ManagerLayout'
import { getManagerAnnouncements, getManagerDashboardSummary } from '../lib/api'
import { useAuth } from '../hooks/useAuth'

const statusLabels = {
  pending: 'Pending',
  expired: 'Expired',
  confirmed: 'Confirmed',
  cancelled: 'Cancelled',
  checked_in: 'Checked In',
  checked_out: 'Checked Out',
}

const accommodationStatusLabels = {
  available: 'Available',
  unavailable: 'Occupied / unavailable',
  maintenance: 'Maintenance',
}

const housekeepingStatusLabels = {
  pending: 'Needs cleaning',
  assigned: 'Assigned',
  in_progress: 'In progress',
  completed_today: 'Completed today',
}

function ManagerDashboard() {
  const { user } = useAuth()
  const [dashboard, setDashboard] = useState(null)
  const [announcements, setAnnouncements] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [pageError, setPageError] = useState('')

  useEffect(() => {
    let isMounted = true

    async function loadDashboard() {
      setIsLoading(true)
      setPageError('')

      try {
        const data = await getManagerDashboardSummary()

        if (isMounted) setDashboard(data)
      } catch {
        if (isMounted) setPageError('Unable to load manager dashboard data.')
      } finally {
        if (isMounted) setIsLoading(false)
      }
    }

    async function loadAnnouncements() {
      try {
        const result = await getManagerAnnouncements()

        if (isMounted) setAnnouncements(result.announcements.slice(0, 3))
      } catch {
        // Announcements are supplemental; keep the operational dashboard usable.
      }
    }

    loadDashboard()
    loadAnnouncements()

    return () => {
      isMounted = false
    }
  }, [])

  const summary = dashboard?.summary ?? {}
  const financial = dashboard?.financial ?? {}
  const accommodationStatus = dashboard?.accommodation_status ?? {}
  const housekeepingStatus = dashboard?.housekeeping_status ?? {}
  const recentReservations = (dashboard?.recent_reservations ?? []).slice(0, 5)
  const accommodationTotal = Object.values(accommodationStatus).reduce((total, value) => total + Number(value ?? 0), 0)
  const accommodationRows = Object.entries(accommodationStatus)
  const housekeepingRows = Object.entries(housekeepingStatus)

  const cards = [
    ['Today\'s Reservations', summary.todays_reservations, CalendarCheck, 'Due today', 'green'],
    ['Pending Reservations', summary.pending_reservations, Clock3, 'Awaiting action', 'amber'],
    ['Available Accommodations', summary.available_accommodations, BedDouble, 'Ready for booking', 'blue'],
    ['Collected Revenue', financial.collected_revenue, PhilippinePeso, 'Paid transactions only', 'green', true],
  ]

  function valueOf(value) {
    return isLoading ? '...' : value ?? 0
  }

  return (
    <ManagerLayout title="Dashboard">
      <section className="manager-dashboard-welcome" aria-labelledby="manager-dashboard-welcome-title">
        <p className="eyebrow">Manager overview</p>
        <h2 id="manager-dashboard-welcome-title">Welcome back, {user?.name ?? 'Manager'}</h2>
        <p>Here&apos;s what&apos;s happening at DMD Family Resort today.</p>
      </section>

      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}

      <section className="dashboard-kpi-grid manager-kpi-grid manager-primary-kpis" aria-label="Manager operation summary">
        {cards.map(([label, value, Icon, note, tone, isMoney]) => (
          <article className={`dashboard-kpi-card manager-kpi-card manager-kpi-card-${tone}`} key={label}>
            <span className="dashboard-kpi-icon tone-green">
              <Icon size={20} strokeWidth={2.2} aria-hidden="true" />
            </span>
            <div>
              <p>{label}</p>
              <strong>{isMoney ? valueOfMoney(value, isLoading) : valueOf(value)}</strong>
              <small>{note}</small>
            </div>
          </article>
        ))}
      </section>

      <section className="manager-dashboard-panels" aria-label="Manager operations">
        <article className="dashboard-card manager-dashboard-panel manager-recent-panel">
          <DashboardPanelHeader title="Recent Reservations" link="/manager/bookings" linkLabel="View all" />
          {recentReservations.length > 0 ? (
            <div className="dashboard-recent-list">
              {recentReservations.map((reservation) => (
                <div className="dashboard-recent-item manager-recent-item" key={reservation.id}>
                  <div>
                    <strong>{reservation.booking_reference}</strong>
                    <small>{reservation.accommodation?.name ?? 'Accommodation'}</small>
                    <small>{formatDate(reservation.check_in) || 'Stay date not available'}</small>
                  </div>
                  <span className={`admin-status-pill status-${reservation.status}`}>
                    {statusLabels[reservation.status] ?? reservation.status}
                  </span>
                </div>
              ))}
            </div>
          ) : (
            <CompactEmpty icon={CalendarCheck} text="No recent reservations." />
          )}
        </article>

        <StatusPanel
          title="Accommodation Status"
          rows={accommodationRows}
          labels={accommodationStatusLabels}
          total={accommodationTotal}
          icon={BedDouble}
          link="/manager/accommodations"
          linkLabel="Manage accommodations"
          tone="accommodation"
        />

        <StatusPanel
          title="Cleaning Status"
          rows={housekeepingRows}
          labels={housekeepingStatusLabels}
          icon={ClipboardCheck}
          link="/manager/housekeeping"
          linkLabel="View cleaning tasks"
          tone="cleaning"
        />
      </section>

      <section className="dashboard-card manager-announcements-card">
        <DashboardPanelHeader title="Announcements" link="/manager/announcements" linkLabel="Manage announcements" />
        {announcements.length > 0 ? (
          <div className="manager-announcement-list">
            {announcements.map((announcement) => (
              <div className="manager-announcement-preview" key={announcement.id}>
                <span className="manager-announcement-icon"><Megaphone size={16} aria-hidden="true" /></span>
                <div>
                  <strong>{announcement.title}</strong>
                  <p>{announcement.content}</p>
                </div>
              </div>
            ))}
          </div>
        ) : (
          <CompactEmpty icon={Megaphone} text="No active announcements" detail="New announcements will appear here." />
        )}
      </section>
    </ManagerLayout>
  )
}

function DashboardPanelHeader({ title, link, linkLabel }) {
  return (
    <div className="dashboard-card-header manager-panel-header">
      <h2>{title}</h2>
      <Link className="dashboard-card-link" to={link}>{linkLabel}<ArrowRight size={13} aria-hidden="true" /></Link>
    </div>
  )
}

function StatusPanel({ title, rows, labels, total, icon: Icon, link, linkLabel, tone }) {
  return (
    <article className={`dashboard-card manager-dashboard-panel manager-status-panel manager-status-panel-${tone}`}>
      <DashboardPanelHeader title={title} link={link} linkLabel={linkLabel} />
      {total !== undefined ? (
        <div className="manager-status-total"><Icon size={17} aria-hidden="true" /><strong>{total}</strong><span>Total accommodations</span></div>
      ) : null}
      <div className="dashboard-status-list">
        {rows.length > 0 ? rows.map(([key, value]) => (
          <div key={key}>
            <span><i className={`manager-status-dot manager-status-dot-${key}`} />{labels[key] ?? key.replaceAll('_', ' ')}</span>
            <strong>{value}</strong>
          </div>
        )) : <p className="manager-status-empty">No data yet.</p>}
      </div>
    </article>
  )
}

function CompactEmpty({ icon: Icon, text, detail }) {
  return <div className="manager-compact-empty"><Icon size={18} aria-hidden="true" /><div><strong>{text}</strong>{detail ? <span>{detail}</span> : null}</div></div>
}

function formatDate(value) {
  if (!value) return ''

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''

  return new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }).format(date)
}

function valueOfMoney(value, isLoading) {
  if (isLoading) return '...'

  return `₱${Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

export default ManagerDashboard
