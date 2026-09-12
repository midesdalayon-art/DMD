import { useCallback, useEffect, useState } from 'react'
import { ArrowRight, CheckCircle2, Clock, ClipboardList, PlayCircle } from 'lucide-react'
import { Link } from 'react-router-dom'
import HousekeepingLayout from '../components/HousekeepingLayout'
import { ToastMessage } from '../context/ToastContext'
import { getHousekeepingDashboardSummary, updateHousekeepingTaskStatus } from '../lib/api'
import { canCompleteTask, canStartTask, formatDateTime, priorityLabel, statusLabel, taskLabel } from '../lib/housekeepingDisplay'

function HousekeepingDashboard() {
  const [summary, setSummary] = useState({})
  const [tasks, setTasks] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [pageError, setPageError] = useState('')
  const [pageMessage, setPageMessage] = useState('')
  const [activeTaskId, setActiveTaskId] = useState(null)

  const loadDashboard = useCallback(async () => {
    setIsLoading(true)
    setPageError('')

    try {
      const result = await getHousekeepingDashboardSummary()
      setSummary(result.summary ?? {})
      setTasks(result.today_tasks ?? [])
    } catch {
      setPageError('Unable to load housekeeping dashboard.')
    } finally {
      setIsLoading(false)
    }
  }, [])

  useEffect(() => {
    loadDashboard()
  }, [loadDashboard])

  async function changeStatus(task, status) {
    setActiveTaskId(task.id)
    setPageError('')
    setPageMessage('')

    try {
      const result = await updateHousekeepingTaskStatus(task.id, { status })
      setTasks((current) => current.map((item) => (item.id === result.task.id ? result.task : item)))
      setPageMessage(result.message)
      await loadDashboard()
    } catch (error) {
      const firstError = Object.values(error?.response?.data?.errors ?? {})[0]?.[0]
      setPageError(firstError ?? 'Unable to update this task.')
    } finally {
      setActiveTaskId(null)
    }
  }

  const cards = [
    ['Tasks Today', summary.tasks_today, ClipboardList],
    ['Pending', summary.pending, Clock],
    ['In Progress', summary.in_progress, PlayCircle],
    ['Completed Today', summary.completed_today, CheckCircle2],
  ]

  return (
    <HousekeepingLayout eyebrow="Housekeeping" title="Dashboard">
      <section className="housekeeping-kpi-grid" aria-label="Housekeeping summary">
        {cards.map(([label, value, Icon]) => (
          <article className="housekeeping-kpi-card" key={label}>
            <Icon size={19} aria-hidden="true" />
            <div>
              <strong>{value ?? 0}</strong>
              <span>{label}</span>
            </div>
          </article>
        ))}
      </section>

      <ToastMessage message={pageMessage} />
      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}

      <section className="housekeeping-panel">
        <div className="housekeeping-section-header">
          <div>
            <h2>Today&apos;s Tasks</h2>
            <p>Assigned cleaning, preparation, inspection, and maintenance work.</p>
          </div>
          <Link to="/housekeeping/tasks">View all <ArrowRight size={15} aria-hidden="true" /></Link>
        </div>

        {isLoading ? <div className="housekeeping-empty" role="status" aria-label="Loading assigned tasks..." /> : null}
        {!isLoading && tasks.length === 0 ? (
          <div className="housekeeping-empty">No assigned tasks for today.</div>
        ) : null}
        {!isLoading && tasks.length > 0 ? (
          <div className="housekeeping-task-grid">
            {tasks.map((task) => (
              <article className="housekeeping-task-card" key={task.id}>
                <div className="housekeeping-task-card-top">
                  <div>
                    <h3>{task.accommodation?.name ?? 'Accommodation'}</h3>
                    <p>{taskLabel(task.task_type)} · {formatDateTime(task.scheduled_at)}</p>
                  </div>
                  <span className={`housekeeping-badge status-${task.status}`}>{statusLabel(task.status)}</span>
                </div>
                <div className="housekeeping-task-meta">
                  <span className={`housekeeping-badge priority-${task.priority}`}>{priorityLabel(task.priority)}</span>
                  {task.maintenance_notes ? <span className="housekeeping-badge tone-amber">Issue noted</span> : null}
                </div>
                {task.remarks ? <p className="housekeeping-task-note">{task.remarks}</p> : null}
                <div className="housekeeping-task-actions">
                  <Link to={`/housekeeping/tasks/${task.id}`}>Details</Link>
                  {canStartTask(task) ? (
                    <button
                      type="button"
                      onClick={() => changeStatus(task, 'in_progress')}
                      disabled={activeTaskId === task.id}
                    >
                      {activeTaskId === task.id ? 'Starting...' : 'Start'}
                    </button>
                  ) : null}
                  {canCompleteTask(task) ? (
                    <button
                      className="is-primary"
                      type="button"
                      onClick={() => changeStatus(task, 'completed')}
                      disabled={activeTaskId === task.id}
                    >
                      {activeTaskId === task.id ? 'Completing...' : 'Complete'}
                    </button>
                  ) : null}
                </div>
              </article>
            ))}
          </div>
        ) : null}
      </section>
    </HousekeepingLayout>
  )
}

export default HousekeepingDashboard
