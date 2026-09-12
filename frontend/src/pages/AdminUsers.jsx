import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  createAdminUser,
  getAdminUser,
  getAdminUsers,
  resetAdminUserPassword,
  updateAdminUser,
  updateAdminUserStatus,
} from '../lib/api'
import { ToastMessage } from '../context/ToastContext'
import AdminEmployeeDrawer from '../components/AdminEmployeeDrawer'

const defaultFormValues = {
  first_name: '',
  last_name: '',
  email: '',
  contact_number: '',
  role: 'manager',
  is_active: true,
  password: '',
  password_confirmation: '',
}

const roleLabels = {
  admin: 'Admin',
  manager: 'Manager',
  front_desk_staff: 'Front Desk Staff',
  housekeeping_staff: 'Legacy Staff',
  guest: 'Guest',
}

const loginRoleLabels = {
  admin: 'Admin',
  manager: 'Manager',
  front_desk_staff: 'Front Desk Staff',
}

function AdminUsers() {
  const [users, setUsers] = useState([])
  const [meta, setMeta] = useState({ roles: [], statuses: [], summary: {} })
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [currentPage, setCurrentPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(10)
  const [filters, setFilters] = useState({ search: '', role: '', status: '' })
  const [selectedUser, setSelectedUser] = useState(null)
  const [isUserDrawerOpen, setIsUserDrawerOpen] = useState(false)
  const [isEmployeeDrawerOpen, setIsEmployeeDrawerOpen] = useState(false)
  const [formValues, setFormValues] = useState(defaultFormValues)
  const [passwordValues, setPasswordValues] = useState({ password: '', password_confirmation: '' })
  const [fieldErrors, setFieldErrors] = useState({})
  const [passwordErrors, setPasswordErrors] = useState({})
  const [pageMessage, setPageMessage] = useState('')
  const [pageError, setPageError] = useState('')
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)
  const [isResettingPassword, setIsResettingPassword] = useState(false)
  const [pendingActionId, setPendingActionId] = useState(null)
  const usersRequestSequence = useRef(0)

  const filterParams = useMemo(
    () => ({
      search: filters.search.trim() || undefined,
      role: filters.role || undefined,
      status: filters.status || undefined,
      page: currentPage,
      per_page: rowsPerPage,
    }),
    [currentPage, filters, rowsPerPage],
  )

  const loadUsers = useCallback(async () => {
    const requestId = ++usersRequestSequence.current
    setIsLoading(true)
    setPageError('')

    try {
      const result = await getAdminUsers(filterParams)
      if (requestId !== usersRequestSequence.current) return
      setUsers(result.users)
      setMeta(result.meta)
      setPagination(result.pagination)
      setCurrentPage(result.pagination.current_page)
    } catch {
      if (requestId !== usersRequestSequence.current) return
      setPageError('Unable to load users right now.')
    } finally {
      if (requestId === usersRequestSequence.current) setIsLoading(false)
    }
  }, [filterParams])

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      loadUsers()
    }, 250)

    return () => {
      window.clearTimeout(timeoutId)
      usersRequestSequence.current += 1
    }
  }, [loadUsers])

  function updateFilter(event) {
    const { name, value } = event.target
    setCurrentPage(1)
    setFilters((current) => ({ ...current, [name]: value }))
  }

  function changeRowsPerPage(event) {
    setRowsPerPage(Number(event.target.value))
    setCurrentPage(1)
  }

  function updateFormField(event) {
    const { checked, name, type, value } = event.target
    setFormValues((current) => ({
      ...current,
      [name]: type === 'checkbox' ? checked : value,
    }))
    setFieldErrors((current) => ({ ...current, [name]: '', role: '' }))
    setPageError('')
    setPageMessage('')
  }

  function updatePasswordField(event) {
    const { name, value } = event.target
    setPasswordValues((current) => ({ ...current, [name]: value }))
    setPasswordErrors((current) => ({ ...current, [name]: '' }))
    setPageError('')
    setPageMessage('')
  }

  function startCreate() {
    setSelectedUser(null)
    setFormValues(defaultFormValues)
    setPasswordValues({ password: '', password_confirmation: '' })
    setFieldErrors({})
    setPasswordErrors({})
    setPageError('')
    setPageMessage('')
  }

  function openCreateDrawer() {
    startCreate()
    setIsUserDrawerOpen(true)
  }

  function openEmployeeDrawer() {
    setPageError('')
    setPageMessage('')
    setIsEmployeeDrawerOpen(true)
  }

  function closeUserDrawer() {
    setIsUserDrawerOpen(false)
  }

  async function selectUser(user) {
    setPageError('')
    setPageMessage('')

    try {
      const details = await getAdminUser(user.id)
      setSelectedUser(details)
      setFormValues({
        first_name: details.first_name ?? '',
        last_name: details.last_name ?? '',
        email: details.email ?? '',
        contact_number: details.contact_number ?? '',
        role: details.role ?? 'guest',
        is_active: Boolean(details.is_active),
        password: '',
        password_confirmation: '',
      })
      setPasswordValues({ password: '', password_confirmation: '' })
      setFieldErrors({})
      setPasswordErrors({})
      setIsUserDrawerOpen(true)
    } catch {
      setPageError('Unable to load user details.')
    }
  }

  function validateForm() {
    const errors = {}

    if (!formValues.first_name.trim()) errors.first_name = 'First name is required.'
    if (!formValues.last_name.trim()) errors.last_name = 'Last name is required.'
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formValues.email.trim())) {
      errors.email = 'Enter a valid email address.'
    }
    if (formValues.contact_number && !/^\+?[0-9\s().-]{7,20}$/.test(formValues.contact_number)) {
      errors.contact_number = 'Enter a valid contact number.'
    }
    if (!formValues.role) errors.role = 'Role is required.'
    if (!selectedUser && !formValues.password) errors.password = 'Password is required.'
    if (!selectedUser && formValues.password !== formValues.password_confirmation) {
      errors.password_confirmation = 'Password confirmation does not match.'
    }

    return errors
  }

  async function handleSubmit(event) {
    event.preventDefault()

    const errors = validateForm()
    setFieldErrors(errors)
    setPageError('')
    setPageMessage('')

    if (Object.keys(errors).length > 0) return

    setIsSaving(true)

    const payload = {
      first_name: formValues.first_name.trim(),
      last_name: formValues.last_name.trim(),
      email: formValues.email.trim(),
      contact_number: formValues.contact_number.trim() || null,
      role: formValues.role,
      is_active: formValues.is_active,
      ...(!selectedUser
        ? {
            password: formValues.password,
            password_confirmation: formValues.password_confirmation,
          }
        : {}),
    }

    try {
      const result = selectedUser
        ? await updateAdminUser(selectedUser.id, payload)
        : await createAdminUser(payload)
      setSelectedUser(result.user)
      setPageMessage(result.message)
      await loadUsers()
      await selectUser(result.user)
    } catch (error) {
      const errors = error?.response?.data?.errors
      if (errors) {
        setFieldErrors(Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, value[0]])))
      } else {
        setPageError('Unable to save user right now.')
      }
    } finally {
      setIsSaving(false)
    }
  }

  async function handleStatusToggle(user) {
    setPendingActionId(user.id)
    setPageError('')
    setPageMessage('')

    try {
      const result = await updateAdminUserStatus(user.id, !user.is_active)
      setPageMessage(result.message)
      setUsers((current) => current.map((item) => (item.id === user.id ? result.user : item)))
      if (selectedUser?.id === user.id) {
        await selectUser(result.user)
      }
    } catch (error) {
      const message =
        error?.response?.data?.errors?.role?.[0] ??
        error?.response?.data?.errors?.is_active?.[0] ??
        'Unable to update user status.'
      setPageError(message)
    } finally {
      setPendingActionId(null)
    }
  }

  async function handlePasswordReset(event) {
    event.preventDefault()

    if (!selectedUser) return

    setIsResettingPassword(true)
    setPasswordErrors({})
    setPageError('')
    setPageMessage('')

    try {
      const result = await resetAdminUserPassword(selectedUser.id, passwordValues)
      setSelectedUser(result.user)
      setPasswordValues({ password: '', password_confirmation: '' })
      setPageMessage(result.message)
    } catch (error) {
      const errors = error?.response?.data?.errors
      if (errors) {
        setPasswordErrors(
          Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, value[0]])),
        )
      } else {
        setPageError('Unable to reset password right now.')
      }
    } finally {
      setIsResettingPassword(false)
    }
  }

  function formatDate(value) {
    if (!value) return 'Not set'

    return new Intl.DateTimeFormat('en-PH', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    }).format(new Date(value))
  }

  const summaryCards = [
    ['Total Users', meta.summary?.total_users ?? pagination.total],
    ['Staff', meta.summary?.staff],
    ['Guests', meta.summary?.guests],
    ['Active Accounts', meta.summary?.active_accounts],
  ]
  const roleOptions = Object.keys(loginRoleLabels)

  return (
    <>
      <section className="admin-summary-grid admin-user-summary-grid" aria-label="User account summary">
        {summaryCards.map(([label, value]) => (
          <article className="admin-summary-card" key={label}>
            <span>{value ?? 0}</span>
            <strong>{label}</strong>
          </article>
        ))}
      </section>

      <ToastMessage message={pageMessage} />
      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}

      <section className="admin-management-grid admin-user-management-grid">
        <div className="admin-table-panel admin-user-table-panel">
          <div className="admin-filter-bar admin-user-filter-bar admin-user-toolbar">
            <label>
              <span>Search by name/email</span>
              <input name="search" value={filters.search} onChange={updateFilter} />
            </label>
            <label>
              <span>Role</span>
              <select name="role" value={filters.role} onChange={updateFilter}>
                <option value="">All roles</option>
                {(meta.roles ?? Object.keys(roleLabels)).filter((role) => role !== 'housekeeping_staff').map((role) => (
                  <option key={role} value={role}>{roleLabels[role] ?? role}</option>
                ))}
              </select>
            </label>
            <label>
              <span>Status</span>
              <select name="status" value={filters.status} onChange={updateFilter}>
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </label>
            <div className="admin-user-toolbar-actions">
              <button className="primary-button" type="button" onClick={openCreateDrawer}>
                + Create Account
              </button>
            <button className="primary-button" type="button" onClick={openEmployeeDrawer}>
                + Add Employee
              </button>
            </div>
          </div>

          <div className="admin-table-scroll admin-user-table-scroll">
            <table className="admin-data-table admin-user-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Contact</th>
                  <th>Role</th>
                  <th>Status</th>
                  <th>Created</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {isLoading ? (
                  <tr><td colSpan="7" aria-label="Loading users..." /></tr>
                ) : null}
                {!isLoading && users.length === 0 ? (
                  <tr><td colSpan="7">No users match the current filters.</td></tr>
                ) : null}
                {!isLoading
                  ? users.map((user) => (
                      <tr key={user.id}>
                        <td><strong>{user.name}</strong></td>
                        <td>{user.email}</td>
                        <td>{user.contact_number ?? 'Not set'}</td>
                        <td><span className={`admin-status-pill role-${user.role}`}>{roleLabels[user.role] ?? user.role}</span></td>
                        <td>
                          <span className={`admin-status-pill status-${user.status}`}>
                            {user.status}
                          </span>
                        </td>
                        <td>{formatDate(user.created_at)}</td>
                        <td>
                          <div className="admin-row-actions admin-user-row-actions">
                            <button type="button" onClick={() => selectUser(user)}>View/Edit</button>
                            <button
                              type="button"
                              onClick={() => handleStatusToggle(user)}
                              disabled={pendingActionId === user.id}
                            >
                              {user.is_active ? 'Deactivate' : 'Activate'}
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))
                  : null}
              </tbody>
            </table>
          </div>

          <footer className="admin-user-pagination" aria-label="User list pagination">
            <div className="admin-user-pagination-summary">
              Showing {pagination.total === 0 ? 0 : ((pagination.current_page - 1) * (pagination.per_page ?? rowsPerPage)) + 1}
              –{pagination.total === 0 ? 0 : Math.min(pagination.current_page * (pagination.per_page ?? rowsPerPage), pagination.total)} of {pagination.total.toLocaleString()} users
            </div>
            <label className="admin-user-page-size">
              <span>Rows</span>
              <select value={rowsPerPage} onChange={changeRowsPerPage} aria-label="Users per page">
                {[10, 25, 50].map((size) => <option key={size} value={size}>{size}</option>)}
              </select>
            </label>
            <div className="admin-user-pagination-controls">
              <button type="button" onClick={() => setCurrentPage((page) => Math.max(1, page - 1))} disabled={currentPage <= 1}>Previous</button>
              <div className="admin-user-page-numbers">
                {Array.from({ length: pagination.last_page }, (_, index) => index + 1).map((page) => (
                  <button
                    type="button"
                    key={page}
                    className={page === currentPage ? 'is-active' : ''}
                    aria-current={page === currentPage ? 'page' : undefined}
                    onClick={() => setCurrentPage(page)}
                  >
                    {page}
                  </button>
                ))}
              </div>
              <button type="button" onClick={() => setCurrentPage((page) => Math.min(pagination.last_page, page + 1))} disabled={currentPage >= pagination.last_page}>Next</button>
            </div>
          </footer>
        </div>
      </section>

      {isUserDrawerOpen ? (
        <>
          <button
            className="admin-user-drawer-backdrop"
            type="button"
            aria-label="Close user form"
            onClick={closeUserDrawer}
          />
          <aside className="admin-form-panel admin-user-drawer" aria-label={selectedUser ? 'Edit user account' : 'Create User'}>
            <form onSubmit={handleSubmit} noValidate className="admin-embedded-form">
              <div className="admin-user-drawer-header">
                <div>
                  <p className="eyebrow">{selectedUser ? 'User Details' : 'New User'}</p>
                  <h2>{selectedUser ? selectedUser.name : 'Create User'}</h2>
                </div>
                <button type="button" onClick={closeUserDrawer} aria-label="Close user form">
                  X
                </button>
              </div>

              <fieldset className="admin-user-form-section">
                <legend>Account Information</legend>
                <div className="admin-form-row">
                  <label>
                    <span>First Name</span>
                    <input name="first_name" value={formValues.first_name} onChange={updateFormField} />
                    {fieldErrors.first_name ? <small role="alert">{fieldErrors.first_name}</small> : null}
                  </label>
                  <label>
                    <span>Last Name</span>
                    <input name="last_name" value={formValues.last_name} onChange={updateFormField} />
                    {fieldErrors.last_name ? <small role="alert">{fieldErrors.last_name}</small> : null}
                  </label>
                </div>
                <label>
                  <span>Email</span>
                  <input name="email" value={formValues.email} onChange={updateFormField} />
                  {fieldErrors.email ? <small role="alert">{fieldErrors.email}</small> : null}
                </label>
                <label>
                  <span>Contact Number</span>
                  <input name="contact_number" value={formValues.contact_number} onChange={updateFormField} />
                  {fieldErrors.contact_number ? <small role="alert">{fieldErrors.contact_number}</small> : null}
                </label>
              </fieldset>

              <fieldset className="admin-user-form-section">
                <legend>Access</legend>
                <div className="admin-form-row">
                <label>
                  <span>Role</span>
                  {selectedUser?.role === 'guest' ? (
                    <div className="admin-readonly-field">Guest account</div>
                  ) : (
                    <select name="role" value={formValues.role} onChange={updateFormField}>
                      {roleOptions.map((role) => (
                        <option key={role} value={role}>{roleLabels[role]}</option>
                      ))}
                    </select>
                  )}
                  {fieldErrors.role ? <small role="alert">{fieldErrors.role}</small> : null}
                </label>
                  <label className="admin-checkbox-field">
                    <span>Status</span>
                    <input
                      checked={formValues.is_active}
                      name="is_active"
                      type="checkbox"
                      onChange={updateFormField}
                    />
                    Active account
                  </label>
                </div>
              </fieldset>

              {!selectedUser ? (
                <fieldset className="admin-user-form-section">
                  <legend>Security</legend>
                  <div className="admin-form-row">
                  <label>
                    <span>Password</span>
                    <input
                      name="password"
                      type="password"
                      value={formValues.password}
                      onChange={updateFormField}
                    />
                    {fieldErrors.password ? <small role="alert">{fieldErrors.password}</small> : null}
                  </label>
                  <label>
                    <span>Confirm Password</span>
                    <input
                      name="password_confirmation"
                      type="password"
                      value={formValues.password_confirmation}
                      onChange={updateFormField}
                    />
                    {fieldErrors.password_confirmation ? (
                      <small role="alert">{fieldErrors.password_confirmation}</small>
                    ) : null}
                  </label>
                  </div>
                </fieldset>
              ) : null}

              <div className="admin-form-actions">
                <button className="primary-button" type="submit" disabled={isSaving}>
                  {isSaving ? 'Saving...' : selectedUser ? 'Save Changes' : 'Create User'}
                </button>
                <button className="outline-button" type="button" onClick={startCreate}>
                  Clear
                </button>
              </div>
            </form>

            {selectedUser ? (
              <form className="admin-password-reset-panel" onSubmit={handlePasswordReset} noValidate>
                <div>
                  <p className="eyebrow">Password Reset</p>
                  <h2>Set temporary password</h2>
                </div>
                <label>
                  <span>New Password</span>
                  <input
                    name="password"
                    type="password"
                    value={passwordValues.password}
                    onChange={updatePasswordField}
                  />
                  {passwordErrors.password ? <small role="alert">{passwordErrors.password}</small> : null}
                </label>
                <label>
                  <span>Confirm Password</span>
                  <input
                    name="password_confirmation"
                    type="password"
                    value={passwordValues.password_confirmation}
                    onChange={updatePasswordField}
                  />
                  {passwordErrors.password_confirmation ? (
                    <small role="alert">{passwordErrors.password_confirmation}</small>
                  ) : null}
                </label>
                <button className="outline-button" type="submit" disabled={isResettingPassword}>
                  {isResettingPassword ? 'Resetting...' : 'Reset Password'}
                </button>
              </form>
            ) : null}
          </aside>
        </>
      ) : null}

      {isEmployeeDrawerOpen ? (
        <AdminEmployeeDrawer
          onClose={() => setIsEmployeeDrawerOpen(false)}
          onSaved={(employee, message) => {
            setIsEmployeeDrawerOpen(false)
            setPageMessage(message)
          }}
          onError={() => setPageError('Unable to save employee.')}
        />
      ) : null}
    </>
  )
}

export default AdminUsers
