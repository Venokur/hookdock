<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppShell from '../components/AppShell.vue'
import MethodBadge from '../components/MethodBadge.vue'
import RequestDetail from '../components/RequestDetail.vue'
import AppInput from '../components/ui/AppInput.vue'
import AppSelect from '../components/ui/AppSelect.vue'
import AppSpinner from '../components/ui/AppSpinner.vue'
import AppCheckbox from '../components/ui/AppCheckbox.vue'
import { api } from '../api/client'
import { confirm, notify } from '../composables/dialog'
import { retain, status as realtimeStatus, subscribe } from '../composables/realtime'
import { useRefresh } from '../composables/useRefresh'
import { useAuthStore } from '../stores/auth'
import { useRoomsStore } from '../stores/rooms'
import { formatBytes, formatClock, formatTime } from '../utils/format'

const route = useRoute()
const rooms = useRoomsStore()
// `canWrite` folds the role and the token scope into one flag, so a viewer and a
// read-only token are both spared a button the API would refuse.
const auth = useAuthStore()

const slug = computed(() => (typeof route.params.slug === 'string' ? route.params.slug : ''))
const room = computed(() => (slug.value ? rooms.find(slug.value) : null))

const items = ref([])
const meta = reactive({ page: 1, per_page: 25, total: 0, pages: 1 })
const stats = ref(null)
const filters = reactive({ method: 'ALL', q: '', endpoint: '', period: 'all', from: '', to: '' })

// Endpoint panel search: the same division the sidebar uses for rooms — a page
// from `stats`, and matches fetched on demand for everything else.
const endpointSearch = ref('')
const endpointHits = ref([])
const endpointSearching = ref(false)
const endpointSearchMeta = reactive({ total: 0, returned: 0, has_more: false })
let endpointSeq = 0

const loading = ref(false)
const error = ref('')
const copied = ref(false)
const exportFormat = ref('json')
const exporting = ref(false)

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

/**
 * Selected rows for a bulk action.
 *
 * An explicit id list, never "everything that matches the filter": the API takes
 * ids for the same reason, and the visible page is the only thing the operator
 * can actually see before clicking. Cleared whenever the list is reloaded for a
 * different query, because keeping ids that are no longer on screen would let
 * «Удалить выбранные» act on rows nobody is looking at.
 */
const selected = ref([])
const bulkBusy = ref(false)

const allSelected = computed(() => items.value.length > 0 && selected.value.length === items.value.length)
const someSelected = computed(() => selected.value.length > 0 && !allSelected.value)

function isSelected(id) {
  return selected.value.includes(id)
}

function toggleSelected(id) {
  selected.value = isSelected(id)
    ? selected.value.filter((value) => value !== id)
    : [...selected.value, id]
}

function toggleAll() {
  selected.value = allSelected.value ? [] : items.value.map((row) => row.id)
}

function clearSelection() {
  selected.value = []
}

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

/**
 * Time presets are turned into `from` here, not on the server: "24 часа" means
 * the last 24 hours from now, and the API already accepts an absolute date.
 * `custom` passes the two datetime-local fields as-is.
 */
const PERIOD_HOURS = { '1h': 1, '24h': 24, '7d': 168, '30d': 720 }
const periodOptions = [
  { value: 'all', label: 'Всё время' },
  { value: '1h', label: 'Последний час' },
  { value: '24h', label: '24 часа' },
  { value: '7d', label: '7 дней' },
  { value: '30d', label: '30 дней' },
  { value: 'custom', label: 'Свой период' },
]
const exportOptions = [
  { value: 'json', label: 'JSON' },
  { value: 'csv', label: 'CSV' },
  { value: 'ndjson', label: 'NDJSON' },
]

