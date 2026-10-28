<script setup>
import { computed } from 'vue'

const props = defineProps({
  method: { type: String, default: 'GET' },
})

const classes = computed(() => {
  const map = {
    GET: 'bg-sky-500/10 text-sky-700 ring-sky-500/25 dark:text-sky-300',
    POST: 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/25 dark:text-emerald-300',
    PUT: 'bg-amber-500/10 text-amber-700 ring-amber-500/25 dark:text-amber-300',
    PATCH: 'bg-violet-500/10 text-violet-700 ring-violet-500/25 dark:text-violet-300',
    DELETE: 'bg-rose-500/10 text-rose-700 ring-rose-500/25 dark:text-rose-300',
    OPTIONS: 'bg-ink/5 text-ink-mute ring-line',
  }

  return map[props.method?.toUpperCase()] || 'bg-ink/5 text-ink-mute ring-line'
})
</script>

<template>
  <span
    class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide ring-1 ring-inset"
    :class="classes"
  >
    {{ method }}
  </span>
</template>
