import axios from 'axios'
import { logDevDiagnostic } from './devDiagnostics'

const apiBaseURL = import.meta.env.VITE_API_BASE_URL ?? '/api'
const apiRootURL = apiBaseURL.startsWith('/')
  ? window.location.origin
  : apiBaseURL.replace(/\/api\/?$/, '')
const configuredApiTimeoutMs = Number(import.meta.env.VITE_API_TIMEOUT_MS)
const apiTimeoutMs = Number.isFinite(configuredApiTimeoutMs) && configuredApiTimeoutMs > 0
  ? configuredApiTimeoutMs
  : 45000
const assetOrigin = import.meta.env.VITE_ASSET_BASE_URL
  ?? (import.meta.env.DEV ? 'http://127.0.0.1:8000' : window.location.origin)

export function resolveAssetUrl(value) {
  if (typeof value !== 'string' || !value) return value

  if (value.startsWith('/storage/')) {
    return `${assetOrigin}${value}`
  }

  return value.replace(/https?:\/\/(?:localhost|127\.0\.0\.1):8000(\/storage\/)/, `${assetOrigin}$1`)
}

function normalizeBackendUrls(value) {
  if (typeof Blob !== 'undefined' && value instanceof Blob) {
    return value
  }

  if (typeof value === 'string') {
    const normalized = resolveAssetUrl(value)
    return normalized.replace(/https?:\/\/(?:localhost|127\.0\.0\.1):8000/, apiBaseURL.startsWith('/') ? '' : apiRootURL)
  }

  if (Array.isArray(value)) {
    return value.map(normalizeBackendUrls)
  }

  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, normalizeBackendUrls(item)]))
  }

  return value
}

export function redirectToGoogle() {
  window.location.assign(`${apiRootURL}/auth/google/redirect`)
}

const api = axios.create({
  baseURL: apiBaseURL,
  timeout: apiTimeoutMs,
  withCredentials: true,
  withXSRFToken: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: {
    Accept: 'application/json',
  },
})

const inFlightGetRequests = new Map()
const pageLifecycleController = typeof window !== 'undefined' ? new AbortController() : null
let isPageUnloading = false

if (typeof window !== 'undefined') {
  window.addEventListener('pagehide', () => {
    isPageUnloading = true
    pageLifecycleController?.abort()
  }, { once: true })
}

function stableSerialize(value) {
  if (value instanceof URLSearchParams) return value.toString()
  if (Array.isArray(value)) return value.map(stableSerialize)
  if (value && typeof value === 'object') {
    return Object.keys(value).sort().reduce((result, key) => {
      result[key] = stableSerialize(value[key])
      return result
    }, {})
  }

  return value
}

function getRequestKey(url, config = {}) {
  return JSON.stringify({
    url,
    params: stableSerialize(config.params ?? null),
  })
}

let requestSequence = 0

api.interceptors.request.use((config) => {
  if (pageLifecycleController && !config.signal) {
    config.signal = pageLifecycleController.signal
  } else if (
    pageLifecycleController
    && config.signal
    && typeof AbortSignal !== 'undefined'
    && typeof AbortSignal.any === 'function'
  ) {
    config.signal = AbortSignal.any([config.signal, pageLifecycleController.signal])
  }

  const requestId = ++requestSequence
  config.__dmdRequestId = requestId
  config.__dmdRequestStartedAt = performance.now()
  logDevDiagnostic('request:start', {
    requestId,
    method: config.method?.toUpperCase(),
    url: config.url,
  })
  return config
})

api.interceptors.response.use((response) => {
  logDevDiagnostic('request:complete', {
    requestId: response.config.__dmdRequestId,
    method: response.config.method?.toUpperCase(),
    url: response.config.url,
    status: response.status,
    durationMs: Math.round(performance.now() - response.config.__dmdRequestStartedAt),
  })
  response.data = normalizeBackendUrls(response.data)
  return response
}, (error) => {
  const config = error.config ?? {}
  const aborted = error.code === 'ERR_CANCELED' || error.name === 'CanceledError' || config.signal?.aborted === true

  logDevDiagnostic('request:failure', {
    requestId: config.__dmdRequestId,
    method: config.method?.toUpperCase(),
    url: config.url,
    status: error.response?.status ?? null,
    code: error.code ?? null,
    aborted,
    durationMs: config.__dmdRequestStartedAt ? Math.round(performance.now() - config.__dmdRequestStartedAt) : null,
    message: error.message,
  })

  if (aborted && isPageUnloading) {
    // A browser refresh/navigation has already discarded the page. Keep the
    // teardown from reaching page-level catches that show false load alerts.
    return new Promise(() => {})
  }

  return Promise.reject(error)
})

const originalApiGet = api.get.bind(api)
api.get = (url, config = {}) => {
  // Caller-owned signals need independent cancellation semantics.
  if (config.signal) return originalApiGet(url, config)

  const key = getRequestKey(url, config)
  const existingRequest = inFlightGetRequests.get(key)
  if (existingRequest) {
    logDevDiagnostic('request:deduplicated', { method: 'GET', url })
    return existingRequest
  }

  const request = originalApiGet(url, config).finally(() => {
    if (inFlightGetRequests.get(key) === request) inFlightGetRequests.delete(key)
  })

  inFlightGetRequests.set(key, request)
  return request
}