/**
 * Endpoint filter options.
 *
 * The closed filter shows the room's 15 most frequent endpoints (`stats.endpoints`)
 * — enough to cover the usual case without a panel that scrolls for a screen. A
 * room can also receive on hundreds of paths, so the rest are reachable by typing
 * in the panel: the term goes to `/api/endpoints`, the same way the sidebar
 * searches rooms by name/slug. Options come from one of two lists (`endpointHits`
 * while searching), never from both, so a slow search cannot mix a stale page into
 * fresh matches.
 *
 * The selected value is always included: a filter that fell out of the top 15 is
 * still active, and a closed select would otherwise read "Все эндпоинты" while the
 * log stayed filtered.
 */
const endpointOptions = computed(() => {
  const source = endpointSearch.value.trim() === ''
    ? (stats.value?.endpoints ?? [])
    : endpointHits.value

  const known = source.map((item) => ({ value: item.endpoint, label: item.endpoint }))
  const current = filters.endpoint.trim()

  if (current !== '' && !known.some((option) => option.value === current)) {
    known.unshift({ value: current, label: current })
  }

  return [{ value: '', label: 'Все эндпоинты' }, ...known]
})

/**
 * Says how much of the list is on screen, so a truncated filter never looks like
 * a complete one. While searching, the count is the search's own meta.
 */
const endpointHint = computed(() => {
  const term = endpointSearch.value.trim()

  if (term !== '') {
    const meta = endpointSearchMeta
    // Empty matches need a line of their own: the panel still lists "Все
    // эндпоинты", so a bare list would read as "the search found nothing to show
    // and probably worked" instead of "there is no such endpoint".
    if (meta.total === 0) {
      return endpointSearching.value ? '' : 'Ничего не найдено.'
    }
    return meta.has_more
      ? `Найдено ${meta.total}, показаны первые ${meta.returned} — уточните запрос.`
      : `Найдено ${meta.total}.`
  }

  const total = stats.value?.endpoints_total ?? 0
  const shown = (stats.value?.endpoints ?? []).length

  return total > shown ? `Показаны ${shown} из ${total} — остальные через поиск.` : ''
})

async function searchEndpoints(term) {
  endpointSearch.value = term

  // Clearing the field goes back to the page from `stats`; there is nothing to
  // fetch, and a request per deletion of the last character would only add noise.
  if (term === '') {
    endpointSeq += 1
    endpointHits.value = []
    endpointSearching.value = false
    Object.assign(endpointSearchMeta, { total: 0, returned: 0, has_more: false })
    return
  }

  const seq = ++endpointSeq
  endpointSearching.value = true

  try {
    const res = await api.endpoints({ room: slug.value, q: term, limit: 15 })
    // A slower answer for an older term must not replace the current matches.
    if (seq !== endpointSeq || term !== endpointSearch.value) {
      return
    }

    endpointHits.value = res.data
    Object.assign(endpointSearchMeta, res.meta)
  } catch (e) {
    if (seq === endpointSeq) {
      endpointHits.value = []
      notify(e.message)
    }
  } finally {
    if (seq === endpointSeq) {
      endpointSearching.value = false
    }
  }
}

function toIso(value) {
  if (!value) {
    return ''
  }

  const date = new Date(value)

  return Number.isNaN(date.getTime()) ? '' : date.toISOString()
}

function rangeParams() {
  if (filters.period === 'custom') {
    const params = {}
    const from = toIso(filters.from)
    const to = toIso(filters.to)

    if (from) params.from = from
    if (to) params.to = to

    return params
  }

  const hours = PERIOD_HOURS[filters.period]

  return hours ? { from: new Date(Date.now() - hours * 3600 * 1000).toISOString() } : {}
}

/** Filters shared by the list and the export, so a download matches the screen. */
function filterParams() {
  return {
    method: filters.method,
    q: filters.q.trim(),
    endpoint: filters.endpoint.trim(),
    ...rangeParams(),
  }
}

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
  {
    label: 'Активные сценарии',
    value: stats.value?.workflows_active ?? 0,
    hint: (stats.value?.workflows_auto_paused ?? 0) > 0
      ? `выключено автоматически: ${stats.value.workflows_auto_paused}`
      : 'правила включены',
    alert: false,
  },
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
        ...filterParams(),
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

    // A selection cannot outlive the rows it was made on: paging, a filter
    // change or a deletion elsewhere would otherwise leave ids ticked that are
    // not on screen, and the bulk button would act on the invisible ones.
    const visible = new Set(items.value.map((row) => row.id))
    selected.value = selected.value.filter((id) => visible.has(id))
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

