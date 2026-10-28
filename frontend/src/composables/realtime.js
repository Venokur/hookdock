import { ref } from 'vue'
import { api, getToken } from '../api/client'

/**
 * Realtime channel to the `ws` container.
 *
 * One socket is shared by every subscriber on the page. A view calls `retain()`
 * while mounted; the socket only exists while something holds it, so logging out
 * or leaving the dashboard closes it.
 *
 * Server events are deliberately thin ({ id, … }): subscribers re-read their own
 * data through the API instead of duplicating filters and pagination on the
 * client.
 */

const WS_PATH = '/api/ws'

export const status = ref('idle') // idle | connecting | open | retrying | offline

const handlers = new Map()
let socket = null
let retained = 0
let attempt = 0
let retryTimer = null
let openedOnce = false
let connecting = false

function emit(type, data) {
  const list = handlers.get(type)
  if (!list) {
    return
  }

  for (const handler of [...list]) {
    try {
      handler(data)
    } catch (e) {
      console.error(`[realtime] handler for "${type}" failed`, e)
    }
  }
}

/**
 * @param {string} type event type ("request.created", "workflow.changed", "resync", …)
 * @param {(data: object) => void} handler
 * @returns {() => void} unsubscribe
 */
export function subscribe(type, handler) {
  if (!handlers.has(type)) {
    handlers.set(type, new Set())
  }

  handlers.get(type).add(handler)

  return () => {
    handlers.get(type)?.delete(handler)
  }
}

function handleMessage(raw) {
  let payload

  try {
    payload = JSON.parse(raw)
  } catch {
    return
  }

  if (!payload || typeof payload.type !== 'string') {
    return
  }

  if (payload.type === 'hello' || payload.type === 'pong') {
    return
  }

  emit(payload.type, payload.data ?? {})
}

function socketUrl(ticket) {
  const scheme = window.location.protocol === 'https:' ? 'wss:' : 'ws:'

  return `${scheme}//${window.location.host}${WS_PATH}?ticket=${encodeURIComponent(ticket)}`
}

async function connect() {
  clearTimeout(retryTimer)
  retryTimer = null

  if (socket || connecting || retained === 0) {
    return
  }

  if (!getToken()) {
    status.value = 'offline'

    return
  }

  connecting = true
  status.value = attempt === 0 ? 'connecting' : 'retrying'

  let ticket = ''
  try {
    const data = await api.wsTicket()
    ticket = data?.data?.ticket ?? ''
  } catch {
    // A 401 already cleared the token and fired auth:expired.
    connecting = false
    status.value = getToken() ? 'retrying' : 'offline'
    scheduleRetry()

    return
  }

  // A retain/release or a logout may have happened while the ticket was in flight.
  if (ticket === '' || retained === 0 || !getToken()) {
    connecting = false

    return
  }

  let next
  try {
    next = new WebSocket(socketUrl(ticket))
  } catch {
    connecting = false
    scheduleRetry()

    return
  }

  socket = next
  connecting = false

  next.onopen = () => {
    attempt = 0
    status.value = 'open'

    if (openedOnce) {
      // Events sent while the socket was down are gone: let views re-read.
      emit('resync', { reason: 'reconnected' })
    }

    openedOnce = true
  }

  next.onmessage = (event) => handleMessage(event.data)

  next.onerror = () => {
    // onclose always follows; retries are scheduled there only.
  }

  next.onclose = () => {
    // A socket replaced by a newer one (logout/login, visibility change) must not
    // touch the status of the live connection or schedule a duplicate retry.
    if (socket !== next) {
      return
    }

    socket = null

    if (retained === 0) {
      status.value = 'idle'

      return
    }

    scheduleRetry()
  }
}

function scheduleRetry() {
  if (retained === 0) {
    return
  }

  attempt = Math.min(attempt + 1, 5)
  const base = Math.min(1000 * 2 ** (attempt - 1), 30000)
  const delay = Math.round(base * (0.8 + Math.random() * 0.4))

  status.value = 'retrying'
  clearTimeout(retryTimer)
  retryTimer = setTimeout(connect, delay)
}

function disconnect() {
  clearTimeout(retryTimer)
  retryTimer = null
  attempt = 0
  connecting = false

  const current = socket
  socket = null

  if (current) {
    try {
      current.close(1000, 'client closed')
    } catch {
      // Already closed.
    }
  }

  status.value = 'idle'
}

/**
 * Call from onMounted; the returned function releases the socket.
 *
 * @returns {() => void}
 */
export function retain() {
  retained += 1

  if (retained === 1) {
    connect()
  }

  let released = false

  return () => {
    if (released) {
      return
    }
    released = true
    retained = Math.max(0, retained - 1)

    if (retained === 0) {
      disconnect()
    }
  }
}

if (typeof window !== 'undefined') {
  window.addEventListener('auth:expired', () => disconnect())
  window.addEventListener('auth:logout', () => disconnect())

  // Background tabs get throttled; re-check on return so the list is fresh.
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      return
    }

    if (!socket && retained > 0) {
      attempt = 0
      connect()

      return
    }

    if (socket) {
      emit('resync', { reason: 'visible' })
    }
  })
}
