import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api, getToken, setToken } from '../api/client'

/**
 * Session state: the token, the account behind it, and what that account may do.
 *
 * Two independent limits exist on the server and the SPA mirrors both:
 *
 * - **scope** — a `read` token refuses every mutating method, no matter who holds
 *   it. It is what an operator pastes into a dashboard or a script.
 * - **role** — `viewer` also refuses every write, but for the person, not the
 *   credential. An editor may change rooms and rules; only an administrator may
 *   manage accounts.
 *
 * `canWrite`/`canAdmin` are computed from both so views ask one question instead
 * of re-deriving it, and the flags come from the login response as well as `me`,
 * which keeps the sidebar from flickering while `me` is still in flight. They are
 * a convenience only: the API enforces the same rule, so hiding a button is never
 * what protects the data.
 */
export const useAuthStore = defineStore('auth', () => {
  const token = ref(getToken())
  const user = ref(null)
  const ready = ref(false)

  const isAuthenticated = computed(() => Boolean(token.value))

  const role = computed(() => user.value?.role || '')
  const readOnlyToken = computed(() => user.value?.scope === 'read')

  /** May change rooms, rules, requests and breakers. */
  const canWrite = computed(() => Boolean(user.value?.can_write) && !readOnlyToken.value)

  /** May manage accounts (and grant roles). */
  const canAdmin = computed(() => Boolean(user.value?.can_admin) && !readOnlyToken.value)

  function applyUser(next) {
    if (!next) {
      user.value = null

      return
    }

    // A login response carries the same flags as `me`; a document that predates
    // roles has neither, and the server would treat it as an admin — mirror that
    // instead of hiding every control from a working account.
    const hasRole = typeof next.role === 'string' && next.role !== ''
    const scope = next.scope || 'api'

    user.value = {
      ...next,
      role: hasRole ? next.role : 'admin',
      scope,
      can_write: next.can_write ?? (scope !== 'read'),
      can_admin: next.can_admin ?? (hasRole ? next.role === 'admin' : scope !== 'read'),
    }
  }

  async function login(email, password) {
    const data = await api.login(email, password)
    token.value = data.token
    applyUser(data.user)
    setToken(data.token)
    ready.value = true
  }

  async function fetchMe() {
    if (!token.value) {
      ready.value = true
      return
    }

    try {
      const data = await api.me()
      applyUser(data.user)
    } catch {
      logout()
    } finally {
      ready.value = true
    }
  }

  /**
   * Refreshes the flags after an admin changes their own role, and after any
   * other change that could have, so the nav does not keep offering a page the
   * API now refuses.
   */
  async function refresh() {
    if (!token.value) {
      return
    }

    try {
      const data = await api.me()
      applyUser(data.user)
    } catch {
      // A failed refresh is not a logout: `fetchMe` already handles a dead token.
    }
  }

  function logout() {
    token.value = ''
    user.value = null
    ready.value = true
    setToken('')
    window.dispatchEvent(new Event('auth:logout'))
  }

  return {
    token,
    user,
    ready,
    role,
    isAuthenticated,
    canWrite,
    canAdmin,
    login,
    fetchMe,
    refresh,
    logout,
  }
})
