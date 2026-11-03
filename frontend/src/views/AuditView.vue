<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppShell from '../components/AppShell.vue'
import AppInput from '../components/ui/AppInput.vue'
import AppSelect from '../components/ui/AppSelect.vue'
import AppSpinner from '../components/ui/AppSpinner.vue'
import { api } from '../api/client'
import { retain, subscribe } from '../composables/realtime'
import { useRefresh } from '../composables/useRefresh'
import { useRoomsStore } from '../stores/rooms'
import { formatClock, formatTime, timeAgo } from '../utils/format'

const route = useRoute()
const rooms = useRoomsStore()

const slug = computed(() => (typeof route.params.slug === 'string' ? route.params.slug : ''))
const room = computed(() => (slug.value ? rooms.find(slug.value) : null))

/**
 * The feed is installation-wide by default, because the question "what did this
 * person do" is not room-scoped. `scope` switches between the two, and only the
 * room view sends `?room=`, so the global one keeps entries of deleted rooms.
 */
const scope = ref('room')
const items = ref([])
const loading = ref(false)
const error = ref('')

const filters = ref({ action: '', actor: '', q: '' })
const meta = ref({ page: 1, per_page: 25, total: 0, pages: 1, actions: [] })

// Actions are grouped for the dropdown: a flat list of twenty values is a wall.
const actionLabels = {
  'room.created': 'Комната создана',
  'room.updated': 'Комната изменена',
  'room.deleted': 'Комната удалена',
  'room.secret_issued': 'Выпущен секрет подписи',
  'room.secret_cleared': 'Секрет подписи снят',
  'workflow.created': 'Сценарий создан',
  'workflow.updated': 'Сценарий изменён',
  'workflow.deleted': 'Сценарий удалён',
  'workflow.toggled': 'Сценарий включён/выключен',
  'workflow.duplicated': 'Сценарий скопирован',
  'workflow.imported': 'Сценарии импортированы',
  'workflow.rolled_back': 'Сценарий откатан',
  'request.deleted': 'Запрос удалён',
  'request.replayed': 'Запрос маршрутизирован повторно',
  'run.retried': 'Запуск повторён',
  'breaker.reset': 'Сброшен предохранитель',
  'channel.created': 'Канал уведомлений создан',
  'channel.updated': 'Канал уведомлений изменён',
  'channel.deleted': 'Канал уведомлений удалён',
  'channel.tested': 'Канал уведомлений проверен',
  'user.created': 'Пользователь создан',
  'user.updated': 'Пользователь изменён',
  'user.deleted': 'Пользователь удалён',
  'user.password_changed': 'Пароль изменён',
}

const actionOptions = computed(() => [
  { value: '', label: scope.value === 'room' ? 'Все действия' : 'Все действия (вся установка)' },
  ...meta.value.actions.map((action) => ({
    value: action,
    label: actionLabels[action] || action,
  })),
])

let offRealtime = null
let releaseLive = null
let reloadTimer = null
let searchTimer = null
let loadSeq = 0

async function load({ silent = false } = {}) {
  const seq = ++loadSeq
  const roomSlug = slug.value
  const scoped = scope.value === 'room'

  if (!silent) {
    loading.value = true
  }

  try {
    const data = await api.audit({
      room: scoped ? roomSlug : '',
      action: filters.value.action,
      actor: filters.value.actor.trim(),
      q: filters.value.q.trim(),
      page: meta.value.page,
      per_page: meta.value.per_page,
    })

    if (seq !== loadSeq || roomSlug !== slug.value) {
      return
    }

    items.value = data.data
    meta.value = { ...meta.value, ...data.meta }
    error.value = ''
  } catch (e) {
    if (seq === loadSeq && roomSlug === slug.value) {
      error.value = e.message
    }
  } finally {
    if (!silent && seq === loadSeq) {
      loading.value = false
    }
  }
}

const { busy: refreshing, updatedAt, run: refreshNow } = useRefresh(() => load())

/**
 * One visible loader per surface.
 *
 * `loading` (first paint, filter reload) and `refreshing` (the button, held for a
 * minimum so a fast local API still shows the click) overlap, and they used to
 * paint an overlay over the empty-state spinner. Both indicators are therefore
 * derived from one predicate and are mutually exclusive by construction.
 */
const listLoading = computed(() => loading.value || refreshing.value)
const showOverlay = computed(() => listLoading.value && items.value.length > 0)
const showInlineLoader = computed(() => listLoading.value && items.value.length === 0)

function refreshSoon() {
  clearTimeout(reloadTimer)
  reloadTimer = setTimeout(() => load({ silent: true }), 300)
}

function goToPage(page) {
  if (page < 1 || page > meta.value.pages) {
    return
  }

  meta.value.page = page
  load()
}