export async function getCsrfCookie() {
  await axios.get(`${apiRootURL}/sanctum/csrf-cookie`, {
    timeout: apiTimeoutMs,
    withCredentials: true,
    withXSRFToken: true,
    xsrfCookieName: 'XSRF-TOKEN',
    xsrfHeaderName: 'X-XSRF-TOKEN',
    headers: {
      Accept: 'application/json',
    },
  })
}

export async function login(credentials) {
  await getCsrfCookie()
  await api.post('/login', credentials)

  return getCurrentUser()
}

export async function register(accountDetails) {
  await getCsrfCookie()
  const response = await api.post('/register', accountDetails)

  return {
    user: response.data.user,
    email: response.data.email,
    emailVerificationRequired: response.data.email_verification_required === true,
    message: response.data.message,
    registrationCancelToken: response.data.registration_cancel_token ?? null,
  }
}

export async function verifyEmailCode(details) {
  await getCsrfCookie()
  const response = await api.post('/email/verify-code', details)

  return response.data
}

export async function resendEmailCode(email) {
  await getCsrfCookie()
  const response = await api.post('/email/resend-code', { email })

  return response.data
}

export async function cancelPendingRegistration(email, cancellationToken) {
  await getCsrfCookie()
  const response = await api.post('/email/cancel-registration', { email }, {
    headers: cancellationToken ? { 'X-Registration-Cancel-Token': cancellationToken } : {},
  })

  return response.data
}

export async function logout() {
  await api.post('/logout')
}

export async function getCurrentUser() {
  const response = await api.get('/user')

  return response.data.user
}

export async function updateProfile(profileDetails) {
  await getCsrfCookie()
  const response = await api.put('/user/profile', profileDetails)

  return {
    user: response.data.user,
    message: response.data.message,
  }
}

export async function getAccommodations(filters = {}, requestConfig = {}) {
  const response = await api.get('/accommodations', {
    params: filters,
    ...requestConfig,
  })

  return response.data.data
}

export async function getAccommodation(id) {
  const response = await api.get(`/accommodations/${id}`)

  return response.data.data
}

export async function checkAccommodationAvailability(id, details) {
  const response = await api.get(`/accommodations/${id}/availability`, {
    params: details,
  })

  return response.data
}

export async function createReservation(details) {
  await getCsrfCookie()
  const response = await api.post('/reservations', details)

  return {
    reservation: response.data.data,
    message: response.data.message,
  }
}

export async function createGuestReservation(details) {
  await getCsrfCookie()
  const response = await api.post('/guest/reservations', details)

  return {
    reservation: response.data.data,
    message: response.data.message,
    guestCheckoutToken: response.data.guest_checkout_token ?? null,
  }
}

export async function abandonGuestReservation(id, guestCheckoutToken) {
  await getCsrfCookie()
  const response = await api.post(`/guest/reservations/${id}/abandon`, {}, {
    headers: {
      'X-Guest-Checkout-Token': guestCheckoutToken,
    },
  })

  return {
    reservation: response.data.data,
    message: response.data.message,
  }
}

export async function getReservations() {
  const response = await api.get('/reservations')

  return response.data.data
}

let paymentHistoryRequest = null

export function clearApiCaches() {
  inFlightGetRequests.clear()
  paymentHistoryRequest = null
}

export async function getPaymentHistory({ force = false } = {}) {
  if (!force && paymentHistoryRequest) return paymentHistoryRequest

  const request = api.get('/payments').then((response) => response.data.data)
  paymentHistoryRequest = request

  try {
    return await request
  } finally {
    if (paymentHistoryRequest === request) paymentHistoryRequest = null
  }
}

export async function getReservation(id) {
  const response = await api.get(`/reservations/${id}`)

  return response.data.data
}

export async function cancelReservation(id, reason) {
  await getCsrfCookie()
  const response = await api.post(`/reservations/${id}/cancel`, {
    reason,
  })

  return {
    reservation: response.data.data,
    message: response.data.message,
  }
}

export async function updatePassword(passwordDetails) {
  await getCsrfCookie()
  const response = await api.patch('/user/password', passwordDetails)

  return {
    message: response.data.message,
  }
}

export async function createPayMongoCheckout(reservationId, purpose) {
  await getCsrfCookie()
  const response = await api.post('/payments/paymongo/checkout', {
    reservation_id: reservationId,
    purpose,
  })

  return {
    payment: response.data.data,
    message: response.data.message,
  }
}

export async function createGuestPayMongoCheckout(reservationId, purpose, guestCheckoutToken) {
  await getCsrfCookie()
  const response = await api.post('/guest/payments/paymongo/checkout', {
    reservation_id: reservationId,
    purpose,
  }, {
    headers: {
      'X-Guest-Checkout-Token': guestCheckoutToken,
    },
  })

  return {
    payment: response.data.data,
    message: response.data.message,
  }
}

