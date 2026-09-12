import { useCallback, useEffect, useMemo, useState } from 'react'
import ManagerLayout from '../components/ManagerLayout'
import { ToastMessage } from '../context/ToastContext'
import {
  createManagerHousekeepingTask,
  getManagerAccommodations,
  getManagerHousekeepingTasks,
  updateManagerHousekeepingTaskStatus,
} from '../lib/api'
import { formatDateTime, priorityLabel, statusLabel, taskLabel } from '../lib/housekeepingDisplay'

const emptyForm = {
  accommodation_id: '',
  employee_id: '',
  task_type: 'cleaning',
  priority: 'normal',
  status: 'pending',
  scheduled_at: '',
  remarks: '',
  maintenance_notes: '',
}

const readinessPriority = {
  needs_cleaning: 0,
  in_progress: 1,
  maintenance: 2,
  ready: 3,
}

function ManagerHousekeeping() {
  const [tasks, setTasks] = useState([])
  const [summary, setSummary] = useState({})
  const [meta, setMeta] = useState({ task_types: [], priorities: [], statuses: [], staff: [] })
  const [accommodations, setAccommodations] = useState([])
  const [filters, setFilters] = useState({ search: '', accommodation_id: '', employee_id: '', status: '', priority: '', task_type: '' })
  const [form, setForm] = useState(emptyForm)
  const [fieldErrors, setFieldErrors] = useState({})
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)
  const [isCreateOpen, setIsCreateOpen] = useState(false)
  const [isAccommodationLocked, setIsAccommodationLocked] = useState(false)
  const [readinessFilter, setReadinessFilter] = useState('all')
  const [readinessPage, setReadinessPage] = useState(1)
  const [taskPage, setTaskPage] = useState(1)
  const [pageMessage, setPageMessage] = useState('')
  const [pageError, setPageError] = useState('')

  const filterParams = useMemo(
    () => Object.fromEntries(Object.entries(filters).map(([key, value]) => [key, value || undefined])),
    [filters],
  )

  const readinessRows = useMemo(() => accommodations.map((accommodation) => ({
    ...accommodation,
    cleaningStatus: accommodation.housekeeping_status === 'ready'
      ? 'ready'
      : accommodation.housekeeping_status === 'cleaning'
        ? 'in_progress'
        : accommodation.housekeeping_status === 'maintenance'
          ? 'maintenance'
          : 'needs_cleaning',
  })).filter((accommodation) => readinessFilter === 'all'
    || (readinessFilter === 'maintenance'
      ? accommodation.operational_status === 'maintenance' || accommodation.cleaningStatus === 'maintenance'
      : accommodation.cleaningStatus === readinessFilter))
    .sort((left, right) => (readinessPriority[left.cleaningStatus] ?? 99) - (readinessPriority[right.cleaningStatus] ?? 99)), [accommodations, readinessFilter])

  const readinessPageSize = 5
  const readinessPageCount = Math.max(1, Math.ceil(readinessRows.length / readinessPageSize))
  const visibleReadinessRows = readinessRows.slice((readinessPage - 1) * readinessPageSize, readinessPage * readinessPageSize)
  const taskPageSize = 5
  const taskPageCount = Math.max(1, Math.ceil(tasks.length / taskPageSize))
  const visibleTasks = tasks.slice((taskPage - 1) * taskPageSize, taskPage * taskPageSize)

  useEffect(() => {
    setTaskPage((current) => Math.min(current, taskPageCount))
  }, [taskPageCount])

  useEffect(() => {
    setReadinessPage((current) => Math.min(current, readinessPageCount))
  }, [readinessPageCount])

  const loadTasks = useCallback(async () => {
    setIsLoading(true)
    setPageError('')

    try {
      const [taskResult, accommodationResult] = await Promise.allSettled([
        getManagerHousekeepingTasks(filterParams),
        getManagerAccommodations(),
      ])
      if (taskResult.status === 'rejected') throw taskResult.reason

      const taskData = taskResult.value
      setTasks(taskData.tasks)
      setSummary(taskData.summary)
      setMeta(taskData.meta)
      setAccommodations(accommodationResult.status === 'fulfilled' ? accommodationResult.value.accommodations : [])
    } catch {
      setPageError('Unable to load cleaning tasks.')
    } finally {
      setIsLoading(false)
    }
  }, [filterParams])

  useEffect(() => {
    const timeoutId = window.setTimeout(loadTasks, 250)

    return () => window.clearTimeout(timeoutId)
  }, [loadTasks])

  function updateFilter(event) {
    const { name, value } = event.target
    setFilters((current) => ({ ...current, [name]: value }))
    setTaskPage(1)
  }

  function updateReadinessFilter(event) {
    setReadinessFilter(event.target.value)
    setReadinessPage(1)
  }

  function updateForm(event) {
    const { name, value } = event.target
    setForm((current) => ({ ...current, [name]: value }))
    setFieldErrors((current) => ({ ...current, [name]: '' }))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setIsSaving(true)
    setFieldErrors({})
    setPageError('')
    setPageMessage('')

    try {
      const payload = {
        ...form,
        accommodation_id: Number(form.accommodation_id),
        employee_id: form.employee_id ? Number(form.employee_id) : null,
        scheduled_at: form.scheduled_at || null,
      }
      const result = await createManagerHousekeepingTask(payload)
      await loadTasks()
      setForm(emptyForm)
      setIsCreateOpen(false)
      setPageMessage(result.message)
    } catch (error) {
      const errors = error?.response?.data?.errors
      const firstError = Object.values(errors ?? {})[0]?.[0]

      if (errors) {
        setFieldErrors(Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, value[0]])))
      }
      setPageError(firstError ?? error?.response?.data?.message ?? 'Unable to save cleaning task.')
    } finally {
      setIsSaving(false)
    }
  }

  async function changeStatus(task, status) {
    setPageError('')
    setPageMessage('')

    try {
      const result = await updateManagerHousekeepingTaskStatus(task.id, { status })
      await loadTasks()
      setPageMessage(result.message)
    } catch (error) {
      const firstError = Object.values(error?.response?.data?.errors ?? {})[0]?.[0]
      setPageError(firstError ?? 'Unable to update cleaning status.')
    }
  }

  function openCreateTask(accommodationId = '') {
    setForm({ ...emptyForm, accommodation_id: accommodationId ? String(accommodationId) : '' })
    setIsAccommodationLocked(Boolean(accommodationId))
    setFieldErrors({})
    setIsCreateOpen(true)
  }

  function focusTask(task, accommodationId) {
    setFilters((current) => ({ ...current, accommodation_id: String(accommodationId) }))
    window.requestAnimationFrame(() => document.getElementById('manager-cleaning-tasks')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
    if (task?.id) setPageMessage('The related cleaning task is selected below.')
  }

  const cards = [
    ['Needs Cleaning', summary.needs_cleaning],
    ['In Progress', summary.in_progress],
    ['Completed Today', summary.completed_today],
    ['Maintenance', summary.maintenance],
  ]

  return (
    <ManagerLayout eyebrow="Cleaning" title="Cleaning Management">
      <section className="admin-summary-grid housekeeping-summary-grid manager-housekeeping-summary-grid" aria-label="Cleaning summary">
        {cards.map(([label, value]) => <article className="admin-summary-card" key={label}><span>{value ?? 0}</span><strong>{label}</strong></article>)}
      </section>
      <ToastMessage message={pageMessage} />
      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}
      <div className="manager-housekeeping-workspace">
      <section className="manager-readiness-section" aria-labelledby="manager-readiness-title">
        <div className="manager-readiness-header">
          <div>
            <p className="eyebrow">Operational view</p>
            <h2 id="manager-readiness-title">Room Readiness</h2>
          </div>
          <label className="manager-readiness-filter">
            <span className="sr-only">Filter room readiness</span>
            <select value={readinessFilter} onChange={updateReadinessFilter}>
              <option value="all">All</option>
              <option value="ready">Ready</option>
              <option value="needs_cleaning">Needs Cleaning</option>
              <option value="in_progress">In Progress</option>
              <option value="maintenance">Maintenance</option>
            </select>
          </label>
        </div>
        <div className="manager-readiness-table-wrap">
          <table className="manager-readiness-table">
            <thead>
              <tr>
                <th>Unit</th>
                <th>Type</th>
                <th>Status</th>
                <th>Cleaning</th>
                <th>Staff</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
          {visibleReadinessRows.map((accommodation) => {
            const activeTask = accommodation.active_housekeeping_task
            const assignedStaff = activeTask?.assigned_staff_name || activeTask?.assigned_staff?.name
            const operationalStatus = accommodation.operational_status || accommodation.status
            const cleaningLabel = accommodation.cleaningStatus === 'in_progress'
              ? 'In Progress'
              : statusLabel(accommodation.cleaningStatus)

            return (
              <tr key={accommodation.id}>
                <td>
                  <div className="manager-readiness-accommodation">
                  <strong>{accommodation.name}</strong>
                  </div>
                </td>
                <td className="manager-readiness-type">{accommodation.type_label || '—'}</td>
                <td><span className={`admin-status-pill readiness-operational-${operationalStatus}`}>
                  {operationalStatus === 'occupied'
                    ? 'Occupied'
                    : operationalStatus === 'maintenance'
                      ? 'Maintenance'
                      : operationalStatus === 'available'
                        ? 'Available'
                        : 'Unavailable'}
                </span></td>
                <td><span className={`admin-status-pill readiness-cleaning-${accommodation.cleaningStatus}`}>
                  {cleaningLabel}
                </span></td>
                <td>
                <span className="manager-readiness-staff">{assignedStaff || '—'}</span>
                </td>
                <td className="manager-readiness-action">
                  {accommodation.cleaningStatus === 'needs_cleaning' && !activeTask ? (
                    <button className="table-action-button" type="button" onClick={() => openCreateTask(accommodation.id)}>
                      Assign
                    </button>
                  ) : activeTask && (activeTask.allowed_statuses ?? []).includes('completed') ? (
                    <button className="table-action-button" type="button" onClick={() => changeStatus(activeTask, 'completed')}>
                      Mark Ready
                    </button>
                  ) : activeTask ? (
                    <button className="table-action-button" type="button" onClick={() => focusTask(activeTask, accommodation.id)}>
                      View
                    </button>
                  ) : <span className="manager-readiness-dash">—</span>}
                </td>
              </tr>
            )
          })}
          {!isLoading && readinessRows.length === 0 ? <tr><td className="manager-readiness-empty" colSpan="6">No accommodations match this readiness filter.</td></tr> : null}
            </tbody>
          </table>
        </div>
        {!isLoading && readinessRows.length > 0 ? <PanelPagination label="accommodations" page={readinessPage} pageCount={readinessPageCount} total={readinessRows.length} pageSize={readinessPageSize} onPageChange={setReadinessPage} /> : null}
      </section>
      <section className="manager-cleaning-tasks-section" aria-labelledby="manager-cleaning-tasks-title" id="manager-cleaning-tasks">
        <div className="manager-cleaning-tasks-heading">
          <div>
            <p className="eyebrow">Workflow</p>
            <h2 id="manager-cleaning-tasks-title">Cleaning Tasks</h2>
          </div>
          <button className="primary-button" type="button" onClick={() => openCreateTask()}>
            + Create Task
          </button>
        </div>
        <div className="manager-housekeeping-content">
          <div className="admin-filter-bar housekeeping-filter-bar manager-housekeeping-filter-bar">
            <label><span>Search</span><input name="search" value={filters.search} onChange={updateFilter} /></label>
            <label><span>Accommodation</span><select name="accommodation_id" value={filters.accommodation_id} onChange={updateFilter}><option value="">All</option>{accommodations.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
            <label><span>Staff</span><select name="employee_id" value={filters.employee_id} onChange={updateFilter}><option value="">All</option>{meta.staff.map((staff) => <option key={staff.id} value={staff.id}>{staff.name}</option>)}</select></label>
            <label><span>Status</span><select name="status" value={filters.status} onChange={updateFilter}><option value="">All</option>{meta.statuses.map((status) => <option key={status} value={status}>{statusLabel(status)}</option>)}</select></label>
            <label><span>Priority</span><select name="priority" value={filters.priority} onChange={updateFilter}><option value="">All</option>{meta.priorities.map((priority) => <option key={priority} value={priority}>{priorityLabel(priority)}</option>)}</select></label>
          </div>
          {!isLoading && tasks.length === 0 ? (
            <div className="manager-cleaning-empty-state">
              <strong>No cleaning tasks match the filters.</strong>
              <span>New assignments will appear here once created.</span>
            </div>
          ) : <div className="admin-table-scroll">
            <table className="admin-data-table housekeeping-table manager-housekeeping-table">
              <thead><tr><th>Accommodation</th><th>Task</th><th>Assigned Staff</th><th>Schedule</th><th>Priority</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody>
                {isLoading ? <tr><td colSpan="7" aria-label="Loading tasks..." /></tr> : null}
                {!isLoading ? visibleTasks.map((task) => (
                  <tr key={task.id}>
                    <td>{task.accommodation?.name ?? 'Accommodation'}</td>
                    <td>{taskLabel(task.task_type)}<small>{task.remarks}</small></td>
                    <td>{task.assigned_staff?.name ?? 'Unassigned'}</td>
                    <td>{formatDateTime(task.scheduled_at)}</td>
                    <td><span className={`admin-status-pill priority-${task.priority}`}>{priorityLabel(task.priority)}</span></td>
                    <td><span className={`admin-status-pill status-${task.status}`}>{statusLabel(task.status)}</span></td>
                    <td>
                      <select value={task.status} onChange={(event) => changeStatus(task, event.target.value)} disabled={(task.allowed_statuses ?? []).length === 0}>
                        {[task.status, ...(task.allowed_statuses ?? [])].map((status) => <option key={status} value={status}>{statusLabel(status)}</option>)}
                      </select>
                    </td>
                  </tr>
                )) : null}
              </tbody>
            </table>
          </div>}
          {!isLoading && tasks.length > 0 ? <PanelPagination label="tasks" page={taskPage} pageCount={taskPageCount} total={tasks.length} pageSize={taskPageSize} onPageChange={setTaskPage} /> : null}
        </div>
      </section>
      </div>

      {isCreateOpen ? (
        <>
          <button
            className="manager-housekeeping-drawer-backdrop"
            type="button"
            aria-label="Close create task form"
            onClick={() => setIsCreateOpen(false)}
          />
          <aside className="admin-form-panel manager-housekeeping-drawer" aria-label="Create cleaning task">
          <form className="admin-embedded-form" onSubmit={handleSubmit} noValidate>
            <div className="manager-housekeeping-drawer-header">
              <div><p className="eyebrow">Create Task</p><h2>Assign staff</h2></div>
              <button type="button" onClick={() => setIsCreateOpen(false)} aria-label="Close create task form">
                X
              </button>
            </div>
            <label className={isAccommodationLocked ? 'manager-housekeeping-locked-field' : ''}><span>Accommodation</span>{isAccommodationLocked ? <div className="manager-housekeeping-readonly-value" aria-readonly="true">{accommodations.find((item) => String(item.id) === String(form.accommodation_id))?.name ?? 'Selected accommodation'}</div> : <select name="accommodation_id" value={form.accommodation_id} onChange={updateForm} required><option value="">Select accommodation</option>{accommodations.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>}{isAccommodationLocked ? <small className="manager-housekeeping-field-note">Fixed from Room Readiness</small> : null}{fieldErrors.accommodation_id ? <small role="alert">{fieldErrors.accommodation_id}</small> : null}</label>
            <label><span>Assign Staff</span><select name="employee_id" value={form.employee_id} onChange={updateForm}><option value="">Unassigned</option>{meta.staff.map((staff) => <option key={staff.id} value={staff.id}>{staff.name}</option>)}</select>{fieldErrors.employee_id ? <small role="alert">{fieldErrors.employee_id}</small> : null}</label>
            <div className="admin-form-row">
              <label><span>Task Type</span><select name="task_type" value={form.task_type} onChange={updateForm}>{meta.task_types.map((type) => <option key={type} value={type}>{type.replaceAll('_', ' ')}</option>)}</select></label>
              <label><span>Priority</span><select name="priority" value={form.priority} onChange={updateForm}>{meta.priorities.map((priority) => <option key={priority} value={priority}>{priority}</option>)}</select></label>
            </div>
            <label><span>Schedule</span><input name="scheduled_at" type="datetime-local" value={form.scheduled_at} onChange={updateForm} />{fieldErrors.scheduled_at ? <small role="alert">{fieldErrors.scheduled_at}</small> : null}</label>
            {fieldErrors.status ? <small role="alert">{fieldErrors.status}</small> : null}
            <label><span>Remarks</span><textarea name="remarks" rows="3" value={form.remarks} onChange={updateForm} /></label>
            <label><span>Maintenance Notes</span><textarea name="maintenance_notes" rows="3" value={form.maintenance_notes} onChange={updateForm} /></label>
            <button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? 'Saving...' : 'Create Task'}</button>
          </form>
        </aside>
        </>
      ) : null}
    </ManagerLayout>
  )
}

export default ManagerHousekeeping

function PanelPagination({ label, page, pageCount, total, pageSize, onPageChange }) {
  const from = total === 0 ? 0 : ((page - 1) * pageSize) + 1
  const to = Math.min(page * pageSize, total)

  return (
    <footer className="manager-panel-pagination" aria-label={`${label} pagination`}>
      <span>Showing {from}–{to} of {total} {label}</span>
      <div>
        <button type="button" onClick={() => onPageChange((current) => Math.max(1, current - 1))} disabled={page === 1}>Previous</button>
        <strong>Page {page} of {pageCount}</strong>
        <button type="button" onClick={() => onPageChange((current) => Math.min(pageCount, current + 1))} disabled={page === pageCount}>Next</button>
      </div>
    </footer>
  )
}
