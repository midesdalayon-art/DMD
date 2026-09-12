import { Link } from 'react-router-dom'
import SectionHeading from '../components/SectionHeading'

function AuthPlaceholder({ mode }) {
  const isRegister = mode === 'register'

  return (
    <section className="site-shell page-section narrow-page">
      <SectionHeading
        eyebrow={isRegister ? 'Register' : 'Sign In'}
        title={isRegister ? 'Create an account' : 'Sign in to continue'}
      >
        Authentication routes are reserved for the existing or future Laravel
        auth flow.
      </SectionHeading>
      <div className="auth-placeholder">
        <p>
          This public UI does not implement authentication logic. Connect this
          route to the proper backend flow when it is ready.
        </p>
        <Link className="outline-button" to="/rooms">
          Browse Accommodations
        </Link>
      </div>
    </section>
  )
}

export default AuthPlaceholder