export async function getGuestReservation(id) {
  const response = await api.get(`/payments/reservations/${id}`)

  return response.data.data
}

export async function getGuestReservationPaymentStatus(id, guestCheckoutToken = null) {
  const endpoint = guestCheckoutToken
    ? `/guest/payments/reservations/${id}/status`
    : `/payments/reservations/${id}/status`
  const response = await api.get(endpoint, guestCheckoutToken ? {
    headers: {
      'X-Guest-Checkout-Token': guestCheckoutToken,
    },
  } : undefined)

  return {
    ...response.data.data,
    guest_access_token: response.data.guest_access_token ?? null,
    guest_confirmation_email_status: response.data.guest_confirmation_email_status ?? null,
  }
}

export async function getGuestBooking(guestAccessToken) {
  const response = await api.get('/guest/booking', {
    headers: {
      'X-Guest-Access-Token': guestAccessToken,
    },
  })

  return response.data.data
}

export async function submitReservationFeedback(id, details) {
  await getCsrfCookie()
  const response = await api.post(`/reservations/${id}/feedback`, details)
  return response.data
}

export async function submitGuestBookingFeedback(guestAccessToken, details) {
  await getCsrfCookie()
  const response = await api.post('/guest/booking/feedback', details, {
    headers: { 'X-Guest-Access-Token': guestAccessToken },
  })
  return response.data
}

export async function createGuestBookingBalanceCheckout(guestAccessToken) {
  await getCsrfCookie()
  const response = await api.post('/guest/booking/payments/paymongo/checkout', {
    purpose: 'balance',
  }, {
    headers: {
      'X-Guest-Access-Token': guestAccessToken,
    },
  })

  return {
    payment: response.data.data,
    message: response.data.message,
  }
}

export async function getGuestBookingPaymentStatus(id, guestAccessToken) {
  const response = await api.get(`/guest/booking/reservations/${id}/status`, {
    headers: {
      'X-Guest-Access-Token': guestAccessToken,
    },
  })

  return {
    ...response.data.data,
    guest_access_token: response.data.guest_access_token ?? null,
    guest_confirmation_email_status: response.data.guest_confirmation_email_status ?? null,
  }
}

export async function getAdminDashboardSummary() {
  const response = await api.get('/admin/dashboard-summary')

  return response.data.data
}

export async function getAdminAccommodations(filters = {}) {
  const response = await api.get('/admin/accommodations', {
    params: filters,
  })

  return response.data.data
}

export async function getAdminAmenities() {
  const response = await api.get('/admin/amenities')

  return response.data.data
}

export async function createAdminAmenity(details) {
  await getCsrfCookie()
  const response = await api.post('/admin/amenities', details)

  return {
    amenity: response.data.data,
    message: response.data.message,
  }
}

export async function createAdminAccommodation(details) {
  await getCsrfCookie()
  const response = await api.post('/admin/accommodations', details)

  return {
    accommodation: response.data.data,
    message: response.data.message,
  }
}

export async function updateAdminAccommodation(id, details) {
  await getCsrfCookie()
  const response = details instanceof FormData
    ? await api.post(`/admin/accommodations/${id}`, details)
    : await api.put(`/admin/accommodations/${id}`, details)

  return {
    accommodation: response.data.data,
    message: response.data.message,
  }
}

export async function updateAdminAccommodationStatus(id, status) {
  await getCsrfCookie()
  const response = await api.patch(`/admin/accommodations/${id}/status`, {
    status,
  })

  return {
    accommodation: response.data.data,
    message: response.data.message,
  }
}

export async function deleteAdminAccommodation(id) {
  await getCsrfCookie()
  const response = await api.delete(`/admin/accommodations/${id}`)

  return response.data
}

export async function getAdminReservations(filters = {}) {
  const response = await api.get('/admin/reservations', {
    params: filters,
  })

  return {
    reservations: response.data.data.data,
    pagination: {
      current_page: response.data.data.current_page,
      last_page: response.data.data.last_page,
      total: response.data.data.total,
      per_page: response.data.data.per_page,
    },
    meta: response.data.meta,
  }
}

export async function getAdminReservation(id) {
  const response = await api.get(`/admin/reservations/${id}`)

  return {
    reservation: response.data.data,
    meta: response.data.meta,
  }
}

export async function updateAdminReservationStatus(id, details) {
  await getCsrfCookie()
  const response = await api.patch(`/admin/reservations/${id}/status`, details)

  return {
    reservation: response.data.data,
    message: response.data.message,
  }
}

export async function recordAdminReservationRefund(id, refundReference = '') {
  await getCsrfCookie()
  const response = await api.post(`/admin/reservations/${id}/refund`, {
    refund_reference: refundReference || undefined,
  })

  return {
    reservation: response.data.data,
    message: response.data.message,
  }
}

