import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Clock3, Mail, MessageSquare, Search, ShieldCheck, UserRound } from 'lucide-react'
import LoadingState from '../components/LoadingState'
import EmptyState from '../components/EmptyState'
import LoadingButton from '../components/LoadingButton'
import { setWaitingSupportCount } from '../stores/supportInboxStore'
import {
  claimAdminSupportConversation,
  claimSupportConversation,
  getAdminSupportConversation,
  getAdminSupportConversations,
  getSupportConversation,
  getSupportConversations,
  resolveAdminSupportConversation,
  resolveSupportConversation,
  sendAdminSupportConversationMessage,
  sendSupportConversationMessage,
} from '../lib/api'

function formatSupportTime(value) {
  if (!value) return 'Just now'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return typeof value === 'string' ? value : 'Just now'
  return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
}

function formatSupportDate(value) {
  if (!value) return 'Today'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return typeof value === 'string' ? value : 'Today'
  return date.toLocaleDateString([], { month: 'long', day: 'numeric', year: 'numeric' })
}

function supportStatusLabel(status) {
  if (status === 'assigned') return 'Assigned'
  if (typeof status !== 'string' || !status) return 'Waiting'
  return status.charAt(0).toUpperCase() + status.slice(1)
}

function matchesSupportFilter(status, filter) {
  if (filter === 'all') return true
  if (filter === 'active') return status === 'active' || status === 'assigned'
  return status === filter
}

function normalizeSupportConversation(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return null

  return {
    ...value,
    status: typeof value.status === 'string' ? value.status : 'waiting',
    guest_name: typeof value.guest_name === 'string' && value.guest_name.trim()
      ? value.guest_name
      : 'Anonymous Visitor',
    guest_email: typeof value.guest_email === 'string' ? value.guest_email : null,
    latest_message: typeof value.latest_message === 'string' ? value.latest_message : null,
    unread_count: Number.isFinite(Number(value.unread_count)) ? Number(value.unread_count) : 0,
    assigned_to: value.assigned_to && typeof value.assigned_to === 'object' && typeof value.assigned_to.name === 'string'
      ? value.assigned_to
      : null,
  }
}

function normalizeSupportDetail(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return null

  const conversation = normalizeSupportConversation(value.conversation ?? value)
  if (!conversation) return null

  return {
    ...value,
    conversation,
    messages: Array.isArray(value.messages)
      ? value.messages
        .filter((message) => message && typeof message === 'object' && !Array.isArray(message))
        .map((message) => ({
          ...message,
          sender_type: typeof message.sender_type === 'string' ? message.sender_type : 'guest',
          sender_name: typeof message.sender_name === 'string' ? message.sender_name : null,
          message: typeof message.message === 'string' ? message.message : '',
        }))
      : [],
  }
}

function normalizeSupportPagination(value, currentPage, itemCount) {
  const pagination = value && typeof value === 'object' ? value : {}
  const normalizedCurrentPage = Number(pagination.current_page) || currentPage
  const normalizedLastPage = Math.max(1, Number(pagination.last_page) || 1)

  return {
    current_page: normalizedCurrentPage,
    last_page: normalizedLastPage,
    from: Number(pagination.from) || (itemCount ? (normalizedCurrentPage - 1) * 10 + 1 : 0),
    to: Number(pagination.to) || (itemCount ? (normalizedCurrentPage - 1) * 10 + itemCount : 0),
    total: Number(pagination.total) || itemCount,
    status_counts: pagination.status_counts && typeof pagination.status_counts === 'object' ? pagination.status_counts : {},
  }
}

