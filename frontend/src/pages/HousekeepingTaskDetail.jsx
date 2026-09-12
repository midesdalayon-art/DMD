import { useCallback, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import HousekeepingLayout from '../components/HousekeepingLayout'
import { ToastMessage } from '../context/ToastContext'
import { getHousekeepingTask, updateHousekeepingTaskNotes, updateHousekeepingTaskStatus } from '../lib/api'
import { canCompleteTask, canStartTask, formatDateTime, priorityLabel, statusLabel, taskLabel } from '../lib/housekeepingDisplay'

function HousekeepingTaskDetail() {
  const { id } = useParams()
  const [task, setTask] = useState(null)
  const [history, setHistory] = useState([])
  const [notes, setNotes] = useState({ remarks: '', maintenance_notes: '' })
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)
  const [pageError, setPageError] = useState('')
  const [pageMessage, setPageMessage] = useState('')

  const loadTask = useCallback(async () => {
    setIsLoading(true)
    setPageError('')

    try {
      const result = await getHousekeepingTask(id)
      setTask(result.task)
      setHistory(result.history ?? [])
      setNotes({
        remarks: result.task.remarks ?? '',
        maintenance_notes: result.task.maintenance_notes ?? '',
      })
    } catch (error) {
      setPageError(error?.response?.status === 403 ? 'You cannot view this task.' : 'Unable to load this task.')
    } finally {
      setIsLoading(false)
    }
  }, [id])

  useEffect(() => {
    loadTask()
  }, [loadTask])

  function updateNotes(event) {
    const { name, value } = event.target
    setNotes((current) => ({ ...current, [name]: value }))
  }

  async function saveNotes(event) {
    event.preventDefault()
    setIsSaving(true)
    setPageError('')
    setPageMessage('')

    try {
      const result = await updateHousekeepingTaskNotes(id, notes)
      setTask(result.task)
      setPageMessage(result.message)
      await loadTask()
    } catch (error) {
      const firstError = Object.values(error?.response?.data?.errors ?? {})[0]?.[0]
      setPageError(firstError ?? 'Unable to save task notes.')
    } finally {
      setIsSaving(false)
    }
  }

  async function changeStatus(status) {
    setIsSaving(true)
    setPageError('')
    setPageMessage('')

    try {
      const result = await updateHousekeepingTaskStatus(id, { status, ...notes })
      setTask(result.task)
      setPageMessage(result.message)
      await loadTask()
    } catch (error) {
      const firstError = Object.values(error?.response?.data?.errors ?? {})[0]?.[0]
      setPageError(firstError ?? 'Unable to update this task.')
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <HousekeepingLayout eyebrow="Housekeeping" title="Task Details">
      <section className="housekeeping-panel">
        <div className="housekeeping-section-header">
          <div>
            <h2>{task?.accommodation?.name ?? 'Assigned Task'}</h2>
            <p>{task ? `${taskLabel(task.task_type)} · ${formatDateTime(task.scheduled_at)}` : 'Task assignment details'}</p>
          </div>
          <Link to="/housekeeping/tasks">Back to tasks</Link>
        </div>

        <ToastMessage message={pageMessage} />
        {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}
        {isLoading ? <div className="housekeeping-empty" role="status" aria-label="Loading task details..." /> : null}

        {!isLoading && task ? (
          <div className="housekeeping-detail-grid">
            <article className="housekeeping-detail-card">
              <dl>
                <div><dt>Accommodation</dt><dd>{task.accommodation?.name ?? 'Accommodation'}</dd></div>
                <div><dt>Task Type</dt><dd>{taskLabel(task.task_type)}</dd></div>
                <div><dt>Priority</dt><dd><span className={`housekeeping-badge priority-${task.priority}`}>{priorityLabel(task.priority)}</span></dd></div>
                <div><dt>Status</dt><dd><span className={`housekeeping-badge status-${task.status}`}>{statusLabel(task.status)}</span></dd></div>
                <div><dt>Schedule</dt><dd>{formatDateTime(task.scheduled_at)}</dd></div>
                <div><dt>Started Time</dt><dd>{formatDateTime(task.started_at)}</dd></div>
                <div><dt>Completed Time</dt><dd>{formatDateTime(task.completed_at)}</dd></div>
              </dl>
              <div className="housekeeping-task-actions">
                {canStartTask(task) ? <button type="button" onClick={() => changeStatus('in_progress')} disabled={isSaving}>Start Task</button> : null}
                {canCompleteTask(task) ? <button className="is-primary" type="button" onClick={() => changeStatus('completed')} disabled={isSaving}>Complete Task</button> : null}
              </div>
            </article>

            <form className="housekeeping-detail-card housekeeping-notes-form" onSubmit={saveNotes}>
              <h3>Remarks & Maintenance Issue</h3>
              <label>
                <span>Remarks</span>
                <textarea name="remarks" rows="5" value={notes.remarks} onChange={updateNotes} disabled={['completed', 'cancelled'].includes(task.status)} />
              </label>
              <label>
                <span>Maintenance Notes</span>
                <textarea name="maintenance_notes" rows="5" value={notes.maintenance_notes} onChange={updateNotes} disabled={['completed', 'cancelled'].includes(task.status)} />
              </label>
              <button type="submit" disabled={isSaving || ['completed', 'cancelled'].includes(task.status)}>
                {isSaving ? 'Saving...' : 'Save Notes'}
              </button>
            </form>
          </div>
        ) : null}
      </section>

      {!isLoading && task ? (
        <section className="housekeeping-panel">
          <div className="housekeeping-section-header">
            <div>
              <h2>Task History</h2>
              <p>Status and notes updates are preserved for accountability.</p>
            </div>
          </div>
          {history.length === 0 ? <div className="housekeeping-empty">No task history recorded yet.</div> : null}
          <div className="housekeeping-history-list">
            {history.map((item) => (
              <article key={item.id}>
                <strong>{item.action.replaceAll('_', ' ')}</strong>
                <span>{formatDateTime(item.created_at)} · {item.performed_by?.name ?? 'System'}</span>
                {item.remarks ? <p>{item.remarks}</p> : null}
              </article>
            ))}
          </div>
        </section>
      ) : null}
    </HousekeepingLayout>
  )
}

export default HousekeepingTaskDetail
