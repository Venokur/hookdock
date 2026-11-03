<script setup>
import { computed, ref } from 'vue'
import JsonViewer from './JsonViewer.vue'
import MethodBadge from './MethodBadge.vue'
import StatusPill from './StatusPill.vue'
import AppSpinner from './ui/AppSpinner.vue'
import AppInput from './ui/AppInput.vue'
import AppSelect from './ui/AppSelect.vue'
import AppTextarea from './ui/AppTextarea.vue'
import { api } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { notify } from '../composables/dialog'
import { formatBytes, formatTime } from '../utils/format'

const props = defineProps({
  request: { type: Object, required: true },
  runs: { type: Array, default: () => [] },
})

const emit = defineEmits(['close', 'delete', 'replay', 'retry', 'redelivered'])

// The store, not a prop: every mutating control in the app asks the same question,
// and threading a boolean through three components would drift.
const auth = useAuthStore()

const tab = ref('payload')
const copied = ref(false)

const downloading = ref(false)

const previewing = ref(false)
const previewError = ref('')
const preview = ref(null)

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

  list.push({ key: 'preview', label: 'Проверка правил' })

  return list
})

/**
 * Dry run: which rules match this payload and what each of them would send.
 *
 * This is the question the routing tab cannot answer — it only shows what already
 * happened. A rule added after the request arrived, or one that was paused, is
 * invisible there, and checking it used to mean sending a real webhook to a real
 * target. Nothing is queued here, so a wrong URL costs nothing.
 */
async function runPreview() {
  previewError.value = ''
  preview.value = null
  previewing.value = true

  try {
    const data = await api.previewRequest(props.request.id, {})
    preview.value = data.data
  } catch (e) {
    previewError.value = e.message
  } finally {
    previewing.value = false
  }
}

/**
 * Saves the stored body as a file.
 *
 * The card can copy a payload, but a large JSON belongs in an editor, and this is
 * the original bytes (`raw_body`), not a re-serialized copy of the parsed JSON.
 */
async function downloadBody() {
  downloading.value = true

  try {
    const { blob, filename } = await api.downloadRequestBody(props.request.id)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    link.remove()
    // Revoking immediately can cancel the download in some browsers.
    setTimeout(() => URL.revokeObjectURL(url), 10_000)
  } catch (e) {
    notify(e.message, { tone: 'error' })
  } finally {
    downloading.value = false
  }
}


const payload = computed(() => {
  if (props.request.body !== null && props.request.body !== undefined) {
    return props.request.body
  }

  return props.request.raw_body || null
})

/**
 * "Send it again with corrections."
 *
 * `replay` re-routes the payload that is stored — right for "the rule was wrong",
 * useless for "the payload was wrong". This panel edits a *copy*: the original
 * row stays exactly as the sender sent it, because the log is the evidence and a
 * run's step log must never describe a payload that never arrived.
 *
 * The draft starts from the stored payload and is only populated when the panel
 * opens, so a failed save does not leave the operator's edits behind in the next
 * request they open.
 */
const redeliverOpen = ref(false)
const redelivering = ref(false)
const redeliverError = ref('')
const redelivery = ref({ body: '', endpoint: '', method: 'POST', path: '' })

const redeliveryIsJson = computed(() => isJsonBody(props.request.content_type))

function isJsonBody(contentType) {
  const type = String(contentType || '').toLowerCase().split(';')[0].trim()

  return type === 'application/json' || type.endsWith('+json')
}

function openRedeliver() {
  redeliverError.value = ''
  redelivery.value = {
    body: props.request.raw_body ?? '',
    endpoint: props.request.endpoint ?? '',
    method: props.request.method ?? 'POST',
    path: props.request.path ?? '',
  }
  redeliverOpen.value = true
}

function closeRedeliver() {
  redeliverOpen.value = false
  redeliverError.value = ''
}

/**
 * Sends the corrected copy and hands the new id back to the list, which opens it.
 *
 * JSON is validated here rather than on the server: the API answers 422 with a
 * generic message, and "ожидался JSON" next to the field the operator is typing
 * in is the only place that sentence is useful.
 */
