export const taskTypeLabels = {
  cleaning: 'Cleaning',
  room_preparation: 'Room Prep',
  inspection: 'Inspection',
  maintenance: 'Maintenance',
}

export const priorityLabels = {
  low: 'Low',
  normal: 'Normal',
  high: 'High',
  urgent: 'Urgent',
}

export const statusLabels = {
  pending: 'Pending',
  assigned: 'Assigned',
  in_progress: 'Cleaning',
  completed: 'Ready',
  cancelled: 'Cancelled',
  needs_cleaning: 'Needs Cleaning',
  ready: 'Ready',
}

export function taskLabel(value) {
  return taskTypeLabels[value] ?? value?.replaceAll('_', ' ') ?? 'Task'
}

export function priorityLabel(value) {
  return priorityLabels[value] ?? value ?? 'Normal'
}

export function statusLabel(value) {
  return statusLabels[value] ?? value?.replaceAll('_', ' ') ?? 'Pending'
}

export function formatDateTime(value) {
  if (!value) {
    return 'Not set'
  }

  return new Intl.DateTimeFormat('en-PH', {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(value))
}

export function formatDate(value) {
  if (!value) {
    return 'Not set'
  }

  return new Intl.DateTimeFormat('en-PH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(new Date(value))
}

export function canStartTask(task) {
  return task?.allowed_statuses?.includes('in_progress')
}

export function canCompleteTask(task) {
  return task?.allowed_statuses?.includes('completed')
}