export async function getAdminUsers(filters = {}) {
  const response = await api.get('/admin/users', {
    params: filters,
  })

  return {
    users: response.data.data.data,
    pagination: {
      current_page: response.data.data.current_page,
      last_page: response.data.data.last_page,
      total: response.data.data.total,
      per_page: response.data.data.per_page,
    },
    meta: response.data.meta,
  }
}

export async function getAdminUser(id) {
  const response = await api.get(`/admin/users/${id}`)

  return response.data.data
}

export async function createAdminUser(details) {
  await getCsrfCookie()
  const response = await api.post('/admin/users', details)

  return {
    user: response.data.data,
    message: response.data.message,
  }
}

export async function updateAdminUser(id, details) {
  await getCsrfCookie()
  const response = await api.put(`/admin/users/${id}`, details)

  return {
    user: response.data.data,
    message: response.data.message,
  }
}

export async function updateAdminUserStatus(id, isActive) {
  await getCsrfCookie()
  const response = await api.patch(`/admin/users/${id}/status`, {
    is_active: isActive,
  })

  return {
    user: response.data.data,
    message: response.data.message,
  }
}

export async function resetAdminUserPassword(id, details) {
  await getCsrfCookie()
  const response = await api.patch(`/admin/users/${id}/password`, details)

  return {
    user: response.data.data,
    message: response.data.message,
  }
}

export async function getAdminInventoryAssets(filters = {}) {
  const response = await api.get('/admin/inventory-assets', {
    params: filters,
  })

  return {
    assets: response.data.data,
    summary: response.data.summary,
    meta: response.data.meta,
  }
}

export async function getAdminInventoryAsset(id) {
  const response = await api.get(`/admin/inventory-assets/${id}`)

  return {
    asset: response.data.data,
    history: response.data.history,
  }
}

export async function createAdminInventoryAsset(details) {
  await getCsrfCookie()
  const response = await api.post('/admin/inventory-assets', details)

  return {
    asset: response.data.data,
    message: response.data.message,
  }
}

export async function updateAdminInventoryAsset(id, details) {
  await getCsrfCookie()
  const response = await api.put(`/admin/inventory-assets/${id}`, details)

  return {
    asset: response.data.data,
    message: response.data.message,
  }
}

export async function assignAdminInventoryAsset(id, details) {
  await getCsrfCookie()
  const response = await api.patch(`/admin/inventory-assets/${id}/assign`, details)

  return {
    asset: response.data.data,
    message: response.data.message,
  }
}

export async function updateAdminInventoryAssetCondition(id, details) {
  await getCsrfCookie()
  const response = await api.patch(`/admin/inventory-assets/${id}/condition`, details)

  return {
    asset: response.data.data,
    message: response.data.message,
  }
}

export async function updateAdminInventoryAssetStatus(id, details) {
  await getCsrfCookie()
  const response = await api.patch(`/admin/inventory-assets/${id}/status`, details)

  return {
    asset: response.data.data,
    message: response.data.message,
  }
}


export async function getAdminInventoryStockItems(filters = {}) {
  const response = await api.get('/admin/inventory-stock-items', { params: filters })

  return {
    items: response.data.data,
    pagination: response.data.pagination,
    summary: response.data.summary,
    meta: response.data.meta,
  }
}

export async function getAdminInventoryStockItem(id) {
  const response = await api.get(`/admin/inventory-stock-items/${id}`)

  return {
    item: response.data.data,
    movements: response.data.movements,
  }
}

export async function createAdminInventoryStockItem(details) {
  await getCsrfCookie()
  const response = await api.post('/admin/inventory-stock-items', details)

  return { item: response.data.data, message: response.data.message }
}

export async function updateAdminInventoryStockItem(id, details) {
  await getCsrfCookie()
  const response = await api.put(`/admin/inventory-stock-items/${id}`, details)

  return { item: response.data.data, message: response.data.message }
}

export async function recordAdminInventoryStockMovement(id, type, details) {
  await getCsrfCookie()
  const response = await api.post(`/admin/inventory-stock-items/${id}/${type}`, details)

  return {
    item: response.data.data,
    movement: response.data.movement,
    message: response.data.message,
  }
}

export async function getAdminInventoryStockMovements(filters = {}) {
  const response = await api.get('/admin/inventory-stock-items/movements', { params: filters })

  return {
    movements: response.data.data,
    pagination: response.data.pagination,
    meta: response.data.meta,
  }
}

export async function getAdminHousekeepingTasks(filters = {}) {
  const response = await api.get('/admin/housekeeping-tasks', {
    params: filters,
  })

  return {
    tasks: response.data.data.data,
    pagination: {
      current_page: response.data.data.current_page,
      last_page: response.data.data.last_page,
      total: response.data.data.total,
      per_page: response.data.data.per_page,
    },
    summary: response.data.summary,
    meta: response.data.meta,
  }
}

export async function getAdminHousekeepingTask(id) {
  const response = await api.get(`/admin/housekeeping-tasks/${id}`)

  return {
    task: response.data.data,
    history: response.data.history,
    meta: response.data.meta,
  }
}