async function submitRedeliver() {
  redeliverError.value = ''

  const body = redelivery.value.body
  if (redeliveryIsJson.value && body.trim() !== '') {
    try {
      JSON.parse(body)
    } catch {
      redeliverError.value = 'Тело не является корректным JSON'
      return
    }
  }

  redelivering.value = true

  try {
    const draft = {
      raw_body: body,
      endpoint: redelivery.value.endpoint.trim(),
      method: redelivery.value.method,
      path: redelivery.value.path.trim(),
    }

    // A JSON body travels as a structure, so the stored jsonb and the raw text
    // cannot disagree: `raw_body` alone would leave `body` NULL and the rules
    // that read `body.*` would see nothing.
    if (redeliveryIsJson.value && body.trim() !== '') {
      draft.body = JSON.parse(body)
      delete draft.raw_body
    }

    const data = await api.redeliverRequest(props.request.id, draft)
    const created = data.data

    notify(
      created.queued > 0
        ? `Создан запрос #${created.id}, поставлено в очередь: ${created.queued}`
        : `Создан запрос #${created.id}, активных сценариев нет`,
      { tone: 'info' },
    )

    redeliverOpen.value = false
    emit('redelivered', created.id)
  } catch (e) {
    redeliverError.value = e.message
  } finally {
    redelivering.value = false
  }
}

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