watch(
  filters,
  () => {
    clearTimeout(searchTimer)
    meta.value.page = 1
    searchTimer = setTimeout(() => load(), 250)
  },
  { deep: true },
)

watch(scope, () => {
  meta.value.page = 1
  load()
})

watch(slug, () => {
  items.value = []
  meta.value = { ...meta.value, page: 1, total: 0, pages: 1 }
  load()
})

/**
 * A change made in another tab is exactly what this page is about, so it reloads
 * on every journal event rather than waiting for a manual refresh.
 */
function onAudit() {
  refreshSoon()
}

onMounted(() => {
  load()

  offRealtime = [
    subscribe('audit.recorded', onAudit),
    subscribe('resync', refreshSoon),
  ]

  releaseLive = retain()
})

onUnmounted(() => {
  for (const off of offRealtime ?? []) {
    off()
  }
  releaseLive?.()
  releaseLive = null
  clearTimeout(reloadTimer)
  clearTimeout(searchTimer)
})

/** A short, human label for an object that may already be deleted. */
function objectLabel(entry) {
  return entry.object_label || (entry.object_id ? `#${entry.object_id}` : '—')
}

/**
 * `changes` is `{field: [before, after]}`. Values are rendered as compact JSON:
 * a condition tree is summarized by the server as `{mode, count}`, so what
 * arrives here is already short enough to read.
 */
function changeRows(entry) {
  const changes = entry.changes
  if (!changes || typeof changes !== 'object' || Array.isArray(changes)) {
    return []
  }

  return Object.entries(changes).map(([field, pair]) => ({
    field: fieldLabels[field] || field,
    before: formatValue(Array.isArray(pair) ? pair[0] : null),
    after: formatValue(Array.isArray(pair) ? pair[1] : null),
  }))
}

const fieldLabels = {
  name: 'название',
  slug: 'slug',
  endpoint: 'эндпоинт',
  enabled: 'включён',
  conditions: 'условия',
  actions: 'действия',
  priority: 'приоритет',
  stop_processing: 'остановка правил',
  actions_version: 'версия действий',
  dedup_header: 'заголовок дедупликации',
  require_timestamp: 'требовать время',
  has_secret: 'секрет подписи',
  email: 'email',
  role: 'роль',
  password: 'пароль',
  version: 'версия',
  created: 'создано',
  secrets_not_imported: 'подписей не перенесено',
  queued: 'в очереди',
  kind: 'тип',
  notify_on_failure: 'сообщать о падениях',
  has_url: 'адрес задан',
  has_token: 'токен задан',
  chat_id: 'chat_id',
  replaces: 'заменяет запуск',
}

function formatValue(value) {
  if (value === null || value === undefined) {
    return '—'
  }
  if (typeof value === 'boolean') {
    return value ? 'да' : 'нет'
  }
  if (typeof value === 'object') {
    if (Array.isArray(value)) {
      return `${value.length} шт.`
    }
    if ('count' in value && 'mode' in value) {
      return `${value.mode === 'any' ? 'любое из' : 'все из'} · ${value.count}`
    }
    if ('count' in value) {
      return `${value.count} шт.`
    }

    return JSON.stringify(value)
  }

  return String(value)
}

const toneByAction = {
  deleted: 'text-rose-600 dark:text-rose-300',
  'room.deleted': 'text-rose-600 dark:text-rose-300',
  'workflow.deleted': 'text-rose-600 dark:text-rose-300',
  'user.deleted': 'text-rose-600 dark:text-rose-300',
  'room.secret_issued': 'text-amber-700 dark:text-amber-300',
  'breaker.reset': 'text-amber-700 dark:text-amber-300',
  'channel.deleted': 'text-rose-600 dark:text-rose-300',
  'channel.tested': 'text-amber-700 dark:text-amber-300',
}

function actionTone(entry) {
  return toneByAction[entry.action] || 'text-ink-soft'
}
</script>

