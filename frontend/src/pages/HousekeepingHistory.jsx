import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import HousekeepingLayout from '../components/HousekeepingLayout'
import { getHousekeepingHistory } from '../lib/api'
import { formatDate, formatDateTime, priorityLabel, statusLabel, taskLabel } from '../lib/housekeepingDisplay'

const defaultFilters = {
  search: '',
  status: '',
  priority: '',
  task_type: '',
}

function HousekeepingHistory() {
  const [tasks, setTasks] = useState([])
  const [meta, setMeta] = useState({ statuses: [], priorities: [], task_types: [] })
  const [filters, setFilters] = useState(defaultFilters)
  const [isLoading, setIsLoading] = useState(true)
  const [pageError, setPageError] = useState('')

  const hasFilters = Object.values(filters).some(Boolean)
  const historyStatuses = meta.statuses.filter((status) => ['completed', 'cancelled'].includes(status))
  const filterParams = useMemo(
    () => Object.fromEntries(Object.entries(filters).map(([key, value]) => [key, value || undefined])),
    [filters],
  )

  const loadHistory = useCallback(async () => {
    setIsLoading(true)
    setPageError('')

    try {
      const result = await getHousekeepingHistory(filterParams)
      setTasks(result.tasks)
      setMeta(result.meta)
    } catch {
      setPageError('Unable to load task history.')
    } finally {
      setIsLoading(false)
    }
  }, [filterParams])

  useEffect(() => {
    const timeoutId = window.setTimeout(loadHistory, 200)

    return () => window.clearTimeout(timeoutId)
  }, [loadHistory])

  function updateFilter(event) {
    const { name, value } = event.target
    setFilters((current) => ({ ...current, [name]: value }))
  }

  return (
    <HousekeepingLayout eyebrow="Housekeeping" title="History">
      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}

      <section className="housekeeping-panel">
        <div className="housekeeping-section-header">
          <div>
            <h2>Completed & Cancelled Tasks</h2>
            <p>Past work remains visible in your task history.</p>
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
              {historyStatuses.map((status) => <option key={status} value={status}>{statusLabel(status)}</option>)}
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

        {isLoading ? <div className="housekeeping-empty" role="status" aria-label="Loading history..." /> : null}
        {!isLoading && tasks.length === 0 ? (
          <div className="housekeeping-empty">
            {hasFilters ? 'No history items match your current filters.' : 'No completed or cancelled tasks yet.'}
          </div>
        ) : null}
        {!isLoading && tasks.length > 0 ? (
          <div className="housekeeping-history-cards">
            {tasks.map((task) => (
              <article className="housekeeping-task-card" key={task.id}>
                <div className="housekeeping-task-card-top">
                  <div>
                    <h3>{task.accommodation?.name ?? 'Accommodation'}</h3>
                    <p>{taskLabel(task.task_type)} · {formatDate(task.completed_at ?? task.updated_at)}</p>
                  </div>
                  <span className={`housekeeping-badge status-${task.status}`}>{statusLabel(task.status)}</span>
                </div>
                <div className="housekeeping-task-meta">
                  <span className={`housekeeping-badge priority-${task.priority}`}>{priorityLabel(task.priority)}</span>
                  <span>{formatDateTime(task.scheduled_at)}</span>
                </div>
                {task.maintenance_notes ? <p className="housekeeping-task-note">{task.maintenance_notes}</p> : null}
                <div className="housekeeping-task-actions">
                  <Link to={`/housekeeping/tasks/${task.id}`}>View Details</Link>
                </div>
              </article>
            ))}
          </div>
        ) : null}
      </section>
    </HousekeepingLayout>
  )
}

export default HousekeepingHistory
