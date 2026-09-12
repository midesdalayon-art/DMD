import { useEffect, useState } from 'react'
import { Link, NavLink, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'
import AuthModal from './AuthModal'
import SearchBar from './SearchBar'
import ResortBrandMark from './ResortBrandMark'
import ResortBrandName from './ResortBrandName'

const navItems = [
  { to: '/', label: 'Home' },
  { to: '/about', label: 'About Us' },
  { to: '/contact', label: 'Contact' },
]

function Header() {
  const [isOpen, setIsOpen] = useState(false)
  const [isAccountOpen, setIsAccountOpen] = useState(false)
  const [authModalMode, setAuthModalMode] = useState(null)
  const [authRedirectLocation, setAuthRedirectLocation] = useState(null)
  const [isSigningOut, setIsSigningOut] = useState(false)
  const [hasScrolledSearch, setHasScrolledSearch] = useState(false)
  const location = useLocation()
  const navigate = useNavigate()
  const { isAuthenticated, isLoading, signOut, user } = useAuth()
  const supportsMorphSearch = location.pathname === '/' || location.pathname === '/rooms'

  useEffect(() => {
    let animationFrame = 0

    function updateScrollState() {
      window.cancelAnimationFrame(animationFrame)
      animationFrame = window.requestAnimationFrame(() => {
        setHasScrolledSearch(window.scrollY > 84)
      })
    }

    updateScrollState()
    window.addEventListener('scroll', updateScrollState, { passive: true })

    return () => {
      window.cancelAnimationFrame(animationFrame)
      window.removeEventListener('scroll', updateScrollState)
    }
  }, [location.pathname])

  useEffect(() => {
    if (location.state?.openAuthModal !== 'signin') {
      return
    }

    setAuthModalMode('signin')
    setAuthRedirectLocation(location.state?.from ?? null)
    navigate(location.pathname, { replace: true, state: null })
  }, [location.pathname, location.state, navigate])

  const closeMenu = () => {
    setIsOpen(false)
    setIsAccountOpen(false)
  }

  function openAuthModal(mode) {
    closeMenu()
    setAuthRedirectLocation(null)
    setAuthModalMode(mode)
  }

  function closeAuthModal() {
    setAuthRedirectLocation(null)
    setAuthModalMode(null)
  }

  async function handleSignOut() {
    setIsSigningOut(true)

    try {
      await signOut()
      closeMenu()
      navigate('/', { replace: true })
    } finally {
      setIsSigningOut(false)
    }
  }

  const accountLinks = user?.role === 'admin'
    ? [{ to: '/admin/dashboard', label: 'Admin Dashboard' }]
    : user?.role === 'manager'
      ? [{ to: '/manager/dashboard', label: 'Manager Dashboard' }]
      : [
          { to: '/account', label: 'My Account' },
          { to: '/account/bookings', label: 'My Bookings' },
        ]

  return (
    <header
      className={`site-header ${
        supportsMorphSearch && hasScrolledSearch ? 'has-compact-search' : ''
      }`}
    >
      <div className="site-shell header-inner">
        <Link className="brand" to="/" onClick={closeMenu}>
          <ResortBrandMark />
          <ResortBrandName className="resort-brand-name" />
        </Link>

        {supportsMorphSearch ? (
          <div
            className="header-search-slot"
            aria-hidden={!hasScrolledSearch}
            inert={!hasScrolledSearch}
          >
            <SearchBar className="header-search-bar" isCompact />
          </div>
        ) : null}

        {supportsMorphSearch ? (
          <div className="mobile-header-search">
            <SearchBar className="mobile-header-search-bar" isMobileTrigger />
          </div>
        ) : null}

        <button
          className="menu-toggle"
          type="button"
          aria-label="Toggle navigation menu"
          aria-expanded={isOpen}
          onClick={() => setIsOpen((current) => !current)}
        >
          <span />
          <span />
          <span />
        </button>

        <nav className={`primary-nav ${isOpen ? 'is-open' : ''}`}>
          <div className="nav-links">
            {navItems.map((item) => (
              <NavLink
                key={item.to}
                className="nav-link"
                to={item.to}
                onClick={closeMenu}
                end={item.to === '/'}
              >
                {item.label}
              </NavLink>
            ))}
          </div>
          <div className="nav-actions">
            {isAuthenticated ? (
              <div className="account-menu">
                <button
                  className="link-button account-menu-trigger"
                  type="button"
                  aria-haspopup="menu"
                  aria-expanded={isAccountOpen}
                  onClick={() => setIsAccountOpen((current) => !current)}
                >
                  {user?.first_name ? `Hi, ${user.first_name}` : 'Account'}
                  <span aria-hidden="true">v</span>
                </button>
                <div className={`account-menu-panel ${isAccountOpen ? 'is-open' : ''}`} role="menu">
                  {accountLinks.map((item) => (
                    <NavLink key={item.to} to={item.to} role="menuitem" onClick={closeMenu}>
                      {item.label}
                    </NavLink>
                  ))}
                  <button type="button" role="menuitem" onClick={handleSignOut} disabled={isSigningOut}>
                    {isSigningOut ? 'Signing Out...' : 'Sign Out'}
                  </button>
                </div>
              </div>
            ) : (
              <button className="link-button" type="button" onClick={() => openAuthModal('signin')}>
                {isLoading ? 'Account' : 'Sign In'}
              </button>
            )}
            <NavLink className="solid-button" to="/rooms" onClick={closeMenu}>
              Book Now
            </NavLink>
          </div>
        </nav>
      </div>
      {authModalMode ? (
        <AuthModal
          initialMode={authModalMode}
          redirectLocation={authRedirectLocation}
          onClose={closeAuthModal}
        />
      ) : null}
    </header>
  )
}

export default Header
