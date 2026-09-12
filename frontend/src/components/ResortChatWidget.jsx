import { useEffect, useMemo, useRef, useState } from 'react'
import { Bot, MessageCircle, Plus, Send, X } from 'lucide-react'
import { useAuth } from '../hooks/useAuth'
import {
  escalateChatConversation,
  getChatConversation,
  getChatbotRules,
  sendChatMessage,
  startChatConversation,
} from '../lib/api'
import './ResortChatWidget.css'

const quickReplies = [
  'Rooms & cottages',
  'Check availability',
  'Booking help',
  'Payments',
  'Check-in and check-out',
  'Facilities',
  'Policies',
  'Location',
  'Talk to Staff',
]

function ResortChatWidget() {
  const { user } = useAuth()
  const [isOpen, setIsOpen] = useState(false)
  const [isLoading, setIsLoading] = useState(false)
  const [message, setMessage] = useState('')
  const [conversation, setConversation] = useState(null)
  const [messages, setMessages] = useState([])
  const [rules, setRules] = useState([])
  const [error, setError] = useState('')
  const [isSending, setIsSending] = useState(false)
  const [isSupportMode, setIsSupportMode] = useState(false)
  const [botOptions, setBotOptions] = useState([])
  const [isResetConfirmOpen, setIsResetConfirmOpen] = useState(false)
  const bottomRef = useRef(null)

  const storageKey = useMemo(() => (user?.id ? `dmd-chat-${user.id}` : 'dmd-chat-guest'), [user?.id])

  function clearActiveChatSession() {
    window.localStorage.removeItem(storageKey)
    setConversation(null)
    setMessages([])
    setBotOptions([])
    setError('')
    setIsSending(false)
    setIsSupportMode(false)
    setMessage('')
    setIsResetConfirmOpen(false)
  }

  function mergeConversation(previous, nextConversation) {
    const normalizedUuid = nextConversation?.uuid ?? nextConversation?.conversation_uuid ?? previous?.uuid ?? previous?.conversation_uuid
    const normalizedToken = nextConversation?.token ?? nextConversation?.access_token ?? previous?.token ?? previous?.access_token

    return {
      ...(previous ?? {}),
      ...(nextConversation ?? {}),
      uuid: normalizedUuid,
      conversation_uuid: normalizedUuid,
      token: normalizedToken,
      access_token: normalizedToken,
    }
  }

  function persistConversation(nextConversation) {
    if (!nextConversation?.uuid) {
      return
    }

    const storedConversation = {
      ...nextConversation,
      token: nextConversation.token ?? nextConversation.access_token ?? null,
      conversation_uuid: nextConversation.conversation_uuid ?? nextConversation.uuid ?? null,
    }

    window.localStorage.setItem(storageKey, JSON.stringify(storedConversation))
    setConversation(storedConversation)
  }

  useEffect(() => {
    getChatbotRules().then(setRules).catch(() => setRules([]))
  }, [])

  useEffect(() => {
    const saved = window.localStorage.getItem(storageKey)
    if (!saved) {
      return
    }

    const parsed = JSON.parse(saved)
    if (!parsed?.uuid) {
      return
    }

    setConversation(parsed)
    getChatConversation(parsed.uuid, parsed.token)
      .then((data) => {
        const nextConversation = mergeConversation(parsed, data.conversation)
        persistConversation(nextConversation)
        setMessages(data.messages ?? [])
        setBotOptions(data.bot_options ?? [])
        setIsSupportMode((nextConversation.status ?? 'bot') !== 'bot')
        if ((nextConversation.status ?? 'bot') !== 'bot') {
          setError('You are now connected to staff support. A team member will reply shortly.')
        }
      })
      .catch(() => {
        window.localStorage.removeItem(storageKey)
      })
  }, [storageKey])

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' })
  }, [messages, isOpen])

  useEffect(() => {
    const conversationUuid = conversation?.uuid
    const conversationToken = conversation?.token
    const supportStatus = conversation?.status

    if (!isOpen || !conversationUuid || supportStatus === 'bot') {
      return undefined
    }

    let cancelled = false
    const canPoll = () => document.visibilityState === 'visible'

    async function refreshConversation() {
      if (!canPoll()) {
        return
      }

      try {
        const data = await getChatConversation(conversationUuid, conversationToken)
        if (cancelled) {
          return
        }

        const nextConversation = mergeConversation(conversation, data.conversation)
        persistConversation(nextConversation)
        setMessages(data.messages ?? [])
        setBotOptions(data.bot_options ?? [])
        setIsSupportMode((nextConversation.status ?? 'bot') !== 'bot')
      } catch (error) {
        console.error('Chatbot refresh failed', {
          conversationUuid,
          status: error?.response?.status,
          data: error?.response?.data,
          error,
        })
      }
    }

    refreshConversation()
    const intervalId = window.setInterval(refreshConversation, 5000)
    const visibilityHandler = () => {
      if (canPoll()) {
        refreshConversation()
      }
    }

    document.addEventListener('visibilitychange', visibilityHandler)

    return () => {
      cancelled = true
      window.clearInterval(intervalId)
      document.removeEventListener('visibilitychange', visibilityHandler)
    }
  }, [conversation?.uuid, conversation?.token, conversation?.status, isOpen])

  async function handleSubmit(nextMessage, selection = null) {
    const text = String(nextMessage ?? message).trim()
    if (!text) {
      return
    }

    if (isSending) {
      return
    }

    setIsSending(true)
    setError('')

    try {
      const isTalkToStaff = text.toLowerCase().includes('talk to staff')

      if (isTalkToStaff) {
        let currentConversation = conversation

        if (!currentConversation?.uuid) {
          const data = await startChatConversation({
            guest_name: user?.name ?? user?.first_name ?? '',
            guest_email: user?.email ?? '',
            message: text,
            ...(selection ?? {}),
          })

          currentConversation = mergeConversation(null, data.conversation)
          persistConversation(currentConversation)
          setMessages(data.messages ?? [])
          setBotOptions(data.bot_options ?? [])
        }

        const escalated = await escalateChatConversation(currentConversation.uuid, currentConversation.token)
        persistConversation(mergeConversation(currentConversation, escalated.conversation))
        setMessages(escalated.messages ?? [])
        setMessage('')
        setIsSupportMode(true)
        setError('You are now connected to staff support. A team member will reply shortly.')
        return
      }

      if (!conversation?.uuid) {
        const data = await startChatConversation({
          guest_name: user?.name ?? user?.first_name ?? '',
          guest_email: user?.email ?? '',
          message: text,
          ...(selection ?? {}),
        })

        const next = mergeConversation(null, data.conversation)
        persistConversation(next)
        setMessages(data.messages ?? [])
        setBotOptions(data.bot_options ?? [])
        setIsSupportMode(false)
      } else {
        const data = await sendChatMessage(conversation.uuid, { message: text, ...(selection ?? {}) }, conversation.token)
        const nextConversation = mergeConversation(conversation, data.conversation)
        persistConversation(nextConversation)
        setMessages(data.messages ?? [])
        setBotOptions(data.bot_options ?? [])
        setIsSupportMode((nextConversation.status ?? 'bot') !== 'bot')
      }
      setMessage('')

      const nextStatus = (conversation?.status ?? 'bot')
      if (nextStatus !== 'bot') {
        setError('You are now connected to staff support. A team member will reply shortly.')
      }
    } catch (error) {
      console.error('Chatbot message failed', {
        message: text,
        status: error?.response?.status,
        data: error?.response?.data,
        error,
      })
      setError("We couldn't send your message. Please try again.")
    } finally {
      setIsSending(false)
    }
  }

  function handleBotOptionClick(option) {
    if (!option?.id || !option?.label) {
      return
    }

    handleSubmit(option.label, { selected_accommodation_id: option.id })
  }

  function openWidget() {
    setIsOpen(true)
    if (messages.length === 0 && conversation?.uuid == null) {
      const greeting = 'Hi! Welcome to DMD Family Resort. How can I help you today?'
      setMessages([{ id: 'greeting', sender_type: 'bot', sender_name: 'DMD Family Resort', message: greeting }])
    }
  }

  function handleNewChatClick() {
    if (conversation?.status && conversation.status !== 'bot') {
      setIsResetConfirmOpen(true)
      return
    }

    clearActiveChatSession()
  }

  function confirmNewChat() {
    clearActiveChatSession()
  }

  return (
    <div className="resort-chat-widget">
      {isOpen ? (
        <div className="resort-chat-panel" role="dialog" aria-label="DMD Family Resort chat support">
          <header className="resort-chat-header">
            <div>
              <strong>DMD Family Resort</strong>
              <span>Guest Assistance</span>
            </div>
            <div className="resort-chat-header-actions">
              <button type="button" onClick={handleNewChatClick} aria-label="New chat" title="New chat">
                <Plus size={16} aria-hidden="true" />
              </button>
              <button type="button" onClick={() => setIsOpen(false)} aria-label="Close chat">
                <X size={18} aria-hidden="true" />
              </button>
            </div>
          </header>

          <div className="resort-chat-body">
            {messages.length === 0 ? (
              <div className="resort-chat-empty">
                <Bot size={26} aria-hidden="true" />
                <p>Hi! Welcome to DMD Family Resort. How can I help you today?</p>
              </div>
            ) : null}

            {messages.map((item) => (
              <div key={item.id ?? `${item.sender_type}-${item.created_at}-${item.message}`} className={`resort-chat-message is-${item.sender_type}`}>
                <strong>{item.sender_name ?? (item.sender_type === 'bot' ? 'DMD Family Resort' : 'Guest')}</strong>
                <p>{item.message}</p>
              </div>
            ))}
            {error ? <div className={`resort-chat-error${isSupportMode ? ' is-info' : ''}`}>{error}</div> : null}
            <div ref={bottomRef} />
          </div>

          <div className="resort-chat-quick-replies">
            {botOptions.length > 0 ? (
              <div className="resort-chat-bot-options" aria-label="Suggested rooms">
                {botOptions.map((option) => (
                  <button
                    key={option.id}
                    type="button"
                    onClick={() => handleBotOptionClick(option)}
                    disabled={isSending || isSupportMode}
                  >
                    {option.label}
                  </button>
                ))}
              </div>
            ) : null}

            {quickReplies.map((reply) => (
              <button key={reply} type="button" onClick={() => handleSubmit(reply)} disabled={isSending || isSupportMode}>
                {reply}
              </button>
            ))}
          </div>

          <form
            className="resort-chat-input"
            onSubmit={(event) => {
              event.preventDefault()
              handleSubmit()
            }}
          >
            <input
              value={message}
              onChange={(event) => setMessage(event.target.value)}
              placeholder="Type your question..."
              disabled={isSending}
            />
            <button type="submit" disabled={isSending}>
              <Send size={16} aria-hidden="true" />
            </button>
          </form>

          {isResetConfirmOpen ? (
            <div className="resort-chat-reset-confirm" role="dialog" aria-modal="true" aria-label="Start new chat confirmation">
              <p>Start a new chat? Your current support conversation will remain in history.</p>
              <div className="resort-chat-reset-actions">
                <button type="button" onClick={() => setIsResetConfirmOpen(false)}>
                  Cancel
                </button>
                <button type="button" className="is-primary" onClick={confirmNewChat}>
                  Start New Chat
                </button>
              </div>
            </div>
          ) : null}
        </div>
      ) : null}

      <button className="resort-chat-launcher" type="button" onClick={openWidget}>
        <MessageCircle size={18} aria-hidden="true" />
        <span>Need help?</span>
      </button>
    </div>
  )
}

export default ResortChatWidget
