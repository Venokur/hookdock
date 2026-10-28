import { ref } from 'vue'

const THEME_KEY = 'wr_theme'

const theme = ref('light')

export function initTheme() {
  let stored = null
  try {
    stored = localStorage.getItem(THEME_KEY)
  } catch {
    stored = null
  }

  theme.value = stored === 'dark' ? 'dark' : 'light'
  document.documentElement.classList.toggle('dark', theme.value === 'dark')
}

export function toggleTheme() {
  theme.value = theme.value === 'light' ? 'dark' : 'light'
  document.documentElement.classList.toggle('dark', theme.value === 'dark')

  try {
    localStorage.setItem(THEME_KEY, theme.value)
  } catch {
    // Storage can be unavailable in private mode; the toggle still works for the session.
  }
}

export function useTheme() {
  return { theme, toggleTheme }
}
