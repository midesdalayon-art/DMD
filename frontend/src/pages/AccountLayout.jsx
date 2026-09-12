import { NavLink } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'

const accountLinks = [
  { to: '/account', label: 'Overview', end: true },
  { to: '/account/profile', label: 'Profile' },
  { to: '/account/bookings', label: 'My Bookings' },
  { to: '/account/security', label: 'Security' },
  { to: '/account/payments', label: 'Payments' },
]

function AccountLayout({ children, title, eyebrow = 'Guest Portal', description }) {
  const { user } = useAuth()
  const firstName = user?.first_name ?? 'Guest'

  return (
    <section className="site-shell account-page">
      <header className="account-header">
        <div>
          <p className="eyebrow">{eyebrow}</p>
          <h1>Welcome back, {firstName}</h1>
          <p>{description}</p>
        </div>
        <div className="account-identity" aria-label="Signed in account">
          <span>{firstName.charAt(0) ?? 'G'}</span>
          <div>
            <strong>{user?.first_name ? `${user.first_name} ${user.last_name ?? ''}` : 'Guest'}</strong>
            <small>{user?.email}</small>
          </div>
        </div>
      </header>

      <nav className="account-mobile-nav" aria-label="Guest account sections">
        {accountLinks.map((item) => (
          <NavLink key={item.to} to={item.to} end={item.end}>
            {item.label}
          </NavLink>
        ))}
      </nav>

      <nav className="account-tabs" aria-label="Guest account sections">
        {accountLinks.map((item) => (
          <NavLink key={item.to} to={item.to} end={item.end}>
            {item.label}
          </NavLink>
        ))}
      </nav>

      <div className="account-layout">
        <nav className="account-nav" aria-label="Guest account navigation">
          {accountLinks.map((item) => (
            <NavLink key={item.to} to={item.to} end={item.end}>
              {item.label}
            </NavLink>
          ))}
        </nav>

        <div className="account-content">{children}</div>
      </div>
    </section>
  )
}

export default AccountLayout
