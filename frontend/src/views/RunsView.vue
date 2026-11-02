<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppShell from '../components/AppShell.vue'
import AppInput from '../components/ui/AppInput.vue'
import AppSelect from '../components/ui/AppSelect.vue'
import AppSpinner from '../components/ui/AppSpinner.vue'
import StatusPill from '../components/StatusPill.vue'
import { api } from '../api/client'
import { confirm, notify } from '../composables/dialog'
import { retain, subscribe } from '../composables/realtime'
import { useRefresh } from '../composables/useRefresh'
import { useRoomsStore } from '../stores/rooms'
import { formatClock, formatTime, timeAgo } from '../utils/format'

const route = useRoute()
const router = useRouter()
const rooms = useRoomsStore()

const slug = computed(() => (typeof route.params.slug === 'string' ? route.params.slug : ''))
const room = computed(() => (slug.value ? rooms.find(slug.value) : null))

const items = ref([])
const loading = ref(false)
const error = ref('')

const filters = ref({ status: '', q: '' })
const meta = ref({ page: 1, per_page: 25, total: 0, pages: 1 })

const statusOptions = [
  { value: '', label: 'Все статусы' },
  { value: 'failed', label: 'Ошибка' },
  { value: 'pending', label: 'В очереди' },
  { value: 'running', label: 'Выполняется' },
  { value: 'success', label: 'Успех' },
  { value: 'skipped', label: 'Пропущен' },
]

let offRealtime = null
let releaseLive = null
let reloadTimer = null
let searchTimer = null
// A superseded response must not repaint the list of a newer filter.
let loadSeq = 0

async function load({ silent = false } = {}) {
  const seq = ++loadSeq
  const roomSlug = slug.value

  if (!silent) {
    loading.value = true
  }

  try {
    const data = await api.runs({
      room: roomSlug,
      status: filters.value.status,
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

watch(slug, () => {
  items.value = []
  meta.value = { ...meta.value, page: 1, total: 0, pages: 1 }
  load()
})

/**
 * A retry inserts a new run and leaves the failed one as evidence, so the list is
 * reloaded instead of patching the row — the old row must keep saying "failed".
 */
async function retryRun(run) {
  const confirmed = await confirm({
    title: 'Повторить запуск?',
    message: `Сценарий «${run.workflow_name}» будет выполнен заново для запроса #${run.request_id}. Прошлый запуск останется в журнале.`,
    confirmLabel: 'Повторить',
  })

  if (!confirmed) {
    return
  }

  try {
    await api.retryRun(run.id)
    notify('Запуск поставлен в очередь', { tone: 'success' })
    await load()
  } catch (e) {
    notify(e.message, { tone: 'error' })
  }
}

function openRequest(run) {
  router.push({ name: 'room-requests', params: { slug: slug.value }, query: { request: String(run.request_id) } })
}

onMounted(() => {
  load()

  offRealtime = [
    subscribe('request.updated', refreshSoon),
    subscribe('request.created', refreshSoon),
    subscribe('workflow.changed', refreshSoon),
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
</script>

<template>
  <AppShell>
    <div class="mx-auto max-w-7xl space-y-6">
      <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">Журнал</p>
          <h1 class="mt-1 text-2xl font-semibold text-ink">Запуски · {{ room?.name || 'комната' }}</h1>
          <p class="mt-1 text-sm text-ink-soft">
            Каждое выполнение сценария: прошлые попытки остаются, поэтому ошибка и её повтор видны рядом.
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
        <AppSelect v-model="filters.status" :options="statusOptions" size="sm" class="w-44" />
        <AppInput v-model="filters.q" size="sm" placeholder="Сценарий, путь или сообщение" class="min-w-48 flex-1" />
        <span class="text-[11px] text-ink-mute">найдено: {{ meta.total }}</span>
      </div>

      <p v-if="error" class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-600 dark:text-rose-300">
        {{ error }}
      </p>

      <section class="relative">
        <Transition name="fade">
          <div
            v-if="refreshing"
            class="absolute inset-0 z-10 grid place-items-center rounded-2xl bg-surface/70 backdrop-blur-[1px]"
          >
            <span class="flex items-center gap-2 text-xs font-medium text-ink-soft">
              <AppSpinner size="sm" />
              Обновляем журнал…
            </span>
          </div>
        </Transition>

        <section
          v-if="!loading && items.length === 0"
          class="rounded-2xl border border-dashed border-line bg-surface px-6 py-16 text-center"
        >
          <h2 class="text-sm font-semibold text-ink">Запусков нет</h2>
          <p class="mx-auto mt-1 max-w-sm text-xs text-ink-mute">
            Здесь появятся выполнения сценариев — по одному на каждое правило, сработавшее на пойманный запрос.
          </p>
        </section>

        <section v-else-if="loading && items.length === 0" class="rounded-2xl border border-line bg-surface px-6 py-16 text-center">
          <span class="inline-flex items-center gap-2 text-xs text-ink-soft">
            <AppSpinner size="sm" />
            Загружаем журнал…
          </span>
        </section>

        <div v-else class="card divide-y divide-line" :class="{ 'opacity-60': loading }">
          <article
            v-for="run in items"
            :key="run.id"
            class="flex flex-wrap items-start gap-3 px-4 py-3"
          >
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-mono text-[11px] text-ink-mute">#{{ run.id }}</span>
                <span class="truncate text-sm font-medium text-ink">{{ run.workflow_name }}</span>
                <StatusPill :status="run.status" />
                <span v-if="run.priority > 0" class="font-mono text-[10px] text-ink-mute">P{{ run.priority }}</span>
                <span v-if="run.attempts > 1" class="text-[10px] text-ink-mute">попыток: {{ run.attempts }}</span>
              </div>

              <p class="mt-1 break-words font-mono text-[11px] text-ink-mute">{{ run.message || '—' }}</p>

              <p class="mt-1 text-[11px] text-ink-mute">
                запрос #{{ run.request_id }} · {{ run.method }} · {{ run.path }}
                · шагов: {{ run.steps }} · {{ formatTime(run.created_at) }}
                <span class="text-ink-mute/70">({{ timeAgo(run.created_at) }})</span>
              </p>
            </div>

            <div class="flex shrink-0 items-center gap-1">
              <button
                type="button"
                class="rounded-lg px-2 py-1 text-xs text-ink-soft transition hover:bg-surface-2 hover:text-ink"
                @click="openRequest(run)"
              >
                Запрос
              </button>
              <button
                v-if="run.status === 'failed' || run.status === 'skipped'"
                type="button"
                class="rounded-lg px-2 py-1 text-xs text-ink-soft transition hover:bg-surface-2 hover:text-ink"
                @click="retryRun(run)"
              >
                Повторить
              </button>
            </div>
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
