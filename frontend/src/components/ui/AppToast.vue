<script setup>
import { dismissToast, useDialog } from '../../composables/dialog'

const { toasts } = useDialog()
</script>

<template>
  <Teleport to="body">
    <div
      class="pointer-events-none fixed right-4 top-4 z-[60] flex w-full max-w-sm flex-col gap-2"
      aria-live="polite"
    >
      <TransitionGroup name="toast">
        <div
          v-for="toast in toasts"
          :key="toast.id"
          class="card pointer-events-auto flex items-start gap-2.5 overflow-hidden px-4 py-3 shadow-lg shadow-slate-900/10 dark:shadow-none"
          :role="toast.tone === 'error' ? 'alert' : 'status'"
        >
          <span
            class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full"
            :class="
              toast.tone === 'error'
                ? 'bg-rose-500/10 text-rose-600 dark:text-rose-300'
                : 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-300'
            "
          >
            <svg
              v-if="toast.tone === 'error'"
              class="h-3.5 w-3.5"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2.2"
            >
              <path stroke-linecap="round" d="M12 8v5m0 3.5h.01" />
              <circle cx="12" cy="12" r="9" />
            </svg>
            <svg v-else class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
              <path stroke-linecap="round" stroke-linejoin="round" d="m5 12.5 4.5 4.5L19 7.5" />
            </svg>
          </span>

          <p class="min-w-0 flex-1 whitespace-pre-line break-words text-xs leading-relaxed text-ink">
            {{ toast.message }}
          </p>

          <button
            type="button"
            class="-mr-1 shrink-0 rounded-lg p-1 text-ink-mute transition hover:bg-surface-2 hover:text-ink"
            aria-label="Закрыть сообщение"
            @click="dismissToast(toast.id)"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
            </svg>
          </button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>
