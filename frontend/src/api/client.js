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
  me: () => request('/api/auth/me'),
  stats: () => request('/api/stats'),
  requests: (params = {}) => request(`/api/requests${query(params)}`),
  request: (id) => request(`/api/requests/${id}`),
  deleteRequest: (id) => request(`/api/requests/${id}`, { method: 'DELETE' }),
  workflows: () => request('/api/workflows'),
  saveWorkflow: (workflow) =>
    workflow.id
      ? request(`/api/workflows/${workflow.id}`, { method: 'PUT', body: workflow })
      : request('/api/workflows', { method: 'POST', body: workflow }),
  toggleWorkflow: (id) => request(`/api/workflows/${id}/toggle`, { method: 'POST' }),
  deleteWorkflow: (id) => request(`/api/workflows/${id}`, { method: 'DELETE' }),
}
