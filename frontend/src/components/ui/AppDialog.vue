<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { answerConfirm, useDialog } from '../../composables/dialog'

const { dialog } = useDialog()

const cancelButton = ref(null)

// Focus lands on the safe choice: a stray Enter dismisses instead of deleting.
watch(
  () => dialog.open,
  async (open) => {
    if (!open) {
      window.removeEventListener('keydown', onKeydown)
      return
    }

    window.addEventListener('keydown', onKeydown)
    await nextTick()
    cancelButton.value?.focus()
  },
)

function onKeydown(event) {
  if (event.key === 'Escape') {
    event.stopPropagation()
    answerConfirm(false)
  }
}

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="dialog.open"
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/30 p-4 backdrop-blur-sm dark:bg-slate-950/80"
        @click.self="answerConfirm(false)"
      >
        <div
          role="alertdialog"
          aria-modal="true"
          aria-labelledby="app-dialog-title"
          aria-describedby="app-dialog-message"
          class="card w-full max-w-sm overflow-hidden"
        >
          <div class="px-6 pb-4 pt-6">
            <div class="flex items-start gap-3">
              <span
                class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-full"
                :class="
                  dialog.tone === 'danger'
                    ? 'bg-rose-500/10 text-rose-600 dark:text-rose-300'
                    : 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-300'
                "
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"
                  />
                </svg>
              </span>

              <div class="min-w-0">
                <h2 id="app-dialog-title" class="text-sm font-semibold text-ink">
                  {{ dialog.title }}
                </h2>
                <p id="app-dialog-message" class="mt-1.5 text-xs leading-relaxed text-ink-soft">
                  {{ dialog.message }}
                </p>
              </div>
            </div>
          </div>

          <footer class="flex items-center justify-end gap-2 border-t border-line bg-surface-2 px-6 py-4">
            <button ref="cancelButton" type="button" class="btn" @click="answerConfirm(false)">
              {{ dialog.cancelLabel }}
            </button>
            <button
              type="button"
              :class="dialog.tone === 'danger' ? 'btn-danger-solid' : 'btn-primary'"
              @click="answerConfirm(true)"
            >
              {{ dialog.confirmLabel }}
            </button>
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
