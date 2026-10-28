<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import AppInput from './ui/AppInput.vue'
import AppSelect from './ui/AppSelect.vue'
import AppTextarea from './ui/AppTextarea.vue'
import JsonTree from './JsonTree.vue'
import { api } from '../api/client'
import { buildTree, findNode, typeLabel } from '../utils/jsonPaths'
import { formatTime } from '../utils/format'

const props = defineProps({
  room: { type: Object, default: null },
  endpoint: { type: String, default: '' },
})

const emit = defineEmits(['close', 'pick'])

const SAMPLE_LIMIT = 20

const loading = ref(true)
const error = ref('')
const samples = ref([])
const selectedId = ref(null)
const usedFallback = ref(false)

const source = ref('body')
const filter = ref('')
const selectedPath = ref('')
const manualJson = ref('')
const manualError = ref('')
const manualTree = ref(null)
const mode = ref('samples')

const currentSample = computed(() => samples.value.find((item) => item.id === selectedId.value) ?? null)

const metaSources = computed(() => {
  const sample = currentSample.value
  if (!sample) {
    return []
  }

  return [
    { key: 'id', value: sample.id },
    { key: 'method', value: sample.method },
    { key: 'path', value: sample.path },
    { key: 'endpoint', value: sample.endpoint },
    { key: 'ip', value: sample.ip },
    { key: 'received_at', value: sample.received_at },
  ]
})
const sampleOptions = computed(() =>
  samples.value.map((item) => ({
    value: item.id,
    label: `${item.method} · ${item.endpoint} · ${formatTime(item.received_at)}`,
  })),
)

const rawBody = computed(() => {
  const sample = currentSample.value
  if (!sample) {
    return ''
  }

  if (sample.raw_body) {
    return sample.raw_body
  }

  return sample.body ? JSON.stringify(sample.body, null, 2) : ''
})

/**
 * Only attributes that `WorkflowEngine::context()` actually exposes become
 * addressable sources; `raw` and `transformed` are not JSON, so they are not
 * offered as trees.
 */
const sources = computed(() => {
  const sample = currentSample.value
  const available = []

  if (sample?.body !== null && sample?.body !== undefined) {
    available.push({ value: 'body', label: 'Тело' })
  }
  if (sample?.headers && Object.keys(sample.headers).length) {
    available.push({ value: 'headers', label: 'Заголовки' })
  }
  if (sample?.query && Object.keys(sample.query).length) {
    available.push({ value: 'query', label: 'Query' })
  }
  if (sample) {
    available.push({ value: 'meta', label: 'Метаданные' })
  }
  if (rawBody.value) {
    available.push({ value: 'raw', label: 'Сырое тело' })
  }

  return available
})

const activeSource = computed(() => {
  if (sources.value.some((item) => item.value === source.value)) {
    return source.value
  }

  return sources.value[0]?.value ?? 'body'
})

const tree = computed(() => {
  if (mode.value === 'manual') {
    return manualTree.value
  }

  const sample = currentSample.value
  if (!sample) {
    return null
  }

  const from = activeSource.value
  if (from === 'meta') {
    return buildTree(Object.fromEntries(metaSources.value.map((row) => [row.key, row.value])), 'meta')
  }
  if (from === 'raw') {
    return null
  }

  const value = from === 'body' ? sample.body : from === 'headers' ? sample.headers : sample.query

  return value === null || value === undefined ? null : buildTree(value, from)
})

const selectedNode = computed(() => (tree.value && selectedPath.value ? findNode(tree.value, selectedPath.value) : null))

const selectedType = computed(() => (selectedNode.value ? typeLabel(selectedNode.value.type) : ''))
const canPickValue = computed(() => {
  const node = selectedNode.value
  if (!node) {
    return false
  }

  if (node.type === 'array') {
    // `contains` compares against a scalar element, so an array of objects has
    // no sensible default value to pre-fill.
    return node.children.length > 0 && node.children.every((child) => child.type !== 'object' && child.type !== 'array')
  }

  return ['string', 'number', 'boolean'].includes(node.type)
})

/** `contains` works on scalars inside an array, so offer the first element as a default. */
const pickValue = computed(() => {
  const node = selectedNode.value
  if (!node) {
    return undefined
  }
  if (node.type === 'array') {
    return node.children[0]?.value
  }

  return node.value
})

