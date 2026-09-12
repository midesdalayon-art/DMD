import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import HousekeepingLayout from '../components/HousekeepingLayout'
import { ToastMessage } from '../context/ToastContext'
import { getHousekeepingTasks, updateHousekeepingTaskStatus } from '../lib/api'
import { canCompleteTask, canStartTask, formatDateTime, priorityLabel, statusLabel, taskLabel } from '../lib/housekeepingDisplay'
import PaginationControls from '../components/PaginationControls'

const defaultFilters = {
  search: '',
  status: '',
  priority: '',
  task_type: '',
}

function HousekeepingTasks() {
  const [tasks, setTasks] = useState([])
  const [meta, setMeta] = useState({ statuses: [], priorities: [], task_types: [] })
  const [filters, setFilters] = useState(defaultFilters)
  const [isLoading, setIsLoading] = useState(true)
  const [pageError, setPageError] = useState('')
  const [pageMessage, setPageMessage] = useState('')
  const [activeTaskId, setActiveTaskId] = useState(null)
  const [currentPage, setCurrentPage] = useState(1)
  const pageSize = 10

  const hasFilters = Object.values(filters).some(Boolean)
  const filterParams = useMemo(
    () => Object.fromEntries(Object.entries(filters).map(([key, value]) => [key, value || undefined])),
    [filters],
  )

  const loadTasks = useCallback(async () => {
    setIsLoading(true)
    setPageError('')

    try {
      const result = await getHousekeepingTasks(filterParams)
      setTasks(result.tasks)
      setCurrentPage(1)
      setMeta(result.meta)
    } catch {
      setPageError('Unable to load assigned tasks.')
    } finally {
      setIsLoading(false)
    }
  }, [filterParams])

  useEffect(() => {
    const timeoutId = window.setTimeout(loadTasks, 200)

    return () => window.clearTimeout(timeoutId)
  }, [loadTasks])

  function updateFilter(event) {
    const { name, value } = event.target
    setFilters((current) => ({ ...current, [name]: value }))
  }

  const pageCount = Math.max(1, Math.ceil(tasks.length / pageSize))
  const visibleTasks = tasks.slice((currentPage - 1) * pageSize, currentPage * pageSize)

  async function changeStatus(task, status) {
    setActiveTaskId(task.id)
    setPageError('')
    setPageMessage('')

    try {
      const result = await updateHousekeepingTaskStatus(task.id, { status })
      setTasks((current) => current.map((item) => (item.id === result.task.id ? result.task : item)))
      setPageMessage(result.message)
    } catch (error) {
      const firstError = Object.values(error?.response?.data?.errors ?? {})[0]?.[0]
      setPageError(firstError ?? 'Unable to update this task.')
    } finally {
      setActiveTaskId(null)
    }
  }

  return (
    <HousekeepingLayout eyebrow="Housekeeping" title="My Tasks">
      <ToastMessage message={pageMessage} />
      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}

      <section className="housekeeping-panel">
        <div className="housekeeping-section-header">
          <div>
            <h2>Assigned Tasks ({tasks.length})</h2>
            <p>Only work assigned to your account is shown here.</p>
          </div>
          {hasFilters ? (
            <button type="button" onClick={() => setFilters(defaultFilters)}>
              Clear filters
            </button>
          ) : null}
        </div>

        <div className="housekeeping-filter-bar">
          <label>
            <span>Search</span>
            <input name="search" value={filters.search} onChange={updateFilter} placeholder="Accommodation or notes" />
          </label>
          <label>
            <span>Status</span>
            <select name="status" value={filters.status} onChange={updateFilter}>
              <option value="">All</option>
              {meta.statuses.map((status) => <option key={status} value={status}>{statusLabel(status)}</option>)}
            </select>
          </label>
          <label>
            <span>Priority</span>
            <select name="priority" value={filters.priority} onChange={updateFilter}>
              <option value="">All</option>
              {meta.priorities.map((priority) => <option key={priority} value={priority}>{priorityLabel(priority)}</option>)}
            </select>
          </label>
          <label>
            <span>Task Type</span>
            <select name="task_type" value={filters.task_type} onChange={updateFilter}>
              <option value="">All</option>
              {meta.task_types.map((type) => <option key={type} value={type}>{taskLabel(type)}</option>)}
            </select>
          </label>
        </div>

        <div className="housekeeping-table-wrap">
          <table className="admin-data-table housekeeping-staff-table">
            <thead>
              <tr>
                <th>Accommodation</th>
                <th>Task Type</th>
                <th>Schedule</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {isLoading ? <tr><td colSpan="6" aria-label="Loading tasks..." /></tr> : null}
              {!isLoading && tasks.length === 0 ? (
                <tr>
                  <td colSpan="6">{hasFilters ? 'No tasks match your current filters.' : 'No assigned tasks yet.'}</td>
                </tr>
              ) : null}
              {!isLoading ? visibleTasks.map((task) => (
                <tr key={task.id}>
                  <td><strong>{task.accommodation?.name ?? 'Accommodation'}</strong><small>{task.accommodation?.type_label}</small></td>
                  <td>{taskLabel(task.task_type)}</td>
                  <td>{formatDateTime(task.scheduled_at)}</td>
                  <td><span className={`housekeeping-badge priority-${task.priority}`}>{priorityLabel(task.priority)}</span></td>
                  <td><span className={`housekeeping-badge status-${task.status}`}>{statusLabel(task.status)}</span></td>
                  <td>
                    <div className="housekeeping-row-actions">
                      <Link to={`/housekeeping/tasks/${task.id}`}>View</Link>
                      {canStartTask(task) ? <button type="button" onClick={() => changeStatus(task, 'in_progress')} disabled={activeTaskId === task.id}>Start</button> : null}
                      {canCompleteTask(task) ? <button type="button" onClick={() => changeStatus(task, 'completed')} disabled={activeTaskId === task.id}>Complete</button> : null}
                    </div>
                  </td>
                </tr>
              )) : null}
            </tbody>
          </table>
        </div>
        {!isLoading && tasks.length > 0 ? <PaginationControls page={currentPage} pageCount={pageCount} total={tasks.length} pageSize={pageSize} label="assigned tasks" onPageChange={setCurrentPage} /> : null}
      </section>
    </HousekeepingLayout>
  )
}

export default HousekeepingTasks
