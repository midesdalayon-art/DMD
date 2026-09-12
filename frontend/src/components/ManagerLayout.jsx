import { useState } from 'react'
import {
  BarChart3,
  Boxes,
  CalendarCheck,
  ChevronDown,
  Home,
  Hotel,
  Megaphone,
  Menu,
  Search,
  ShieldCheck,
  UserRound,
} from 'lucide-react'
import { NavLink, useNavigate } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'
import ResortBrandMark from './ResortBrandMark'
import ResortBrandName from './ResortBrandName'
import AnnouncementNotificationBell from './AnnouncementNotificationBell'

const sidebarItems = [
  { label: 'Dashboard', to: '/manager/dashboard', icon: Home },
  { label: 'Bookings', to: '/manager/bookings', icon: CalendarCheck },
  { label: 'Accommodations', to: '/manager/accommodations', icon: Hotel },
  { label: 'Inventory', to: '/manager/inventory', icon: Boxes },
  { label: 'Cleaning Management', to: '/manager/housekeeping', icon: ShieldCheck },
  { label: 'Attendance', to: '/manager/attendance', icon: UserRound },
  { label: 'Reports & Analytics', to: '/manager/reports', icon: BarChart3 },
  { label: 'Announcements', to: '/manager/announcements', icon: Megaphone },
]

function ManagerLayout({ eyebrow, title, children }) {
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

  return (
    <section
      className={`admin-shell manager-shell ${isSidebarCollapsed ? 'is-collapsed' : ''} ${
        isMobileSidebarOpen ? 'is-mobile-open' : ''
      }`}
    >
      <button
        className="admin-mobile-backdrop"
        type="button"
        aria-label="Close manager navigation"
        onClick={closeMobileSidebar}
      />

      <aside className="admin-sidebar" aria-label="Manager navigation">
        <div className="admin-sidebar-brand">
          <ResortBrandMark className="admin-brand-icon" size="large" />
          <div>
            <ResortBrandName as="strong" className="resort-brand-name" />
            <small>Manager Console</small>
          </div>
        </div>

        <nav className="admin-sidebar-nav">
          {sidebarItems.map((item) => {
            const Icon = item.icon

            return (
              <NavLink
                key={item.label}
                to={item.to}
                className={({ isActive }) => (isActive ? 'is-active' : '')}
                onClick={closeMobileSidebar}
              >
                <Icon size={20} strokeWidth={2.4} aria-hidden="true" />
                <span>{item.label}</span>
              </NavLink>
            )
          })}
        </nav>

        <button
          className="admin-collapse-button"
          type="button"
          onClick={() => setIsSidebarCollapsed((current) => !current)}
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
            <span>{user?.first_name?.charAt(0) ?? 'M'}</span>
            <div>
              <strong>{user?.name ?? 'Manager'}</strong>
              <small>Manager</small>
            </div>
            <button
              type="button"
              aria-label={isSidebarProfileMenuOpen ? 'Close profile menu' : 'Open profile menu'}
              onClick={(event) => {
                event.stopPropagation()
                toggleSidebarProfileMenu()
              }}
            >
              <ChevronDown size={18} aria-hidden="true" />
            </button>
          </div>

          <div className={`admin-profile-panel ${isSidebarProfileMenuOpen ? 'is-open' : ''}`} role="menu">
            <div>
              <strong>{user?.name ?? 'Manager'}</strong>
              <small>{user?.email}</small>
            </div>
            <button type="button" role="menuitem" onClick={handleSignOut}>
              {isSigningOut ? 'Signing Out...' : 'Sign Out'}
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
            aria-label="Open manager navigation"
          >
            <Menu size={23} strokeWidth={2.5} aria-hidden="true" />
          </button>

          <div className="admin-page-title">
            {eyebrow ? <p className="eyebrow">{eyebrow}</p> : null}
            <h1>{title}</h1>
          </div>

          <div className="admin-topbar-actions">
            <label className="admin-header-search">
              <span>Search</span>
              <input type="search" placeholder="Search operations..." />
              <Search size={18} aria-hidden="true" />
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
                <span>{user?.first_name?.charAt(0) ?? 'M'}</span>
                <div>
                  <strong>{user?.name ?? 'Manager'}</strong>
                  <small>Manager</small>
                </div>
                <ChevronDown size={17} aria-hidden="true" />
              </button>
              <div className={`admin-profile-panel ${isTopbarProfileMenuOpen ? 'is-open' : ''}`} role="menu">
                <div>
                  <strong>{user?.name ?? 'Manager'}</strong>
                  <small>{user?.email}</small>
                </div>
                <button type="button" role="menuitem" onClick={handleSignOut}>
                  {isSigningOut ? 'Signing Out...' : 'Sign Out'}
                </button>
              </div>
            </div>
          </div>
        </header>

        <main className="admin-dashboard-content">{children}</main>
      </div>
    </section>
  )
}

export default ManagerLayout