export async function createAdminHousekeepingTask(details) {
  await getCsrfCookie()
  const response = await api.post('/admin/housekeeping-tasks', details)

  return {
    task: response.data.data,
    message: response.data.message,
  }
}

export async function updateAdminHousekeepingTask(id, details) {
  await getCsrfCookie()
  const response = await api.put(`/admin/housekeeping-tasks/${id}`, details)

  return {
    task: response.data.data,
    message: response.data.message,
  }
}

export async function assignAdminHousekeepingTask(id, details) {
  await getCsrfCookie()
  const response = await api.patch(`/admin/housekeeping-tasks/${id}/assign`, details)

  return {
    task: response.data.data,
    message: response.data.message,
  }
}

export async function updateAdminHousekeepingTaskStatus(id, details) {
  await getCsrfCookie()
  const response = await api.patch(`/admin/housekeeping-tasks/${id}/status`, details)

  return {
    task: response.data.data,
    message: response.data.message,
  }
}

export async function getAdminAttendanceRecords(filters = {}) {
  const response = await api.get('/admin/attendance-records', {
    params: filters,
  })

  return {
    records: response.data.data,
    summary: response.data.summary,
    meta: response.data.meta,
  }
}

export async function getAdminEmployees(filters = {}) {
  const response = await api.get('/admin/employees', {
    params: filters,
  })

  return {
    employees: response.data.data,
    meta: response.data.meta,
  }
}

export async function createAdminEmployee(details) {
  await getCsrfCookie()
  const response = await api.post('/admin/employees', details)

  return {
    employee: response.data.data,
    message: response.data.message,
  }
}

export async function updateAdminEmployee(id, details) {
  await getCsrfCookie()
  const response = await api.put(`/admin/employees/${id}`, details)

  return {
    employee: response.data.data,
    message: response.data.message,
  }
}

export async function deleteAdminEmployee(id) {
  await getCsrfCookie()
  const response = await api.delete(`/admin/employees/${id}`)

  return {
    message: response.data.message,
  }
}

export async function getAdminFingerprintSlot() {
  const response = await api.get('/admin/employees/fingerprint/available-slot')
  return response.data.fingerprint_id
}

export async function assignAdminFingerprint(employeeId, fingerprintId) {
  await getCsrfCookie()
  const response = await api.post(`/admin/employees/${employeeId}/fingerprint`, { fingerprint_id: fingerprintId })
  return { employee: response.data.data, message: response.data.message }
}

export async function removeAdminFingerprint(employeeId) {
  await getCsrfCookie()
  const response = await api.delete(`/admin/employees/${employeeId}/fingerprint`)
  return { employee: response.data.data, message: response.data.message }
}

async function fingerprintBridgeRequest(path, fingerprintId) {
  await getCsrfCookie()
  const response = await api.post(`/admin/iot${path}`, { fingerprint_id: fingerprintId })
  return response.data
}

export function enrollFingerprintOnBridge(fingerprintId) {
  return fingerprintBridgeRequest('/fingerprints/enroll', fingerprintId)
}

export function deleteFingerprintOnBridge(fingerprintId) {
  return fingerprintBridgeRequest('/fingerprints/delete', fingerprintId)
}

export async function deleteFingerprintForAdminEmployee(employeeId, fingerprintId) {
  await getCsrfCookie()
  const response = await api.post(`/admin/iot/fingerprints/delete-for-employee/${employeeId}`, {
    fingerprint_id: fingerprintId,
  })

  return {
    employee: response.data.data,
    message: response.data.message,
  }
}

export async function getAdminAttendanceRecord(id) {
  const response = await api.get(`/admin/attendance-records/${id}`)

  return {
    record: response.data.data,
    history: response.data.history,
    meta: response.data.meta,
  }
}

export async function createAdminAttendanceRecord(details) {
  await getCsrfCookie()
  const response = await api.post('/admin/attendance-records', details)

  return {
    record: response.data.data,
    message: response.data.message,
  }
}

export async function updateAdminAttendanceRecord(id, details) {
  await getCsrfCookie()
  const response = await api.put(`/admin/attendance-records/${id}`, details)

  return {
    record: response.data.data,
    message: response.data.message,
  }
}

export async function getAdminReports(filters = {}) {
  const response = await api.get('/admin/reports', {
    params: filters,
  })

  return response.data.data
}

export function getAdminReportsExportUrl(filters = {}) {
  const params = new URLSearchParams()

  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      params.set(key, value)
    }
  })

  return `${apiBaseURL}/admin/reports/export${params.toString() ? `?${params.toString()}` : ''}`
}

export async function downloadAdminReportExport(format, filters = {}) {
  const params = new URLSearchParams()
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') params.set(key, value)
  })
  const response = await api.get(`/admin/reports/export/${format}`, { params, responseType: 'blob' })
  const disposition = response.headers['content-disposition'] ?? ''
  const filename = disposition.match(/filename="?([^";]+)"?/i)?.[1] ?? `dmd-resort-report.${format === 'excel' ? 'xlsx' : format}`
  const url = URL.createObjectURL(response.data)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

