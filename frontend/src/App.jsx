import { Component, lazy, Suspense, useEffect } from 'react'
import { Navigate, Route, Routes, useLocation } from 'react-router-dom'
import Footer from './components/Footer'
import Header from './components/Header'
import PublicBottomNav from './components/PublicBottomNav'
import RoleRoute from './components/RoleRoute'
import About from './pages/About'
import AccommodationDetail from './pages/AccommodationDetail'
import AuthFoundation from './pages/AuthFoundation'
import Contact from './pages/Contact'
import Facilities from './pages/Facilities'
import Home from './pages/Home'
import Rooms from './pages/Rooms'
import Register from './pages/Register'
import VerifyEmail from './pages/VerifyEmail'
import BookingPaymentCancel from './pages/BookingPaymentCancel'
import BookingPaymentSuccess from './pages/BookingPaymentSuccess'
import GuestBooking from './pages/GuestBooking'
import FunctionHall from './pages/FunctionHall'
import ExclusiveResort from './pages/ExclusiveResort'
import NotFound from './pages/NotFound'
import { BookingSearchProvider } from './context/BookingSearchContext'
import InlineLoader from './components/InlineLoader'
import './App.css'

const AccountBookingDetail = lazyWithDevelopmentRecovery(() => import('./pages/AccountBookingDetail'), 'AccountBookingDetail')
const AccountBookings = lazyWithDevelopmentRecovery(() => import('./pages/AccountBookings'), 'AccountBookings')
const AccountOverview = lazyWithDevelopmentRecovery(() => import('./pages/AccountOverview'), 'AccountOverview')
const AccountSettings = lazyWithDevelopmentRecovery(() => import('./pages/AccountSettings'), 'AccountSettings')
const AccountPayments = lazyWithDevelopmentRecovery(() => import('./pages/AccountPayments'), 'AccountPayments')
const AdminChatbot = lazyWithDevelopmentRecovery(() => import('./pages/AdminChatbot'), 'AdminChatbot')
const AdminAccommodations = lazyWithDevelopmentRecovery(() => import('./pages/AdminAccommodations'), 'AdminAccommodations')
const AdminAnnouncements = lazyWithDevelopmentRecovery(() => import('./pages/AdminAnnouncements'), 'AdminAnnouncements')
const AdminAttendance = lazyWithDevelopmentRecovery(() => import('./pages/AdminAttendance'), 'AdminAttendance')
const AdminBookings = lazyWithDevelopmentRecovery(() => import('./pages/AdminBookings'), 'AdminBookings')
const AdminDashboard = lazyWithDevelopmentRecovery(() => import('./pages/AdminDashboard'), 'AdminDashboard')
const AdminHousekeeping = lazyWithDevelopmentRecovery(() => import('./pages/AdminHousekeeping'), 'AdminHousekeeping')
const AdminInventory = lazyWithDevelopmentRecovery(() => import('./pages/AdminInventory'), 'AdminInventory')
const AdminReports = lazyWithDevelopmentRecovery(() => import('./pages/AdminReports'), 'AdminReports')
const AdminSettings = lazyWithDevelopmentRecovery(() => import('./pages/AdminSettings'), 'AdminSettings')
const AdminSystemLogs = lazyWithDevelopmentRecovery(() => import('./pages/AdminSystemLogs'), 'AdminSystemLogs')
const AdminUsers = lazyWithDevelopmentRecovery(() => import('./pages/AdminUsers'), 'AdminUsers')
const FrontDeskAccommodations = lazyWithDevelopmentRecovery(() => import('./pages/FrontDeskAccommodations'), 'FrontDeskAccommodations')
const FrontDeskAnnouncements = lazyWithDevelopmentRecovery(() => import('./pages/FrontDeskAnnouncements'), 'FrontDeskAnnouncements')
const FrontDeskBookings = lazyWithDevelopmentRecovery(() => import('./pages/FrontDeskBookings'), 'FrontDeskBookings')
const FrontDeskCheckIn = lazyWithDevelopmentRecovery(() => import('./pages/FrontDeskCheckIn'), 'FrontDeskCheckIn')
const FrontDeskDashboard = lazyWithDevelopmentRecovery(() => import('./pages/FrontDeskDashboard'), 'FrontDeskDashboard')
const ManagerAccommodations = lazyWithDevelopmentRecovery(() => import('./pages/ManagerAccommodations'), 'ManagerAccommodations')
const ManagerAnnouncements = lazyWithDevelopmentRecovery(() => import('./pages/ManagerAnnouncements'), 'ManagerAnnouncements')
const ManagerAttendance = lazyWithDevelopmentRecovery(() => import('./pages/ManagerAttendance'), 'ManagerAttendance')
const ManagerBookings = lazyWithDevelopmentRecovery(() => import('./pages/ManagerBookings'), 'ManagerBookings')
const ManagerDashboard = lazyWithDevelopmentRecovery(() => import('./pages/ManagerDashboard'), 'ManagerDashboard')
const ManagerHousekeeping = lazyWithDevelopmentRecovery(() => import('./pages/ManagerHousekeeping'), 'ManagerHousekeeping')
const ManagerInventory = lazyWithDevelopmentRecovery(() => import('./pages/ManagerInventory'), 'ManagerInventory')
const ManagerReports = lazyWithDevelopmentRecovery(() => import('./pages/ManagerReports'), 'ManagerReports')
const SupportInboxPage = lazyWithDevelopmentRecovery(() => import('./pages/SupportInboxPage'), 'SupportInboxPage')
const AdminLayout = lazyWithDevelopmentRecovery(() => import('./components/AdminLayout'), 'AdminLayout')
const ManagerLayout = lazyWithDevelopmentRecovery(() => import('./components/ManagerLayout'), 'ManagerLayout')
const FrontDeskLayout = lazyWithDevelopmentRecovery(() => import('./components/FrontDeskLayout'), 'FrontDeskLayout')
import ResortChatWidget from './components/ResortChatWidget'
import { useResortBranding } from './hooks/useResortBranding'
import { logDevDiagnostic } from './lib/devDiagnostics'

