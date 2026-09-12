import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  createAdminHousekeepingTask,
  getAdminAccommodations,
  getAdminHousekeepingTask,
  getAdminHousekeepingTasks,
  updateAdminHousekeepingTask,
  updateAdminHousekeepingTaskStatus,
} from '../lib/api'
import { ToastMessage } from '../context/ToastContext'

const defaultFormValues = {
  accommodation_id: '',
  employee_id: '',
  task_type: 'cleaning',
  priority: 'normal',
  status: 'pending',
  scheduled_at: '',
  started_at: '',
  completed_at: '',
  remarks: '',
  maintenance_notes: '',
}

const taskTypeLabels = {
  cleaning: 'Cleaning',
  room_preparation: 'Room Preparation',
  inspection: 'Inspection',
  maintenance: 'Maintenance',
}

const priorityLabels = {
  low: 'Low',
  normal: 'Normal',
  high: 'High',
  urgent: 'Urgent',
}

const statusLabels = {
  pending: 'Pending',
  assigned: 'Assigned',
  in_progress: 'In Progress',
  completed: 'Completed',
  cancelled: 'Cancelled',
}

function AdminHousekeeping() {
  const [tasks, setTasks] = useState([])
  const [summary, setSummary] = useState(null)
  const [meta, setMeta] = useState({ task_types: [], priorities: [], statuses: [], staff: [] })
  const [accommodations, setAccommodations] = useState([])
  const [history, setHistory] = useState([])
  const [selectedTask, setSelectedTask] = useState(null)
  const [formValues, setFormValues] = useState(defaultFormValues)
  const [filters, setFilters] = useState({
    search: '',
    accommodation_id: '',
    employee_id: '',
    status: '',
    priority: '',
    task_type: '',
  })
  const [fieldErrors, setFieldErrors] = useState({})
  const [pageMessage, setPageMessage] = useState('')
  const [pageError, setPageError] = useState('')
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)
  const [pendingActionId, setPendingActionId] = useState(null)
  const [isDrawerOpen, setIsDrawerOpen] = useState(false)
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0, per_page: 10 })
  const [currentPage, setCurrentPage] = useState(1)
  const [rowsPerPage] = useState(10)

  const filterParams = useMemo(
    () => ({
      search: filters.search.trim() || undefined,
      accommodation_id: filters.accommodation_id || undefined,
      employee_id: filters.employee_id || undefined,
      status: filters.status || undefined,
      priority: filters.priority || undefined,
      task_type: filters.task_type || undefined,
      page: currentPage,
      per_page: rowsPerPage,
    }),
    [currentPage, filters, rowsPerPage],
  )

  const loadHousekeeping = useCallback(async () => {
    setIsLoading(true)
    setPageError('')

    try {
      const [taskResult, accommodationResult] = await Promise.allSettled([
        getAdminHousekeepingTasks(filterParams),
        getAdminAccommodations(),
      ])
      if (taskResult.status === 'rejected') throw taskResult.reason

      const taskData = taskResult.value
      setTasks(taskData.tasks)
      setSummary(taskData.summary)
      setMeta(taskData.meta)
      setPagination(taskData.pagination)
      setAccommodations(accommodationResult.status === 'fulfilled' ? accommodationResult.value : [])
    } catch {
      setPageError('Unable to load cleaning tasks right now.')
    } finally {
      setIsLoading(false)
    }
  }, [filterParams])

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      loadHousekeeping()
    }, 250)

    return () => window.clearTimeout(timeoutId)
  }, [loadHousekeeping])

  function updateFilter(event) {
    const { name, value } = event.target
    setCurrentPage(1)
    setFilters((current) => ({ ...current, [name]: value }))
  }

  function updateFormField(event) {
    const { name, value } = event.target
    setFormValues((current) => ({ ...current, [name]: value }))
    setFieldErrors((current) => ({ ...current, [name]: '' }))
    setPageError('')
    setPageMessage('')
  }

  function startCreate() {
    setSelectedTask(null)
    setHistory([])
    setFormValues({
      ...defaultFormValues,
      accommodation_id: accommodations[0]?.id ?? '',
    })
    setFieldErrors({})
    setPageError('')
    setPageMessage('')
    setIsDrawerOpen(true)
  }

  async function selectTask(task) {
    setPageError('')
    setPageMessage('')

    try {
      const result = await getAdminHousekeepingTask(task.id)
      setSelectedTask(result.task)
      setHistory(result.history)
      setMeta((current) => ({ ...current, ...result.meta }))
      setFormValues(taskToForm(result.task))
      setFieldErrors({})
      setIsDrawerOpen(true)
    } catch {
      setPageError('Unable to load cleaning task details.')
    }
  }

  function closeDrawer() {
    setIsDrawerOpen(false)
  }

  function clearFilters() {
    setCurrentPage(1)
    setFilters({
      search: '',
      accommodation_id: '',
      employee_id: '',
      status: '',
      priority: '',
      task_type: '',
    })
  }

  function taskToForm(task) {
    return {
      accommodation_id: task.accommodation_id ?? '',
      employee_id: task.employee_id ?? task.assigned_to ?? '',
      task_type: task.task_type ?? 'cleaning',
      priority: task.priority ?? 'normal',
      status: task.status ?? 'pending',
      scheduled_at: toDateTimeInput(task.scheduled_at),
      started_at: toDateTimeInput(task.started_at),
      completed_at: toDateTimeInput(task.completed_at),
      remarks: task.remarks ?? '',
      maintenance_notes: task.maintenance_notes ?? '',
    }
  }

  function buildPayload() {
    return {
      accommodation_id: Number(formValues.accommodation_id),
      employee_id: formValues.employee_id ? Number(formValues.employee_id) : null,
      task_type: formValues.task_type,
      priority: formValues.priority,
      status: formValues.status,
      scheduled_at: formValues.scheduled_at || null,
      started_at: formValues.started_at || null,
      completed_at: formValues.completed_at || null,
      remarks: formValues.remarks.trim() || null,
      maintenance_notes: formValues.maintenance_notes.trim() || null,
    }
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setIsSaving(true)
    setFieldErrors({})
    setPageError('')
    setPageMessage('')

    try {
      const result = selectedTask
        ? await updateAdminHousekeepingTask(selectedTask.id, buildPayload())
        : await createAdminHousekeepingTask(buildPayload())
      setPageMessage(result.message)
      await loadHousekeeping()
      await selectTask(result.task)
    } catch (error) {
      const errors = error?.response?.data?.errors
      if (errors) {
        setFieldErrors(Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, value[0]])))
      } else {
        setPageError('Unable to save cleaning task.')
      }
    } finally {
      setIsSaving(false)
    }
  }

  async function quickStatus(task, status) {
    setPendingActionId(task.id)
    setPageError('')
    setPageMessage('')

    try {
      const result = await updateAdminHousekeepingTaskStatus(task.id, {
        status,
        remarks: `Status changed to ${statusLabels[status]}.`,
      })
      setPageMessage(result.message)
      await loadHousekeeping()
      if (selectedTask?.id === task.id) await selectTask(result.task)
    } catch (error) {
      setPageError(error?.response?.data?.errors?.status?.[0] ?? 'Unable to update task status.')
    } finally {
      setPendingActionId(null)
    }
  }

  function formatDateTime(value) {
    if (!value) return 'Not scheduled'
    return new Intl.DateTimeFormat('en-PH', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
    }).format(new Date(value))
  }

  const statusOptions = selectedTask
    ? [selectedTask.status, ...(selectedTask.allowed_statuses ?? [])]
    : (meta.statuses ?? Object.keys(statusLabels))
  const hasActiveFilters = Object.values(filters).some(Boolean)
  const hasAnyTasks = pagination.total > 0

  useEffect(() => {
    setCurrentPage((page) => Math.min(page, Math.max(1, pagination.last_page)))
  }, [pagination.last_page])

  return (
    <>
      <section className="admin-summary-grid housekeeping-summary-grid" aria-label="Cleaning summary">
        {[
          ['Pending Tasks', summary?.pending_tasks ?? 0],
          ['In Progress', summary?.in_progress ?? 0],
          ['Completed Today', summary?.completed_today ?? 0],
          ['Needs Cleaning', summary?.needs_cleaning ?? 0],
          ['Maintenance', summary?.maintenance ?? 0],
        ].map(([label, value]) => (
          <article className="admin-summary-card" key={label}>
            <span>{label}</span>
            <strong>{isLoading ? '...' : value}</strong>
          </article>
        ))}
      </section>

      <ToastMessage message={pageMessage} />
      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}

      <section className="admin-management-grid housekeeping-management-grid admin-housekeeping-management-grid">
        <div className="admin-table-panel admin-housekeeping-table-panel">
          <div className="admin-table-heading">
            <h2>Cleaning Tasks ({pagination.total.toLocaleString()})</h2>
            <div className="admin-table-heading-actions">
              {hasActiveFilters ? (
                <button type="button" onClick={clearFilters}>
                  Clear filters
                </button>
              ) : null}
              <button className="primary-button" type="button" onClick={startCreate}>
                + Create Cleaning Task
              </button>
            </div>
          </div>
          <div className="admin-filter-bar housekeeping-filter-bar">
            <label>
              <span>Search</span>
              <input name="search" value={filters.search} onChange={updateFilter} placeholder="Accommodation, staff, remarks" />
            </label>
            <label>
              <span>Accommodation</span>
              <select name="accommodation_id" value={filters.accommodation_id} onChange={updateFilter}>
                <option value="">All</option>
                {accommodations.map((accommodation) => (
                  <option key={accommodation.id} value={accommodation.id}>{accommodation.name}</option>
                ))}
              </select>
            </label>
            <label>
              <span>Staff</span>
              <select name="employee_id" value={filters.employee_id} onChange={updateFilter}>
                <option value="">All</option>
                {(meta.staff ?? []).map((staff) => (
                  <option key={staff.id} value={staff.id}>{staff.name}</option>
                ))}
              </select>
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
              <span>Priority</span>
              <select name="priority" value={filters.priority} onChange={updateFilter}>
                <option value="">All</option>
                {(meta.priorities ?? Object.keys(priorityLabels)).map((priority) => (
                  <option key={priority} value={priority}>{priorityLabels[priority]}</option>
                ))}
              </select>
            </label>
          </div>

          <div className="admin-table-scroll admin-housekeeping-table-scroll">
            <table className="admin-data-table housekeeping-table">
              <thead>
                <tr>
                  <th>Accommodation</th>
                  <th>Task</th>
                  <th>Assigned Staff</th>
                  <th>Schedule</th>
                  <th>Priority</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {isLoading ? <tr><td colSpan="7" aria-label="Loading cleaning tasks..." /></tr> : null}
                {!isLoading && tasks.length === 0 ? (
                  <tr>
                    <td colSpan="7">
                      {hasActiveFilters && hasAnyTasks
                        ? 'No cleaning tasks match the current filters.'
                        : 'No cleaning tasks have been created yet.'}
                    </td>
                  </tr>
                ) : null}
                {!isLoading ? tasks.map((task) => (
                  <tr key={task.id}>
                    <td>
                      <strong>{task.accommodation?.name ?? 'Accommodation removed'}</strong>
                      <small>{formatHousekeepingStatus(task.accommodation?.housekeeping_status)}</small>
                    </td>
                    <td>
                      <strong>{taskTypeLabels[task.task_type]}</strong>
                      <small>{task.maintenance_notes ? 'Maintenance issue noted' : 'Standard task'}</small>
                    </td>
                    <td>{task.assigned_staff?.name ?? 'Unassigned'}</td>
                    <td>{formatDateTime(task.scheduled_at)}</td>
                    <td><span className={`admin-status-pill priority-${task.priority}`}>{priorityLabels[task.priority]}</span></td>
                    <td><span className={`admin-status-pill status-${task.status}`}>{statusLabels[task.status]}</span></td>
                    <td>
                      <div className="admin-row-actions">
                        <button type="button" onClick={() => selectTask(task)}>View</button>
                        {task.allowed_statuses?.includes('in_progress') ? (
                          <button type="button" onClick={() => quickStatus(task, 'in_progress')} disabled={pendingActionId === task.id}>Start</button>
                        ) : null}
                        {task.allowed_statuses?.includes('completed') ? (
                          <button type="button" onClick={() => quickStatus(task, 'completed')} disabled={pendingActionId === task.id}>Complete</button>
                        ) : null}
                        {task.allowed_statuses?.includes('cancelled') ? (
                          <button type="button" onClick={() => quickStatus(task, 'cancelled')} disabled={pendingActionId === task.id}>Cancel</button>
                        ) : null}
                      </div>
                    </td>
                  </tr>
                )) : null}
              </tbody>
            </table>
          </div>
          {!isLoading && pagination.total > 0 ? (
            <footer className="admin-housekeeping-pagination" aria-label="Cleaning task pagination">
              <span>
                Showing {((pagination.current_page - 1) * (pagination.per_page ?? rowsPerPage)) + 1}
                –{Math.min(pagination.current_page * (pagination.per_page ?? rowsPerPage), pagination.total)} of {pagination.total.toLocaleString()} cleaning tasks
              </span>
              <div>
                <button type="button" onClick={() => setCurrentPage((page) => Math.max(1, page - 1))} disabled={currentPage <= 1}>Previous</button>
                <div className="admin-housekeeping-page-numbers">
                  {Array.from({ length: Math.max(1, pagination.last_page) }, (_, index) => index + 1).map((page) => (
                    <button type="button" key={page} className={page === currentPage ? 'is-active' : ''} aria-current={page === currentPage ? 'page' : undefined} onClick={() => setCurrentPage(page)}>
                      {page}
                    </button>
                  ))}
                </div>
                <button type="button" onClick={() => setCurrentPage((page) => Math.min(pagination.last_page, page + 1))} disabled={currentPage >= pagination.last_page}>Next</button>
              </div>
            </footer>
          ) : null}
        </div>
      </section>

      {isDrawerOpen ? (
        <>
          <button
            className="admin-housekeeping-drawer-backdrop"
            type="button"
            aria-label="Close cleaning task panel"
            onClick={closeDrawer}
          />
          <aside className="admin-form-panel admin-housekeeping-drawer" aria-label="Cleaning task details">
            <div className="admin-housekeeping-drawer-header">
              <div>
                <p className="eyebrow">{selectedTask ? 'Task Details' : 'New Task'}</p>
                <h2>{selectedTask ? taskTypeLabels[selectedTask.task_type] : 'Create cleaning task'}</h2>
              </div>
              <button type="button" onClick={closeDrawer} aria-label="Close cleaning task panel">
                X
              </button>
            </div>

          <form className="admin-embedded-form" onSubmit={handleSubmit} noValidate>
            <label>
              <span>Accommodation</span>
              <select name="accommodation_id" value={formValues.accommodation_id} onChange={updateFormField}>
                <option value="">Select accommodation</option>
                {accommodations.map((accommodation) => (
                  <option key={accommodation.id} value={accommodation.id}>{accommodation.name}</option>
                ))}
              </select>
              {fieldErrors.accommodation_id ? <small role="alert">{fieldErrors.accommodation_id}</small> : null}
            </label>

            <label>
              <span>Assign Staff</span>
              <select name="employee_id" value={formValues.employee_id} onChange={updateFormField}>
                <option value="">Unassigned</option>
                {(meta.staff ?? []).map((staff) => (
                  <option key={staff.id} value={staff.id}>{staff.name}</option>
                ))}
              </select>
              {fieldErrors.employee_id ? <small role="alert">{fieldErrors.employee_id}</small> : null}
            </label>

            <div className="admin-form-row">
              <label>
                <span>Task Type</span>
                <select name="task_type" value={formValues.task_type} onChange={updateFormField}>
                  {Object.keys(taskTypeLabels).map((type) => <option key={type} value={type}>{taskTypeLabels[type]}</option>)}
                </select>
              </label>
              <label>
                <span>Priority</span>
                <select name="priority" value={formValues.priority} onChange={updateFormField}>
                  {Object.keys(priorityLabels).map((priority) => <option key={priority} value={priority}>{priorityLabels[priority]}</option>)}
                </select>
              </label>
            </div>

            <div className="admin-form-row">
              <label>
                <span>Status</span>
                <select name="status" value={formValues.status} onChange={updateFormField}>
                  {[...new Set(statusOptions)].map((status) => <option key={status} value={status}>{statusLabels[status]}</option>)}
                </select>
                {fieldErrors.status ? <small role="alert">{fieldErrors.status}</small> : null}
              </label>
              <label>
                <span>Scheduled Date / Time</span>
                <input name="scheduled_at" type="datetime-local" value={formValues.scheduled_at} onChange={updateFormField} />
              </label>
            </div>

            <div className="admin-form-row">
              <label>
                <span>Started At</span>
                <input name="started_at" type="datetime-local" value={formValues.started_at} onChange={updateFormField} />
              </label>
              <label>
                <span>Completed At</span>
                <input name="completed_at" type="datetime-local" value={formValues.completed_at} onChange={updateFormField} />
                {fieldErrors.completed_at ? <small role="alert">{fieldErrors.completed_at}</small> : null}
              </label>
            </div>

            <label>
              <span>Remarks</span>
              <textarea name="remarks" rows="3" value={formValues.remarks} onChange={updateFormField} />
              {fieldErrors.remarks ? <small role="alert">{fieldErrors.remarks}</small> : null}
            </label>
            <label>
              <span>Maintenance Notes</span>
              <textarea name="maintenance_notes" rows="3" value={formValues.maintenance_notes} onChange={updateFormField} />
            </label>

            <div className="admin-form-actions">
              <button className="primary-button" type="submit" disabled={isSaving || selectedTask?.status === 'completed' || selectedTask?.status === 'cancelled'}>
                {isSaving ? 'Saving...' : selectedTask ? 'Save Changes' : 'Create Task'}
              </button>
              <button className="outline-button" type="button" onClick={startCreate}>Clear</button>
            </div>
          </form>

          {selectedTask ? (
            <section className="admin-password-reset-panel">
              <div>
                <p className="eyebrow">Task History</p>
                <h2>Activity trail</h2>
              </div>
              <div className="inventory-history-list">
                {history.length > 0 ? history.map((entry) => (
                  <article key={entry.id}>
                    <strong>{entry.action.replaceAll('_', ' ')}</strong>
                    <span>{formatDateTime(entry.created_at)} - {entry.performed_by?.name ?? 'System'}</span>
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

function toDateTimeInput(value) {
  if (!value) return ''

  const date = new Date(value)
  const timezoneOffset = date.getTimezoneOffset() * 60000

  return new Date(date.getTime() - timezoneOffset).toISOString().slice(0, 16)
}

function formatHousekeepingStatus(status) {
  const labels = {
    needs_cleaning: 'Needs Cleaning',
    in_progress: 'Cleaning',
    ready: 'Ready',
    completed: 'Ready',
    pending: 'Pending',
    assigned: 'Assigned',
    cancelled: 'Cancelled',
  }

  if (!status) return 'Status not set'

  return labels[status] ?? status.replaceAll('_', ' ')
}

export default AdminHousekeeping
