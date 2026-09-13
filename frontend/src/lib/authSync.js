const authSyncStorageKey = 'dmd-auth-sync'
const authSyncChannelName = 'dmd-auth'

let channel = null

function getChannel() {
  if (channel || typeof window === 'undefined' || typeof BroadcastChannel === 'undefined') {
    return channel
  }

  try {
    channel = new BroadcastChannel(authSyncChannelName)
  } catch {
    channel = null
  }

  return channel
}

function createMessage(type) {
  const randomId = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
    ? crypto.randomUUID()
    : `${Date.now()}-${Math.random().toString(36).slice(2)}`

  return {
    id: randomId,
    type,
    timestamp: Date.now(),
  }
}

function normalizeMessage(value) {
  if (!value || !['login', 'logout'].includes(value.type) || !value.id) {
    return null
  }

  return value
}

export function publishAuthChange(type) {
  if (typeof window === 'undefined') {
    return
  }

  const message = createMessage(type)

  try {
    window.localStorage.setItem(authSyncStorageKey, JSON.stringify(message))
  } catch {
    // BroadcastChannel below remains available where storage is restricted.
  }

  try {
    getChannel()?.postMessage(message)
  } catch {
    // localStorage remains the fallback when BroadcastChannel is unavailable.
  }
}

export function subscribeToAuthChanges(listener) {
  if (typeof window === 'undefined') {
    return () => {}
  }

  const seenMessageIds = new Set()

  function handleMessage(rawMessage) {
    const message = normalizeMessage(rawMessage)
    if (!message || seenMessageIds.has(message.id)) {
      return
    }

    seenMessageIds.add(message.id)
    if (seenMessageIds.size > 20) {
      seenMessageIds.delete(seenMessageIds.values().next().value)
    }
    listener(message)
  }

  function handleStorage(event) {
    if (event.key !== authSyncStorageKey || !event.newValue) {
      return
    }

    try {
      handleMessage(JSON.parse(event.newValue))
    } catch {
      // Ignore malformed values in this app-owned coordination key.
    }
  }

  function handleChannelMessage(event) {
    handleMessage(event.data)
  }

  window.addEventListener('storage', handleStorage)
  const currentChannel = getChannel()
  currentChannel?.addEventListener('message', handleChannelMessage)

  return () => {
    window.removeEventListener('storage', handleStorage)
    currentChannel?.removeEventListener('message', handleChannelMessage)
  }
}