function lazyWithDevelopmentRecovery(importer, moduleName) {
  return lazy(async () => {
    try {
      const module = await importer()

      if (import.meta.env.DEV && typeof window !== 'undefined') {
        window.sessionStorage.removeItem(`dmd-lazy-recovery:${moduleName}`)
      }

      return module
    } catch (error) {
      if (import.meta.env.DEV && typeof window !== 'undefined') {
        const recoveryKey = `dmd-lazy-recovery:${moduleName}`

        if (window.sessionStorage.getItem(recoveryKey) !== 'used') {
          window.sessionStorage.setItem(recoveryKey, 'used')
          window.location.reload()
          return new Promise(() => {})
        }
      }

      throw error
    }
  })
}

const authRoutes = ['/login', '/register', '/verify-email', '/forgot-password']

function App() {
  const location = useLocation()
  const { branding } = useResortBranding()
  const isAuthRoute = authRoutes.includes(location.pathname)
  const isVerifyEmailRoute = location.pathname === '/verify-email'
  const isStandaloneAuthRoute = isAuthRoute && !isVerifyEmailRoute
  const isAdminRoute = location.pathname.startsWith('/admin')
  const isManagerRoute = location.pathname.startsWith('/manager')
  const isFrontDeskRoute = location.pathname.startsWith('/frontdesk')
  const isAccountRoute = location.pathname.startsWith('/account')

  useEffect(() => {
    logDevDiagnostic('router:location', { pathname: location.pathname, search: location.search })
  }, [location.pathname, location.search])

  useEffect(() => {
    const faviconHref = branding?.favicon_url || '/favicon.svg'

    let link = document.querySelector('link[rel="icon"]')

    if (!link) {
      link = document.createElement('link')
      link.rel = 'icon'
      document.head.appendChild(link)
    }

    link.href = faviconHref

    return undefined
  }, [branding?.favicon_url])

  return (
    <div className="app">
      <BookingSearchProvider>
        {!isStandaloneAuthRoute && !isAdminRoute && !isManagerRoute && !isFrontDeskRoute && !isAccountRoute && <Header />}
        <main>
          <RouteErrorBoundary key={location.pathname} routePath={location.pathname}>
            <Suspense fallback={<RouteLoadingFallback />}>
              <Routes>
          <Route path="/" element={<Home />} />
          <Route path="/rooms" element={<Rooms />} />
          <Route path="/rooms/:id" element={<AccommodationDetail />} />
          <Route path="/function-hall" element={<FunctionHall />} />
          <Route path="/exclusive-resort" element={<ExclusiveResort />} />
          <Route path="/exclusive-resort/:id" element={<AccommodationDetail />} />
          <Route path="/facilities" element={<Facilities />} />
          <Route path="/about" element={<About />} />
          <Route path="/contact" element={<Contact />} />
          <Route path="/login" element={<LoginRedirect />} />
          <Route path="/register" element={<Register />} />
          <Route path="/verify-email" element={<VerifyEmail />} />
          <Route path="/forgot-password" element={<AuthFoundation mode="forgotPassword" />} />
          <Route path="/booking/payment/success" element={<BookingPaymentSuccess />} />
          <Route path="/booking/payment/cancelled" element={<BookingPaymentCancel />} />
          <Route path="/guest/booking" element={<GuestBooking />} />
          <Route path="/guest/booking/:token" element={<GuestBooking />} />
          <Route
            path="/account"
            element={
              <RoleRoute allowedRoles={['guest']}>
                <AccountOverview />
              </RoleRoute>
            }
          />
          <Route path="/account/profile" element={<Navigate to="/account/settings?tab=profile" replace />} />
          <Route
            path="/account/bookings"
            element={
              <RoleRoute allowedRoles={['guest']}>
                <AccountBookings />
              </RoleRoute>
            }
          />
          <Route
            path="/account/bookings/:id"
            element={
              <RoleRoute allowedRoles={['guest']}>
                <AccountBookingDetail />
              </RoleRoute>
            }
          />
          <Route path="/account/security" element={<Navigate to="/account/settings?tab=security" replace />} />
          <Route
            path="/account/settings"
            element={
              <RoleRoute allowedRoles={['guest']}>
                <AccountSettings />
              </RoleRoute>
            }
          />
          <Route
            path="/account/payments"
            element={
              <RoleRoute allowedRoles={['guest']}>
                <AccountPayments />
              </RoleRoute>
            }
          />
          <Route
            path="/admin"
            element={
              <RoleRoute allowedRoles={['admin']}>
                <AdminLayout />
              </RoleRoute>
            }
          >
            <Route path="dashboard" element={<AdminDashboard />} handle={{ title: 'Dashboard' }} />
            <Route path="accommodations" element={<AdminAccommodations />} handle={{ eyebrow: 'Accommodation Management', title: 'Manage Accommodations' }} />
            <Route path="bookings" element={<AdminBookings />} handle={{ eyebrow: 'Booking Management', title: 'Manage Bookings' }} />
            <Route path="users" element={<AdminUsers />} handle={{ eyebrow: 'User Management', title: 'User Management' }} />
            <Route path="inventory" element={<AdminInventory />} handle={{ eyebrow: 'Inventory Management', title: 'Inventory Management' }} />
            <Route path="housekeeping" element={<AdminHousekeeping />} handle={{ eyebrow: 'Cleaning', title: 'Cleaning Management' }} />
            <Route path="attendance" element={<AdminAttendance />} handle={{ eyebrow: 'Attendance', title: 'Attendance Management' }} />
            <Route path="reports" element={<AdminReports />} handle={{ eyebrow: 'Reports', title: 'Reports & Analytics' }} />
            <Route path="announcements" element={<AdminAnnouncements />} handle={{ eyebrow: 'Announcements', title: 'Announcement Management' }} />
            <Route path="system-logs" element={<AdminSystemLogs />} handle={{ eyebrow: 'Audit Trail', title: 'System Logs' }} />
            <Route path="settings" element={<AdminSettings />} handle={{ eyebrow: 'System', title: 'Settings' }} />
            <Route path="chatbot" element={<AdminChatbot />} handle={{ eyebrow: 'Admin', title: 'Chatbot' }} />
            <Route path="support" element={<SupportInboxPage eyebrow="Admin" title="Support" />} handle={{ eyebrow: 'Admin', title: 'Support' }} />
          </Route>
          <Route
            path="/frontdesk/dashboard"
            element={
              <RoleRoute allowedRoles={['front_desk_staff']}>
                <FrontDeskDashboard />
              </RoleRoute>
            }
          />
          <Route
            path="/frontdesk/bookings"
            element={
              <RoleRoute allowedRoles={['front_desk_staff']}>
                <FrontDeskBookings />
              </RoleRoute>
            }
          />
          <Route
            path="/frontdesk/accommodations"
            element={
              <RoleRoute allowedRoles={['front_desk_staff']}>
                <FrontDeskAccommodations />
              </RoleRoute>
            }
          />
          <Route
            path="/frontdesk/announcements"
            element={
              <RoleRoute allowedRoles={['front_desk_staff']}>
                <FrontDeskAnnouncements />
              </RoleRoute>
            }
          />
          <Route
            path="/frontdesk/check-in"
            element={
              <RoleRoute allowedRoles={['front_desk_staff']}>
                <FrontDeskCheckIn />
              </RoleRoute>
            }
          />
          <Route
            path="/frontdesk/support"
            element={
              <RoleRoute allowedRoles={['front_desk_staff']}>
                <SupportInboxPage Layout={FrontDeskLayout} eyebrow="Front Desk" title="Support" />
              </RoleRoute>
            }
          />
          <Route
            path="/manager/dashboard"
            element={
              <RoleRoute allowedRoles={['manager']}>
                <ManagerDashboard />
              </RoleRoute>
            }
          /> 
          <Route
            path="/manager/bookings"
            element={
              <RoleRoute allowedRoles={['manager']}>
                <ManagerBookings />
              </RoleRoute>
            }
          />
          <Route
            path="/manager/accommodations"
            element={
              <RoleRoute allowedRoles={['manager']}>
                <ManagerAccommodations />
              </RoleRoute>
            }
          />
          <Route
            path="/manager/inventory"
            element={
              <RoleRoute allowedRoles={['manager']}>
                <ManagerInventory />
              </RoleRoute>
            }
          />
          <Route
            path="/manager/housekeeping"
            element={
              <RoleRoute allowedRoles={['manager']}>
                <ManagerHousekeeping />
              </RoleRoute>
            }
          />
          <Route
            path="/manager/attendance"
            element={
              <RoleRoute allowedRoles={['manager']}>
                <ManagerAttendance />
              </RoleRoute>
            }
          />
          <Route
            path="/manager/reports"
            element={
              <RoleRoute allowedRoles={['manager']}>
                <ManagerReports />
              </RoleRoute>
            }
          />
          <Route
            path="/manager/announcements"
            element={
              <RoleRoute allowedRoles={['manager']}>
                <ManagerAnnouncements />
              </RoleRoute>
            }
          />
          <Route
            path="/manager/support"
            element={
              <RoleRoute allowedRoles={['manager']}>
                <SupportInboxPage Layout={ManagerLayout} eyebrow="Manager" title="Support" />
              </RoleRoute>
            }
          />
                <Route path="*" element={<NotFound />} />
              </Routes>
            </Suspense>
          </RouteErrorBoundary>
        </main>
      </BookingSearchProvider>
      {!isStandaloneAuthRoute && !isVerifyEmailRoute && !isAdminRoute && !isManagerRoute && !isFrontDeskRoute && !isAccountRoute ? <ResortChatWidget /> : null}
      {!isStandaloneAuthRoute && !isAdminRoute && !isManagerRoute && !isFrontDeskRoute && !isAccountRoute && <Footer />}
      {!isStandaloneAuthRoute && !isVerifyEmailRoute && !isAdminRoute && !isManagerRoute && !isFrontDeskRoute && !isAccountRoute && <PublicBottomNav />}
    </div>
  )
}

