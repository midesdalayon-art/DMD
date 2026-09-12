import { useState } from 'react'
import {
  CheckSquare,
  ChevronDown,
  ClipboardList,
  Home,
  Menu,
  Search,
  UserRound,
} from 'lucide-react'
import { NavLink, useNavigate } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'
import ResortBrandMark from './ResortBrandMark'
import ResortBrandName from './ResortBrandName'

const navItems = [
  { label: 'Dashboard', mobileLabel: 'Today', to: '/housekeeping/dashboard', icon: Home },
  { label: 'My Tasks', mobileLabel: 'Tasks', to: '/housekeeping/tasks', icon: ClipboardList },
  { label: 'History', mobileLabel: 'History', to: '/housekeeping/history', icon: CheckSquare },
]

function HousekeepingLayout({ eyebrow, title, children }) {
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
      className={`admin-shell housekeeping-shell ${isSidebarCollapsed ? 'is-collapsed' : ''} ${
        isMobileSidebarOpen ? 'is-mobile-open' : ''
      }`}
    >
      <button
        className="admin-mobile-backdrop"
        type="button"
        aria-label="Close housekeeping navigation"
        onClick={closeMobileSidebar}
      />

      <aside className="admin-sidebar housekeeping-sidebar" aria-label="Housekeeping navigation">
        <div className="admin-sidebar-brand">
          <ResortBrandMark className="admin-brand-icon" size="large" />
          <div>
            <ResortBrandName as="strong" className="resort-brand-name" />
            <small>Housekeeping</small>
          </div>
        </div>

        <nav className="admin-sidebar-nav">
          {navItems.map((item) => {
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
            <span>{user?.first_name?.charAt(0) ?? 'H'}</span>
            <div>
              <strong>{user?.name ?? 'Housekeeping Staff'}</strong>
              <small>Housekeeping Staff</small>
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
              <strong>{user?.name ?? 'Housekeeping Staff'}</strong>
              <small>{user?.email}</small>
            </div>
            <button type="button" role="menuitem" onClick={handleSignOut}>
              {isSigningOut ? 'Signing Out...' : 'Sign Out'}
            </button>
          </div>
        </div>
      </aside>

      <div className="admin-main housekeeping-main">
        <header className="admin-topbar housekeeping-topbar">
          <button
            className="admin-menu-button"
            type="button"
            onClick={() => setIsMobileSidebarOpen(true)}
            aria-label="Open housekeeping navigation"
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
              <input type="search" placeholder="Search tasks..." />
              <Search size={18} aria-hidden="true" />
            </label>
            <div className="admin-profile-menu">
              <button
                className="admin-user-chip"
                type="button"
                aria-haspopup="menu"
                aria-expanded={isTopbarProfileMenuOpen}
                onClick={toggleTopbarProfileMenu}
              >
                <span>{user?.first_name?.charAt(0) ?? 'H'}</span>
                <div>
                  <strong>{user?.name ?? 'Housekeeping Staff'}</strong>
                  <small>Housekeeping Staff</small>
                </div>
                <ChevronDown size={17} aria-hidden="true" />
              </button>
              <div className={`admin-profile-panel ${isTopbarProfileMenuOpen ? 'is-open' : ''}`} role="menu">
                <div>
                  <strong>{user?.name ?? 'Housekeeping Staff'}</strong>
                  <small>{user?.email}</small>
                </div>
                <button type="button" role="menuitem" onClick={handleSignOut}>
                  {isSigningOut ? 'Signing Out...' : 'Sign Out'}
                </button>
              </div>
            </div>
          </div>
        </header>

        <main className="admin-dashboard-content housekeeping-content">{children}</main>
      </div>

      <nav className="housekeeping-bottom-nav" aria-label="Housekeeping quick navigation">
        {navItems.map((item) => {
          const Icon = item.icon

          return (
            <NavLink key={item.label} to={item.to} className={({ isActive }) => (isActive ? 'is-active' : '')}>
              <Icon size={18} strokeWidth={2.4} aria-hidden="true" />
              <span>{item.mobileLabel}</span>
            </NavLink>
          )
        })}
        <button type="button" onClick={() => navigate('/housekeeping/dashboard')} aria-label="Profile">
          <UserRound size={18} strokeWidth={2.4} aria-hidden="true" />
          <span>Profile</span>
        </button>
      </nav>
    </section>
  )
}

export default HousekeepingLayout
