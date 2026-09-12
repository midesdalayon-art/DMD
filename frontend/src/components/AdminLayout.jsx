import { Component, Suspense, useEffect, useRef, useState } from 'react'
import {
  BarChart3,
  Boxes,
  CalendarCheck,
  ChevronDown,
  FileText,
  Home,
  Hotel,
  Megaphone,
  Menu,
  MessageSquare,
  Search,
  Settings,
  ShieldCheck,
  UserRound,
  Users,
} from 'lucide-react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'
import { useWaitingSupportCount } from '../stores/supportInboxStore'
import { searchAdminGlobal } from '../lib/api'
import { logDevDiagnostic } from '../lib/devDiagnostics'
import ResortBrandMark from './ResortBrandMark'
import ResortBrandName from './ResortBrandName'
import InlineLoader from './InlineLoader'
import AnnouncementNotificationBell from './AnnouncementNotificationBell'

const sidebarItems = [
  { label: 'Dashboard', to: '/admin/dashboard', icon: Home },
  { label: 'Users', to: '/admin/users', icon: Users },
  { label: 'Accommodations', to: '/admin/accommodations', icon: Hotel },
  { label: 'Bookings', to: '/admin/bookings', icon: CalendarCheck },
  { label: 'Inventory', to: '/admin/inventory', icon: Boxes },
  { label: 'Cleaning Management', to: '/admin/housekeeping', icon: ShieldCheck },
  { label: 'Attendance', to: '/admin/attendance', icon: UserRound },
  { label: 'Reports', to: '/admin/reports', icon: BarChart3 },
  { label: 'Announcements', to: '/admin/announcements', icon: Megaphone },
  { label: 'Guest Support', to: '/admin/support', icon: MessageSquare },
  { label: 'System Logs', to: '/admin/system-logs', icon: FileText },
  { label: 'Settings', to: '/admin/settings', icon: Settings },
]

const adminRouteMeta = {
  '/admin/dashboard': { title: 'Dashboard' },
  '/admin/accommodations': { eyebrow: 'Accommodation Management', title: 'Manage Accommodations' },
  '/admin/bookings': { eyebrow: 'Booking Management', title: 'Manage Bookings' },
  '/admin/users': { eyebrow: 'User Management', title: 'User Management' },
  '/admin/inventory': { eyebrow: 'Inventory Management', title: 'Inventory Management' },
  '/admin/housekeeping': { eyebrow: 'Cleaning', title: 'Cleaning Management' },
  '/admin/attendance': { eyebrow: 'Attendance', title: 'Attendance Management' },
  '/admin/reports': { eyebrow: 'Reports', title: 'Reports & Analytics' },
  '/admin/announcements': { eyebrow: 'Announcements', title: 'Announcement Management' },
  '/admin/system-logs': { eyebrow: 'Audit Trail', title: 'System Logs' },
  '/admin/settings': { eyebrow: 'System', title: 'Settings' },
  '/admin/chatbot': { eyebrow: 'Admin', title: 'Chatbot' },
  '/admin/support': { eyebrow: 'Admin', title: 'Support' },
}