function hasActiveFilters() {
  return filters.method !== 'ALL'
    || filters.q !== ''
    || filters.endpoint !== ''
    || filters.period !== 'all'
    || filters.from !== ''
    || filters.to !== ''
}

function resetFilterValues() {
  filters.method = 'ALL'
  filters.q = ''
  filters.endpoint = ''
  filters.period = 'all'
  filters.from = ''
  filters.to = ''
}

/**
 * Applies a whole filter set in one tick and reports whether anything moved.
 *
 * The watcher ignores exactly one firing, so the flag must only be raised when a
 * value actually changes: writing the same set does not trigger Vue's watcher,
 * and a stale flag would swallow the user's next real edit.
 */
function applyFilters(values) {
  const before = JSON.stringify(filters)
  resetFilterValues()
  Object.assign(filters, values)

  return JSON.stringify(filters) !== before
}

watch(slug, () => {
  // Coming back to a room restores the question last asked there. A default set
  // is not stored, so a room nobody filtered opens unfiltered.
  suppressFilterReload = applyFilters(savedFiltersFor(slug.value) ?? defaultFilters())
  // A search term for the previous room would be sent with the new room's first
  // stats request; the endpoints of one room say nothing about another's. Bumping
  // the sequence also discards an answer that is still in flight.
  endpointSeq += 1
  endpointSearch.value = ''
  endpointHits.value = []
  endpointSearching.value = false
  Object.assign(endpointSearchMeta, { total: 0, returned: 0, has_more: false })
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
    // The room is captured here, not read in the timer: switching rooms before
    // the debounce must not save the old filter under the new room's key.
    const roomSlug = slug.value

    filterTimer = setTimeout(() => {
      meta.page = 1
      persistFilters(roomSlug)
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

/**
 * Opens a request the URL names, for links from the run journal.
 *
 * The journal knows a run's `request_id` but not its row (the request may even be on
 * another page of the log after a filter change), so this fetches the detail
 * directly and lets the drawer fill itself in. An id that no longer exists closes
 * the drawer and reports it instead of leaving an empty panel.
 */
async function openRequestById(id) {
  showDetail.value = true
  detailLoading.value = true
  detail.value = null
  runs.value = []

  try {
    const data = await api.request(id)
    detail.value = data.data.request
    runs.value = data.data.runs
  } catch (e) {
    notify(e.message, { tone: 'error' })
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

/**
 * A corrected copy was created: switch the drawer to the new row.
 *
 * Opening the copy is the whole point of the action — the operator wants to see
 * whether the corrected payload matched more rules — and leaving the original
 * card up would show the old result under a toast about a new request.
 */
async function onRedelivered(id) {
  await load({ silent: true })
  await openRequestById(id)
}

async function copyWebhook() {  try {
    await navigator.clipboard.writeText(webhookUrl.value)
    copied.value = true
    setTimeout(() => (copied.value = false), 1500)
  } catch {
    // Буфер обмена может быть недоступен.
  }
}

function resetFilters() {
  resetFilterValues()
}

/**
 * Saved filter sets, one per room.
 *
 * A filter is a question the operator asks repeatedly ("what did Stripe send
 * today"), and re-picking four controls on every page load makes the log feel
 * like a form. Stored in `localStorage` rather than on the server: it is a view
 * preference of this browser, not a property of the installation, and it must
 * keep working before the first API call.
 *
 * Only rooms with an actual filter are stored — a default set is not a
 * preference, and writing one would make «Сбросить» silently ineffective on the
 * next visit.
 */
const FILTERS_KEY = 'wr_filters'

function readSavedFilters() {
  try {
    const parsed = JSON.parse(localStorage.getItem(FILTERS_KEY) || '{}')

    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {}
  } catch {
    return {}
  }
}

/**
 * A saved set from an older build (or a hand-edited entry) must not be able to
 * put the list into a state the controls cannot represent: an unknown method or
 * period would render the select as «Все» while the request stayed filtered,
 * which reads as a broken filter rather than as a stale value.
 */
function savedFiltersFor(roomSlug) {
  if (!roomSlug) {
    return null
  }

  const saved = readSavedFilters()[roomSlug]
  if (!saved || typeof saved !== 'object') {
    return null
  }

  const periods = ['all', 'custom', ...Object.keys(PERIOD_HOURS)]

  return {
    method: methods.includes(saved.method) ? saved.method : 'ALL',
    q: typeof saved.q === 'string' ? saved.q : '',
    endpoint: typeof saved.endpoint === 'string' ? saved.endpoint : '',
    period: periods.includes(saved.period) ? saved.period : 'all',
    from: typeof saved.from === 'string' ? saved.from : '',
    to: typeof saved.to === 'string' ? saved.to : '',
  }
}

function defaultFilters() {
  return { method: 'ALL', q: '', endpoint: '', period: 'all', from: '', to: '' }
}

function persistFilters(roomSlug = slug.value) {
  if (!roomSlug) {
    return
  }

  const all = readSavedFilters()
  if (hasActiveFilters()) {
    all[roomSlug] = { ...filters }
  } else {
    delete all[roomSlug]
  }

  try {
    localStorage.setItem(FILTERS_KEY, JSON.stringify(all))
  } catch {
    // Private mode or a full quota: losing the preference is not an error.
  }
}

/**
 * Re-routes the open request through the room's enabled workflows. The stored
 * row is not duplicated: the API only queues new runs, so the log keeps one
 * entry per delivery while the operator can retry a fix.
 */
async function replayRequest() {
  const id = detail.value?.id
  if (!id) {
    return
  }

  try {
    const data = await api.replayRequest(id)
    const queued = data.data.queued ?? 0

    notify(
      queued > 0
        ? `Сценарии поставлены в очередь: ${queued}`
        : 'Нет активных сценариев для этого эндпоинта',
      { tone: 'info' },
    )

    await refreshDetail()
  } catch (e) {
    notify(e.message)
  }
}

/**
 * Queues a fresh run of the same workflow. The failed run stays in the log as
 * evidence; the new one executes every action again.
 */
async function retryRun(runId) {
  try {
    await api.retryRun(runId)
    notify('Запуск поставлен в очередь', { tone: 'info' })
    await refreshDetail()
  } catch (e) {
    notify(e.message)
  }
}

/**
 * Re-routes everything selected in one call.
 *
 * One request instead of N: a page of 25 replays used to be 25 round trips, each
 * with its own toast, and a failure in the middle left the operator unsure what
 * had been queued. The API reports how many rows it skipped (a run still in
 * flight) and how many ids no longer exist, which is more truthful than a
 * blanket "готово".
 */
async function replaySelected() {
  if (selected.value.length === 0) {
    return
  }

  bulkBusy.value = true

  try {
    const data = await api.bulkRequests('replay', selected.value)
    const { count, queued, skipped, missing } = data.data

    const details = [
      queued > 0 ? `в очередь: ${queued}` : 'нет активных сценариев',
      skipped.length > 0 ? `пропущено: ${skipped.length} (запуск ещё идёт)` : '',
      missing.length > 0 ? `не найдено: ${missing.length}` : '',
    ].filter(Boolean)

    notify(`Повторно маршрутизировано: ${count}. ${details.join(', ')}`, {
      tone: skipped.length > 0 || missing.length > 0 ? 'warning' : 'info',
    })

    clearSelection()
    await load({ silent: true })
  } catch (e) {
    notify(e.message)
  } finally {
    bulkBusy.value = false
  }
}

/**
 * Deletes the selected rows after an explicit confirmation that names the count.
 *
 * The dialog is not decoration: the log is the only record of what a sender
 * actually sent, and there is no undo. A request with a run still in flight is
 * refused by the API per row, so the answer says which ones stayed.
 */
async function deleteSelected() {
  if (selected.value.length === 0) {
    return
  }

  const count = selected.value.length
  const ok = await confirm({
    title: 'Удалить запросы?',
    message: `Будет удалено запросов: ${count}. Записи и их журнал запусков исчезнут без возможности отмены.`
      + ' Запросы, у которых маршрутизация ещё выполняется, останутся.',
    confirmLabel: `Удалить ${count}`,
    tone: 'danger',
  })

  if (!ok) {
    return
  }

  bulkBusy.value = true

  try {
    const data = await api.bulkRequests('delete', selected.value)
    const { count: deleted, skipped, missing } = data.data

    const details = [
      skipped.length > 0 ? `пропущено: ${skipped.length} (маршрутизация идёт)` : '',
      missing.length > 0 ? `не найдено: ${missing.length}` : '',
    ].filter(Boolean)

    notify(`Удалено: ${deleted}. ${details.join(', ')}`, {
      tone: skipped.length > 0 || missing.length > 0 ? 'warning' : 'info',
    })

    clearSelection()

    // The open card may be one of the deleted rows; leaving it up would show a
    // request the API no longer has.
    if (detail.value?.id && !items.value.some((row) => row.id === detail.value.id)) {
      showDetail.value = false
      detail.value = null
      runs.value = []
    }

    await load({ silent: true })
  } catch (e) {
    notify(e.message)
  } finally {
    bulkBusy.value = false
  }
}

/**
 * Downloads the current selection as a file. The request goes through `fetch`
 * because the API needs a Bearer token, which a plain link cannot send.
 */
async function exportNow() {
  exporting.value = true

  try {
    const { blob, filename } = await api.exportRequests({
      room: slug.value,
      format: exportFormat.value,
      ...filterParams(),
    })

    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)

    notify(`Экспорт готов: ${filename}`, { tone: 'info' })
  } catch (e) {
    notify(e.message)
  } finally {
    exporting.value = false
  }
}

onMounted(() => {
  // Restore before the first request: loading unfiltered and then reloading a
  // tick later would paint one list and immediately replace it. The watcher
  // consumes the flag on the next tick and stays silent, because this load
  // already uses the restored values.
  suppressFilterReload = applyFilters(savedFiltersFor(slug.value) ?? defaultFilters())
  loadWithLoader()

  // A link from the run journal: open the request it names.
  const requested = Number(route.query.request)
  if (Number.isFinite(requested) && requested > 0) {
    openRequestById(requested)
  }

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

        <div class="flex flex-wrap items-center gap-3">
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
          <span v-if="room?.has_secret" class="text-emerald-700 dark:text-emerald-300">
            · подпись обязательна (<code class="font-mono">X-Hookdock-Signature</code>)
          </span>
          <span v-else>· подпись не требуется</span>
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
          class="w-[calc(50%-0.25rem)] sm:w-36"
        />

        <AppSelect
          v-model="filters.period"
          :options="periodOptions"
          size="sm"
          aria-label="Период"
          class="w-[calc(50%-0.25rem)] sm:w-40"
        />

        <template v-if="filters.period === 'custom'">
          <AppInput
            v-model="filters.from"
            type="datetime-local"
            size="sm"
            aria-label="Начало периода"
            class="w-full sm:w-52"
          />
          <AppInput
            v-model="filters.to"
            type="datetime-local"
            size="sm"
            aria-label="Конец периода"
            class="w-full sm:w-52"
          />
        </template>

        <AppSelect
          v-model="filters.endpoint"
          :options="endpointOptions"
          size="sm"
          searchable
          search-placeholder="Поиск эндпоинта"
          :searching="endpointSearching"
          :hint="endpointHint"
          aria-label="Фильтр по эндпоинту"
          class="w-[calc(50%-0.25rem)] sm:w-44"
          @search="searchEndpoints"
        />

        <AppInput
          v-model="filters.q"
          type="search"
          size="sm"
          placeholder="Поиск по пути или телу…"
          class="min-w-0 flex-1 basis-40"
        />

        <button type="button" class="btn border-transparent bg-transparent" @click="resetFilters">
          Сбросить
        </button>

        <!-- The export controls keep their own row on a phone: next to the filters
             they squeeze the search field into an unusable width. -->
        <div class="flex w-full items-center gap-2 sm:ml-auto sm:w-auto">
          <AppSelect
            v-model="exportFormat"
            :options="exportOptions"
            size="sm"
            aria-label="Формат экспорта"
            class="w-28"
          />
          <button type="button" class="btn" :disabled="exporting" @click="exportNow">
            <AppSpinner v-if="exporting" size="xs" />
            {{ exporting ? 'Готовим…' : 'Экспорт' }}
          </button>
        </div>
      </section>

      <!-- Bulk actions appear only with a selection, and the count is in the bar
           itself: "delete selected" with no visible number is a button nobody
           dares press. The bar sits between the filters and the log so it cannot
           be mistaken for a row of the table. -->
      <section
        v-if="selected.length > 0"
        class="flex flex-wrap items-center gap-2 rounded-2xl border border-indigo-500/30 bg-indigo-500/[0.08] px-4 py-3"
      >
        <span class="text-xs font-medium text-ink">
          Выбрано: {{ selected.length }}<span v-if="meta.total > items.length" class="text-ink-mute"> из {{ items.length }} на странице</span>
        </span>
        <button
          type="button"
          class="btn px-2.5 py-1 text-[11px]"
          :disabled="bulkBusy"
          @click="clearSelection"
        >
          Снять выбор
        </button>
        <div class="ml-auto flex flex-wrap items-center gap-2">
          <button
            v-if="auth.canWrite"
            type="button"
            class="btn"
            :disabled="bulkBusy"
            title="Запустить активные сценарии для выбранных запросов"
            @click="replaySelected"
          >
            <AppSpinner v-if="bulkBusy" size="xs" />
            Повторить маршрутизацию
          </button>
          <button
            v-if="auth.canWrite"
            type="button"
            class="btn-danger"
            :disabled="bulkBusy"
            @click="deleteSelected"
          >
            Удалить выбранные
          </button>
        </div>
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

        <!-- Seven columns do not fit a phone, and horizontal scrolling hides the
             method/time pair that makes a log readable at a glance. Below `md` the
             same rows render as cards; the table stays for wider screens. Both read
             `items`, so there is one data source and one loader. -->
        <div v-if="items.length" class="hidden overflow-x-auto md:block">
          <table class="min-w-full text-left">
            <thead class="border-b border-line bg-surface-2 text-[11px] uppercase tracking-wider text-ink-mute">
              <tr>
                <th class="w-10 px-4 py-3 font-medium">
                  <span class="sr-only">Выбрать все</span>
                  <AppCheckbox
                    v-if="auth.canWrite"
                    :model-value="allSelected"
                    :indeterminate="someSelected"
                    aria-label="Выбрать все запросы на странице"
                    @update:model-value="toggleAll"
                  />
                </th>
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
                :class="{ 'bg-indigo-500/[0.06]': isSelected(row.id) }"
                @click="openRequest(row)"
              >
                <td class="w-10 px-4 py-3" @click.stop>
                  <AppCheckbox
                    v-if="auth.canWrite"
                    :model-value="isSelected(row.id)"
                    :aria-label="`Выбрать запрос #${row.id}`"
                    @update:model-value="toggleSelected(row.id)"
                  />
                </td>
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
                  <span
                    v-if="row.duplicate_of"
                    class="mr-1 rounded bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider text-amber-700 dark:text-amber-300"
                    :title="`Повтор доставки #${row.duplicate_of}: сценарии не запускались`"
                  >повтор</span>
                  <span v-if="row.pending_runs" class="text-indigo-700 dark:text-indigo-300">в очереди: {{ row.pending_runs }}</span>
                  <span v-if="row.run_count" class="text-ink-soft" :class="{ 'ml-1': row.pending_runs }">запусков: {{ row.run_count }}</span>
                  <span v-if="row.failed_runs" class="ml-1 text-rose-600 dark:text-rose-300">· ошибок: {{ row.failed_runs }}</span>
                  <span v-if="!row.run_count" class="text-ink-mute">без правил</span>
                </td>
                <td class="px-4 py-3 text-right text-ink-mute">→</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- The phone layout needs its own "select all": the table header that
             carries it on wide screens is not rendered here, and a bulk action
             whose only entry point is a column nobody can see is a bug. -->
        <div
          v-if="items.length && auth.canWrite"
          class="flex items-center justify-between border-b border-line px-4 py-2 md:hidden"
        >
          <AppCheckbox
            :model-value="allSelected"
            :indeterminate="someSelected"
            label="Выбрать все на странице"
            @update:model-value="toggleAll"
          />
          <span class="text-[11px] text-ink-mute">{{ items.length }}</span>
        </div>

        <ul v-if="items.length" class="divide-y divide-line md:hidden">
          <li v-for="row in items" :key="row.id" class="flex items-start gap-2 px-4 py-3">
            <span v-if="auth.canWrite" class="pt-0.5">
              <AppCheckbox
                :model-value="isSelected(row.id)"
                :aria-label="`Выбрать запрос #${row.id}`"
                @update:model-value="toggleSelected(row.id)"
              />
            </span>
            <button
              type="button"
              class="block min-w-0 flex-1 text-left transition active:opacity-70"
              @click="openRequest(row)"
            >
              <div class="flex items-center gap-2">
                <MethodBadge :method="row.method" />
                <span class="min-w-0 flex-1 truncate font-mono text-xs text-ink">{{ row.path }}</span>
                <span class="shrink-0 text-ink-mute" aria-hidden="true">→</span>
              </div>

              <p class="mt-1.5 truncate font-mono text-[11px] text-ink-mute">
                {{ formatTime(row.received_at) }} · {{ row.endpoint }} · {{ formatBytes(row.body_bytes) }}
              </p>

              <p class="mt-1 text-[11px]">
                <span
                  v-if="row.duplicate_of"
                  class="mr-1 rounded bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider text-amber-700 dark:text-amber-300"
                >повтор</span>
                <span v-if="row.pending_runs" class="text-indigo-700 dark:text-indigo-300">в очереди: {{ row.pending_runs }}</span>
                <span v-if="row.run_count" class="text-ink-soft" :class="{ 'ml-1': row.pending_runs }">запусков: {{ row.run_count }}</span>
                <span v-if="row.failed_runs" class="ml-1 text-rose-600 dark:text-rose-300">· ошибок: {{ row.failed_runs }}</span>
                <span v-if="!row.run_count" class="text-ink-mute">без правил</span>
              </p>
            </button>
          </li>
        </ul>

        <!-- One feedback block for both layouts: the empty state and the inline
             loader are mutually exclusive with each other and with the overlay
             above, so no screen ever shows two spinners for one action. -->
        <div v-if="!listLoading && items.length === 0" class="px-4 py-16 text-center">
          <p class="text-sm font-medium text-ink">Пока ничего не поймано</p>
          <p class="mx-auto mt-1 max-w-md break-all text-xs text-ink-mute">
            Отправьте запрос на <code class="font-mono text-indigo-600 dark:text-indigo-300">{{ webhookUrl }}</code>
            — он появится здесь сразу.
          </p>
        </div>

        <div v-else-if="showInlineLoader" class="px-4 py-16 text-center">
          <span class="inline-flex items-center gap-2 text-xs text-ink-soft">
            <AppSpinner size="sm" />
            {{ filtering ? 'Фильтруем…' : 'Загружаем лог…' }}
          </span>
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
            @replay="replayRequest"
            @retry="retryRun"
            @redelivered="onRedelivered"
          />
        </aside>
      </Transition>
    </Teleport>
  </AppShell>
</template>
