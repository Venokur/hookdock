<script setup>
import { computed, ref } from 'vue'
import JsonViewer from './JsonViewer.vue'
import MethodBadge from './MethodBadge.vue'
import StatusPill from './StatusPill.vue'
import { formatBytes, formatTime } from '../utils/format'

const props = defineProps({
  request: { type: Object, required: true },
  runs: { type: Array, default: () => [] },
})

const emit = defineEmits(['close', 'delete'])

const tab = ref('payload')
const copied = ref(false)

const tabs = computed(() => {
  const list = [
    { key: 'payload', label: 'Тело' },
    { key: 'headers', label: 'Заголовки' },
    { key: 'query', label: 'Query' },
    { key: 'meta', label: 'Метаданные' },
  ]

  if (props.runs.length > 0) {
    list.splice(3, 0, { key: 'runs', label: `Маршрутизация (${props.runs.length})` })
  }

  return list
})

const payload = computed(() => {
  if (props.request.body !== null && props.request.body !== undefined) {
    return props.request.body
  }

  return props.request.raw_body || null
})

const curl = computed(() => {
  const request = props.request
  const origin = typeof window !== 'undefined' ? window.location.origin : ''
  const lines = [`curl -X ${request.method} '${origin}${request.path}${queryString(request.query)}'`]

  if (request.content_type) {
    lines.push(`-H 'Content-Type: ${request.content_type}'`)
  }
  if (request.raw_body) {
    lines.push(`--data '${String(request.raw_body).replace(/'/g, "'\\''")}'`)
  }

  return lines.join(' \\\n  ')
})

const metaRows = computed(() => [
  ['ID', `#${props.request.id}`],
  ['Эндпоинт', props.request.endpoint],
  ['Метод', props.request.method],
  ['Путь', props.request.path],
  ['IP-адрес', props.request.ip],
  ['Content-Type', props.request.content_type || '—'],
  ['Размер тела', formatBytes(props.request.body_bytes)],
  ['Получен', formatTime(props.request.received_at)],
])

function queryString(query) {
  if (!query || typeof query !== 'object') {
    return ''
  }

  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(query)) {
    search.set(key, typeof value === 'object' ? JSON.stringify(value) : String(value))
  }

  const encoded = search.toString()

  return encoded ? `?${encoded}` : ''
}

async function copyCurl() {
  try {
    await navigator.clipboard.writeText(curl.value)
    copied.value = true
    setTimeout(() => (copied.value = false), 1500)
  } catch {
    // Буфер обмена может быть недоступен.
  }
}
</script>

<template>
  <div class="flex h-full flex-col">
    <header class="border-b border-line p-5">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <MethodBadge :method="request.method" />
            <span class="text-xs text-ink-mute">#{{ request.id }}</span>
          </div>
          <p class="mt-2 break-all font-mono text-sm text-ink">{{ request.path }}</p>
          <p class="mt-1 text-xs text-ink-mute">
            {{ request.endpoint }} · {{ request.ip }} · {{ formatTime(request.received_at) }}
          </p>
        </div>
        <button
          type="button"
          class="rounded-lg p-1.5 text-ink-mute transition hover:bg-surface-2 hover:text-ink"
          aria-label="Закрыть"
          @click="emit('close')"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
          </svg>
        </button>
      </div>

      <nav class="mt-4 flex flex-wrap gap-1">
        <button
          v-for="item in tabs"
          :key="item.key"
          type="button"
          class="rounded-lg px-2.5 py-1.5 text-xs font-medium transition"
          :class="tab === item.key
            ? 'bg-indigo-50 text-indigo-700 dark:bg-white/10 dark:text-white'
            : 'text-ink-soft hover:bg-surface-2 hover:text-ink'"
          @click="tab = item.key"
        >
          {{ item.label }}
        </button>
      </nav>
    </header>

    <div class="flex-1 overflow-y-auto p-5">
      <JsonViewer v-if="tab === 'payload'" :value="payload" empty-text="У этого запроса нет тела." />

      <JsonViewer
        v-else-if="tab === 'headers'"
        :value="request.headers || {}"
        empty-text="Заголовки не сохранены."
      />

      <JsonViewer v-else-if="tab === 'query'" :value="request.query || {}" empty-text="Нет query-параметров." />

      <div v-else-if="tab === 'runs'" class="space-y-2">
        <div
          v-for="run in runs"
          :key="run.id"
          class="rounded-xl border border-line bg-surface-2 p-3"
        >
          <div class="flex items-center justify-between gap-2">
            <p class="text-sm text-ink">{{ run.workflow_name }}</p>
            <StatusPill :status="run.status" />
          </div>
          <p class="mt-1 break-words font-mono text-[11px] text-ink-mute">{{ run.message || '—' }}</p>
          <p v-if="run.attempts > 1" class="mt-1 text-[11px] text-ink-mute">попыток: {{ run.attempts }}</p>

          <!-- Step log: one row per action per attempt, so a retried forward and
               the response it got are visible instead of collapsed into a string. -->
          <details v-if="(run.steps ?? []).length" class="mt-2 border-t border-line pt-2">
            <summary class="cursor-pointer text-[11px] font-medium text-ink-mute">
              Шаги ({{ run.steps.length }})
            </summary>
            <ul class="mt-2 space-y-1.5">
              <li
                v-for="step in run.steps"
                :key="step.id"
                class="rounded-lg border border-line bg-surface px-2.5 py-2"
              >
                <div class="flex flex-wrap items-center gap-2 text-[11px]">
                  <span class="font-mono text-ink-mute">{{ step.action_index + 1 }}.</span>
                  <span class="font-medium text-ink-soft">{{ step.type }}</span>
                  <StatusPill :status="step.status" />
                  <span v-if="step.attempt > 1" class="text-ink-mute">попытка {{ step.attempt }}</span>
                  <span v-if="step.response_status" class="font-mono text-ink-mute">HTTP {{ step.response_status }}</span>
                  <span class="ml-auto font-mono text-[10px] text-ink-mute">{{ step.duration_ms }} мс</span>
                </div>
                <p class="mt-1 break-words font-mono text-[11px] text-ink-mute">{{ step.message || '—' }}</p>
                <pre
                  v-if="step.response_body"
                  class="mt-1 max-h-32 overflow-auto rounded-md bg-surface-2 p-2 font-mono text-[10px] text-ink-soft"
                >{{ step.response_body }}</pre>
              </li>
            </ul>
          </details>
        </div>
      </div>

      <dl v-else class="grid grid-cols-1 gap-px overflow-hidden rounded-xl border border-line bg-line sm:grid-cols-2">
        <div v-for="[label, value] in metaRows" :key="label" class="bg-surface px-4 py-3">
          <dt class="text-[11px] uppercase tracking-wider text-ink-mute">{{ label }}</dt>
          <dd class="mt-1 break-all font-mono text-xs text-ink-soft">{{ value }}</dd>
        </div>
      </dl>
    </div>

    <footer class="flex items-center justify-between gap-3 border-t border-line p-4">
      <button type="button" class="btn" @click="copyCurl">
        {{ copied ? 'Скопировано!' : 'Копировать как cURL' }}
      </button>
      <button type="button" class="btn-danger py-2" @click="emit('delete', request.id)">
        Удалить запрос
      </button>
    </footer>
  </div>
</template>
