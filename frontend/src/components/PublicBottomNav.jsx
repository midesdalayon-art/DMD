import { useState } from 'react'
import { BookOpen, Home, Search, UserRound } from 'lucide-react'
import { NavLink, useNavigate } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'
import AuthModal from './AuthModal'

function PublicBottomNav() {
  const navigate = useNavigate()
  const { isAuthenticated, user } = useAuth()
  const [isAuthOpen, setIsAuthOpen] = useState(false)

  function handleAccount() {
    if (!isAuthenticated) {
      setIsAuthOpen(true)
      return
    }

    if (user?.role === 'admin') {
      navigate('/admin/dashboard')
      return
    }

    if (user?.role === 'manager') {
      navigate('/manager/dashboard')
      return
    }

    navigate('/account')
  }

  function handleBookings() {
    if (!isAuthenticated) {
      setIsAuthOpen(true)
      return
    }

    navigate('/account/bookings')
  }

  return (
    <>
      <nav className="public-bottom-nav" aria-label="Public quick navigation">
        <NavLink to="/" end>
          <Home size={18} aria-hidden="true" />
          <span>Home</span>
        </NavLink>
        <NavLink to="/rooms">
          <Search size={18} aria-hidden="true" />
          <span>Explore</span>
        </NavLink>
        <button type="button" onClick={handleBookings}>
          <BookOpen size={18} aria-hidden="true" />
          <span>Bookings</span>
        </button>
        <button type="button" onClick={handleAccount}>
          <UserRound size={18} aria-hidden="true" />
          <span>Account</span>
        </button>
      </nav>
      {isAuthOpen ? <AuthModal initialMode="signin" onClose={() => setIsAuthOpen(false)} /> : null}
    </>
  )
}

export default PublicBottomNav
