<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppShell from '../components/AppShell.vue'
import MethodBadge from '../components/MethodBadge.vue'
import RequestDetail from '../components/RequestDetail.vue'
import AppInput from '../components/ui/AppInput.vue'
import AppSelect from '../components/ui/AppSelect.vue'
import AppSpinner from '../components/ui/AppSpinner.vue'
import { api } from '../api/client'
import { confirm } from '../composables/dialog'
import { retain, status as realtimeStatus, subscribe } from '../composables/realtime'
import { useRefresh } from '../composables/useRefresh'
import { useRoomsStore } from '../stores/rooms'
import { formatBytes, formatClock, formatTime } from '../utils/format'

const route = useRoute()
const rooms = useRoomsStore()

const slug = computed(() => (typeof route.params.slug === 'string' ? route.params.slug : ''))
const room = computed(() => (slug.value ? rooms.find(slug.value) : null))

const items = ref([])
const meta = reactive({ page: 1, per_page: 25, total: 0, pages: 1 })
const stats = ref(null)
const filters = reactive({ method: 'ALL', q: '', endpoint: '' })

const loading = ref(false)
const error = ref('')
const copied = ref(false)

// User-driven reloads (filters, paging, room switch) get their own busy flag so the
// loader is visible even when a local API answers in a few milliseconds.
// `filtering` only picks the wording ("Фильтруем…"): the select no longer carries its
// own spinner, because the table already shows one for the same action — two
// indicators for one click read as two operations.
const filtering = ref(false)
const listBusy = ref(false)

const showDetail = ref(false)
const detailLoading = ref(false)
const detail = ref(null)
const runs = ref([])

let releaseLive = null
let offRealtime = null
let filterTimer = null
let reloadTimer = null
let busyToken = 0
let loadSeq = 0

const FILTER_DEBOUNCE = 250
const BUSY_MIN = 350