function AdminLayout({ eyebrow, title, children }) {
  const navigate = useNavigate()
  const location = useLocation()
  const { signOut, user } = useAuth()
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(false)
  const [isMobileSidebarOpen, setIsMobileSidebarOpen] = useState(false)
  const [isSidebarProfileMenuOpen, setIsSidebarProfileMenuOpen] = useState(false)
  const [isTopbarProfileMenuOpen, setIsTopbarProfileMenuOpen] = useState(false)
  const [isSigningOut, setIsSigningOut] = useState(false)
  const [globalQuery, setGlobalQuery] = useState('')
  const [globalResults, setGlobalResults] = useState([])
  const [isGlobalSearchOpen, setIsGlobalSearchOpen] = useState(false)
  const [isGlobalSearchLoading, setIsGlobalSearchLoading] = useState(false)
  const [globalSearchIndex, setGlobalSearchIndex] = useState(-1)
  const globalSearchRef = useRef(null)
  const supportCount = useWaitingSupportCount()
  const isSupportRoute = location.pathname.endsWith('/support')
  const routeMeta = adminRouteMeta[location.pathname] ?? {}
  const resolvedEyebrow = eyebrow ?? routeMeta.eyebrow
  const resolvedTitle = title ?? routeMeta.title

  useEffect(() => {
    logDevDiagnostic('admin:layout:mount')
    return () => logDevDiagnostic('admin:layout:unmount')
  }, [])

  useEffect(() => {
    logDevDiagnostic('admin:route:enter', { pathname: location.pathname })
  }, [location.pathname])

  const flatGlobalResults = globalResults.flatMap((group) => group.results.map((result) => ({ ...result, category: group.label, categoryKey: group.key })))

  useEffect(() => {
    function handleOutsideSearchClick(event) {
      if (!globalSearchRef.current?.contains(event.target)) setIsGlobalSearchOpen(false)
    }

    document.addEventListener('mousedown', handleOutsideSearchClick)
    return () => document.removeEventListener('mousedown', handleOutsideSearchClick)
  }, [])

  useEffect(() => {
    const query = globalQuery.trim()
    if (query.length < 2) {
      setGlobalResults([])
      setIsGlobalSearchLoading(false)
      setGlobalSearchIndex(-1)
      return undefined
    }

    const controller = new AbortController()
    const timeoutId = window.setTimeout(async () => {
      setIsGlobalSearchLoading(true)

      try {
        const results = await searchAdminGlobal(query, controller.signal)
        setGlobalResults(Array.isArray(results) ? results : [])
        setGlobalSearchIndex(-1)
      } catch (error) {
        if (error?.name !== 'CanceledError' && error?.name !== 'AbortError') setGlobalResults([])
      } finally {
        if (!controller.signal.aborted) setIsGlobalSearchLoading(false)
      }
    }, 300)

    return () => {
      window.clearTimeout(timeoutId)
      controller.abort()
    }
  }, [globalQuery])

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

  function openGlobalSearchResult(result) {
    navigate(result.path)
    setGlobalQuery('')
    setGlobalResults([])
    setIsGlobalSearchOpen(false)
    setGlobalSearchIndex(-1)
  }

  function handleGlobalSearchKeyDown(event) {
    if (event.key === 'Escape') {
      setIsGlobalSearchOpen(false)
      setGlobalSearchIndex(-1)
      return
    }

    if (event.key === 'ArrowDown' && flatGlobalResults.length > 0) {
      event.preventDefault()
      setIsGlobalSearchOpen(true)
      setGlobalSearchIndex((current) => (current + 1) % flatGlobalResults.length)
    }

    if (event.key === 'ArrowUp' && flatGlobalResults.length > 0) {
      event.preventDefault()
      setIsGlobalSearchOpen(true)
      setGlobalSearchIndex((current) => (current <= 0 ? flatGlobalResults.length - 1 : current - 1))
    }

    if (event.key === 'Enter' && globalSearchIndex >= 0 && flatGlobalResults[globalSearchIndex]) {
      event.preventDefault()
      openGlobalSearchResult(flatGlobalResults[globalSearchIndex])
    }
  }

  return (
    <section
      className={`admin-shell ${isSupportRoute ? 'is-support-route' : ''} ${isSidebarCollapsed ? 'is-collapsed' : ''} ${
        isMobileSidebarOpen ? 'is-mobile-open' : ''
      }`}
    >
      <button
        className="admin-mobile-backdrop"
        type="button"
        aria-label="Close admin navigation"
        onClick={closeMobileSidebar}
      />

      <aside className="admin-sidebar" aria-label="Admin navigation">
        <div className="admin-sidebar-brand">
          <ResortBrandMark className="admin-brand-icon" size="large" />
          <div>
            <ResortBrandName as="strong" className="resort-brand-name" />
            <small>Admin Console</small>
          </div>
        </div>

        <nav className="admin-sidebar-nav">
          {sidebarItems.map((item) => {
            const Icon = item.icon

            return item.disabled ? (
              <button key={item.label} type="button" disabled title="Module pending implementation">
                <Icon size={20} strokeWidth={2.4} aria-hidden="true" />
                <span>{item.label}</span>
              </button>
            ) : (
              <NavLink
                key={item.label}
                to={item.to}
                className={({ isActive }) => (isActive ? 'is-active' : '')}
                onClick={() => {
                  logDevDiagnostic('admin:navigation', { target: item.to })
                  closeMobileSidebar()
                }}
              >
                <Icon size={20} strokeWidth={2.4} aria-hidden="true" />
                <span>{item.label}</span>
                {item.label === 'Guest Support' && supportCount > 0 ? <span className="admin-nav-badge">{supportCount}</span> : null}
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
            <span>{user?.first_name?.charAt(0) ?? 'A'}</span>
            <div>
              <strong>{user?.name ?? 'Administrator'}</strong>
              <small>Administrator</small>
            </div>
            <ChevronDown size={15} aria-hidden="true" />
          </div>

          <div className={`admin-profile-panel ${isSidebarProfileMenuOpen ? 'is-open' : ''}`} role="menu">
            <div>
              <strong>{user?.name ?? 'Administrator'}</strong>
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
            aria-label="Open admin navigation"
          >
            <Menu size={23} strokeWidth={2.5} aria-hidden="true" />
          </button>

          <div className="admin-page-title">
            {resolvedEyebrow ? <p className="eyebrow">{resolvedEyebrow}</p> : null}
            <h1>{resolvedTitle}</h1>
          </div>

          <div className="admin-topbar-actions">
            <label className="admin-header-search" ref={globalSearchRef}>
              <span>Search</span>
              <input
                type="search"
                value={globalQuery}
                placeholder="Search anything..."
                aria-label="Search Admin data"
                aria-expanded={isGlobalSearchOpen && globalQuery.trim().length >= 2}
                aria-controls="admin-global-search-results"
                onChange={(event) => {
                  setGlobalQuery(event.target.value)
                  setIsGlobalSearchOpen(event.target.value.trim().length >= 2)
                }}
                onFocus={() => setIsGlobalSearchOpen(globalQuery.trim().length >= 2)}
                onKeyDown={handleGlobalSearchKeyDown}
              />
              <Search size={18} aria-hidden="true" />
              {isGlobalSearchOpen && globalQuery.trim().length >= 2 ? (
                <div className="admin-global-search-results" id="admin-global-search-results" role="listbox">
                  {isGlobalSearchLoading ? <div className="admin-global-search-status sr-only" role="status">Searching...</div> : null}
                  {!isGlobalSearchLoading && flatGlobalResults.length === 0 ? <div className="admin-global-search-status">No results found</div> : null}
                  {!isGlobalSearchLoading ? globalResults.map((group) => {
                    const Icon = group.key === 'bookings'
                      ? CalendarCheck
                      : group.key === 'accommodations'
                        ? Hotel
                        : group.key === 'users'
                          ? Users
                          : group.key === 'inventory'
                            ? Boxes
                            : group.key === 'support'
                              ? MessageSquare
                              : FileText

                    return (
                      <div className="admin-global-search-group" key={group.key}>
                        <p>{group.label}</p>
                        {group.results.map((result) => {
                          const resultIndex = flatGlobalResults.findIndex((item) => item.id === result.id && item.categoryKey === group.key)
                          return (
                            <button
                              key={`${group.key}-${result.id}`}
                              className={resultIndex === globalSearchIndex ? 'is-active' : ''}
                              type="button"
                              role="option"
                              aria-selected={resultIndex === globalSearchIndex}
                              onMouseDown={(event) => event.preventDefault()}
                              onClick={() => openGlobalSearchResult({ ...result, category: group.label, categoryKey: group.key })}
                            >
                              <Icon size={16} aria-hidden="true" />
                              <span><strong>{result.title}</strong><small>{result.subtitle}</small></span>
                            </button>
                          )
                        })}
                      </div>
                    )
                  }) : null}
                </div>
              ) : null}
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
                <span>{user?.first_name?.charAt(0) ?? 'A'}</span>
                <div>
                  <strong>{user?.name ?? 'Administrator'}</strong>
                  <small>Administrator</small>
                </div>
                <ChevronDown size={17} aria-hidden="true" />
              </button>
              <div
                className={`admin-profile-panel ${isTopbarProfileMenuOpen ? 'is-open' : ''}`}
                role="menu"
              >
                <div>
                  <strong>{user?.name ?? 'Administrator'}</strong>
                  <small>{user?.email}</small>
                </div>
                <button type="button" role="menuitem" onClick={handleSignOut}>
                  {isSigningOut ? 'Signing Out...' : 'Sign Out'}
                </button>
              </div>
            </div>
          </div>
        </header>

        <main className={`admin-dashboard-content ${location.pathname.endsWith('/support') ? 'admin-dashboard-content--support' : ''}`}>
          <AdminModuleBoundary key={location.pathname} routePath={location.pathname}>
            <Suspense
              fallback={
                <section className="admin-route-loading" aria-label="Loading admin module">
                  <InlineLoader message="Loading module..." />
                </section>
              }
            >
              {children ?? <Outlet />}
            </Suspense>
          </AdminModuleBoundary>
        </main>
      </div>
    </section>
  )
}

class AdminModuleBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { error: null }
  }

  static getDerivedStateFromError(error) {
    return { error }
  }

  componentDidCatch(error, info) {
    logDevDiagnostic('admin:module-render-error', {
      route: this.props.routePath,
      name: error?.name,
      message: error?.message,
      stack: error?.stack,
      componentStack: info?.componentStack,
    })
    console.error('Admin module rendering error:', error, info)
  }

  render() {
    if (this.state.error) {
      return (
        <section className="admin-module-error" role="alert">
          <strong>This module could not be displayed.</strong>
          <p>Return to another Admin section or try loading this module again.</p>
          <button className="outline-button" type="button" onClick={() => this.setState({ error: null })}>
            Try again
          </button>
        </section>
      )
    }

    return this.props.children
  }
}

export default AdminLayout
