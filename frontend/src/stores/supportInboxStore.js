import { useSyncExternalStore } from 'react'

let waitingCount = 0
const listeners = new Set()

function emit() {
  listeners.forEach((listener) => listener())
}

export function setWaitingSupportCount(count) {
  waitingCount = Number.isFinite(count) ? count : 0
  emit()
}

function subscribe(listener) {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

function getSnapshot() {
  return waitingCount
}

export function useWaitingSupportCount() {
  return useSyncExternalStore(subscribe, getSnapshot, getSnapshot)
}
