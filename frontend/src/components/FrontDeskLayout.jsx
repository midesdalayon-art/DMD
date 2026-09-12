import { useState } from 'react'
import {
  CalendarCheck,
  ChevronDown,
  Home,
  Hotel,
  Megaphone,
  Menu,
  Search,
  UserRound,
} from 'lucide-react'
import { NavLink, useNavigate } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'
import ResortBrandMark from './ResortBrandMark'
import ResortBrandName from './ResortBrandName'
import AnnouncementNotificationBell from './AnnouncementNotificationBell'

const sidebarItems = [
  {
    label: 'Dashboard',
    to: '/frontdesk/dashboard',
    icon: Home,
  },
  {
    label: 'Bookings',
    to: '/frontdesk/bookings',
    icon: CalendarCheck,
  },
  {
    label: 'Check-In / Check-Out',
    to: '/frontdesk/check-in',
    icon: UserRound,
  },
  {
    label: 'Accommodations',
    to: '/frontdesk/accommodations',
    icon: Hotel,
  },
  {
    label: 'Announcements',
    to: '/frontdesk/announcements',
    icon: Megaphone,
  },
]

function FrontDeskLayout({ eyebrow, title, children }) {
  const navigate = useNavigate()
  const { signOut, user } = useAuth()

  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(false)
  const [isMobileSidebarOpen, setIsMobileSidebarOpen] = useState(false)
  const [isSidebarProfileMenuOpen, setIsSidebarProfileMenuOpen] = useState(false)
  const [isTopbarProfileMenuOpen, setIsTopbarProfileMenuOpen] = useState(false)
  const [isSigningOut, setIsSigningOut] = useState(false)

  async function handleSignOut() {
    setIsSigningOut(true)

    try {
      await signOut()
      navigate('/', { replace: true })
    } finally {
      setIsSigningOut(false)
    }
  }

  function closeMobileSidebar() {
    setIsMobileSidebarOpen(false)
  }

  function toggleSidebarProfileMenu() {
    setIsSidebarProfileMenuOpen((current) => !current)
    setIsTopbarProfileMenuOpen(false)
  }

  function toggleTopbarProfileMenu() {
    setIsTopbarProfileMenuOpen((current) => !current)
    setIsSidebarProfileMenuOpen(false)
  }

  const userInitial =
    user?.first_name?.charAt(0) ||
    user?.name?.charAt(0) ||
    'F'

  const userName = user?.name || 'Front Desk Staff'

  return (
    <section
      className={`admin-shell manager-shell ${
        isSidebarCollapsed ? 'is-collapsed' : ''
      } ${isMobileSidebarOpen ? 'is-mobile-open' : ''}`}
    >
      <button
        className="admin-mobile-backdrop"
        type="button"
        aria-label="Close front desk navigation"
        onClick={closeMobileSidebar}
      />

      <aside
        className="admin-sidebar"
        aria-label="Front Desk navigation"
      >
        <div className="admin-sidebar-brand">
          <ResortBrandMark className="admin-brand-icon" size="large" />

          <div>
            <ResortBrandName as="strong" className="resort-brand-name" />
            <small>Front Desk Console</small>
          </div>
        </div>

        <nav className="admin-sidebar-nav frontdesk-sidebar-nav">
          {sidebarItems.map((item) => {
            const Icon = item.icon

            return (
              <NavLink
                key={item.label}
                to={item.to}
                className={({ isActive }) =>
                  isActive ? 'is-active' : ''
                }
                onClick={closeMobileSidebar}
              >
                <Icon
                  size={20}
                  strokeWidth={2.4}
                  aria-hidden="true"
                />

                <span className="frontdesk-nav-label">{item.label}</span>
              </NavLink>
            )
          })}
        </nav>

        <button
          className="admin-collapse-button"
          type="button"
          onClick={() =>
            setIsSidebarCollapsed((current) => !current)
          }
          aria-pressed={isSidebarCollapsed}
        >
          {isSidebarCollapsed ? 'Expand' : 'Collapse'}
        </button>

        <div className="admin-sidebar-profile-menu">
          <div
            className="admin-sidebar-profile"
            role="button"
            tabIndex={0}
            aria-haspopup="menu"
            aria-expanded={isSidebarProfileMenuOpen}
            onClick={toggleSidebarProfileMenu}
            onKeyDown={(event) => {
              if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault()
                toggleSidebarProfileMenu()
              }
            }}
          >
              <span>{userInitial}</span>

              <div>
                <strong>{userName}</strong>
                <small>Front Desk</small>
              </div>

            <button
              type="button"
              aria-label={isSidebarProfileMenuOpen ? 'Close profile menu' : 'Open profile menu'}
              onClick={(event) => {
                event.stopPropagation()
                toggleSidebarProfileMenu()
              }}
              disabled={isSigningOut}
            >
              <ChevronDown
                size={18}
                aria-hidden="true"
              />
            </button>
          </div>

          <div className={`admin-profile-panel ${isSidebarProfileMenuOpen ? 'is-open' : ''}`} role="menu">
            <div>
              <strong>{userName}</strong>
              <small>{user?.email}</small>
            </div>

            <button
              type="button"
              role="menuitem"
              onClick={handleSignOut}
              disabled={isSigningOut}
            >
              {isSigningOut
                ? 'Signing Out...'
                : 'Sign Out'}
            </button>
          </div>
        </div>
      </aside>

      <div className="admin-main">
        <header className="admin-topbar">
          <button
            className="admin-menu-button"
            type="button"
            onClick={() => setIsMobileSidebarOpen(true)}
            aria-label="Open front desk navigation"
          >
            <Menu
              size={23}
              strokeWidth={2.5}
              aria-hidden="true"
            />
          </button>

          <div className="admin-page-title">
            {eyebrow ? (
              <p className="eyebrow">{eyebrow}</p>
            ) : null}

            <h1>{title}</h1>
          </div>

          <div className="admin-topbar-actions">
            <label className="admin-header-search">
              <span>Search</span>

              <input
                type="search"
                placeholder="Search front desk..."
              />

              <Search
                size={18}
                aria-hidden="true"
              />
            </label>

            <AnnouncementNotificationBell />

            <div className="admin-profile-menu">
              <button
                className="admin-user-chip"
                type="button"
                aria-haspopup="menu"
                aria-expanded={isTopbarProfileMenuOpen}
                onClick={toggleTopbarProfileMenu}
              >
                <span>{userInitial}</span>

                <div>
                  <strong>{userName}</strong>
                  <small>Front Desk</small>
                </div>

                <ChevronDown
                  size={17}
                  aria-hidden="true"
                />
              </button>

              <div
                className={`admin-profile-panel ${
                  isTopbarProfileMenuOpen ? 'is-open' : ''
                }`}
                role="menu"
              >
                <div>
                  <strong>{userName}</strong>
                  <small>{user?.email}</small>
                </div>

                <button
                  type="button"
                  role="menuitem"
                  onClick={handleSignOut}
                  disabled={isSigningOut}
                >
                  {isSigningOut
                    ? 'Signing Out...'
                    : 'Sign Out'}
                </button>
              </div>
            </div>
          </div>
        </header>

        <main className="admin-dashboard-content">
          {children}
        </main>
      </div>
    </section>
  )
}

export default FrontDeskLayout
