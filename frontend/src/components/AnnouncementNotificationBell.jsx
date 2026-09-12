import { useEffect, useRef, useState } from 'react'
import { Bell, Check, LoaderCircle } from 'lucide-react'
import { getAnnouncementNotifications, markAllAnnouncementsRead, markAnnouncementRead } from '../lib/api'

function AnnouncementNotificationBell() {
  const [isOpen, setIsOpen] = useState(false)
  const [announcements, setAnnouncements] = useState([])
  const [unreadCount, setUnreadCount] = useState(0)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState('')
  const [expandedId, setExpandedId] = useState(null)
  const rootRef = useRef(null)

  async function loadNotifications() {
    setIsLoading(true)
    setError('')

    try {
      const result = await getAnnouncementNotifications()
      setAnnouncements(result.announcements)
      setUnreadCount(result.unreadCount)
    } catch {
      setError('Unable to load notifications.')
    } finally {
      setIsLoading(false)
    }
  }

  useEffect(() => {
    loadNotifications()

    function closeOnOutsideClick(event) {
      if (!rootRef.current?.contains(event.target)) setIsOpen(false)
    }

    document.addEventListener('mousedown', closeOnOutsideClick)
    return () => document.removeEventListener('mousedown', closeOnOutsideClick)
  }, [])

  async function toggleOpen() {
    const nextOpen = !isOpen
    setIsOpen(nextOpen)
    if (nextOpen) await loadNotifications()
  }

  async function openAnnouncement(announcement) {
    setExpandedId((current) => current === announcement.id ? null : announcement.id)

    if (!announcement.is_read) {
      setAnnouncements((current) => current.map((item) => item.id === announcement.id ? { ...item, is_read: true, read_at: new Date().toISOString() } : item))
      setUnreadCount((current) => Math.max(0, current - 1))

      try {
        const result = await markAnnouncementRead(announcement.id)
        setUnreadCount(result.unreadCount)
      } catch {
        setError('Unable to save notification state.')
      }
    }
  }

  async function markAllRead() {
    try {
      const result = await markAllAnnouncementsRead()
      setAnnouncements((current) => current.map((item) => ({ ...item, is_read: true, read_at: new Date().toISOString() })))
      setUnreadCount(result.unreadCount)
    } catch {
      setError('Unable to mark notifications as read.')
    }
  }

  return (
    <div className="admin-notification-wrap" ref={rootRef}>
      <button className="admin-notification-button" type="button" aria-label="Notifications" aria-expanded={isOpen} onClick={toggleOpen}>
        <Bell size={21} aria-hidden="true" />
        {unreadCount > 0 ? <span>{unreadCount > 99 ? '99+' : unreadCount}</span> : null}
      </button>
      {isOpen ? (
        <div className="admin-notification-panel" role="dialog" aria-label="Announcements">
          <div className="admin-notification-panel-header">
            <div><strong>Notifications</strong><small>{unreadCount ? `${unreadCount} unread` : 'All caught up'}</small></div>
            {unreadCount > 0 ? <button type="button" onClick={markAllRead}><Check size={13} aria-hidden="true" /> Mark all read</button> : null}
          </div>
          {isLoading ? <div className="admin-notification-state"><LoaderCircle size={16} aria-hidden="true" /> Loading</div> : null}
          {error ? <div className="admin-notification-error" role="alert">{error}</div> : null}
          {!isLoading && announcements.length === 0 ? <div className="admin-notification-state">No announcements</div> : null}
          {!isLoading ? announcements.map((announcement) => (
            <button
              className={`admin-notification-item${announcement.is_read ? '' : ' is-unread'}`}
              type="button"
              key={announcement.id}
              onClick={() => openAnnouncement(announcement)}
            >
              <span className="admin-notification-dot" aria-hidden="true" />
              <span>
                <strong>{announcement.title}</strong>
                <small>{announcement.content}</small>
                {expandedId === announcement.id ? <em>{announcement.content}</em> : null}
              </span>
            </button>
          )) : null}
        </div>
      ) : null}
    </div>
  )
}

export default AnnouncementNotificationBell