const metaRows = computed(() => {
  const rows = [
    ['ID', `#${props.request.id}`],
    ['Эндпоинт', props.request.endpoint],
    ['Метод', props.request.method],
    ['Путь', props.request.path],
    ['IP-адрес', props.request.ip],
    ['Content-Type', props.request.content_type || '—'],
    ['Размер тела', formatBytes(props.request.body_bytes)],
    ['Получен', formatTime(props.request.received_at)],
  ]

  // A repeat is stored (so the log shows that the sender retried) but was never
  // routed. Naming the original is the only way to tell the two apart later.
  if (props.request.duplicate_of) {
    rows.push(['Повтор доставки', `#${props.request.duplicate_of} — сценарии не запускались`])
  }

  // A corrected copy is a real delivery and was routed, so it is not a repeat.
  // Naming its origin is still what makes the log readable: "where did this come
  // from" is the question a redelivery row raises.
  if (props.request.replayed_from) {
    rows.push(['Отправлен с правками из', `#${props.request.replayed_from}`])
  }

  return rows
})

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
            <span
              v-if="request.duplicate_of"
              class="rounded bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider text-amber-700 dark:text-amber-300"
              :title="`Повтор доставки #${request.duplicate_of}`"
            >повтор</span>
            <span
              v-if="request.replayed_from"
              class="rounded bg-indigo-500/10 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider text-indigo-700 dark:text-indigo-300"
              :title="`Копия запроса #${request.replayed_from}, отправленная с правками`"
            >с правками</span>
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

      <div v-else-if="tab === 'preview'" class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <p class="text-sm font-medium text-ink">Что сделают правила</p>
            <p class="text-[11px] text-ink-mute">
              Условия проверяются на этом теле, действия только описываются — ничего не отправляется
              и не ставится в очередь.
            </p>
          </div>
          <button type="button" class="btn px-3 py-1.5" :disabled="previewing" @click="runPreview">
            <AppSpinner v-if="previewing" size="xs" />
            <span>{{ previewing ? 'Проверяем…' : 'Проверить' }}</span>
          </button>
        </div>

        <p
          v-if="previewError"
          class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-300"
        >
          {{ previewError }}
        </p>

        <p v-if="preview && preview.workflows.length === 0" class="rounded-xl border border-dashed border-line px-3 py-4 text-xs text-ink-mute">
          В комнате нет активных сценариев для этого эндпоинта.
        </p>

        <div
          v-for="rule in preview?.workflows ?? []"
          :key="rule.workflow_id"
          class="rounded-xl border border-line bg-surface-2 p-3"
        >
          <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-ink">{{ rule.name }}</p>
            <span
              class="rounded-full px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider"
              :class="rule.matched
                ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                : 'bg-ink/5 text-ink-mute'"
            >
              {{ rule.matched ? 'совпало' : 'пропустит' }}
            </span>
          </div>

          <ol v-if="rule.steps.length" class="mt-2 space-y-1.5">
            <li
              v-for="step in rule.steps"
              :key="step.index"
              class="rounded-lg border border-line bg-surface px-2.5 py-2 text-[11px]"
            >
              <p class="font-medium text-ink-soft">{{ step.index + 1 }}. {{ step.type }}</p>

              <p
                v-if="step.skipped"
                class="mt-1 rounded-lg border border-line bg-surface-2 px-2 py-1 text-ink-mute"
              >
                пропущено: {{ step.reason || 'условие действия не выполнено' }}
              </p>

              <p v-else-if="step.type === 'log'" class="mt-1 text-ink-mute">{{ step.message || 'совпадение' }}</p>

              <p v-else-if="step.type === 'delay'" class="mt-1 text-ink-mute">
                пауза {{ step.seconds }} с, затем остальные действия
              </p>

              <pre
                v-else-if="step.type === 'transform'"
                class="mt-1 overflow-x-auto whitespace-pre-wrap break-all text-ink-mute"
              >{{ JSON.stringify(step.mapping, null, 2) }}</pre>

              <template v-else-if="step.type === 'extract'">
                <p class="mt-1 text-ink-mute">источник: {{ step.source }}</p>
                <pre
                  class="mt-1 overflow-x-auto whitespace-pre-wrap break-all text-ink-mute"
                >{{ JSON.stringify(step.extracted, null, 2) }}</pre>
              </template>

              <template v-else-if="step.type === 'notify'">
                <p class="mt-1 text-ink-mute">
                  {{ step.target || 'канал' }}
                  <span v-if="!step.enabled" class="text-amber-600 dark:text-amber-300">· канал выключен</span>
                  <span v-if="step.breaker && step.breaker !== 'closed'" class="text-rose-600 dark:text-rose-300">
                    · адрес не отвечает, повтор через {{ step.breaker_retry_in }} с
                  </span>
                </p>
                <p v-if="step.error" class="mt-1 text-rose-600 dark:text-rose-300">{{ step.error }}</p>
                <pre class="mt-1 max-h-40 overflow-auto whitespace-pre-wrap break-all text-ink-mute">{{ step.message }}</pre>
              </template>

              <template v-else-if="step.type === 'forward'">
                <p class="mt-1 break-all font-mono text-ink-mute">{{ step.method }} {{ step.url }}</p>
                <p v-if="step.timeout" class="mt-1 text-ink-mute">таймаут {{ step.timeout }} с</p>
                <p
                  v-if="!step.allowed"
                  class="mt-1 rounded-lg border border-rose-500/30 bg-rose-500/10 px-2 py-1 text-rose-600 dark:text-rose-300"
                >
                  Адрес будет отклонён: {{ step.reason || 'недопустимый URL' }}
                </p>
                <pre class="mt-1 max-h-40 overflow-auto whitespace-pre-wrap break-all text-ink-mute">{{ step.body }}</pre>
              </template>

              <p v-else class="mt-1 text-ink-mute">{{ step.message }}</p>
            </li>
          </ol>
        </div>
      </div>

      <div v-else-if="tab === 'runs'" class="space-y-2">
        <div
          v-for="run in runs"
          :key="run.id"
          class="rounded-xl border border-line bg-surface-2 p-3"
        >
          <div class="flex items-center justify-between gap-2">
            <div class="flex min-w-0 items-center gap-2">
              <p class="truncate text-sm text-ink">{{ run.workflow_name }}</p>
              <StatusPill :status="run.status" />
            </div>
            <!-- A fresh run, not a reset: the failed row keeps its attempts and
                 steps, and every action runs again. -->
            <button
              v-if="auth.canWrite && (run.status === 'failed' || run.status === 'skipped')"
              type="button"
              class="btn shrink-0 px-2.5 py-1 text-[11px]"
              @click="emit('retry', run.id)"
            >
              Повторить
            </button>
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

    <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-line p-4">
      <div class="flex flex-wrap items-center gap-2">
        <button type="button" class="btn" @click="copyCurl">
          {{ copied ? 'Скопировано!' : 'Копировать как cURL' }}
        </button>
        <button
          type="button"
          class="btn"
          :disabled="downloading || !request.raw_body"
          title="Скачать исходное тело запроса файлом"
          @click="downloadBody"
        >
          <AppSpinner v-if="downloading" size="xs" />
          {{ downloading ? 'Скачиваем…' : 'Скачать тело' }}
        </button>
        <button
          v-if="auth.canWrite"
          type="button"
          class="btn"
          title="Запустить активные сценарии комнаты заново"
          @click="emit('replay')"
        >
          Повторить маршрутизацию
        </button>
        <button
          v-if="auth.canWrite"
          type="button"
          class="btn"
          title="Отправить копию запроса с исправленной полезной нагрузкой"
          @click="redeliverOpen ? closeRedeliver() : openRedeliver()"
        >
          {{ redeliverOpen ? 'Скрыть правку' : 'Отправить с правками' }}
        </button>
      </div>
      <button
        v-if="auth.canWrite"
        type="button"
        class="btn-danger py-2"
        @click="emit('delete', request.id)"
      >
        Удалить запрос
      </button>
    </footer>

    <!-- The editor sits under the actions rather than in a modal: the payload it
         edits is a tab above it, and covering that tab to fix a field makes the
         operator retype what they were looking at. -->
    <section
      v-if="redeliverOpen"
      class="border-t border-line bg-surface-2/60 p-4"
      aria-label="Отправка с правками"
    >
      <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
          <p class="text-sm font-medium text-ink">Отправить копию с правками</p>
          <p class="mt-0.5 text-[11px] text-ink-mute">
            Исходный запрос #{{ request.id }} останется в журнале как есть — сценарии выполнятся
            для новой копии.
          </p>
        </div>
        <button
          type="button"
          class="text-[11px] text-ink-mute underline-offset-2 hover:text-ink hover:underline"
          @click="closeRedeliver"
        >
          Отмена
        </button>
      </div>

      <div class="mt-3 grid gap-2 sm:grid-cols-2">
        <label class="block">
          <span class="text-[11px] uppercase tracking-wider text-ink-mute">Метод</span>
          <AppSelect
            v-model="redelivery.method"
            :options="['POST', 'PUT', 'PATCH', 'GET', 'DELETE', 'OPTIONS'].map((m) => ({ value: m, label: m }))"
            size="sm"
            aria-label="Метод копии"
            class="mt-1"
          />
        </label>
        <label class="block">
          <span class="text-[11px] uppercase tracking-wider text-ink-mute">Эндпоинт</span>
          <AppInput
            v-model="redelivery.endpoint"
            size="sm"
            class="mt-1"
            aria-label="Эндпоинт копии"
          />
        </label>
        <label class="block sm:col-span-2">
          <span class="text-[11px] uppercase tracking-wider text-ink-mute">Путь</span>
          <AppInput v-model="redelivery.path" size="sm" class="mt-1" aria-label="Путь копии" />
        </label>
        <label class="block sm:col-span-2">
          <span class="text-[11px] uppercase tracking-wider text-ink-mute">
            Тело {{ redeliveryIsJson ? '(JSON)' : '' }}
          </span>
          <AppTextarea
            v-model="redelivery.body"
            :rows="8"
            size="sm"
            class="mt-1 font-mono"
            placeholder="Тело запроса"
            aria-label="Тело копии"
          />
        </label>
      </div>

      <p
        v-if="redeliverError"
        class="mt-2 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-600 dark:text-rose-300"
      >
        {{ redeliverError }}
      </p>

      <div class="mt-3 flex items-center justify-end gap-2">
        <button type="button" class="btn" :disabled="redelivering" @click="closeRedeliver">
          Отмена
        </button>
        <button type="button" class="btn-primary" :disabled="redelivering" @click="submitRedeliver">
          <AppSpinner v-if="redelivering" size="xs" />
          {{ redelivering ? 'Отправляем…' : 'Отправить копию' }}
        </button>
      </div>
    </section>
  </div>
</template>