<template>
  <AppShell>
    <div class="mx-auto max-w-7xl space-y-6">
      <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">История</p>
          <h1 class="mt-1 text-2xl font-semibold text-ink">Аудит · {{ room?.name || 'комната' }}</h1>
          <p class="mt-1 text-sm text-ink-soft">
            Кто и когда менял комнаты, сценарии и настройки. Записи остаются после удаления объекта.
          </p>
        </div>

        <div class="flex items-center gap-2">
          <span v-if="updatedAt" class="font-mono text-[11px] text-ink-mute">обновлено {{ formatClock(updatedAt) }}</span>
          <button type="button" class="btn" :disabled="refreshing" @click="refreshNow">
            <AppSpinner v-if="refreshing" size="xs" />
            {{ refreshing ? 'Обновляем…' : 'Обновить' }}
          </button>
        </div>
      </header>

      <div class="card flex flex-wrap items-center gap-2 p-3">
        <div class="flex rounded-lg border border-line p-0.5 text-xs">
          <button
            type="button"
            class="rounded-md px-2.5 py-1 transition"
            :class="scope === 'room' ? 'bg-indigo-500/10 font-medium text-indigo-700 dark:text-indigo-300' : 'text-ink-mute'"
            @click="scope = 'room'"
          >
            Эта комната
          </button>
          <button
            type="button"
            class="rounded-md px-2.5 py-1 transition"
            :class="scope === 'all' ? 'bg-indigo-500/10 font-medium text-indigo-700 dark:text-indigo-300' : 'text-ink-mute'"
            @click="scope = 'all'"
          >
            Вся установка
          </button>
        </div>

        <AppSelect v-model="filters.action" :options="actionOptions" size="sm" class="w-56" />
        <AppInput v-model="filters.actor" size="sm" placeholder="Кто (email)" class="w-44" />
        <AppInput v-model="filters.q" size="sm" placeholder="Что изменилось" class="min-w-40 flex-1" />
        <span class="text-[11px] text-ink-mute">найдено: {{ meta.total }}</span>
      </div>

      <p v-if="error" class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-600 dark:text-rose-300">
        {{ error }}
      </p>

      <section class="relative">
        <Transition name="fade">
          <div
            v-if="showOverlay"
            class="absolute inset-0 z-10 grid place-items-center rounded-2xl bg-surface/70 backdrop-blur-[1px]"
          >
            <span class="flex items-center gap-2 text-xs font-medium text-ink-soft">
              <AppSpinner size="sm" />
              Обновляем журнал…
            </span>
          </div>
        </Transition>

        <section
          v-if="!listLoading && items.length === 0"
          class="rounded-2xl border border-dashed border-line bg-surface px-6 py-16 text-center"
        >
          <h2 class="text-sm font-semibold text-ink">Записей нет</h2>
          <p class="mx-auto mt-1 max-w-sm text-xs text-ink-mute">
            Здесь появятся изменения, сделанные через интерфейс: переименование комнаты, правка сценария,
            сброс предохранителя, работа с пользователями.
          </p>
        </section>

        <section v-else-if="showInlineLoader" class="rounded-2xl border border-line bg-surface px-6 py-16 text-center">
          <span class="inline-flex items-center gap-2 text-xs text-ink-soft">
            <AppSpinner size="sm" />
            Загружаем журнал…
          </span>
        </section>

        <div v-else class="card divide-y divide-line" :class="{ 'opacity-60': showOverlay }">
          <article v-for="entry in items" :key="entry.id" class="px-4 py-3">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-mono text-[11px] text-ink-mute">#{{ entry.id }}</span>
              <span class="text-sm font-medium" :class="actionTone(entry)">
                {{ actionLabels[entry.action] || entry.action }}
              </span>
              <span class="rounded-md bg-ink/5 px-1.5 py-0.5 font-mono text-[10px] text-ink-soft">
                {{ objectLabel(entry) }}
              </span>
              <span v-if="entry.room_slug && scope === 'all'" class="font-mono text-[10px] text-ink-mute">
                комната {{ entry.room_slug }}
              </span>
            </div>

            <p class="mt-1 break-words text-[12px] text-ink-soft">{{ entry.summary || '—' }}</p>

            <p class="mt-1 text-[11px] text-ink-mute">
              {{ formatTime(entry.created_at) }}
              <span class="text-ink-mute/70">({{ timeAgo(entry.created_at) }})</span>
              · {{ entry.actor_email || 'система' }}
              <span v-if="entry.actor_role" class="font-mono">· {{ entry.actor_role }}</span>
              <span v-if="entry.ip" class="font-mono">· {{ entry.ip }}</span>
            </p>

            <dl v-if="changeRows(entry).length" class="mt-2 space-y-1">
              <div
                v-for="row in changeRows(entry)"
                :key="row.field"
                class="flex flex-wrap items-baseline gap-1.5 text-[11px]"
              >
                <dt class="text-ink-mute">{{ row.field }}:</dt>
                <dd class="font-mono text-ink-mute line-through decoration-rose-500/40">{{ row.before }}</dd>
                <span class="text-ink-mute">→</span>
                <dd class="font-mono text-ink-soft">{{ row.after }}</dd>
              </div>
            </dl>
          </article>
        </div>
      </section>

      <div v-if="meta.pages > 1" class="flex items-center justify-between gap-2">
        <span class="text-[11px] text-ink-mute">страница {{ meta.page }} из {{ meta.pages }}</span>
        <div class="flex items-center gap-1">
          <button type="button" class="btn px-3 py-1.5" :disabled="meta.page <= 1" @click="goToPage(meta.page - 1)">
            Назад
          </button>
          <button type="button" class="btn px-3 py-1.5" :disabled="meta.page >= meta.pages" @click="goToPage(meta.page + 1)">
            Вперёд
          </button>
        </div>
      </div>
    </div>
  </AppShell>
</template>
