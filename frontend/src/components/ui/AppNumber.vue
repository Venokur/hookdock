<script setup>
/**
 * Number field with our own stepper.
 *
 * `type="number"` is not just a themed box: Chromium draws two OS spinner arrows
 * inside it, Windows highlights the field on scroll and the stepper's size/colour
 * cannot be set. The input here is `type="text"` with `inputmode="numeric"`, so
 * a phone still opens the numeric keyboard, and the two buttons beside it are
 * ours — they respect `min`/`max`, work in both themes and do not change on hover
 * because the OS decided to paint them.
 *
 * The model stays a number (or `''` when empty) rather than the string a text
 * field would produce, because every caller here sends `seconds`, `timeout` or
 * `priority` straight to the API.
 */
import { computed, ref, watch } from 'vue'

const model = defineModel({ type: [Number, String], default: '' })

const props = defineProps({
  min: { type: [Number, String], default: null },
  max: { type: [Number, String], default: null },
  step: { type: [Number, String], default: 1 },
  size: { type: String, default: 'md' },
  placeholder: { type: String, default: '' },
  disabled: Boolean,
  invalid: Boolean,
  /** Unused today, kept so a caller can say «15 с» without a wrapping label. */
  suffix: { type: String, default: '' },
  ariaLabel: { type: String, default: '' },
})

const sizes = {
  sm: 'rounded-lg py-1.5 text-xs',
  md: 'rounded-xl py-2 text-sm',
}

const buttons = {
  sm: 'h-[26px] w-6',
  md: 'h-[30px] w-7',
}

const numericMin = computed(() => (props.min === null || props.min === '' ? null : Number(props.min)))
const numericMax = computed(() => (props.max === null || props.max === '' ? null : Number(props.max)))
const stepSize = computed(() => Number(props.step) || 1)

function toText(value) {
  if (value === '' || value === null || value === undefined) {
    return ''
  }

  const number = Number(value)

  return Number.isFinite(number) ? String(number) : ''
}

/**
 * What the operator is typing is kept as text verbatim: «1.» must not be
 * rewritten to «1» while the rest of the number is still coming.
 */
const text = ref(toText(model.value))

watch(
  () => model.value,
  (value) => {
    if (toText(value) !== toText(text.value)) {
      text.value = toText(value)
    }
  },
)

const atMin = computed(() => numericMin.value !== null && Number(model.value) <= numericMin.value)
const atMax = computed(() => numericMax.value !== null && Number(model.value) >= numericMax.value)

const busy = computed(() => props.disabled)
function clamp(value) {
  let next = value

  if (numericMin.value !== null) {
    next = Math.max(numericMin.value, next)
  }

  if (numericMax.value !== null) {
    next = Math.min(numericMax.value, next)
  }

  return next
}

function commit(raw) {
  const trimmed = String(raw).trim()

  if (trimmed === '') {
    model.value = ''
    return
  }

  const parsed = Number(trimmed.replace(',', '.'))

  if (!Number.isFinite(parsed)) {
    // Not a number: restore the last good value instead of sending `NaN` to the
    // API, which would arrive as `null` and read as "no value" there.
    text.value = toText(model.value)
    return
  }

  const next = clamp(parsed)

  model.value = next
  text.value = String(next)
}

function onInput(event) {
  text.value = event.target.value

  const trimmed = text.value.trim()

  // An empty field is a legitimate answer (`timeout: ''` means "as the server
  // says"), so only a complete number is written through while typing.
  if (trimmed === '') {
    model.value = ''
    return
  }

  const parsed = Number(trimmed.replace(',', '.'))

  if (Number.isFinite(parsed)) {
    model.value = parsed
  }
}

function onBlur(event) {
  commit(event.target.value)
}

function nudge(delta) {
  if (busy.value) {
    return
  }

  const base = Number(model.value)
  const start = Number.isFinite(base) ? base : (numericMin.value ?? 0)

  model.value = clamp(start + delta)
  text.value = toText(model.value)
}
</script>

<template>
  <div
    class="flex w-full items-center gap-1 border bg-surface transition
      focus-within:border-indigo-500 focus-within:ring-2 focus-within:ring-indigo-500/15"
    :class="[
      props.size === 'sm' ? 'rounded-lg' : 'rounded-xl',
      props.invalid ? 'border-rose-500/60' : 'border-line',
      props.disabled ? 'cursor-not-allowed opacity-60' : '',
    ]"
  >
    <button
      type="button"
      :disabled="busy || atMin"
      :aria-label="`Уменьшить${props.ariaLabel ? ': ' + props.ariaLabel : ''}`"
      class="ml-1 grid shrink-0 place-items-center rounded-md text-ink-mute transition hover:bg-surface-2 hover:text-ink
        disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent"
      :class="buttons[props.size] ?? buttons.md"
      @click="nudge(-stepSize)"
    >
      <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4">
        <path stroke-linecap="round" d="M5 12h14" />
      </svg>
    </button>

    <input
      :value="text"
      type="text"
      inputmode="numeric"
      autocomplete="off"
      :placeholder="props.placeholder"
      :disabled="props.disabled"
      :aria-label="props.ariaLabel || undefined"
      class="min-w-0 flex-1 border-0 bg-transparent px-1 text-center font-mono text-ink outline-none
        placeholder:text-ink-mute placeholder:font-sans"
      :class="sizes[props.size] ?? sizes.md"
      @input="onInput"
      @blur="onBlur"
      @keydown.up.prevent="nudge(stepSize)"
      @keydown.down.prevent="nudge(-stepSize)"
    />

    <span v-if="props.suffix" class="shrink-0 pr-1 text-[11px] text-ink-mute">{{ props.suffix }}</span>

    <button
      type="button"
      :disabled="busy || atMax"
      :aria-label="`Увеличить${props.ariaLabel ? ': ' + props.ariaLabel : ''}`"
      class="mr-1 grid shrink-0 place-items-center rounded-md text-ink-mute transition hover:bg-surface-2 hover:text-ink
        disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent"
      :class="buttons[props.size] ?? buttons.md"
      @click="nudge(stepSize)"
    >
      <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4">
        <path stroke-linecap="round" d="M12 5v14M5 12h14" />
      </svg>
    </button>
  </div>
</template>
