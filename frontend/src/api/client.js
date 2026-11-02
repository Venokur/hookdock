const TOKEN_KEY = 'wr_token'

export function getToken() {
  return localStorage.getItem(TOKEN_KEY) || ''
}

export function setToken(token) {
  if (token) {
    localStorage.setItem(TOKEN_KEY, token)
  } else {
    localStorage.removeItem(TOKEN_KEY)
  }
}

async function request(path, { method = 'GET', body, headers = {} } = {}) {
  const finalHeaders = { Accept: 'application/json', ...headers }
  if (body !== undefined) {
    finalHeaders['Content-Type'] = 'application/json'
  }

  const token = getToken()
  if (token) {
    finalHeaders.Authorization = `Bearer ${token}`
  }

  let response
  try {
    response = await fetch(path, {
      method,
      headers: finalHeaders,
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch {
    throw new Error('Network error: backend is not reachable')
  }

  const text = await response.text()
  let payload = null
  if (text) {
    try {
      payload = JSON.parse(text)
    } catch {
      payload = null
    }
  }

  if (response.status === 401) {
    setToken('')
    window.dispatchEvent(new Event('auth:expired'))
    throw new Error(payload?.error?.message || 'Unauthorized')
  }

  if (!response.ok) {
    throw new Error(payload?.error?.message || `Request failed (${response.status})`)
  }

  return payload
}

/**
 * Fetches a file with the auth header and hands back its blob.
 *
 * A plain `<a href="/api/...">` cannot download an export: the API expects a
 * Bearer token and a browser navigation does not send one. The filename comes
 * from the server so the client does not have to rebuild it.
 */
async function download(path) {
  const token = getToken()

  let response
  try {
    response = await fetch(path, {
      headers: token ? { Authorization: `Bearer ${token}` } : {},
    })
  } catch {
    throw new Error('Network error: backend is not reachable')
  }

  if (response.status === 401) {
    setToken('')
    window.dispatchEvent(new Event('auth:expired'))
    throw new Error('Unauthorized')
  }

  if (!response.ok) {
    let message = `Не удалось скачать файл (${response.status})`
    try {
      const payload = await response.json()
      message = payload?.error?.message || message
    } catch {
      // The error body was not JSON; the status is all we have.
    }
    throw new Error(message)
  }

  const disposition = response.headers.get('Content-Disposition') || ''
  const match = /filename="([^"]+)"/.exec(disposition)

  return {
    blob: await response.blob(),
    filename: match ? match[1] : 'hookdock-export',
  }
}

function query(params) {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value !== '' && value !== null && value !== undefined) {
      search.set(key, String(value))
    }
  }
  const encoded = search.toString()

  return encoded ? `?${encoded}` : ''
}

export const api = {
  login: (email, password) => request('/api/auth/login', { method: 'POST', body: { email, password } }),
  config: () => request('/api/auth/config'),
  wsTicket: () => request('/api/ws/ticket', { method: 'POST' }),
  me: () => request('/api/auth/me'),
  rooms: (params = {}) => request(`/api/rooms${query(params)}`),
  createRoom: (room) => request('/api/rooms', { method: 'POST', body: room }),
  updateRoom: (id, room) => request(`/api/rooms/${id}`, { method: 'PUT', body: room }),
  deleteRoom: (id) => request(`/api/rooms/${id}`, { method: 'DELETE' }),
  stats: (params = {}) => request(`/api/stats${query(params)}`),
  endpoints: (params = {}) => request(`/api/endpoints${query(params)}`),
  requests: (params = {}) => request(`/api/requests${query(params)}`),
  request: (id) => request(`/api/requests/${id}`),
  deleteRequest: (id) => request(`/api/requests/${id}`, { method: 'DELETE' }),
  replayRequest: (id) => request(`/api/requests/${id}/replay`, { method: 'POST' }),
  redeliverRequest: (id, body) => request(`/api/requests/${id}/redeliver`, { method: 'POST', body }),
  bulkRequests: (action, ids) => request('/api/requests/bulk', { method: 'POST', body: { action, ids } }),
  retryRun: (id) => request(`/api/runs/${id}/retry`, { method: 'POST' }),
  previewRequest: (id, body) => request(`/api/requests/${id}/preview`, { method: 'POST', body }),
  downloadRequestBody: (id) => download(`/api/requests/${id}/body`),
  exportRequests: (params = {}) => download(`/api/requests/export${query(params)}`),
  rotateRoomSecret: (id) => request(`/api/rooms/${id}/secret`, { method: 'POST' }),
  clearRoomSecret: (id) => request(`/api/rooms/${id}/secret`, { method: 'DELETE' }),
  workflows: (params = {}) => request(`/api/workflows${query(params)}`),
  saveWorkflow: (workflow) =>
    workflow.id
      ? request(`/api/workflows/${workflow.id}`, { method: 'PUT', body: workflow })
      : request('/api/workflows', { method: 'POST', body: workflow }),
  toggleWorkflow: (id) => request(`/api/workflows/${id}/toggle`, { method: 'POST' }),
  duplicateWorkflow: (id) => request(`/api/workflows/${id}/duplicate`, { method: 'POST' }),
  exportRoomWorkflows: (id) => download(`/api/rooms/${id}/workflows/export`),
  importRoomWorkflows: (id, document) =>
    request(`/api/rooms/${id}/workflows/import`, { method: 'POST', body: document }),
  breakers: () => request('/api/breakers'),
  resetBreaker: (address) => request('/api/breakers/reset', { method: 'POST', body: { address } }),
  readToken: () => request('/api/auth/read-token', { method: 'POST' }),
  changePassword: (body) => request('/api/auth/password', { method: 'POST', body }),
  users: () => request('/api/users'),
  createUser: (body) => request('/api/users', { method: 'POST', body }),
  updateUser: (id, body) => request(`/api/users/${id}`, { method: 'PUT', body }),
  deleteUser: (id) => request(`/api/users/${id}`, { method: 'DELETE' }),
  audit: (params = {}) => request(`/api/audit${query(params)}`),
  workflowVersions: (id) => request(`/api/workflows/${id}/versions`),
  workflowVersion: (id, version) => request(`/api/workflows/${id}/versions/${version}`),
  restoreWorkflowVersion: (id, version) =>
    request(`/api/workflows/${id}/versions/${version}/restore`, { method: 'POST' }),
  runs: (params = {}) => request(`/api/runs${query(params)}`),
  deleteWorkflow: (id) => request(`/api/workflows/${id}`, { method: 'DELETE' }),
}
