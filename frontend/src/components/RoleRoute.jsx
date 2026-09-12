import { Navigate, useLocation } from 'react-router-dom'
import ProtectedRoute from './ProtectedRoute'
import { useAuth } from '../hooks/useAuth'

const roleDashboardPaths = {
  admin: '/admin/dashboard',
  manager: '/manager/dashboard',
  front_desk_staff: '/frontdesk/dashboard',
  guest: '/account',
}

function dashboardPathForUser(user) {
  return user?.redirect_to || roleDashboardPaths[user?.role] || '/'
}

function RoleRoute({ allowedRoles, children }) {
  const { user, isLoading } = useAuth()
  const location = useLocation()

  if (!isLoading && user && !allowedRoles.includes(user.role)) {
    const destination = dashboardPathForUser(user)

    return <Navigate to={destination === location.pathname ? '/' : destination} replace />
  }

  return <ProtectedRoute>{children}</ProtectedRoute>
}

export default RoleRoute
