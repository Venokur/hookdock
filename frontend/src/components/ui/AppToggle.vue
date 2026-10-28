<script setup>
const model = defineModel({ type: Boolean, default: false })

const props = defineProps({
  disabled: Boolean,
})
</script>

<template>
  <button
    type="button"
    role="switch"
    :aria-checked="model"
    :disabled="props.disabled"
    class="relative h-5 w-9 shrink-0 rounded-full transition
      focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/40
      focus-visible:ring-offset-2 focus-visible:ring-offset-canvas
      disabled:cursor-not-allowed disabled:opacity-50"
    :class="model ? 'bg-indigo-600 dark:bg-indigo-500' : 'bg-ink/20'"
    @click="model = !model"
  >
    <span
      class="absolute top-0.5 h-4 w-4 rounded-full bg-white shadow-sm transition-all"
      :class="model ? 'left-[1.15rem]' : 'left-0.5'"
    ></span>
  </button>
</template>