async function load(params) {
  const data = await api.requests({ room: props.room?.slug, per_page: SAMPLE_LIMIT, include: 'body', ...params })

  return data.data
}

/**
 * A path only makes sense inside the payload it was picked from, so switching
 * the sample or the source drops the current selection instead of keeping a
 * path that no longer exists in the tree.
 */
watch([selectedId, activeSource, mode, manualTree], () => {
  selectedPath.value = ''
  filter.value = ''
})

onMounted(async () => {
  const endpoint = props.endpoint.trim()

  try {
    let list = []

    if (endpoint && endpoint !== '*') {
      list = await load({ endpoint })
      if (list.length === 0) {
        list = await load({})
        usedFallback.value = true
      }
    } else {
      list = await load({})
    }

    samples.value = list
    selectedId.value = list[0]?.id ?? null
    mode.value = list.length === 0 ? 'manual' : 'samples'
  } catch (e) {
    error.value = e.message
    mode.value = 'manual'
  } finally {
    loading.value = false
  }
})

function parseManual() {
  manualError.value = ''
  const text = manualJson.value.trim()

  if (text === '') {
    manualTree.value = null
    return
  }

  try {
    manualTree.value = buildTree(JSON.parse(text), 'body')
  } catch (e) {
    manualTree.value = null
    manualError.value = `Не удалось разобрать JSON: ${e.message}`
  }
}

function pick() {
  if (!selectedNode.value) {
    return
  }

  emit('pick', { path: selectedNode.value.path, value: pickValue.value, type: selectedNode.value.type })
}

function pickPathOnly() {
  if (!selectedNode.value) {
    return
  }

  emit('pick', { path: selectedNode.value.path, value: undefined, type: selectedNode.value.type, pathOnly: true })
}
</script>

