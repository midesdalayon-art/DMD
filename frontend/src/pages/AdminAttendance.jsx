import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  createAdminAttendanceRecord,
  createAdminEmployee,
  assignAdminFingerprint,
  deleteAdminEmployee,
  deleteFingerprintOnBridge,
  deleteFingerprintForAdminEmployee,
  enrollFingerprintOnBridge,
  getAdminFingerprintSlot,
  getAdminAttendanceRecord,
  getAdminAttendanceRecords,
  getAdminEmployees,
  updateAdminAttendanceRecord,
  updateAdminEmployee,
} from '../lib/api'
import { ToastMessage } from '../context/ToastContext'
import AdminEmployeeDrawer from '../components/AdminEmployeeDrawer'
import { echo } from '../lib/echo'

function getPhilippineDateString() {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date())
}

const defaultAttendanceFormValues = {
  employee_id: '',
  attendance_date: getPhilippineDateString(),
  time_in: '',
  time_out: '',
  status: 'present',
  verification_method: 'manual',
  device_id: '',
  remarks: '',
  correction_reason: '',
}

const defaultEmployeeFormValues = {
  user_id: '',
  first_name: '',
  last_name: '',
  position: 'Cleaning Staff',
  phone: '',
  date_hired: '',
  status: 'active',
}

const employeePositions = ['Cleaning Staff', 'Maintenance Staff', 'Utility Staff', 'Security Guard', 'Lifeguard', 'Other']

const statusLabels = { present: 'Present', late: 'Late', absent: 'Absent', incomplete: 'Incomplete' }
const methodLabels = { manual: 'Manual', fingerprint: 'Fingerprint', face: 'Face' }
const employeeStatusLabels = { active: 'Active', inactive: 'Inactive' }

