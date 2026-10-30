<script setup>
import { computed } from 'vue'

const props = defineProps({
  status: { type: String, default: '' },
})

const labels = {
  pending: 'в очереди',
  running: 'выполняется',
  success: 'успех',
  failed: 'ошибка',
  skipped: 'пропущен',
}

const classes = computed(() => {
  const map = {
    pending: 'bg-ink/5 text-ink-mute ring-line',
    running: 'bg-indigo-500/10 text-indigo-700 ring-indigo-500/25 dark:text-indigo-300',
    success: 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/25 dark:text-emerald-300',
    failed: 'bg-rose-500/10 text-rose-700 ring-rose-500/25 dark:text-rose-300',
    skipped: 'bg-ink/5 text-ink-mute ring-line',
  }

  return map[props.status] || 'bg-ink/5 text-ink-mute ring-line'
})

const label = computed(() => labels[props.status] || props.status)
</script>

<template>
  <span
    v-if="status"
    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset"
    :class="classes"
  >
    <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
    {{ label }}
  </span>
</template>
