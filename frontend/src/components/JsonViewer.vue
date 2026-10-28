<script setup>
import { computed } from 'vue'

const props = defineProps({
  value: { type: null, default: null },
  emptyText: { type: String, default: 'Нет данных' },
})

const pretty = computed(() => {
  const value = props.value

  if (value === null || value === undefined || value === '') {
    return ''
  }

  if (typeof value === 'string') {
    try {
      return JSON.stringify(JSON.parse(value), null, 2)
    } catch {
      return value
    }
  }

  return JSON.stringify(value, null, 2)
})

function escapeHtml(text) {
  return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

const highlighted = computed(() => {
  const json = escapeHtml(pretty.value)

  return json.replace(
    /("(?:\\u[a-fA-F0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(?:true|false|null)\b|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/g,
    (match) => {
      let cls = 'text-amber-700 dark:text-amber-300'
      if (match.startsWith('"')) {
        cls = match.endsWith(':')
          ? 'text-sky-700 dark:text-sky-300'
          : 'text-emerald-700 dark:text-emerald-300'
      } else if (match === 'true' || match === 'false') {
        cls = 'text-fuchsia-700 dark:text-fuchsia-300'
      } else if (match === 'null') {
        cls = 'text-ink-mute'
      }

      return `<span class="${cls}">${match}</span>`
    },
  )
})
</script>

<template>
  <pre
    v-if="pretty"
    class="max-h-[28rem] overflow-auto rounded-xl border border-line bg-surface-2 p-4 font-mono text-[12px] leading-relaxed text-ink"
  ><code v-html="highlighted"></code></pre>
  <p v-else class="rounded-xl border border-dashed border-line bg-surface-2 p-4 text-sm text-ink-mute">
    {{ emptyText }}
  </p>
</template>