function AdminAttendance() {
  const [view, setView] = useState(() => new URLSearchParams(window.location.search).get('view') === 'employees' ? 'employees' : 'attendance')
  const [records, setRecords] = useState([])
  const [employees, setEmployees] = useState([])
  const [summary, setSummary] = useState(null)
  const [meta, setMeta] = useState({ statuses: [], verification_methods: [], employees: [] })
  const [history, setHistory] = useState([])
  const [selectedAttendance, setSelectedAttendance] = useState(null)
  const [selectedEmployee, setSelectedEmployee] = useState(null)
  const [attendanceFormValues, setAttendanceFormValues] = useState(defaultAttendanceFormValues)
  const [employeeFormValues, setEmployeeFormValues] = useState(defaultEmployeeFormValues)
  const [filters, setFilters] = useState({
    search: '',
    attendance_date: getPhilippineDateString(),
    status: '',
    verification_method: '',
  })
  const [employeeFilters, setEmployeeFilters] = useState({
    search: '',
    status: '',
  })
  const [fieldErrors, setFieldErrors] = useState({})
  const [pageMessage, setPageMessage] = useState('')
  const [pageError, setPageError] = useState('')
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)
  const [isDrawerOpen, setIsDrawerOpen] = useState(false)
  const [drawerMode, setDrawerMode] = useState('attendance')
  const [totalRecordCount, setTotalRecordCount] = useState(null)
  const [attendancePage, setAttendancePage] = useState(1)
  const [fingerprintOperation, setFingerprintOperation] = useState(null)
  const fingerprintActionInFlight = useRef(false)
  const employeeActionHandled = useRef(false)

  const attendanceFilterParams = useMemo(() => ({
    search: filters.search.trim() || undefined,
    attendance_date: filters.attendance_date || undefined,
    status: filters.status || undefined,
    verification_method: filters.verification_method || undefined,
  }), [filters])

  const employeeFilterParams = useMemo(() => ({
    search: employeeFilters.search.trim() || undefined,
    status: employeeFilters.status || undefined,
  }), [employeeFilters])

  const loadData = useCallback(async () => {
    setIsLoading(true)
    setPageError('')

    try {
      const [attendanceResult, employeeResult] = await Promise.allSettled([
        getAdminAttendanceRecords(attendanceFilterParams),
        getAdminEmployees(employeeFilterParams),
      ])
      if (attendanceResult.status === 'rejected') throw attendanceResult.reason

      const attendanceData = attendanceResult.value
      setRecords(attendanceData.records)
      setSummary(attendanceData.summary)
      setMeta(attendanceData.meta)
      setEmployees(employeeResult.status === 'fulfilled' ? employeeResult.value.employees : [])

      const hasAttendanceFilters = Object.values(attendanceFilterParams).some(Boolean)
      if (!hasAttendanceFilters) {
        setTotalRecordCount(attendanceData.records.length)
      } else if (totalRecordCount === null) {
        try {
          const unfilteredResult = await getAdminAttendanceRecords({})
          setTotalRecordCount(unfilteredResult.records.length)
        } catch {
          // The filtered records and employee data are sufficient to render the page.
          // Keep the count unavailable without turning this optional request into a page error.
          setTotalRecordCount(null)
        }
      }
    } catch {
      setPageError('Unable to load attendance data right now.')
    } finally {
      setIsLoading(false)
    }
  }, [attendanceFilterParams, employeeFilterParams, totalRecordCount])

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      loadData()
    }, 250)

    return () => window.clearTimeout(timeoutId)
  }, [loadData])

  useEffect(() => {
    if (!echo) return undefined

    const channel = echo.private('attendance')
    const refresh = () => { loadData() }
    channel.listen('.AttendanceUpdated', refresh)
    echo.connector.pusher?.connection?.bind('connected', refresh)

    return () => {
      channel.stopListening('.AttendanceUpdated', refresh)
      echo.connector.pusher?.connection?.unbind('connected', refresh)
      echo.leave('private-attendance')
    }
  }, [loadData])

  function updateFilter(event) {
    const { name, value } = event.target
    setAttendancePage(1)
    setFilters((current) => ({ ...current, [name]: value }))
  }

  function updateEmployeeFilter(event) {
    const { name, value } = event.target
    setEmployeeFilters((current) => ({ ...current, [name]: value }))
  }

  function updateAttendanceField(event) {
    const { name, value } = event.target
    setAttendanceFormValues((current) => ({ ...current, [name]: value }))
    setFieldErrors((current) => ({ ...current, [name]: '' }))
    setPageError('')
    setPageMessage('')
  }

  function updateEmployeeField(event) {
    const { name, value } = event.target
    setEmployeeFormValues((current) => ({
      ...current,
      [name]: value,
      ...(name === 'position' && !['Admin', 'Manager', 'Front Desk'].includes(value) ? { user_id: '' } : {}),
    }))
    setFieldErrors((current) => ({ ...current, [name]: '' }))
    setPageError('')
    setPageMessage('')
  }

  function openAttendanceCreate() {
    setDrawerMode('attendance')
    setSelectedAttendance(null)
    setHistory([])
    setAttendanceFormValues({
      ...defaultAttendanceFormValues,
      employee_id: employees.find((employee) => employee.status === 'active')?.id ?? '',
      attendance_date: filters.attendance_date || defaultAttendanceFormValues.attendance_date,
    })
    setFieldErrors({})
    setPageError('')
    setPageMessage('')
    setIsDrawerOpen(true)
  }

  function openEmployeeCreate() {
    setDrawerMode('employee')
    setSelectedEmployee(null)
    setEmployeeFormValues(defaultEmployeeFormValues)
    setFieldErrors({})
    setPageError('')
    setPageMessage('')
    setIsDrawerOpen(true)
  }

  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    if (!employeeActionHandled.current && params.get('view') === 'employees' && params.get('action') === 'create') {
      employeeActionHandled.current = true
      openEmployeeCreate()
    }
  }, [])

  async function openAttendanceRecord(record) {
    setPageError('')
    setPageMessage('')
    setDrawerMode('attendance')

    try {
      const result = await getAdminAttendanceRecord(record.id)
      setSelectedAttendance(result.record)
      setHistory(result.history)
      setMeta((current) => ({ ...current, ...result.meta }))
      setAttendanceFormValues({
        employee_id: result.record.employee_id ?? '',
        attendance_date: result.record.attendance_date ?? defaultAttendanceFormValues.attendance_date,
        time_in: result.record.time_in?.slice(0, 5) ?? '',
        time_out: result.record.time_out?.slice(0, 5) ?? '',
        status: result.record.status ?? 'present',
        verification_method: result.record.verification_method ?? 'manual',
        device_id: result.record.device_id ?? '',
        remarks: result.record.remarks ?? '',
        correction_reason: '',
      })
      setFieldErrors({})
      setIsDrawerOpen(true)
    } catch {
      setPageError('Unable to load attendance details.')
    }
  }

  function openEmployeeRecord(employee) {
    setDrawerMode('employee')
    setSelectedEmployee(employee)
    setEmployeeFormValues({
      user_id: employee.user_id ?? '',
      first_name: employee.first_name ?? '',
      last_name: employee.last_name ?? '',
      position: employee.position ?? '',
      phone: employee.phone ?? '',
      date_hired: employee.date_hired ?? '',
      status: employee.status ?? 'active',
    })
    setFieldErrors({})
    setPageError('')
    setPageMessage('')
    setIsDrawerOpen(true)
  }

  function closeDrawer() {
    setIsDrawerOpen(false)
  }

  function clearAttendanceFilters() {
    setAttendancePage(1)
    setFilters({ search: '', attendance_date: '', status: '', verification_method: '' })
  }

  function clearEmployeeFilters() {
    setEmployeeFilters({ search: '', status: '' })
  }

  function buildAttendancePayload() {
    return {
      employee_id: Number(attendanceFormValues.employee_id),
      attendance_date: attendanceFormValues.attendance_date,
      time_in: attendanceFormValues.time_in || null,
      time_out: attendanceFormValues.time_out || null,
      status: attendanceFormValues.status,
      verification_method: attendanceFormValues.verification_method,
      device_id: attendanceFormValues.device_id.trim() || null,
      remarks: attendanceFormValues.remarks.trim() || null,
      correction_reason: selectedAttendance ? attendanceFormValues.correction_reason.trim() : undefined,
    }
  }

  function buildEmployeePayload() {
    return {
      first_name: employeeFormValues.first_name,
      last_name: employeeFormValues.last_name,
      position: employeeFormValues.position,
      phone: employeeFormValues.phone.trim() || null,
      date_hired: employeeFormValues.date_hired || null,
      status: employeeFormValues.status,
    }
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setIsSaving(true)
    setFieldErrors({})
    setPageError('')
    setPageMessage('')

    try {
      const result = drawerMode === 'employee'
        ? selectedEmployee
          ? await updateAdminEmployee(selectedEmployee.id, buildEmployeePayload())
          : await createAdminEmployee(buildEmployeePayload())
        : selectedAttendance
          ? await updateAdminAttendanceRecord(selectedAttendance.id, buildAttendancePayload())
          : await createAdminAttendanceRecord(buildAttendancePayload())

      setPageMessage(result.message)
      await loadData()

      if (drawerMode === 'employee') {
        if (result.employee) {
          setSelectedEmployee(result.employee)
          setEmployeeFormValues({
            user_id: result.employee.user_id ?? '',
            first_name: result.employee.first_name ?? '',
            last_name: result.employee.last_name ?? '',
            position: result.employee.position ?? '',
            phone: result.employee.phone ?? '',
            date_hired: result.employee.date_hired ?? '',
            status: result.employee.status ?? 'active',
          })
        }
      } else if (result.record) {
        setSelectedAttendance(result.record)
        setAttendanceFormValues({
          employee_id: result.record.employee_id ?? '',
          attendance_date: result.record.attendance_date ?? defaultAttendanceFormValues.attendance_date,
          time_in: result.record.time_in?.slice(0, 5) ?? '',
          time_out: result.record.time_out?.slice(0, 5) ?? '',
          status: result.record.status ?? 'present',
          verification_method: result.record.verification_method ?? 'manual',
          device_id: result.record.device_id ?? '',
          remarks: result.record.remarks ?? '',
          correction_reason: '',
        })
      }
    } catch (error) {
      const errors = error?.response?.data?.errors
      if (errors) {
        setFieldErrors(Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, value[0]])))
      } else {
        setPageError(drawerMode === 'employee' ? 'Unable to save employee.' : 'Unable to save attendance record.')
      }
    } finally {
      setIsSaving(false)
    }
  }

  async function removeEmployee(employee) {
    if (!window.confirm(`Deactivate or delete ${employee.name}?`)) return

    setPageError('')
    setPageMessage('')

    try {
      const result = await deleteAdminEmployee(employee.id)
      setPageMessage(result.message)
      await loadData()
    } catch {
      setPageError('Unable to update employee status.')
    }
  }

  async function enrollFingerprint(employee) {
    if (fingerprintOperation) return
    setFingerprintOperation({ employeeId: employee.id, label: 'Preparing scanner...' })
    setPageError('')
    setPageMessage('')

    try {
      const slot = await getAdminFingerprintSlot()
      setFingerprintOperation({ employeeId: employee.id, label: 'Place finger firmly on scanner...' })
      const bridgeResult = await enrollFingerprintOnBridge(slot)
      setFingerprintOperation({ employeeId: employee.id, label: 'Saving fingerprint mapping...' })
      try {
        const result = await assignAdminFingerprint(employee.id, slot)
        setPageMessage(result.message)
        await loadData()
      } catch (mappingError) {
        try { await deleteFingerprintOnBridge(slot) } catch { /* surface the original consistency failure */ }
        throw new Error(`Fingerprint enrolled but employee mapping failed. Sensor cleanup was attempted. ${mappingError.message}`)
      }
      if (!bridgeResult.ok) throw new Error('Scanner enrollment did not complete.')
    } catch (error) {
      setPageError(error.message || 'Fingerprint enrollment failed.')
    } finally {
      setFingerprintOperation(null)
    }
  }

  async function removeFingerprint(employee) {
    if (fingerprintOperation || fingerprintActionInFlight.current || !employee.fingerprint_id) return
    if (!window.confirm(`Remove fingerprint for ${employee.name}? This will delete the sensor enrollment.`)) return
    fingerprintActionInFlight.current = true
    setFingerprintOperation({ employeeId: employee.id, label: 'Removing fingerprint from scanner...' })
    setPageError('')
    setPageMessage('')

    try {
      const result = await deleteFingerprintForAdminEmployee(employee.id, employee.fingerprint_id)
      setPageMessage(result.message)
      await loadData()
    } catch (error) {
      setPageError(error.message || 'Fingerprint removal failed. The database mapping was preserved.')
    } finally {
      fingerprintActionInFlight.current = false
      setFingerprintOperation(null)
    }
  }

  function formatDate(value) {
    if (!value) return 'Not set'
    const date = /^\d{4}-\d{2}-\d{2}$/.test(value)
      ? new Date(`${value}T12:00:00Z`)
      : new Date(value)

    if (Number.isNaN(date.getTime())) return 'Not set'

    return new Intl.DateTimeFormat('en-PH', { year: 'numeric', month: 'short', day: 'numeric', timeZone: 'Asia/Manila' })
      .format(date)
  }

  const attendanceHasFilters = Object.values(filters).some(Boolean)
  const attendanceHasAnyRecords = (totalRecordCount ?? (!attendanceHasFilters ? records.length : 0)) > 0
  const attendancePageSize = 10
  const attendancePageCount = Math.max(1, Math.ceil(records.length / attendancePageSize))
  const visibleAttendanceRecords = records.slice(
    (attendancePage - 1) * attendancePageSize,
    attendancePage * attendancePageSize,
  )
  const activeEmployees = employees.filter((employee) => employee.status === 'active')

  useEffect(() => {
    setAttendancePage((current) => Math.min(current, attendancePageCount))
  }, [attendancePageCount])

  return (
    <>
      <section className="admin-summary-grid attendance-summary-grid" aria-label="Attendance summary">
        {[
          ['Present Today', summary?.present_today ?? 0],
          ['Late Today', summary?.late_today ?? 0],
          ['Absent Today', summary?.absent_today ?? 0],
          ['Incomplete', summary?.incomplete ?? 0],
        ].map(([label, value]) => (
          <article className="admin-summary-card" key={label}>
            <span>{label}</span>
            <strong>{isLoading ? '...' : value}</strong>
          </article>
        ))}
      </section>

      <ToastMessage message={pageMessage} />
      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}

      <section className="admin-table-panel admin-attendance-table-panel">
        <div className="admin-table-heading">
          <h2>{view === 'employees' ? `Employees (${employees.length.toLocaleString()})` : `Records (${records.length.toLocaleString()})`}</h2>
          <div className="admin-table-heading-actions">
            <button type="button" className={view === 'attendance' ? 'primary-button' : 'outline-button'} onClick={() => setView('attendance')}>
              Attendance
            </button>
            <button type="button" className={view === 'employees' ? 'primary-button' : 'outline-button'} onClick={() => setView('employees')}>
              Employees
            </button>
            {view === 'attendance' ? (
              <>
                {attendanceHasFilters ? <button type="button" onClick={clearAttendanceFilters}>Clear filters</button> : null}
                <button className="primary-button" type="button" onClick={openAttendanceCreate}>
                  + Add Manual Attendance
                </button>
              </>
            ) : (
              <>
                {employeeFilters.search || employeeFilters.status ? <button type="button" onClick={clearEmployeeFilters}>Clear filters</button> : null}
                <button className="primary-button" type="button" onClick={openEmployeeCreate}>
                  + Add Employee
                </button>
              </>
            )}
          </div>
        </div>

        {view === 'attendance' ? (
          <>
            <div className="admin-filter-bar attendance-filter-bar">
              <label>
                <span>Search Employee</span>
                <input name="search" value={filters.search} onChange={updateFilter} placeholder="Name, code, position, email" />
              </label>
              <label>
                <span>Date</span>
                <input name="attendance_date" type="date" value={filters.attendance_date} onChange={updateFilter} />
              </label>
              <label>
                <span>Status</span>
                <select name="status" value={filters.status} onChange={updateFilter}>
                  <option value="">All</option>
                  {(meta.statuses ?? Object.keys(statusLabels)).map((status) => (
                    <option key={status} value={status}>{statusLabels[status]}</option>
                  ))}
                </select>
              </label>
              <label>
                <span>Verification</span>
                <select name="verification_method" value={filters.verification_method} onChange={updateFilter}>
                  <option value="">All</option>
                  {(meta.verification_methods ?? Object.keys(methodLabels)).map((method) => (
                    <option key={method} value={method}>{methodLabels[method]}</option>
                  ))}
                </select>
              </label>
            </div>

            <div className="admin-table-scroll admin-attendance-table-scroll">
              <table className="admin-data-table attendance-table">
                <thead>
                  <tr>
                    <th>Employee</th>
                    <th>Position</th>
                    <th>Date</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th>Verification</th>
                    <th>Status</th>
                    <th>Remarks</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {isLoading ? <tr><td colSpan="9" aria-label="Loading attendance records..." /></tr> : null}
                  {!isLoading && records.length === 0 ? (
                    <tr>
                      <td colSpan="9">
                        {attendanceHasFilters && attendanceHasAnyRecords
                          ? 'No attendance records match the current filters.'
                          : 'No attendance records have been created yet.'}
                      </td>
                    </tr>
                  ) : null}
                  {!isLoading ? visibleAttendanceRecords.map((record) => (
                    <tr key={record.id}>
                      <td>
                        <strong>{record.employee?.name ?? record.staff?.name ?? 'Employee unavailable'}</strong>
                        <small>{record.employee?.employee_code ?? record.staff?.email ?? 'No account'}</small>
                      </td>
                      <td>{record.employee?.position ?? record.staff?.role ?? 'Not set'}</td>
                      <td>{formatDate(record.attendance_date)}</td>
                      <td>{record.time_in ?? 'Not set'}</td>
                      <td>{record.time_out ?? 'Not set'}</td>
                      <td>
                        <span className={`admin-status-pill verification-${record.verification_method}`}>
                          {methodLabels[record.verification_method]}
                        </span>
                        <small>{record.device_id ?? 'Manual Entry'}</small>
                      </td>
                      <td><span className={`admin-status-pill status-${record.status}`}>{statusLabels[record.status]}</span></td>
                      <td>{record.remarks ?? 'No remarks'}</td>
                      <td>
                        <button type="button" onClick={() => openAttendanceRecord(record)}>View</button>
                      </td>
                    </tr>
                  )) : null}
                </tbody>
              </table>
            </div>
            {!isLoading && records.length > 0 ? (
              <footer className="admin-attendance-pagination" aria-label="Attendance records pagination">
                <span>Page {attendancePage} of {attendancePageCount}</span>
                <div>
                  <button type="button" onClick={() => setAttendancePage((current) => Math.max(1, current - 1))} disabled={attendancePage === 1}>
                    Previous
                  </button>
                  <button type="button" onClick={() => setAttendancePage((current) => Math.min(attendancePageCount, current + 1))} disabled={attendancePage === attendancePageCount}>
                    Next
                  </button>
                </div>
              </footer>
            ) : null}
          </>
        ) : (
          <>
            <div className="admin-filter-bar attendance-filter-bar">
              <label>
                <span>Search Employee</span>
                <input name="search" value={employeeFilters.search} onChange={updateEmployeeFilter} placeholder="Code, name, position, account" />
              </label>
              <label>
                <span>Status</span>
                <select name="status" value={employeeFilters.status} onChange={updateEmployeeFilter}>
                  <option value="">All</option>
                  {Object.keys(employeeStatusLabels).map((status) => (
                    <option key={status} value={status}>{employeeStatusLabels[status]}</option>
                  ))}
                </select>
              </label>
            </div>

            <div className="admin-table-scroll">
              <table className="admin-data-table attendance-table">
                <thead>
                  <tr>
                    <th>Employee ID</th>
                    <th>Name</th>
                      <th>Position</th>
                      <th>System Account</th>
                      <th>Fingerprint</th>
                      <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {isLoading ? <tr><td colSpan="6" aria-label="Loading employees..." /></tr> : null}
                  {!isLoading && employees.length === 0 ? (
                    <tr>
                      <td colSpan="6">
                        {employeeFilters.search || employeeFilters.status ? 'No employees match the current filters.' : 'No employees have been created yet.'}
                      </td>
                    </tr>
                  ) : null}
                  {!isLoading ? employees.map((employee) => (
                    <tr key={employee.id}>
                      <td>{employee.employee_code}</td>
                      <td>
                        <strong>{employee.name}</strong>
                        <small>{employee.phone ?? 'No phone'}</small>
                      </td>
                      <td>{employee.position}</td>
                      <td>{employee.system_account ? employee.system_account.name : 'No Account'}</td>
                      <td>
                        <span className={`admin-status-pill ${employee.fingerprint_id ? 'status-active' : 'status-inactive'}`}>
                          {employee.fingerprint_id ? 'Enrolled' : 'Not Enrolled'}
                        </span>
                        {employee.fingerprint_id ? <small className="admin-table-secondary">Fingerprint ID: {employee.fingerprint_id}</small> : null}
                      </td>
                      <td><span className={`admin-status-pill status-${employee.status}`}>{employeeStatusLabels[employee.status] ?? employee.status}</span></td>
                      <td>
                        <button type="button" onClick={() => openEmployeeRecord(employee)}>Edit</button>
                        {employee.fingerprint_id ? (
                          <button type="button" disabled={Boolean(fingerprintOperation)} onClick={() => removeFingerprint(employee)}>Remove Fingerprint</button>
                        ) : (
                          <button type="button" disabled={Boolean(fingerprintOperation) || employee.status !== 'active'} onClick={() => enrollFingerprint(employee)}>Enroll Fingerprint</button>
                        )}
                        <button type="button" onClick={() => removeEmployee(employee)}>Deactivate</button>
                      </td>
                    </tr>
                  )) : null}
                </tbody>
              </table>
            </div>
          </>
        )}
      </section>

      {fingerprintOperation ? (
        <ToastMessage type="info" message={`Fingerprint operation for ${employees.find((employee) => employee.id === fingerprintOperation.employeeId)?.name ?? 'employee'}: ${fingerprintOperation.label}`} />
      ) : null}

      {isDrawerOpen && drawerMode === 'employee' ? (
        <AdminEmployeeDrawer
          employee={selectedEmployee}
          onClose={closeDrawer}
          onSaved={async (employee, message) => {
            setSelectedEmployee(employee)
            setPageMessage(message)
            await loadData()
          }}
          onError={() => setPageError('Unable to save employee.')}
        />
      ) : null}

      {isDrawerOpen && drawerMode === 'attendance' ? (
        <>
          <button
            className="admin-attendance-drawer-backdrop"
            type="button"
            aria-label="Close details panel"
            onClick={closeDrawer}
          />
          <aside className="admin-form-panel admin-attendance-drawer" aria-label={drawerMode === 'employee' ? 'Employee details' : 'Attendance record details'}>
            <div className="admin-attendance-drawer-header">
              <div>
                <p className="eyebrow">{drawerMode === 'employee' ? 'Employee' : selectedAttendance ? 'Attendance Details' : 'Manual Entry'}</p>
                <h2>{drawerMode === 'employee' ? (selectedEmployee ? selectedEmployee.name : 'Add employee') : (selectedAttendance ? selectedAttendance.employee?.name ?? selectedAttendance.staff?.name : 'Add attendance record')}</h2>
              </div>
              <button type="button" onClick={closeDrawer} aria-label="Close panel">
                X
              </button>
            </div>

            <form className="admin-embedded-form" onSubmit={handleSubmit} noValidate>
              {drawerMode === 'employee' ? (
                <>
                  <p className="admin-form-note">Employees are operational records only. Create login accounts separately from User Management.</p>
                  {selectedEmployee ? (
                    <div className="admin-form-note">
                      <strong>Fingerprint</strong>
                      <span>{selectedEmployee.fingerprint_id ? `Enrolled — Fingerprint ID: ${selectedEmployee.fingerprint_id}` : 'Not enrolled'}</span>
                    </div>
                  ) : null}

                  <div className="admin-form-row">
                    <label>
                      <span>First Name</span>
                      <input name="first_name" value={employeeFormValues.first_name} onChange={updateEmployeeField} />
                      {fieldErrors.first_name ? <small role="alert">{fieldErrors.first_name}</small> : null}
                    </label>
                    <label>
                      <span>Last Name</span>
                      <input name="last_name" value={employeeFormValues.last_name} onChange={updateEmployeeField} />
                      {fieldErrors.last_name ? <small role="alert">{fieldErrors.last_name}</small> : null}
                    </label>
                  </div>

                  <label>
                    <span>Position</span>
                    <select name="position" value={employeeFormValues.position} onChange={updateEmployeeField} required>
                      <option value="">Select position</option>
                      {employeePositions.map((position) => <option key={position} value={position}>{position}</option>)}
                      {employeeFormValues.position && !employeePositions.includes(employeeFormValues.position) ? <option value={employeeFormValues.position}>{employeeFormValues.position} (legacy)</option> : null}
                    </select>
                    {fieldErrors.position ? <small role="alert">{fieldErrors.position}</small> : null}
                  </label>

                  <div className="admin-form-row">
                    <label>
                      <span>Phone</span>
                      <input name="phone" value={employeeFormValues.phone} onChange={updateEmployeeField} />
                    </label>
                    <label>
                      <span>Date Hired</span>
                      <input name="date_hired" type="date" value={employeeFormValues.date_hired} onChange={updateEmployeeField} />
                    </label>
                  </div>

                  <label>
                    <span>Status</span>
                    <select name="status" value={employeeFormValues.status} onChange={updateEmployeeField}>
                      {Object.keys(employeeStatusLabels).map((status) => <option key={status} value={status}>{employeeStatusLabels[status]}</option>)}
                    </select>
                    {fieldErrors.status ? <small role="alert">{fieldErrors.status}</small> : null}
                  </label>
                </>
              ) : (
                <>
                  <label>
                    <span>Staff Member</span>
                    <select name="employee_id" value={attendanceFormValues.employee_id} onChange={updateAttendanceField}>
                      <option value="">Select employee</option>
                      {(activeEmployees.length > 0 ? activeEmployees : meta.employees ?? []).map((employee) => (
                        <option key={employee.id} value={employee.id}>{employee.employee_code} — {employee.name} — {employee.position}</option>
                      ))}
                    </select>
                    {fieldErrors.employee_id ? <small role="alert">{fieldErrors.employee_id}</small> : null}
                  </label>

                  <div className="admin-form-row">
                    <label>
                      <span>Attendance Date</span>
                      <input name="attendance_date" type="date" value={attendanceFormValues.attendance_date} onChange={updateAttendanceField} />
                      {fieldErrors.attendance_date ? <small role="alert">{fieldErrors.attendance_date}</small> : null}
                    </label>
                    <label>
                      <span>Status</span>
                      <select name="status" value={attendanceFormValues.status} onChange={updateAttendanceField}>
                        {Object.keys(statusLabels).map((status) => <option key={status} value={status}>{statusLabels[status]}</option>)}
                      </select>
                      {fieldErrors.status ? <small role="alert">{fieldErrors.status}</small> : null}
                    </label>
                  </div>

                  <div className="admin-form-row">
                    <label>
                      <span>Time In</span>
                      <input name="time_in" type="time" value={attendanceFormValues.time_in} onChange={updateAttendanceField} />
                      {fieldErrors.time_in ? <small role="alert">{fieldErrors.time_in}</small> : null}
                    </label>
                    <label>
                      <span>Time Out</span>
                      <input name="time_out" type="time" value={attendanceFormValues.time_out} onChange={updateAttendanceField} />
                      {fieldErrors.time_out ? <small role="alert">{fieldErrors.time_out}</small> : null}
                    </label>
                  </div>

                  <div className="admin-form-row">
                    <label>
                      <span>Verification</span>
                      <select name="verification_method" value={attendanceFormValues.verification_method} onChange={updateAttendanceField}>
                        {Object.keys(methodLabels).map((method) => <option key={method} value={method}>{methodLabels[method]}</option>)}
                      </select>
                    </label>
                    <label>
                      <span>Device / Source</span>
                      <input name="device_id" value={attendanceFormValues.device_id} onChange={updateAttendanceField} placeholder="Manual Entry" />
                    </label>
                  </div>

                  <label>
                    <span>Remarks</span>
                    <textarea name="remarks" rows="3" value={attendanceFormValues.remarks} onChange={updateAttendanceField} />
                    {fieldErrors.remarks ? <small role="alert">{fieldErrors.remarks}</small> : null}
                  </label>

                  {selectedAttendance ? (
                    <label>
                      <span>Correction Reason</span>
                      <textarea name="correction_reason" rows="2" value={attendanceFormValues.correction_reason} onChange={updateAttendanceField} />
                      {fieldErrors.correction_reason ? <small role="alert">{fieldErrors.correction_reason}</small> : null}
                    </label>
                  ) : null}
                </>
              )}

              <div className="admin-form-actions">
                <button className="primary-button" type="submit" disabled={isSaving}>
                  {isSaving ? 'Saving...' : drawerMode === 'employee' ? (selectedEmployee ? 'Save Employee' : 'Create Employee') : selectedAttendance ? 'Save Correction' : 'Save Attendance'}
                </button>
                <button className="outline-button" type="button" onClick={drawerMode === 'employee' ? openEmployeeCreate : openAttendanceCreate}>Clear</button>
              </div>
            </form>

            {drawerMode === 'attendance' && selectedAttendance ? (
              <section className="admin-password-reset-panel">
                <div>
                  <p className="eyebrow">Attendance History</p>
                  <h2>Audit trail</h2>
                </div>
                <div className="inventory-history-list">
                  {history.length > 0 ? history.map((entry) => (
                    <article key={entry.id}>
                      <strong>{entry.action.replaceAll('_', ' ')}</strong>
                      <span>{formatDate(entry.created_at)} - {entry.performed_by?.name ?? 'System'}</span>
                      {entry.remarks ? <p>{entry.remarks}</p> : null}
                    </article>
                  )) : <p>No history recorded yet.</p>}
                </div>
              </section>
            ) : null}
          </aside>
        </>
      ) : null}
    </>
  )
}

export default AdminAttendance
