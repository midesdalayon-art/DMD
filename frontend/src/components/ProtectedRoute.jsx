import { Navigate, useLocation } from 'react-router-dom'
import LoadingState from './LoadingState'
import { useAuth } from '../hooks/useAuth'

function ProtectedRoute({ children }) {
  const { authError, isAuthenticated, isLoading, isSigningOut, refreshUser } = useAuth()
  const location = useLocation()

  if (authError) {
    return (
      <section className="route-error-state site-shell page-section" role="alert">
        <h1>Unable to verify your session.</h1>
        <p>The server connection was interrupted. Your session was not cleared.</p>
        <button className="solid-button" type="button" onClick={() => refreshUser().catch(() => undefined)}>
          Try again
        </button>
      </section>
    )
  }

  if (isLoading) {
    return <LoadingState message="Checking your session..." />
  }

  if (!isAuthenticated) {
    if (isSigningOut) {
      return <Navigate to="/" replace />
    }

    return <Navigate to="/login" replace state={{ from: location }} />
  }

  return children
}

export default ProtectedRoute