export async function getAdminAnnouncements(filters = {}) {
  const response = await api.get('/admin/announcements', {
    params: filters,
  })

  return {
    announcements: response.data.data,
    summary: response.data.summary,
    meta: response.data.meta,
  }
}

export async function getAdminAnnouncement(id) {
  const response = await api.get(`/admin/announcements/${id}`)

  return {
    announcement: response.data.data,
    meta: response.data.meta,
  }
}

export async function createAdminAnnouncement(details) {
  await getCsrfCookie()
  const response = await api.post('/admin/announcements', details)

  return {
    announcement: response.data.data,
    message: response.data.message,
  }
}

export async function updateAdminAnnouncement(id, details) {
  await getCsrfCookie()
  const response = await api.put(`/admin/announcements/${id}`, details)

  return {
    announcement: response.data.data,
    message: response.data.message,
  }
}

export async function updateAdminAnnouncementStatus(id, status) {
  await getCsrfCookie()
  const response = await api.patch(`/admin/announcements/${id}/status`, {
    status,
  })

  return {
    announcement: response.data.data,
    message: response.data.message,
  }
}


export async function getAnnouncementNotifications() {
  const response = await api.get('/notifications/announcements')

  return {
    announcements: response.data.data,
    unreadCount: response.data.unread_count ?? 0,
    pagination: response.data.pagination,
  }
}

export async function markAnnouncementRead(id) {
  await getCsrfCookie()
  const response = await api.post(`/notifications/announcements/${id}/read`)

  return { unreadCount: response.data.unread_count ?? 0 }
}

export async function markAllAnnouncementsRead() {
  await getCsrfCookie()
  const response = await api.post('/notifications/announcements/read-all')

  return { unreadCount: response.data.unread_count ?? 0 }
}

export async function getAdminSystemLogs(filters = {}) {
  const response = await api.get('/admin/system-logs', {
    params: filters,
  })

  return {
    logs: response.data.data.data,
    pagination: {
      current_page: response.data.data.current_page,
      last_page: response.data.data.last_page,
      total: response.data.data.total,
    },
    summary: response.data.summary,
    meta: response.data.meta,
  }
}

export async function getAdminSystemLog(id) {
  const response = await api.get(`/admin/system-logs/${id}`)

  return response.data.data
}

export async function searchAdminGlobal(query, signal) {
  const response = await api.get('/admin/global-search', {
    params: { q: query },
    signal,
  })

  return response.data.data ?? []
}

export async function getAdminSettings() {
  const response = await api.get('/admin/settings')

  return {
    settings: response.data.data,
    meta: response.data.meta,
  }
}

export async function updateAdminSettings(settings) {
  await getCsrfCookie()
  const response = settings instanceof FormData
    ? await api.post('/admin/settings', settings)
    : await api.put('/admin/settings', settings)

  return {
    settings: response.data.data,
    message: response.data.message,
  }
}

export async function getPublicSettings() {
  const response = await api.get('/public-settings')

  return response.data.data
}

export async function getChatbotRules() {
  const response = await api.get('/chatbot/rules')

  return response.data.data
}

export async function startChatConversation(details) {
  const response = await api.post('/chat/conversations', details)

  return response.data.data
}

export async function getChatConversation(uuid, token) {
  const response = await api.get(`/chat/conversations/${uuid}`, {
    headers: token ? { 'X-Conversation-Token': token } : undefined,
  })

  return response.data.data
}

export async function sendChatMessage(uuid, details, token) {
  const response = await api.post(`/chat/conversations/${uuid}/messages`, details, {
    headers: token ? { 'X-Conversation-Token': token } : undefined,
  })

  return response.data.data
}

export async function escalateChatConversation(uuid, token) {
  const response = await api.post(`/chat/conversations/${uuid}/escalate`, {}, {
    headers: token ? { 'X-Conversation-Token': token } : undefined,
  })

  return response.data.data
}

export async function getSupportConversations(filters = {}) {
  const response = await api.get('/frontdesk/support/conversations', {
    params: filters,
  })

  const payload = response.data
  const data = Array.isArray(payload.data) ? payload.data : payload.data?.data

  return {
    conversations: Array.isArray(data) ? data : [],
    pagination: payload.meta ?? payload.data?.meta ?? null,
  }
}

export async function getAdminSupportConversations(filters = {}) {
  const response = await api.get('/admin/support/conversations', {
    params: filters,
  })

  const payload = response.data
  const data = Array.isArray(payload.data) ? payload.data : payload.data?.data

  return {
    conversations: Array.isArray(data) ? data : [],
    pagination: payload.meta ?? payload.data?.meta ?? null,
  }
}

export async function getSupportConversation(uuid, signal) {
  const response = await api.get(`/frontdesk/support/conversations/${uuid}`, { signal })

  return response.data.data
}

export async function getAdminSupportConversation(uuid, signal) {
  const response = await api.get(`/admin/support/conversations/${uuid}`, { signal })

  return response.data.data
}

export async function claimSupportConversation(uuid) {
  await getCsrfCookie()
  const response = await api.post(`/frontdesk/support/conversations/${uuid}/claim`)

  return response.data.data
}

