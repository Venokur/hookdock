import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api, getToken, setToken } from '../api/client'

export const useAuthStore = defineStore('auth', () => {
  const token = ref(getToken())
  const user = ref(null)
  const ready = ref(false)

  const isAuthenticated = computed(() => Boolean(token.value))

  async function login(email, password) {
    const data = await api.login(email, password)
    token.value = data.token
    user.value = data.user
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
      user.value = data.user
    } catch {
      logout()
    } finally {
      ready.value = true
    }
  }

  function logout() {
    token.value = ''
    user.value = null
    ready.value = true
    setToken('')
  }

  return { token, user, ready, isAuthenticated, login, fetchMe, logout }
})
