<script setup>
import { computed } from 'vue'

const model = defineModel({ type: Boolean, default: false })

const props = defineProps({
  label: { type: String, default: '' },
  description: { type: String, default: '' },
  disabled: Boolean,
  /**
   * Renders the "some, not all" dash. Native checkboxes support this only through
   * a DOM property, so it cannot be bound as an attribute — and a table's
   * "select all" header is exactly the control that needs a third state, where a
   * plain unchecked box would claim that nothing is selected.
   */
  indeterminate: Boolean,
})

const box = computed(() => (props.indeterminate && !model.value ? 'dash' : 'check'))
</script>

<template>
  <!-- `items-start` + a 16px box against the 16px line height of `text-xs`:
       the box and the first text line share both their top and their bottom, so
       the label reads as centred on the box. The box used to carry `mt-0.5`,
       which pushed it 2px below the text — the label then looked like it hung
       off the bottom of the checkbox. Do not reintroduce an offset here; if a
       line height ever changes, change the box and the line together. -->
  <label class="inline-flex cursor-pointer select-none items-start gap-2 text-left align-middle">
    <input
      v-model="model"
      type="checkbox"
      class="peer sr-only"
      :disabled="props.disabled"
      :aria-checked="props.indeterminate ? 'mixed' : model"
    />

    <span
      class="grid h-4 w-4 shrink-0 place-items-center rounded-[5px] border bg-surface transition
        border-line
        peer-hover:border-ink-mute
        peer-checked:border-indigo-600 peer-checked:bg-indigo-600
        peer-focus-visible:ring-2 peer-focus-visible:ring-indigo-500/30 peer-focus-visible:ring-offset-2
        peer-focus-visible:ring-offset-canvas
        peer-disabled:cursor-not-allowed peer-disabled:opacity-50
        dark:peer-checked:border-indigo-500 dark:peer-checked:bg-indigo-500"
      :class="{ 'border-indigo-600 bg-indigo-600 dark:border-indigo-500 dark:bg-indigo-500': box === 'dash' }"
      aria-hidden="true"
    >
      <svg
        v-if="box === 'check'"
        class="h-3 w-3 text-white"
        viewBox="0 0 16 16"
        fill="none"
        stroke="currentColor"
        stroke-width="2.5"
        stroke-linecap="round"
        stroke-linejoin="round"
      >
        <path d="M3.5 8.5l3 3 6-7" />
      </svg>
      <svg
        v-else
        class="h-3 w-3 text-white"
        viewBox="0 0 16 16"
        fill="none"
        stroke="currentColor"
        stroke-width="2.5"
        stroke-linecap="round"
      >
        <path d="M4 8h8" />
      </svg>
    </span>

    <span v-if="props.label || props.description" class="min-w-0">
      <span v-if="props.label" class="block text-xs text-ink-soft">{{ props.label }}</span>
      <span v-if="props.description" class="mt-0.5 block text-[11px] text-ink-mute">{{ props.description }}</span>
    </span>
  </label>
</template>