export async function claimAdminSupportConversation(uuid) {
  await getCsrfCookie()
  const response = await api.post(`/admin/support/conversations/${uuid}/claim`)

  return response.data.data
}

export async function sendSupportConversationMessage(uuid, details) {
  await getCsrfCookie()
  const response = await api.post(`/frontdesk/support/conversations/${uuid}/messages`, details)

  return response.data.data
}

export async function sendAdminSupportConversationMessage(uuid, details) {
  await getCsrfCookie()
  const response = await api.post(`/admin/support/conversations/${uuid}/messages`, details)

  return response.data.data
}

export async function resolveSupportConversation(uuid) {
  await getCsrfCookie()
  const response = await api.post(`/frontdesk/support/conversations/${uuid}/resolve`)

  return response.data.data
}

export async function resolveAdminSupportConversation(uuid) {
  await getCsrfCookie()
  const response = await api.post(`/admin/support/conversations/${uuid}/resolve`)

  return response.data.data
}

export async function getAdminChatbotRules() {
  const response = await api.get('/admin/chatbot/rules')

  return response.data.data
}

export async function getAdminChatbotCategories() {
  const response = await api.get('/admin/chatbot/categories')

  return response.data.data
}

export async function createAdminChatbotCategory(details) {
  await getCsrfCookie()
  const response = await api.post('/admin/chatbot/categories', details)

  return response.data.data
}

export async function updateAdminChatbotCategory(id, details) {
  await getCsrfCookie()
  const response = await api.put(`/admin/chatbot/categories/${id}`, details)

  return response.data.data
}

export async function deleteAdminChatbotCategory(id) {
  await getCsrfCookie()
  const response = await api.delete(`/admin/chatbot/categories/${id}`)

  return response.data
}

export async function createAdminChatbotRule(details) {
  await getCsrfCookie()
  const response = await api.post('/admin/chatbot/rules', details)

  return response.data.data
}

export async function updateAdminChatbotRule(id, details) {
  await getCsrfCookie()
  const response = await api.put(`/admin/chatbot/rules/${id}`, details)

  return response.data.data
}

export async function deleteAdminChatbotRule(id) {
  await getCsrfCookie()
  const response = await api.delete(`/admin/chatbot/rules/${id}`)

  return response.data
}

export async function getManagerDashboardSummary() {
  const response = await api.get('/manager/dashboard-summary')

  return response.data.data
}

export async function getFrontDeskDashboardSummary() {
  const response = await api.get('/frontdesk/dashboard-summary')

  return response.data.data
}

export async function getFrontDeskReservations(filters = {}) {
  const response = await api.get('/frontdesk/reservations', {
    params: filters,
  })

  return {
    reservations: response.data.data,
    meta: response.data.meta,
  }
}

export async function getFrontDeskReservation(id) {
  const response = await api.get(`/frontdesk/reservations/${id}`)

  return {
    reservation: response.data.data,
    meta: response.data.meta,
  }
}

export async function verifyFrontDeskBookingQr(payload) {
  await getCsrfCookie()
  const response = await api.post('/frontdesk/booking-qr/verify', { payload })

  return {
    verification: response.data.data,
    message: response.data.message,
  }
}

export async function updateFrontDeskReservationStatus(id, details) {
  await getCsrfCookie()
  const response = await api.patch(`/frontdesk/reservations/${id}/status`, details)

  return {
    reservation: response.data.data,
    message: response.data.message,
  }
}

export async function recordFrontDeskReservationPayment(id, details) {
  await getCsrfCookie()
  const response = await api.post(`/frontdesk/reservations/${id}/payments`, details)

  return {
    payment: response.data.data.payment,
    reservation: response.data.data.reservation,
    message: response.data.message,
  }
}

export async function getFrontDeskAccommodations(filters = {}) {
  const response = await api.get('/frontdesk/accommodations', {
    params: filters,
  })

  return {
    accommodations: response.data.data,
    meta: response.data.meta,
  }
}

export async function getFrontDeskAccommodation(id) {
  const response = await api.get(`/frontdesk/accommodations/${id}`)

  return {
    accommodation: response.data.data,
  }
}

export async function getFrontDeskAnnouncements(filters = {}) {
  const response = await api.get('/frontdesk/announcements', {
    params: filters,
  })

  return {
    announcements: response.data.data,
    summary: response.data.summary,
    meta: response.data.meta,
  }
}

export async function getFrontDeskAnnouncement(id) {
  const response = await api.get(`/frontdesk/announcements/${id}`)

  return {
    announcement: response.data.data,
    meta: response.data.meta,
  }
}

export async function getManagerReservations(filters = {}) {
  const response = await api.get('/manager/reservations', {
    params: filters,
  })

  return {
    reservations: response.data.data,
    meta: response.data.meta,
  }
}

export async function getManagerReservation(id) {
  const response = await api.get(`/manager/reservations/${id}`)

  return {
    reservation: response.data.data,
    meta: response.data.meta,
  }
}

