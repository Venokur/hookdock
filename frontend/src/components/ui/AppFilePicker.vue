<script setup>
/**
 * File picker that opens our own dialog.
 *
 * A bare `<input type="file">` renders the OS button («Choose files» / «Обзор…»)
 * with the system font, and the hidden-input-behind-a-styled-button trick still
 * ends with the OS file dialog — which is fine, that part *is* the OS, but the
 * trigger has to look like every other control in the app. This component owns
 * the trigger, the accepted types, and the "which file did I pick" line, so a
 * caller only listens to `@change`.
 *
 * The real input stays in the DOM (not `display: none`) because some browsers
 * refuse `.click()` on a hidden input — it is visually hidden and still
 * keyboard-reachable through the trigger button.
 */
import { ref } from 'vue'

const props = defineProps({
  accept: { type: String, default: '' },
  label: { type: String, default: 'Выбрать файл' },
  disabled: Boolean,
  /** A picker that is the main action of its block reads as a primary button. */
  variant: { type: String, default: 'secondary' },
})

const emit = defineEmits(['change'])
const inputRef = ref(null)
const chosen = ref(null)

function pick() {
  if (props.disabled) {
    return
  }

  inputRef.value?.click()
}

function onChange(event) {
  const file = event.target.files?.[0] ?? null
  chosen.value = file
  // The same file has to be choosable twice (a re-import after a failed attempt),
  // and a held value would swallow the second `change`.
  event.target.value = ''
  emit('change', file)
}
</script>

<template>
  <div class="flex items-center gap-2">
    <input
      ref="inputRef"
      :accept="props.accept"
      type="file"
      class="sr-only"
      tabindex="-1"
      aria-hidden="true"
      @change="onChange"
    />

    <button
      type="button"
      :class="props.variant === 'primary' ? 'btn-primary' : 'btn'"
      :disabled="props.disabled"
      @click="pick"
    >
      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L8 8m4-4l4 4" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 15v3a2 2 0 002 2h12a2 2 0 002-2v-3" />
      </svg>
      {{ props.label }}
    </button>

    <span v-if="chosen" class="min-w-0 truncate font-mono text-[11px] text-ink-mute" :title="chosen.name">
      {{ chosen.name }}
    </span>
  </div>
</template>
