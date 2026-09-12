import { useEffect, useMemo, useRef, useState } from 'react'
import { NavLink } from 'react-router-dom'
import {
  CalendarClock,
  BookOpenText,
  ChevronLeft,
  ChevronRight,
  Home,
  LogOut,
  Menu,
  Settings,
  UserRound,
  WalletCards,
  X,
} from 'lucide-react'
import { useAuth } from '../hooks/useAuth'
import { useResortBranding } from '../hooks/useResortBranding'
import { resolveAssetUrl } from '../lib/api'
import AnnouncementNotificationBell from '../components/AnnouncementNotificationBell'

const accountLinks = [
  { to: '/account', label: 'Dashboard', icon: Home, end: true },
  { to: '/account/bookings', label: 'My Bookings', icon: BookOpenText },
  { to: '/account/payments', label: 'Payments', icon: WalletCards },
  { to: '/account/settings', label: 'Settings', icon: Settings },
]

function CustomerAccountLayout({ children, title, eyebrow = 'Guest Portal', description }) {
  const { user, signOut } = useAuth()
  const { resortName, branding } = useResortBranding()
  const [isCollapsed, setIsCollapsed] = useState(false)
  const [isDrawerOpen, setIsDrawerOpen] = useState(false)
  const [isUserMenuOpen, setIsUserMenuOpen] = useState(false)
  const userMenuRef = useRef(null)

  const firstName = user?.first_name ?? 'Guest'
  const displayName = user?.first_name ? `${user.first_name} ${user.last_name ?? ''}`.trim() : 'Guest'
  const portalInitial = useMemo(() => firstName.charAt(0).toUpperCase() || 'G', [firstName])
  const portalLogo = resolveAssetUrl(branding?.logo_url)
  const mobilePortalName = resortName?.toUpperCase?.() ?? 'DMD FAMILY RESORT'

  useEffect(() => {
    document.body.classList.toggle('is-guest-drawer-open', isDrawerOpen)

    return () => {
      document.body.classList.remove('is-guest-drawer-open')
    }
  }, [isDrawerOpen])

  useEffect(() => {
    setIsUserMenuOpen(false)
  }, [isDrawerOpen, isCollapsed])

  useEffect(() => {
    function handleDocumentClick(event) {
      if (!userMenuRef.current?.contains(event.target)) {
        setIsUserMenuOpen(false)
      }
    }

    document.addEventListener('mousedown', handleDocumentClick)

    return () => {
      document.removeEventListener('mousedown', handleDocumentClick)
    }
  }, [])

  async function handleSignOut() {
    await signOut()
  }

  function handleNavigate() {
    setIsDrawerOpen(false)
    setIsUserMenuOpen(false)
  }

  return (
    <section className={`customer-portal-shell ${isCollapsed ? 'is-collapsed' : ''}`}>
      <aside className={`customer-portal-sidebar ${isDrawerOpen ? 'is-open' : ''}`}>
        <div className="customer-portal-sidebar-header">
          {portalLogo ? (
            <img className="customer-portal-logo" src={portalLogo} alt={`${resortName} logo`} />
          ) : (
            <div className="customer-portal-logo customer-portal-logo-fallback" aria-hidden="true">
              D
            </div>
          )}
          {!isCollapsed ? (
            <div className="customer-portal-brand">
              <strong>{resortName}</strong>
              <span>{eyebrow}</span>
            </div>
          ) : null}
          <button
            type="button"
            className="customer-portal-close"
            onClick={() => setIsDrawerOpen(false)}
            aria-label="Close account menu"
          >
            <X size={18} aria-hidden="true" />
          </button>
        </div>

        <nav className="customer-portal-nav" aria-label="Customer account navigation">
          {accountLinks.map((item) => {
            const Icon = item.icon

            return (
              <NavLink key={item.to} to={item.to} end={item.end} onClick={handleNavigate}>
                <Icon size={18} aria-hidden="true" />
                <span>{item.label}</span>
              </NavLink>
            )
          })}
        </nav>

        <NavLink className="customer-portal-book" to="/rooms" onClick={handleNavigate}>
          <BookOpenText size={18} aria-hidden="true" />
          <span>Book a Stay</span>
        </NavLink>

        <div className="customer-portal-user-card">
          <div className="customer-portal-avatar" aria-hidden="true">
            {portalInitial}
          </div>
          {!isCollapsed ? (
            <div className="customer-portal-user-copy">
              <strong>{displayName}</strong>
              <small>{user?.email ?? 'Guest access'}</small>
            </div>
          ) : null}
          {!isCollapsed ? (
            <button type="button" className="customer-portal-signout" onClick={handleSignOut}>
              <LogOut size={16} aria-hidden="true" />
              <span>Sign Out</span>
            </button>
          ) : (
            <button
              type="button"
              className="customer-portal-signout-icon"
              onClick={handleSignOut}
              aria-label="Sign out"
            >
              <LogOut size={16} aria-hidden="true" />
            </button>
          )}
        </div>
      </aside>

      {isDrawerOpen ? (
        <button
          type="button"
          className="customer-portal-backdrop"
          onClick={() => setIsDrawerOpen(false)}
          aria-label="Close account drawer"
        />
      ) : null}

      <div className="customer-portal-main">
        <header className="customer-portal-mobile-topbar" aria-label="Guest portal mobile header">
          <div className="customer-portal-mobile-topbar-copy">
            <strong>{mobilePortalName}</strong>
            <span>{eyebrow}</span>
          </div>
          <div className="customer-portal-mobile-topbar-actions">
            <NavLink className="customer-portal-mobile-avatar" to="/account/settings?tab=profile" aria-label="Open settings">
              <span aria-hidden="true">{portalInitial}</span>
            </NavLink>
          </div>
        </header>

        <header className="customer-portal-topbar">
          <div className="customer-portal-topbar-title">
            <button
              type="button"
              className="customer-portal-menu-button"
              onClick={() => setIsDrawerOpen(true)}
              aria-label="Open account menu"
            >
              <Menu size={20} aria-hidden="true" />
            </button>
            <div>
              <p className="eyebrow">{eyebrow}</p>
              <h1>{title}</h1>
            </div>
          </div>

          <div className="customer-portal-topbar-actions">
            <button
              type="button"
              className="customer-portal-collapse-button"
              onClick={() => setIsCollapsed((value) => !value)}
              aria-label={isCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            >
              {isCollapsed ? <ChevronRight size={16} aria-hidden="true" /> : <ChevronLeft size={16} aria-hidden="true" />}
            </button>
            <AnnouncementNotificationBell />
            <div className="customer-portal-user" ref={userMenuRef}>
              <button
                type="button"
                className="customer-portal-user-button"
                onClick={() => setIsUserMenuOpen((current) => !current)}
              >
                <span className="customer-portal-avatar customer-portal-avatar-small">{portalInitial}</span>
                <span>{firstName}</span>
              </button>
              {isUserMenuOpen ? (
                <div className="customer-portal-user-menu" role="menu">
                  <div className="customer-portal-user-menu-copy">
                    <strong>{displayName}</strong>
                    <small>{user?.email}</small>
                  </div>
                  <button type="button" onClick={handleSignOut}>
                    <LogOut size={16} aria-hidden="true" />
                    <span>Sign Out</span>
                  </button>
                </div>
              ) : null}
            </div>
          </div>
        </header>

        {description ? (
          <div className="customer-portal-intro">
            <p>{description}</p>
          </div>
        ) : null}

        <div className="customer-portal-content">{children}</div>
      </div>

      <nav className="customer-portal-bottom-nav" aria-label="Guest account navigation">
        <NavLink to="/account" end onClick={handleNavigate}>
          <Home size={18} aria-hidden="true" />
          <span>Home</span>
        </NavLink>
        <NavLink to="/account/bookings" onClick={handleNavigate}>
          <CalendarClock size={18} aria-hidden="true" />
          <span>Bookings</span>
        </NavLink>
        <NavLink to="/rooms" onClick={handleNavigate} aria-label="Book a stay">
          <BookOpenText size={18} aria-hidden="true" />
          <span>Book</span>
        </NavLink>
        <NavLink to="/account/profile" onClick={handleNavigate}>
          <UserRound size={18} aria-hidden="true" />
          <span>Profile</span>
        </NavLink>
      </nav>
    </section>
  )
}

export default CustomerAccountLayout
