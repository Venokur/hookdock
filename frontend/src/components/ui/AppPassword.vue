<script setup>
/**
 * Secret field: masked by default, with our own «показать» toggle.
 *
 * `type="password"` alone is drawn by the browser — Edge/Chrome add their own
 * reveal eye, Firefox has none, and neither follows the theme. Here the masking
 * is still the native behaviour (so autofill and password managers keep
 * working), but the toggle is a themed button of ours and it is present in every
 * browser. A secret that can never be read back is a secret that gets pasted
 * wrong, so the reveal is deliberate rather than a browser extra.
 *
 * `autocomplete` is passed through: the login form needs `current-password`,
 * while a new API secret should not be remembered by the password manager.
 */
import { ref } from 'vue'

const model = defineModel({ type: [String, Number], default: '' })

const props = defineProps({
  size: { type: String, default: 'md' },
  placeholder: { type: String, default: '' },
  disabled: Boolean,
  invalid: Boolean,
  autocomplete: { type: String, default: 'new-password' },
  ariaLabel: { type: String, default: '' },
})

const revealed = ref(false)

const sizes = {
  sm: 'rounded-lg py-1.5 text-xs',
  md: 'rounded-xl py-2 text-sm',
}
</script>

<template>
  <div
    class="flex w-full items-center border bg-surface transition
      focus-within:border-indigo-500 focus-within:ring-2 focus-within:ring-indigo-500/15"
    :class="[
      props.size === 'sm' ? 'rounded-lg' : 'rounded-xl',
      props.invalid ? 'border-rose-500/60' : 'border-line',
      props.disabled ? 'cursor-not-allowed opacity-60' : '',
    ]"
  >
    <input
      v-model="model"
      :type="revealed ? 'text' : 'password'"
      :placeholder="props.placeholder"
      :disabled="props.disabled"
      :autocomplete="props.autocomplete"
      :aria-label="props.ariaLabel || undefined"
      spellcheck="false"
      class="min-w-0 flex-1 border-0 bg-transparent px-3 text-ink outline-none
        placeholder:text-ink-mute"
      :class="sizes[props.size] ?? sizes.md"
    />

    <button
      type="button"
      :disabled="props.disabled"
      :aria-label="revealed ? 'Скрыть значение' : 'Показать значение'"
      :aria-pressed="revealed"
      class="mr-1 grid h-6 w-6 shrink-0 place-items-center rounded-md text-ink-mute transition
        hover:bg-surface-2 hover:text-ink disabled:cursor-not-allowed disabled:opacity-40"
      @click="revealed = !revealed"
    >
      <!-- Open eye = «is revealed now», so the icon states the current mode. -->
      <svg v-if="revealed" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9">
        <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" />
        <circle cx="12" cy="12" r="3" />
      </svg>
      <svg v-else class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3.5 3.5l17 17" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M10.4 5.9A9.7 9.7 0 0 1 12 5.5c6 0 9.5 6.5 9.5 6.5a17 17 0 0 1-3.1 4M6.4 7.7A16.9 16.9 0 0 0 2.5 12S6 18.5 12 18.5a9.6 9.6 0 0 0 3.8-.8" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M9.9 10.1a3 3 0 0 0 4.1 4.2" />
      </svg>
    </button>
  </div>
</template>