export async function updateManagerReservationStatus(id, details) {
  await getCsrfCookie()
  const response = await api.patch(`/manager/reservations/${id}/status`, details)

  return {
    reservation: response.data.data,
    message: response.data.message,
  }
}

export async function getManagerAccommodations(filters = {}) {
  const response = await api.get('/manager/accommodations', {
    params: filters,
  })

  return {
    accommodations: response.data.data,
    meta: response.data.meta,
  }
}

export async function updateManagerAccommodationStatus(id, status) {
  await getCsrfCookie()
  const response = await api.patch(`/manager/accommodations/${id}/status`, {
    status,
  })

  return {
    accommodation: response.data.data,
    message: response.data.message,
  }
}

export async function getManagerInventoryAssets(filters = {}) {
  const response = await api.get('/manager/inventory-assets', {
    params: filters,
  })

  return {
    assets: response.data.data,
    summary: response.data.summary,
    meta: response.data.meta,
  }
}

export async function getManagerInventoryAsset(id) {
  const response = await api.get(`/manager/inventory-assets/${id}`)

  return {
    asset: response.data.data,
    history: response.data.history,
  }
}

export async function updateManagerInventoryAssetCondition(id, details) {
  await getCsrfCookie()
  const response = await api.patch(`/manager/inventory-assets/${id}/condition`, details)

  return {
    asset: response.data.data,
    message: response.data.message,
  }
}

export async function updateManagerInventoryAssetStatus(id, details) {
  await getCsrfCookie()
  const response = await api.patch(`/manager/inventory-assets/${id}/status`, details)

  return {
    asset: response.data.data,
    message: response.data.message,
  }
}

export async function getManagerHousekeepingTasks(filters = {}) {
  const response = await api.get('/manager/housekeeping-tasks', {
    params: filters,
  })

  return {
    tasks: response.data.data,
    summary: response.data.summary,
    meta: response.data.meta,
  }
}

export async function getManagerHousekeepingTask(id) {
  const response = await api.get(`/manager/housekeeping-tasks/${id}`)

  return {
    task: response.data.data,
    history: response.data.history,
    meta: response.data.meta,
  }
}

export async function createManagerHousekeepingTask(details) {
  await getCsrfCookie()
  const response = await api.post('/manager/housekeeping-tasks', details)

  return {
    task: response.data.data,
    message: response.data.message,
  }
}

export async function updateManagerHousekeepingTask(id, details) {
  await getCsrfCookie()
  const response = await api.put(`/manager/housekeeping-tasks/${id}`, details)

  return {
    task: response.data.data,
    message: response.data.message,
  }
}

export async function assignManagerHousekeepingTask(id, details) {
  await getCsrfCookie()
  const response = await api.patch(`/manager/housekeeping-tasks/${id}/assign`, details)

  return {
    task: response.data.data,
    message: response.data.message,
  }
}

export async function updateManagerHousekeepingTaskStatus(id, details) {
  await getCsrfCookie()
  const response = await api.patch(`/manager/housekeeping-tasks/${id}/status`, details)

  return {
    task: response.data.data,
    message: response.data.message,
  }
}

export async function getManagerAttendanceRecords(filters = {}) {
  const response = await api.get('/manager/attendance-records', {
    params: filters,
  })

  return {
    records: response.data.data,
    summary: response.data.summary,
    meta: response.data.meta,
  }
}

export async function getManagerReports(filters = {}) {
  const response = await api.get('/manager/reports', {
    params: filters,
  })

  return response.data.data
}

export async function getManagerAnnouncements(filters = {}) {
  const response = await api.get('/manager/announcements', {
    params: filters,
  })

  return {
    announcements: response.data.data,
    summary: response.data.summary,
    meta: response.data.meta,
  }
}

export async function getHousekeepingDashboardSummary() {
  const response = await api.get('/housekeeping/dashboard-summary')

  return response.data.data
}

export async function getHousekeepingTasks(filters = {}) {
  const response = await api.get('/housekeeping/tasks', {
    params: filters,
  })

  return {
    tasks: response.data.data,
    summary: response.data.summary,
    meta: response.data.meta,
  }
}

export async function getHousekeepingTask(id) {
  const response = await api.get(`/housekeeping/tasks/${id}`)

  return {
    task: response.data.data,
    history: response.data.history,
    meta: response.data.meta,
  }
}

export async function getHousekeepingHistory(filters = {}) {
  const response = await api.get('/housekeeping/tasks/history', {
    params: filters,
  })

  return {
    tasks: response.data.data,
    summary: response.data.summary,
    meta: response.data.meta,
  }
}

export async function updateHousekeepingTaskStatus(id, details) {
  await getCsrfCookie()
  const response = await api.patch(`/housekeeping/tasks/${id}/status`, details)

  return {
    task: response.data.data,
    message: response.data.message,
  }
}

export async function updateHousekeepingTaskNotes(id, details) {
  await getCsrfCookie()
  const response = await api.patch(`/housekeeping/tasks/${id}/notes`, details)

  return {
    task: response.data.data,
    message: response.data.message,
  }
}

export default api