export default App

function LoginRedirect() {
  const location = useLocation()

  return (
    <Navigate
      to="/"
      replace
      state={{
        openAuthModal: 'signin',
        from: location.state?.from ?? null,
        authMessage: location.state?.authMessage ?? null,
      }}
    />
  )
}

function RouteLoadingFallback() {
  return (
    <section className="route-loading-fallback" aria-label="Loading page">
      <InlineLoader message="Loading page..." />
    </section>
  )
}

class RouteErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { error: null }
  }

  static getDerivedStateFromError(error) {
    return { error }
  }

  componentDidCatch(error, info) {
    logDevDiagnostic('route:render-error', {
      route: this.props.routePath,
      name: error?.name,
      message: error?.message,
      stack: error?.stack,
      componentStack: info?.componentStack,
    })
    console.error('Route rendering error:', error, info)
  }

  render() {
    if (this.state.error) {
      return (
        <section className="route-error-state site-shell page-section" role="alert">
          <h1>Something went wrong. Please try again.</h1>
          <p>Please try again or return home.</p>
          <div className="form-actions">
            <button
              className="solid-button"
              type="button"
              onClick={() => {
                this.setState({ error: null })
                window.location.reload()
              }}
            >
              Retry
            </button>
            <a className="outline-button" href="/">
              Go Home
            </a>
          </div>
        </section>
      )
    }

    return this.props.children
  }
}
