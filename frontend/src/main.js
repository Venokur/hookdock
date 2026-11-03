import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { initTheme } from './composables/theme'
import './assets/main.css'

/**
 * The splash lives in index.html so it is painted before this file arrives; its
 * job ends when the first screen is actually renderable.
 *
 * A route view is its own lazy `import()`, and the guard in `router/index.js`
 * waits for `rooms.load()` on a cold start, so `router.isReady()` is the honest
 * "everything is there" signal — it resolves after the chunk and the guard. The
 * minimum keeps a fast local API from producing a flash of a splash nobody had
 * time to read; it is measured from `window.__splashAt` (stamped in index.html)
 * rather than from the module's own execution, so the wait shrinks as the load
 * gets slower instead of stacking on top of it.
 */
const SPLASH_MIN_MS = 350
const SPLASH_FADE_MS = 180

initTheme()

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.mount('#app')

function dismissSplash() {
  const splash = document.getElementById('splash')

  if (!splash) {
    return
  }

  const paintedAt = typeof window.__splashAt === 'number' ? window.__splashAt : performance.now()
  const rest = SPLASH_MIN_MS - (performance.now() - paintedAt)

  window.setTimeout(() => {
    splash.classList.add('is-done')
    window.setTimeout(() => splash.remove(), SPLASH_FADE_MS)
  }, Math.max(0, rest))
}

// A failed navigation must still reveal the page (it renders its own error and
// retry), so the rejection handler is the same as the success one.
router.isReady().then(dismissSplash, dismissSplash)
