<script setup>
/**
 * Text field of our own.
 *
 * `type="search"` is not just a semantic hint: Chromium and WebKit paint their
 * own «×» clear button inside the field, in a size and colour the theme cannot
 * reach, and Safari rounds the corners on its own. Every search box in the app
 * therefore stays `type="text"` with `clearable` turned on, so the clear button
 * is ours — same icon, same hover, same position in both themes.
 */
import { computed } from 'vue'

const model = defineModel({ type: [String, Number], default: '' })

const props = defineProps({
  type: { type: String, default: 'text' },
  size: { type: String, default: 'md' },
  placeholder: { type: String, default: '' },
  disabled: Boolean,
  invalid: Boolean,
  /** Draws our own clear button; only meaningful for a text field. */
  clearable: Boolean,
  ariaLabel: { type: String, default: '' },
})

const sizes = {
  sm: 'rounded-lg px-2.5 py-1.5 text-xs',
  md: 'rounded-xl px-3 py-2 text-sm',
}

const showClear = computed(() => props.clearable && !props.disabled && String(model.value ?? '') !== '')

function clear() {
  model.value = ''
}
</script>

<template>
  <div class="relative w-full">
    <input
      v-model="model"
      :type="props.type"
      :placeholder="props.placeholder"
      :disabled="props.disabled"
      :aria-label="props.ariaLabel || undefined"
      spellcheck="false"
      autocomplete="off"
      class="w-full border bg-surface text-ink outline-none transition
        placeholder:text-ink-mute
        focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/15
        disabled:cursor-not-allowed disabled:opacity-60"
      :class="[
        sizes[props.size] ?? sizes.md,
        props.invalid ? 'border-rose-500/60' : 'border-line',
        showClear ? 'pr-8' : '',
      ]"
    />

    <button
      v-if="showClear"
      type="button"
      aria-label="Очистить поле"
      class="absolute right-1.5 top-1/2 grid h-5 w-5 -translate-y-1/2 place-items-center rounded-md
        text-ink-mute transition hover:bg-surface-2 hover:text-ink"
      @click="clear"
    >
      <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
        <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
      </svg>
    </button>
  </div>
</template>