<template>
  <div class="fixed inset-0 z-[60] flex items-start justify-center overflow-y-auto bg-slate-900/30 p-4 backdrop-blur-sm dark:bg-slate-950/80 sm:p-8">
    <div class="card my-auto flex w-full max-w-3xl flex-col overflow-hidden">
      <header class="flex items-start justify-between gap-4 border-b border-line px-6 py-5">
        <div>
          <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">
            Путь из запроса
          </p>
          <h2 class="mt-1 text-lg font-semibold text-ink">Выберите поле, не набирайте путь руками</h2>
          <p v-if="room" class="mt-1 text-[11px] text-ink-mute">
            Комната: <span class="font-medium text-ink-soft">{{ room.name }}</span>
            · <code class="font-mono">{{ room.slug }}</code>
          </p>
        </div>
        <button
          type="button"
          class="rounded-lg p-1.5 text-ink-mute transition hover:bg-surface-2 hover:text-ink"
          aria-label="Закрыть выбор пути"
          @click="emit('close')"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
          </svg>
        </button>
      </header>

      <div class="max-h-[70vh] flex-1 space-y-4 overflow-y-auto px-6 py-5">
        <p v-if="error" class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-600 dark:text-rose-300">
          {{ error }}
        </p>

        <div class="flex flex-wrap gap-1 rounded-xl border border-line bg-surface-2 p-1">
          <button
            type="button"
            class="rounded-lg px-3 py-1.5 text-xs font-medium transition"
            :class="mode === 'samples'
              ? 'bg-surface text-ink shadow-sm'
              : 'text-ink-soft hover:text-ink'"
            @click="mode = 'samples'"
          >
            Из запроса
          </button>
          <button
            type="button"
            class="rounded-lg px-3 py-1.5 text-xs font-medium transition"
            :class="mode === 'manual'
              ? 'bg-surface text-ink shadow-sm'
              : 'text-ink-soft hover:text-ink'"
            @click="mode = 'manual'"
          >
            Вставить JSON
          </button>
        </div>

        <template v-if="mode === 'samples'">
          <p v-if="loading" class="rounded-xl border border-dashed border-line bg-surface-2 px-3 py-6 text-center text-xs text-ink-mute">
            Загружаем последние запросы…
          </p>

          <div v-else-if="samples.length === 0" class="rounded-xl border border-dashed border-line bg-surface-2 px-4 py-6 text-center">
            <p class="text-sm font-medium text-ink">В комнате пока нет запросов</p>
            <p class="mx-auto mt-1 max-w-md text-xs text-ink-mute">
              Отправьте тестовый запрос на вебхук — или откройте вкладку «Вставить JSON» и задайте структуру вручную.
            </p>
            <button type="button" class="btn mt-3" @click="mode = 'manual'">Вставить JSON</button>
          </div>

          <template v-else>
            <label class="block">
              <span class="mb-1.5 block text-xs font-medium text-ink-soft">Образец запроса</span>
              <AppSelect v-model="selectedId" :options="sampleOptions" aria-label="Образец запроса" />
            </label>

            <p v-if="usedFallback" class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-[11px] text-amber-800 dark:text-amber-200">
              По эндпоинту «{{ endpoint }}» запросов нет — показаны последние запросы комнаты.
            </p>

            <div class="flex flex-wrap gap-1">
              <button
                v-for="item in sources"
                :key="item.value"
                type="button"
                class="rounded-lg px-2.5 py-1.5 text-xs font-medium transition"
                :class="activeSource === item.value
                  ? 'bg-indigo-50 text-indigo-700 dark:bg-white/10 dark:text-white'
                  : 'text-ink-soft hover:bg-surface-2 hover:text-ink'"
                @click="source = item.value"
              >
                {{ item.label }}
              </button>
            </div>

            <template v-if="activeSource === 'raw'">
              <pre class="max-h-72 overflow-auto rounded-xl border border-line bg-surface-2 p-4 font-mono text-[12px] leading-relaxed text-ink">{{ rawBody }}</pre>
              <p class="text-[11px] text-ink-mute">
                Сырое тело — не JSON, путь по нему не строится. Если внутри всё-таки JSON,
                сохраните его во вкладке «Вставить JSON».
              </p>
            </template>

            <AppInput
              v-else
              v-model="filter"
              type="search"
              size="sm"
              placeholder="Поиск по пути, например customer"
              aria-label="Поиск по путям"
            />

            <ul
              v-if="activeSource !== 'raw' && tree"
              class="max-h-72 overflow-auto rounded-xl border border-line bg-surface px-2 py-1"
            >
              <JsonTree
                :node="tree"
                :selected="selectedPath"
                :filter="filter"
                @select="selectedPath = $event.path"
              />
            </ul>

            <p
              v-else-if="activeSource !== 'raw'"
              class="rounded-xl border border-dashed border-line bg-surface-2 px-4 py-4 text-xs text-ink-mute"
            >
              В этом источнике нет структуры — выберите другой.
            </p>
          </template>
        </template>

        <template v-else>
          <label class="block">
            <span class="mb-1.5 block text-xs font-medium text-ink-soft">JSON-образец</span>
            <AppTextarea
              v-model="manualJson"
              :rows="6"
              size="sm"
              class="font-mono"
              placeholder='{"event": {"type": "payment"}, "customer": {"email": "ada@example.com"}}'
            />
          </label>

          <div class="flex items-center gap-2">
            <button type="button" class="btn py-1.5" @click="parseManual">Разобрать</button>
            <span v-if="manualError" class="text-[11px] text-rose-600 dark:text-rose-300">{{ manualError }}</span>
            <span v-else-if="manualTree" class="text-[11px] text-ink-mute">Структура построена, выберите поле.</span>
          </div>

          <AppInput
            v-if="manualTree"
            v-model="filter"
            type="search"
            size="sm"
            placeholder="Поиск по пути"
            aria-label="Поиск по путям"
          />

          <ul
            v-if="manualTree"
            class="max-h-72 overflow-auto rounded-xl border border-line bg-surface px-2 py-1"
          >
            <JsonTree
              :node="manualTree"
              :selected="selectedPath"
              :filter="filter"
              @select="selectedPath = $event.path"
            />
          </ul>
        </template>
      </div>

      <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-6 py-4">
        <div class="min-w-0">
          <p class="text-[10px] uppercase tracking-wider text-ink-mute">Выбранный путь</p>
          <p class="mt-0.5 min-w-0 break-all font-mono text-xs text-ink">
            {{ selectedPath || '—' }}
            <span v-if="selectedNode" class="ml-1 text-ink-mute">· {{ selectedType }}</span>
          </p>
        </div>

        <div class="flex items-center gap-2">
          <button type="button" class="btn px-3" @click="emit('close')">Отмена</button>
          <button type="button" class="btn px-3" :disabled="!selectedPath" @click="pickPathOnly">
            Только путь
          </button>
          <button type="button" class="btn-primary" :disabled="!canPickValue" @click="pick">
            Путь + значение
          </button>
        </div>
      </footer>
    </div>
  </div>
</template>
