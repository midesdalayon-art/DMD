import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import { CheckCircle2, Info, TriangleAlert, X, XCircle } from 'lucide-react'

const ToastContext = createContext(null)

const toastDurations = {
  success: 5000,
  error: 7500,
  warning: 6000,
  info: 6000,
}

const toastIcons = {
  success: CheckCircle2,
  error: XCircle,
  warning: TriangleAlert,
  info: Info,
}

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([])
  const nextId = useRef(0)

  const dismissToast = useCallback((id) => {
    setToasts((current) => current.filter((toast) => toast.id !== id))
  }, [])

  const showToast = useCallback((type, message, options = {}) => {
    if (!message) return null

    const id = ++nextId.current
    setToasts((current) => [...current, {
      id,
      type,
      message,
      title: options.title,
    }])
    return id
  }, [])

  const toast = useMemo(() => ({
    success: (message, options) => showToast('success', message, options),
    error: (message, options) => showToast('error', message, options),
    warning: (message, options) => showToast('warning', message, options),
    info: (message, options) => showToast('info', message, options),
  }), [showToast])

  return (
    <ToastContext.Provider value={{ toast, dismissToast }}>
      {children}
      <ToastViewport toasts={toasts} onDismiss={dismissToast} />
    </ToastContext.Provider>
  )
}

export function useToast() {
  const context = useContext(ToastContext)
  if (!context) throw new Error('useToast must be used within ToastProvider')
  return context
}

// Allows existing action state to use the shared toast surface during incremental migration.
export function ToastMessage({ message, type = 'success' }) {
  const { toast } = useToast()
  const lastMessage = useRef('')

  useEffect(() => {
    if (!message || message === lastMessage.current) return
    lastMessage.current = message
    toast[type]?.(message)
  }, [message, toast, type])

  useEffect(() => {
    if (!message) lastMessage.current = ''
  }, [message])

  return null
}

function ToastViewport({ toasts, onDismiss }) {
  return (
    <div className="dmd-toast-viewport" aria-live="polite" aria-atomic="false">
      {toasts.map((toast) => <ToastItem key={toast.id} toast={toast} onDismiss={onDismiss} />)}
    </div>
  )
}

function ToastItem({ toast, onDismiss }) {
  const Icon = toastIcons[toast.type] ?? Info
  const [isLeaving, setIsLeaving] = useState(false)

  const close = useCallback(() => {
    if (isLeaving) return
    setIsLeaving(true)
    window.setTimeout(() => onDismiss(toast.id), 180)
  }, [isLeaving, onDismiss, toast.id])

  useEffect(() => {
    const timer = window.setTimeout(close, toastDurations[toast.type] ?? 6000)
    return () => window.clearTimeout(timer)
  }, [close, toast.type])

  return (
    <div className={`dmd-toast dmd-toast-${toast.type}${isLeaving ? ' is-leaving' : ''}`} role={toast.type === 'error' ? 'alert' : 'status'}>
      <Icon size={19} aria-hidden="true" />
      <div className="dmd-toast-content">
        {toast.title ? <strong>{toast.title}</strong> : null}
        <span>{toast.message}</span>
      </div>
      <button type="button" className="dmd-toast-close" onClick={close} aria-label="Dismiss notification">
        <X size={16} aria-hidden="true" />
      </button>
    </div>
  )
}
