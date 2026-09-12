import { useEffect, useState } from 'react'
import { createAdminEmployee, updateAdminEmployee } from '../lib/api'

const defaultValues = {
  first_name: '',
  last_name: '',
  position: 'Cleaning Staff',
  custom_position: '',
  phone: '',
  date_hired: '',
  status: 'active',
}

const positions = ['Cleaning Staff', 'Maintenance Staff', 'Utility Staff', 'Security Guard', 'Lifeguard', 'Other']
const legacyAccountPositions = ['Admin', 'Manager', 'Front Desk']
const statusLabels = { active: 'Active', inactive: 'Inactive' }

function AdminEmployeeDrawer({ employee = null, onClose, onSaved, onError }) {
  const [values, setValues] = useState(defaultValues)
  const [errors, setErrors] = useState({})
  const [isSaving, setIsSaving] = useState(false)

  useEffect(() => {
    const isCustomPosition = employee
      && !positions.includes(employee.position)
      && !legacyAccountPositions.includes(employee.position)
    setValues(employee ? {
      first_name: employee.first_name ?? '',
      last_name: employee.last_name ?? '',
      position: isCustomPosition ? 'Other' : employee.position ?? '',
      custom_position: isCustomPosition ? employee.position : '',
      phone: employee.phone ?? '',
      date_hired: employee.date_hired ?? '',
      status: employee.status ?? 'active',
    } : defaultValues)
    setErrors({})
  }, [employee])

  function updateField(event) {
    const { name, value } = event.target
    setValues((current) => ({ ...current, [name]: value }))
    setErrors((current) => ({ ...current, [name]: '' }))
  }

  async function submit(event) {
    event.preventDefault()
    setIsSaving(true)
    setErrors({})

    try {
      const payload = {
        first_name: values.first_name,
        last_name: values.last_name,
        position: values.position,
        custom_position: values.position === 'Other' ? values.custom_position.trim() : null,
        phone: values.phone.trim() || null,
        date_hired: values.date_hired || null,
        status: values.status,
      }
      const result = employee
        ? await updateAdminEmployee(employee.id, payload)
        : await createAdminEmployee(payload)
      onSaved?.(result.employee, result.message)
    } catch (error) {
      const fieldErrors = error?.response?.data?.errors
      if (fieldErrors) {
        setErrors(Object.fromEntries(Object.entries(fieldErrors).map(([key, value]) => [key, value[0]])))
      } else {
        onError?.(error)
      }
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <>
      <button className="admin-attendance-drawer-backdrop" type="button" aria-label="Close employee panel" onClick={onClose} />
      <aside className="admin-form-panel admin-attendance-drawer" aria-label="Employee details">
        <div className="admin-attendance-drawer-header">
          <div>
            <p className="eyebrow">Employee</p>
            <h2>{employee ? employee.name : 'Add employee'}</h2>
          </div>
          <button type="button" onClick={onClose} aria-label="Close employee panel">X</button>
        </div>

        <form className="admin-embedded-form" onSubmit={submit} noValidate>
          <p className="admin-form-note">Employees are operational records only. Create login accounts separately from User Management.</p>
          {employee ? (
            <div className="admin-form-note">
              <strong>Fingerprint</strong>
              <span>{employee.fingerprint_id ? 'Enrolled — Fingerprint ID: ' + employee.fingerprint_id : 'Not enrolled'}</span>
            </div>
          ) : null}

          <div className="admin-form-row">
            <label>
              <span>First Name</span>
              <input name="first_name" value={values.first_name} onChange={updateField} />
              {errors.first_name ? <small role="alert">{errors.first_name}</small> : null}
            </label>
            <label>
              <span>Last Name</span>
              <input name="last_name" value={values.last_name} onChange={updateField} />
              {errors.last_name ? <small role="alert">{errors.last_name}</small> : null}
            </label>
          </div>

          <label>
            <span>Position</span>
            <select name="position" value={values.position} onChange={updateField} required>
              <option value="">Select position</option>
              {positions.map((position) => <option key={position} value={position}>{position}</option>)}
              {values.position && !positions.includes(values.position) ? <option value={values.position}>{values.position} (existing)</option> : null}
            </select>
            {errors.position ? <small role="alert">{errors.position}</small> : null}
          </label>

          {values.position === 'Other' ? (
            <label>
              <span>Custom Position</span>
              <input
                name="custom_position"
                value={values.custom_position}
                onChange={updateField}
                placeholder="e.g. Gardener, Driver, Pool Attendant"
                required
              />
              {errors.custom_position ? <small role="alert">{errors.custom_position}</small> : null}
            </label>
          ) : null}

          <div className="admin-form-row">
            <label><span>Phone</span><input name="phone" value={values.phone} onChange={updateField} /></label>
            <label><span>Date Hired</span><input name="date_hired" type="date" value={values.date_hired} onChange={updateField} /></label>
          </div>

          <label>
            <span>Status</span>
            <select name="status" value={values.status} onChange={updateField}>
              {Object.keys(statusLabels).map((status) => <option key={status} value={status}>{statusLabels[status]}</option>)}
            </select>
            {errors.status ? <small role="alert">{errors.status}</small> : null}
          </label>

          <div className="admin-form-actions">
            <button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? 'Saving...' : employee ? 'Save Employee' : 'Create Employee'}</button>
            <button className="outline-button" type="button" onClick={onClose}>Cancel</button>
          </div>
        </form>
      </aside>
    </>
  )
}

export default AdminEmployeeDrawer