const methods = ['ALL', 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']
const methodOptions = methods.map((method) => ({
  value: method,
  label: method === 'ALL' ? 'Все методы' : method,
}))

const webhookUrl = computed(() => {
  // The address advertises the room's own endpoint, not the endpoint filter: the
  // filter narrows the list and must not silently rewrite the copied URL.
  const prefix = slug.value ? `/${slug.value}` : ''
  const endpoint = room.value?.endpoint ? `/${room.value.endpoint}` : ''

  return `${window.location.origin}/api/webhook${prefix}${endpoint}`
})

// Connection state only — updates are always on, so there is nothing to toggle.
const realtimeLabel = computed(() => {
  if (realtimeStatus.value === 'open') {
    return { label: 'в сети', tone: 'ok' }
  }

  if (realtimeStatus.value === 'idle' || realtimeStatus.value === 'connecting') {
    return { label: 'подключение…', tone: 'warn' }
  }

  if (realtimeStatus.value === 'retrying') {
    return { label: 'переподключение…', tone: 'warn' }
  }

  return { label: 'нет соединения', tone: 'bad' }
})

const cards = computed(() => [
  { label: 'Всего запросов', value: stats.value?.requests_total ?? 0, hint: 'за всё время' },
  {
    label: 'За 24 часа',
    value: stats.value?.requests_last_24h ?? 0,
    hint: `${stats.value?.requests_last_hour ?? 0} за последний час`,
  },
  { label: 'Активные сценарии', value: stats.value?.workflows_active ?? 0, hint: 'правила включены' },
  {
    label: 'В очереди',
    value: stats.value?.queued_runs ?? 0,
    hint: 'выполняются в воркере',
    alert: false,
  },
  {
    label: 'Ошибки маршрутизации · 24ч',
    value: stats.value?.failed_runs_24h ?? 0,
    hint: 'смотрите детали запроса',
    alert: (stats.value?.failed_runs_24h ?? 0) > 0,
  },
])

async function load({ silent = false } = {}) {
  // Two loads can overlap: a room switch while a filter reload is still in flight,
  // or a realtime resync during a manual refresh. Without a sequence number the
  // older response wins the race and, worse, its `finally` clears `loading` while
  // the newer request is still running — the loader vanishes and the table looks
  // idle next to stale data.
  const seq = ++loadSeq
  const roomSlug = slug.value
  const current = () => seq === loadSeq && roomSlug === slug.value

  if (!silent) {
    loading.value = true
  }

  try {
    const [list, summary] = await Promise.all([
      api.requests({
        room: roomSlug,
        page: meta.page,
        per_page: meta.per_page,
        method: filters.method,
        q: filters.q.trim(),
        endpoint: filters.endpoint.trim(),
      }),
      api.stats({ room: roomSlug }),
    ])

    // Superseded by a newer load, or the room changed while the requests were in
    // flight: painting this answer would show one room's rows under another's title.
    if (!current()) {
      return
    }

    items.value = list.data
    Object.assign(meta, list.meta)
    stats.value = summary.data
    error.value = ''
  } catch (e) {
    if (current()) {
      error.value = e.message
    }
  } finally {
    if (seq === loadSeq && !silent) {
      loading.value = false
    }
  }
}

/**
 * Manual refreshes go through `useRefresh` so the spinner stays visible long
 * enough to be noticed; realtime reloads stay silent and do not flash the list.
 */
const { busy: refreshing, updatedAt, run: refreshNow } = useRefresh(() => load())

/**
 * Is any loader owed to the user? The list has three independent sources of
 * "waiting" — the initial load, a user-driven reload held for a minimum duration,
 * and the manual refresh button — and they overlap. Derived from one predicate, the
 * overlay and the inline spinner can never render at the same time.
 */
const listLoading = computed(() => loading.value || listBusy.value || refreshing.value)
// The overlay dims rows that exist; with nothing to dim, the empty table shows its
// own spinner instead. Keeping these mutually exclusive is what prevents the
// "two loaders" flash when the room changes to one with no requests yet.
const showOverlay = computed(() => listLoading.value && items.value.length > 0)
const showInlineLoader = computed(() => listLoading.value && items.value.length === 0)

/**
 * Marks the start of a user-driven reload and returns its token. A newer token
 * wins, so a stale response cannot hide the loader the user is looking at.
 */
function beginBusy({ filter = false } = {}) {
  const token = ++busyToken
  listBusy.value = true

  if (filter) {
    filtering.value = true
  }

  return token
}

/**
 * Reloads the list for a user action and keeps the loader up for at least
 * `BUSY_MIN` ms, counted from `started` (the moment the user acted, not the
 * moment the request went out, so the debounce is part of the wait).
 */
async function loadWithLoader({ filter = false, started = Date.now(), token = beginBusy({ filter }) } = {}) {
  try {
    await load()
  } finally {
    const rest = BUSY_MIN - (Date.now() - started)

    if (rest > 0) {
      await new Promise((resolve) => setTimeout(resolve, rest))
    }

    if (token === busyToken) {
      listBusy.value = false
      filtering.value = false
    }
  }
}

/**
 * Realtime events are thin, so any of them just means "re-read what I show".
 * Debounced to collapse bursts (a webhook fans out into created + updated).
 */
function refreshSoon() {
  clearTimeout(reloadTimer)
  reloadTimer = setTimeout(() => {
    load({ silent: true })
  }, 300)
}

async function refreshDetail() {
  const id = detail.value?.id
  if (!showDetail.value || !id) {
    return
  }

  try {
    const data = await api.request(id)

    if (!showDetail.value || detail.value?.id !== id) {
      return
    }

    detail.value = data.data.request
    runs.value = data.data.runs
  } catch {
    // Keep the currently rendered copy; the next event retries.
  }
}

function onRequestEvent(payload) {
  // Events carry room_id: another room's webhook must not disturb this list.
  if (payload?.room_id && room.value && payload.room_id !== room.value.id) {
    return
  }

  if (detail.value?.id && payload?.id === detail.value.id) {
    refreshDetail()
  }

  refreshSoon()
}

/**
 * After a reconnect (or returning to the tab) events may have been missed, so the
 * open card is re-read even though the payload carries no id.
 */
function onResync() {
  refreshDetail()
  refreshSoon()
}

function onRequestDeleted(payload) {
  if (payload?.room_id && room.value && payload.room_id !== room.value.id) {
    return
  }

  // Another tab removed the row we are looking at.
  if (detail.value?.id && payload?.id === detail.value.id) {
    showDetail.value = false
    detail.value = null
    runs.value = []
  }

  refreshSoon()
}

// Switching rooms is a different data set: reset filters/paging and drop the
// open card so no request from the previous room stays on screen. The rows and the
// counters are cleared too — leaving them up would show the previous room's
// requests under the new room's heading until the response arrives.
//
// Resetting the filters above would trip the `filters` watcher and fire a second,
// redundant load. `suppressFilterReload` swallows exactly that one tick; the room
// switch already loads everything the filters would have asked for. It is set only
// when a filter actually differs from its default — writing the same value does not
// trigger the watcher, and a stale flag would swallow the user's next real change.
let suppressFilterReload = false

watch(slug, () => {
  suppressFilterReload = filters.method !== 'ALL' || filters.q !== '' || filters.endpoint !== ''

  filters.method = 'ALL'
  filters.q = ''
  filters.endpoint = ''
  items.value = []
  stats.value = null
  Object.assign(meta, { page: 1, total: 0, pages: 1 })
  showDetail.value = false
  detail.value = null
  runs.value = []
  loadWithLoader()
})

watch(
  filters,
  () => {
    if (suppressFilterReload) {
      suppressFilterReload = false
      return
    }

    clearTimeout(filterTimer)

    // Claim the loader before the debounce: a method pick must react at once,
    // and 250 ms of silence already reads as "the filter did nothing".
    const token = beginBusy({ filter: true })
    const started = Date.now()

    filterTimer = setTimeout(() => {
      meta.page = 1
      loadWithLoader({ filter: true, started, token })
    }, FILTER_DEBOUNCE)
  },
  { deep: true },
)

function goToPage(page) {
  if (page < 1 || page > meta.pages) {
    return
  }

  meta.page = page
  loadWithLoader()
}

async function openRequest(row) {
  showDetail.value = true
  detailLoading.value = true
  detail.value = { ...row, headers: {}, query: {}, body: null, raw_body: null }
  runs.value = []

  try {
    const data = await api.request(row.id)
    detail.value = data.data.request
    runs.value = data.data.runs
  } catch (e) {
    error.value = e.message
    showDetail.value = false
  } finally {
    detailLoading.value = false
  }
}

function closeDetail() {
  showDetail.value = false
}

async function removeRequest(id) {
  const confirmed = await confirm({
    title: 'Удалить запрос?',
    message: `Запрос #${id} и связанные запуски сценариев будут удалены.`,
    confirmLabel: 'Удалить запрос',
  })

  if (!confirmed) {
    return
  }

  try {
    await api.deleteRequest(id)
    showDetail.value = false
    await loadWithLoader()
  } catch (e) {
    error.value = e.message
  }
}

async function copyWebhook() {
  try {
    await navigator.clipboard.writeText(webhookUrl.value)
    copied.value = true
    setTimeout(() => (copied.value = false), 1500)
  } catch {
    // Буфер обмена может быть недоступен.
  }
}

function resetFilters() {
  filters.method = 'ALL'
  filters.q = ''
  filters.endpoint = ''
}

onMounted(() => {
  load()

  const offCreated = subscribe('request.created', onRequestEvent)
  const offUpdated = subscribe('request.updated', onRequestEvent)
  const offDeleted = subscribe('request.deleted', onRequestDeleted)
  const offResync = subscribe('resync', onResync)

  releaseLive = retain()

  offRealtime = () => {
    offCreated()
    offUpdated()
    offDeleted()
    offResync()
  }
})

onUnmounted(() => {
  offRealtime?.()
  releaseLive?.()
  releaseLive = null
  clearTimeout(filterTimer)
  clearTimeout(reloadTimer)
})
</script>

<template>
  <AppShell>
    <div class="mx-auto max-w-7xl space-y-6">
      <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">Входящие</p>
          <h1 class="mt-1 text-2xl font-semibold text-ink">{{ room?.name || 'Комната' }}</h1>
          <p class="mt-1 text-sm text-ink-soft">
            Каждый запрос сохраняется до маршрутизации — независимо от метода и пути.
            <span v-if="room" class="text-ink-mute">Slug: <code class="font-mono">{{ room.slug }}</code></span>
          </p>
        </div>

        <div class="flex items-center gap-3">
          <span class="flex items-center gap-1.5 text-[11px]" :class="{
            'text-emerald-700 dark:text-emerald-300': realtimeLabel.tone === 'ok',
            'text-amber-700 dark:text-amber-300': realtimeLabel.tone === 'warn',
            'text-rose-600 dark:text-rose-300': realtimeLabel.tone === 'bad',
          }">
            <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
            {{ realtimeLabel.label }}
          </span>

          <span v-if="updatedAt" class="font-mono text-[11px] text-ink-mute">
            обновлено {{ formatClock(updatedAt) }}
          </span>

          <button type="button" class="btn" :disabled="refreshing" @click="refreshNow">
            <AppSpinner v-if="refreshing" size="xs" />
            {{ refreshing ? 'Обновляем…' : 'Обновить' }}
          </button>
        </div>
      </header>

      <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <article v-for="card in cards" :key="card.label" class="card p-4">
          <p class="text-[11px] uppercase tracking-wider text-ink-mute">{{ card.label }}</p>
          <p class="mt-2 text-2xl font-semibold" :class="card.alert ? 'text-rose-600 dark:text-rose-300' : 'text-ink'">
            {{ card.value }}
          </p>
          <p class="mt-0.5 text-[11px] text-ink-mute">{{ card.hint }}</p>
        </article>
      </section>

      <section class="flex flex-wrap items-center gap-2 rounded-2xl border border-indigo-500/20 bg-indigo-500/[0.06] px-4 py-3">
        <span class="text-[11px] font-semibold uppercase tracking-wider text-indigo-700 dark:text-indigo-300">Слушает на</span>
        <code class="min-w-0 flex-1 truncate font-mono text-xs text-ink">{{ webhookUrl }}</code>
        <button
          type="button"
          class="rounded-lg bg-indigo-600/10 px-2.5 py-1 text-xs font-medium text-indigo-700 transition hover:bg-indigo-600/15 dark:bg-indigo-500/20 dark:text-indigo-200 dark:hover:bg-indigo-500/30"
          @click="copyWebhook"
        >
          {{ copied ? 'Скопировано!' : 'Копировать URL' }}
        </button>
        <p class="w-full text-[11px] text-ink-mute">
          Эндпоинт комнаты <code class="font-mono text-ink-soft">{{ room?.endpoint }}</code>
          · меняется в настройках комнаты; любой другой путь тоже принимается.
        </p>
      </section>

      <p v-if="error" class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-600 dark:text-rose-300">
        {{ error }}
      </p>

      <section class="flex flex-wrap items-center gap-2">
        <AppSelect
          v-model="filters.method"
          :options="methodOptions"
          size="sm"
          aria-label="Фильтр по методу"
          class="w-36"
        />

        <div class="w-44">
          <AppInput
            v-model="filters.endpoint"
            list="known-endpoints"
            size="sm"
            placeholder="Фильтр по эндпоинту"
          />
        </div>
        <datalist id="known-endpoints">
          <option v-for="item in stats?.endpoints ?? []" :key="item.endpoint" :value="item.endpoint" />
        </datalist>

        <AppInput
          v-model="filters.q"
          type="search"
          size="sm"
          placeholder="Поиск по пути или телу…"
          class="min-w-40 flex-1"
        />

        <button type="button" class="btn border-transparent bg-transparent" @click="resetFilters">
          Сбросить
        </button>
      </section>

      <section class="card relative overflow-hidden">
        <Transition name="fade">
          <div
            v-if="showOverlay"
            class="absolute inset-0 z-10 grid place-items-center bg-surface/70 backdrop-blur-[1px]"
          >
            <span class="flex items-center gap-2 text-xs font-medium text-ink-soft">
              <AppSpinner size="sm" />
              {{ filtering ? 'Фильтруем…' : 'Обновляем лог…' }}
            </span>
          </div>
        </Transition>

        <div class="overflow-x-auto">
          <table class="min-w-full text-left">
            <thead class="border-b border-line bg-surface-2 text-[11px] uppercase tracking-wider text-ink-mute">
              <tr>
                <th class="px-4 py-3 font-medium">Получен</th>
                <th class="px-4 py-3 font-medium">Метод</th>
                <th class="px-4 py-3 font-medium">Эндпоинт</th>
                <th class="px-4 py-3 font-medium">Путь</th>
                <th class="px-4 py-3 font-medium">Размер</th>
                <th class="px-4 py-3 font-medium">Маршрутизация</th>
                <th class="px-4 py-3"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr
                v-for="row in items"
                :key="row.id"
                class="cursor-pointer transition hover:bg-surface-2"
                @click="openRequest(row)"
              >
                <td class="whitespace-nowrap px-4 py-3 font-mono text-[11px] text-ink-soft">
                  {{ formatTime(row.received_at) }}
                </td>
                <td class="px-4 py-3"><MethodBadge :method="row.method" /></td>
                <td class="px-4 py-3">
                  <span class="inline-block max-w-40 truncate rounded-md bg-ink/5 px-2 py-0.5 font-mono text-xs text-ink-soft">
                    {{ row.endpoint }}
                  </span>
                </td>
                <td class="max-w-[22rem] truncate px-4 py-3 font-mono text-xs text-ink-soft">{{ row.path }}</td>
                <td class="whitespace-nowrap px-4 py-3 font-mono text-[11px] text-ink-mute">
                  {{ formatBytes(row.body_bytes) }}
                </td>
                <td class="whitespace-nowrap px-4 py-3 text-xs">
                  <span v-if="row.pending_runs" class="text-indigo-700 dark:text-indigo-300">в очереди: {{ row.pending_runs }}</span>
                  <span v-if="row.run_count" class="text-ink-soft" :class="{ 'ml-1': row.pending_runs }">запусков: {{ row.run_count }}</span>
                  <span v-if="row.failed_runs" class="ml-1 text-rose-600 dark:text-rose-300">· ошибок: {{ row.failed_runs }}</span>
                  <span v-if="!row.run_count" class="text-ink-mute">без правил</span>
                </td>
                <td class="px-4 py-3 text-right text-ink-mute">→</td>
              </tr>

              <tr v-if="!listLoading && items.length === 0">
                <td colspan="7" class="px-4 py-16 text-center">
                  <p class="text-sm font-medium text-ink">Пока ничего не поймано</p>
                  <p class="mx-auto mt-1 max-w-md text-xs text-ink-mute">
                    Отправьте запрос на <code class="font-mono text-indigo-600 dark:text-indigo-300">{{ webhookUrl }}</code>
                    — он появится здесь сразу.
                  </p>
                </td>
              </tr>

              <tr v-if="showInlineLoader">
                <td colspan="7" class="px-4 py-16 text-center">
                  <span class="inline-flex items-center gap-2 text-xs text-ink-soft">
                    <AppSpinner size="sm" />
                    {{ filtering ? 'Фильтруем…' : 'Загружаем лог…' }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3 text-xs text-ink-mute">
          <span>
            {{ meta.total }} запросов · страница {{ meta.page }} из {{ meta.pages }}
          </span>
          <div class="flex items-center gap-1.5">
            <button
              type="button"
              class="btn px-2.5 py-1"
              :disabled="meta.page <= 1"
              @click="goToPage(meta.page - 1)"
            >
              Назад
            </button>
            <button
              type="button"
              class="btn px-2.5 py-1"
              :disabled="meta.page >= meta.pages"
              @click="goToPage(meta.page + 1)"
            >
              Вперёд
            </button>
          </div>
        </footer>
      </section>
    </div>

    <Teleport to="body">
      <Transition name="fade">
        <div
          v-if="showDetail"
          class="fixed inset-0 z-40 bg-slate-900/30 backdrop-blur-sm dark:bg-slate-950/70"
          @click="closeDetail"
        ></div>
      </Transition>

      <Transition name="fade">
        <aside
          v-if="showDetail"
          class="fixed inset-y-0 right-0 z-50 flex w-full max-w-2xl flex-col border-l border-line bg-surface shadow-2xl"
        >
          <div v-if="detailLoading" class="flex h-full items-center justify-center gap-2 text-sm text-ink-soft">
            <AppSpinner size="sm" />
            Загружаем запрос…
          </div>
          <RequestDetail
            v-else-if="detail"
            :request="detail"
            :runs="runs"
            class="h-full"
            @close="closeDetail"
            @delete="removeRequest"
          />
        </aside>
      </Transition>
    </Teleport>
  </AppShell>
</template>
