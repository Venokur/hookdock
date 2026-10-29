import { reactive, ref } from 'vue'

/**
 * App-wide replacement for `window.alert` and `window.confirm`.
 *
 * One `AppToast`/`AppDialog` pair is mounted in `App.vue`, so any component can
 * raise a message or ask for confirmation without owning markup for it. The
 * state lives at module scope (like `composables/theme.js`) because it is UI
 * state, not session data.
 */

const TOAST_LIMIT = 3
const TOAST_TIMEOUT = 6000

const toasts = ref([])
let toastSeq = 0

const dialog = reactive({
  open: false,
  title: '',
  message: '',
  confirmLabel: 'Удалить',
  cancelLabel: 'Отмена',
  tone: 'danger',
})

/** Resolver of the `confirm()` call currently on screen. */
let resolveCurrent = null

/**
 * Shows a short message in the corner. Errors are the common case, so that is
 * the default tone; success/info pass `tone: 'info'`.
 */
export function notify(message, { tone = 'error', timeout = TOAST_TIMEOUT } = {}) {
  const text = String(message ?? '').trim()
  if (text === '') {
    return null
  }

  const id = ++toastSeq
  toasts.value = [...toasts.value, { id, message: text, tone }].slice(-TOAST_LIMIT)

  if (timeout > 0) {
    setTimeout(() => dismissToast(id), timeout)
  }

  return id
}

export function dismissToast(id) {
  toasts.value = toasts.value.filter((toast) => toast.id !== id)
}

/**
 * Resolves `true` only when the user presses the confirm button; closing with
 * Esc, the backdrop or the cancel button resolves `false`. A second call while
 * a dialog is open cancels the previous one instead of leaving its promise
 * pending forever.
 */
export function confirm({
  title = 'Подтвердите действие',
  message = '',
  confirmLabel = 'Удалить',
  cancelLabel = 'Отмена',
  tone = 'danger',
} = {}) {
  return new Promise((resolve) => {
    resolveCurrent?.(false)

    resolveCurrent = resolve
    Object.assign(dialog, {
      open: true,
      title,
      message,
      confirmLabel,
      cancelLabel,
      tone,
    })
  })
}

export function answerConfirm(value) {
  const resolve = resolveCurrent
  resolveCurrent = null
  dialog.open = false

  resolve?.(Boolean(value))
}

export function useDialog() {
  return { toasts, notify, dismissToast, dialog, confirm, answerConfirm }
}
