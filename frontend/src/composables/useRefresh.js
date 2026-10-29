import { ref } from 'vue'

/**
 * Wraps a manual refresh so the click is visible.
 *
 * A local API answers in a few milliseconds, which would make the spinner flash
 * for one frame and read as "nothing happened" — so `run` keeps `busy` set for
 * at least `minDuration`. Only user-triggered refreshes go through this; the
 * background reloads driven by realtime events call the loader directly and
 * must not make the UI flicker.
 */
export function useRefresh(load, { minDuration = 350 } = {}) {
  const busy = ref(false)
  const updatedAt = ref(null)

  async function run() {
    // A second click while the first request is in flight would only queue more work.
    if (busy.value) {
      return
    }

    busy.value = true
    const started = Date.now()

    try {
      await load()
      updatedAt.value = new Date()
    } finally {
      const rest = minDuration - (Date.now() - started)
      if (rest > 0) {
        await new Promise((resolve) => setTimeout(resolve, rest))
      }

      busy.value = false
    }
  }

  return { busy, updatedAt, run }
}
