<script setup>
/**
 * Collapsible section with our own header.
 *
 * `<details>/<summary>` is drawn by the browser: the marker is a system triangle
 * whose position, size and colour differ between engines, `summary` cannot be
 * styled to match the surrounding text and the open/close animation is not
 * controllable. This is the same behaviour (a header button that reveals a
 * region) with a chevron of ours that rotates.
 *
 * The content stays in the DOM while closed (`v-show`), so a nested scroll
 * position or a focused field is not lost by collapsing the section.
 */
import { ref, useId } from 'vue'

const props = defineProps({
  label: { type: String, default: '' },
  /** Starts open, for a section whose content is the point of the page. */
  open: Boolean,
  /** A short count or state on the right of the header. */
  hint: { type: String, default: '' },
})

const expanded = ref(props.open)
const regionId = `disclosure-${useId()}`
</script>

<template>
  <div>
    <button
      type="button"
      :aria-expanded="expanded"
      :aria-controls="regionId"
      class="flex w-full items-center gap-2 text-left"
      @click="expanded = !expanded"
    >
      <svg
        class="h-3 w-3 shrink-0 text-ink-mute transition-transform duration-150"
        :class="{ 'rotate-90': expanded }"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2.2"
        aria-hidden="true"
      >
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6" />
      </svg>

      <span class="text-[11px] font-medium text-ink-mute transition hover:text-ink">
        <slot name="label">{{ props.label }}</slot>
      </span>

      <span v-if="props.hint" class="ml-auto text-[10px] text-ink-mute">{{ props.hint }}</span>
    </button>

    <div v-show="expanded" :id="regionId" class="mt-2">
      <slot />
    </div>
  </div>
</template>