function SupportInboxPage({ Layout, eyebrow, title }) {
  const [filters, setFilters] = useState({ status: 'waiting', search: '' })
  const [list, setList] = useState([])
  const [selected, setSelected] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [isGuestDetailsOpen, setIsGuestDetailsOpen] = useState(false)
  const [page, setPage] = useState(1)
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, from: 0, to: 0, total: 0 })
  const [statusCounts, setStatusCounts] = useState({})
  const messageListRef = useRef(null)
  const supportScope = eyebrow === 'Admin' ? 'admin' : 'staff'
  const pageSize = 10

  const supportApi = useMemo(() => {
    if (supportScope === 'admin') {
      return {
        list: getAdminSupportConversations,
        show: getAdminSupportConversation,
        claim: claimAdminSupportConversation,
        send: sendAdminSupportConversationMessage,
        resolve: resolveAdminSupportConversation,
      }
    }

    return {
      list: getSupportConversations,
      show: getSupportConversation,
      claim: claimSupportConversation,
      send: sendSupportConversationMessage,
      resolve: resolveSupportConversation,
    }
  }, [supportScope])

  const selectedConversation = normalizeSupportConversation(selected?.conversation ?? selected)
  const selectedMessages = Array.isArray(selected?.messages)
    ? selected.messages.filter((message) => message && typeof message === 'object' && !Array.isArray(message))
    : []

  useEffect(() => {
    setIsGuestDetailsOpen(false)
  }, [selectedConversation?.conversation_uuid])

  useEffect(() => {
    const messageList = messageListRef.current
    if (messageList) {
      messageList.scrollTop = messageList.scrollHeight
    }
  }, [selectedConversation?.conversation_uuid, selectedMessages.length])
  const groupedConversations = useMemo(() => {
    const groups = {
      waiting: [],
      active: [],
      resolved: [],
    }

    list.forEach((conversation) => {
      if (conversation.status === 'waiting') {
        groups.waiting.push(conversation)
      } else if (conversation.status === 'resolved') {
        groups.resolved.push(conversation)
      } else {
        groups.active.push(conversation)
      }
    })

    return groups
  }, [list])

  const loadConversationList = useCallback(async (isMounted = () => true) => {
    setIsLoading(true)
    setError('')

    try {
      const result = await supportApi.list({ ...filters, page, per_page: pageSize })
      const conversations = Array.isArray(result)
        ? result
        : Array.isArray(result?.conversations)
          ? result.conversations
          : []
      const safeConversations = conversations.map(normalizeSupportConversation).filter(Boolean)
      const resultPagination = Array.isArray(result) ? null : result.pagination
      const normalizedPagination = normalizeSupportPagination(resultPagination, page, safeConversations.length)

      if (isMounted()) {
        setList(safeConversations)
        setPagination(normalizedPagination)
        setStatusCounts(normalizedPagination.status_counts)
        if (filters.status === 'waiting') {
          setWaitingSupportCount(normalizedPagination.status_counts.waiting ?? safeConversations.length)
        }
        setSelected((current) => {
          if (!current) return safeConversations[0] ?? null
          const currentConversation = normalizeSupportConversation(current.conversation ?? current)
          const currentUuid = currentConversation?.conversation_uuid
          return safeConversations.some((conversation) => conversation.conversation_uuid === currentUuid)
            ? current
            : null
        })
      }
    } catch {
      if (isMounted()) setError('Unable to load support conversations.')
    } finally {
      if (isMounted()) setIsLoading(false)
    }
  }, [filters, page, pageSize, supportApi])

  useEffect(() => {
    let mounted = true
    loadConversationList(() => mounted)

    return () => {
      mounted = false
    }
  }, [loadConversationList])

  useEffect(() => {
    let mounted = true
    let intervalId = null
    let requestInFlight = false
    let controller = null

    async function refreshSelected() {
      if (!selectedConversation?.conversation_uuid || document.visibilityState !== 'visible' || requestInFlight) {
        return
      }

      requestInFlight = true
      controller = new AbortController()
      try {
        const data = await supportApi.show(selectedConversation.conversation_uuid, controller.signal)
        if (mounted) {
          const nextSelected = normalizeSupportDetail(data)
          if (nextSelected) setSelected(nextSelected)
        }
      } catch {
        // ignore refresh errors and keep the current view
      } finally {
        requestInFlight = false
        controller = null
      }
    }

    function stopPolling() {
      if (intervalId) {
        window.clearInterval(intervalId)
        intervalId = null
      }
    }

    function startPolling() {
      if (intervalId || document.visibilityState !== 'visible') return
      intervalId = window.setInterval(refreshSelected, 5000)
    }

    function handleVisibilityChange() {
      if (document.visibilityState === 'visible') {
        void refreshSelected()
        startPolling()
      } else {
        stopPolling()
      }
    }

    if (selectedConversation?.status === 'waiting' || selectedConversation?.status === 'assigned' || selectedConversation?.status === 'active') {
      void refreshSelected()
      startPolling()
      document.addEventListener('visibilitychange', handleVisibilityChange)
    }

    return () => {
      mounted = false
      stopPolling()
      document.removeEventListener('visibilitychange', handleVisibilityChange)
      controller?.abort()
    }
  }, [selectedConversation?.conversation_uuid, selectedConversation?.status, supportApi])

  async function handleClaim() {
    if (!selectedConversation?.conversation_uuid) return
    setIsSaving(true)
    try {
      const data = await supportApi.claim(selectedConversation.conversation_uuid)
      updateConversationAfterAction(data)
    } finally {
      setIsSaving(false)
    }
  }

  async function handleSend(event) {
    event.preventDefault()
    if (!message.trim() || !selectedConversation?.conversation_uuid) return
    setIsSaving(true)
    try {
      const data = await supportApi.send(selectedConversation.conversation_uuid, { message })
      setMessage('')
      updateConversationAfterAction(data)
    } finally {
      setIsSaving(false)
    }
  }

  async function handleResolve() {
    if (!selectedConversation?.conversation_uuid) return
    setIsSaving(true)
    try {
      const data = await supportApi.resolve(selectedConversation.conversation_uuid)
      updateConversationAfterAction(data)
    } finally {
      setIsSaving(false)
    }
  }

  function updateConversationAfterAction(data) {
    const updatedConversation = normalizeSupportConversation(data?.conversation ?? data)
    if (!updatedConversation?.conversation_uuid) return
    const remainsVisible = matchesSupportFilter(updatedConversation.status, filters.status)

    if (!remainsVisible) {
      setPage(1)
      setSelected(null)
      setList((current) => current.filter((conversation) => conversation.conversation_uuid !== updatedConversation.conversation_uuid))
      return
    }

    const nextSelected = normalizeSupportDetail(data)
    if (!nextSelected) return

    setSelected(nextSelected)
    setList((current) =>
      current.map((conversation) =>
        conversation.conversation_uuid === updatedConversation.conversation_uuid
          ? updatedConversation
          : conversation,
      ),
    )
  }

  const pageContent = (
    <div className="support-inbox-page">
      {error ? <EmptyState title="Support inbox error" message={error} /> : null}
      {isLoading ? <LoadingState message="Loading support conversations..." /> : null}

      {!isLoading && !error ? (
        <div className="support-inbox-grid">
          <aside className="support-inbox-list">
            <div className="support-inbox-toolbar">
              <label className="support-search">
                <Search size={16} aria-hidden="true" />
                <input
                  value={filters.search}
                  onChange={(event) => {
                    setPage(1)
                    setFilters((current) => ({ ...current, search: event.target.value }))
                  }}
                  placeholder="Search guest or reference"
                />
              </label>
              <div className="support-filter-chips">
                {['waiting', 'active', 'resolved', 'all'].map((status) => (
                  <button
                    key={status}
                    type="button"
                    className={filters.status === status ? 'is-active' : ''}
                    onClick={() => {
                      setPage(1)
                      setFilters((current) => ({ ...current, status }))
                    }}
                  >
                    {status.charAt(0).toUpperCase() + status.slice(1)}
                    {statusCounts[status] !== undefined ? ` (${statusCounts[status]})` : null}
                  </button>
                ))}
              </div>
            </div>

            <div className="support-list">
              {list.length === 0 ? (
                <div className="support-empty-state">
                  <MessageSquare size={20} aria-hidden="true" />
                  <strong>No conversations</strong>
                  <p>Support conversations will appear here.</p>
                </div>
              ) : (
                Object.entries(groupedConversations).map(([groupName, conversations]) => (
                  <section key={groupName} className="support-group">
                    <div className="support-group-header">
                      <h3>{groupName === 'active' ? 'Assigned / Active' : groupName.charAt(0).toUpperCase() + groupName.slice(1)}</h3>
                      <span>{conversations.length}</span>
                    </div>
                    {conversations.length === 0 ? (
                      <p className="support-group-empty">No conversations.</p>
                    ) : (
                      conversations.map((conversation) => (
                        <button
                          type="button"
                          key={conversation.conversation_uuid}
                          className={`support-list-item ${selectedConversation?.conversation_uuid === conversation.conversation_uuid ? 'is-active' : ''}`}
                          onClick={() => setSelected(conversation)}
                        >
                          <MessageSquare size={16} aria-hidden="true" />
                          <div>
                            <strong>{conversation.guest_name ?? 'Anonymous Visitor'}</strong>
                            <small>{conversation.latest_message ?? 'No messages yet'}</small>
                            <small className="support-list-meta">
                              {' · '}
                              <span>{formatSupportTime(conversation.last_activity_at ?? conversation.last_message_at)}</span>
                            </small>
                          </div>
                          <div className="support-list-status">
                            <span className={`support-status is-${conversation.status}`}>{supportStatusLabel(conversation.status)}</span>
                            {conversation.unread_count > 0 ? <b className="support-unread-count">{conversation.unread_count}</b> : null}
                          </div>
                        </button>
                      ))
                    )}
                  </section>
                ))
              )}
            </div>
            <div className="support-pagination" aria-label="Conversation pagination">
              <span>
                {pagination.total > 0 ? `Showing ${pagination.from}–${pagination.to} of ${pagination.total}` : 'No conversations'}
              </span>
              <div>
                <button type="button" disabled={pagination.current_page <= 1} onClick={() => setPage((current) => Math.max(1, current - 1))}>
                  Previous
                </button>
                <strong>Page {pagination.current_page} of {pagination.last_page}</strong>
                <button type="button" disabled={pagination.current_page >= pagination.last_page} onClick={() => setPage((current) => Math.min(pagination.last_page, current + 1))}>
                  Next
                </button>
              </div>
            </div>
          </aside>

          <section className="support-inbox-thread">
            {selectedConversation ? (
              <>
                <div className="support-thread-header">
                  <div>
                    <div className="support-thread-title">
                      <strong>{selectedConversation.guest_name ?? 'Anonymous Visitor'}</strong>
                      <span className={`support-status is-${selectedConversation.status}`}>
                        {supportStatusLabel(selectedConversation.status)}
                      </span>
                    </div>
                    <small>{selectedConversation.guest_email ?? 'Anonymous visitor'}</small>
                  </div>
                  <div className="support-thread-actions">
                    <button
                      type="button"
                      className="outline-button support-guest-details-toggle"
                      onClick={() => setIsGuestDetailsOpen((current) => !current)}
                      aria-expanded={isGuestDetailsOpen}
                      aria-controls="support-guest-information"
                    >
                      Guest Details
                    </button>
                    <button type="button" className="outline-button" onClick={handleClaim} disabled={isSaving}>
                      Accept Chat
                    </button>
                    <button type="button" className="outline-button" onClick={handleResolve} disabled={isSaving}>
                      Resolve
                    </button>
                  </div>
                </div>

                <div className="support-message-list" ref={messageListRef}>
                  {selectedMessages.length === 0 ? (
                    <EmptyState title="No messages yet" message="The conversation has not started yet." />
                  ) : selectedMessages.map((msg, index) => {
                    const previousMessage = selectedMessages[index - 1]
                    const showDate = !previousMessage || formatSupportDate(previousMessage.created_at) !== formatSupportDate(msg.created_at)
                    return (
                      <div key={msg.id ?? `${msg.created_at}-${index}`}>
                        {showDate ? <div className="support-date-separator"><span>{formatSupportDate(msg.created_at)}</span></div> : null}
                        <article className={`support-message is-${msg.sender_type}`}>
                          <div className="support-message-meta">
                            <strong>{msg.sender_name ?? (msg.sender_type === 'staff' ? 'Staff' : 'Guest')}</strong>
                            <time dateTime={msg.created_at}>{formatSupportTime(msg.created_at)}</time>
                          </div>
                          <p>{msg.message}</p>
                        </article>
                      </div>
                    )
                  })}
                </div>

                <form className="support-reply-form" onSubmit={handleSend}>
                  <label className="support-reply-input">
                    <UserRound size={16} aria-hidden="true" />
                    <input
                      value={message}
                      onChange={(event) => setMessage(event.target.value)}
                      placeholder="Write a reply..."
                      disabled={selectedConversation.status === 'resolved'}
                    />
                  </label>
                  <LoadingButton
                    className="solid-button"
                    isLoading={isSaving}
                    loadingLabel="Sending..."
                    type="submit"
                    disabled={selectedConversation.status === 'resolved'}
                  >
                    Send Reply
                  </LoadingButton>
                </form>
              </>
            ) : (
              <div className="support-empty-state support-empty-state--thread">
                <MessageSquare size={22} aria-hidden="true" />
                <strong>Select a conversation</strong>
                <p>Choose a support chat to view messages.</p>
              </div>
            )}
          </section>

          <aside id="support-guest-information" className={`support-guest-panel ${isGuestDetailsOpen ? 'is-mobile-open' : ''}`}>
            {selectedConversation ? (
              <>
                <div className="support-panel-heading">
                  <span className="support-panel-icon"><UserRound size={17} aria-hidden="true" /></span>
                  <div>
                    <p className="eyebrow">Guest information</p>
                    <h3>{selectedConversation.guest_name ?? 'Anonymous Visitor'}</h3>
                  </div>
                </div>
                <div className="support-guest-badge">
                  <ShieldCheck size={15} aria-hidden="true" />
                  {selectedConversation.guest_email ? 'Guest' : 'Anonymous Visitor'}
                </div>
                <dl className="support-guest-details">
                  {selectedConversation.guest_email ? (
                    <div><dt><Mail size={14} aria-hidden="true" /> Email</dt><dd>{selectedConversation.guest_email}</dd></div>
                  ) : null}
                  <div><dt><MessageSquare size={14} aria-hidden="true" /> Status</dt><dd>{supportStatusLabel(selectedConversation.status)}</dd></div>
                  {selectedConversation.assigned_to?.name ? (
                    <div><dt><UserRound size={14} aria-hidden="true" /> Assigned to</dt><dd>{selectedConversation.assigned_to.name}</dd></div>
                  ) : null}
                  <div><dt><Clock3 size={14} aria-hidden="true" /> First contact</dt><dd>{formatSupportDate(selectedMessages[0]?.created_at ?? selectedConversation.created_at)}</dd></div>
                </dl>
              </>
            ) : (
              <div className="support-empty-state support-empty-state--guest">
                <UserRound size={20} aria-hidden="true" />
                <strong>Guest Information</strong>
                <p>Select a conversation to view details.</p>
              </div>
            )}
          </aside>
        </div>
      ) : null}
    </div>
  )

  return Layout ? <Layout eyebrow={eyebrow} title={title}>{pageContent}</Layout> : pageContent
}

export default SupportInboxPage
