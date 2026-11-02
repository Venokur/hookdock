<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppShell from '../components/AppShell.vue'
import AppSelect from '../components/ui/AppSelect.vue'
import AppSpinner from '../components/ui/AppSpinner.vue'
import { api } from '../api/client'
import { retain, subscribe } from '../composables/realtime'
import { useRefresh } from '../composables/useRefresh'
import { useRoomsStore } from '../stores/rooms'
import { formatBytes, formatClock, formatTime } from '../utils/format'

const route = useRoute()
const rooms = useRoomsStore()

const slug = computed(() => (typeof route.params.slug === 'string' ? route.params.slug : ''))
const room = computed(() => (slug.value ? rooms.find(slug.value) : null))

const data = ref(null)
const loading = ref(false)
const error = ref('')

const windowOptions = [
  { value: 1, label: 'Последний час' },
  { value: 6, label: '6 часов' },
  { value: 24, label: 'Сутки' },
  { value: 72, label: '3 дня' },
  { value: 168, label: 'Неделя' },
]

const filters = ref({ hours: 24 })

// A superseded response must not repaint a newer window.
let loadSeq = 0
let offRealtime = null
let releaseLive = null
let reloadTimer = null

async function load({ silent = false } = {}) {
  const seq = ++loadSeq
  const roomSlug = slug.value

  if (!silent && !data.value) {
    loading.value = true
  }

  try {
    const payload = await api.analytics({ room: roomSlug, hours: filters.value.hours })

    if (seq !== loadSeq || roomSlug !== slug.value) {
      return
    }

    data.value = payload.data
    error.value = ''
  } catch (e) {
    if (seq === loadSeq && roomSlug === slug.value) {
      error.value = e.message
    }
  } finally {
    if (seq === loadSeq) {
      loading.value = false
    }
  }
}

const { busy: refreshing, updatedAt, run: refreshNow } = useRefresh(() => load())

function refreshSoon() {
  clearTimeout(reloadTimer)
  reloadTimer = setTimeout(() => load({ silent: true }), 300)
}

watch(
  () => filters.value.hours,
  () => {
    // The window changed, so the numbers on screen describe the old one: clear
    // them instead of showing yesterday's totals under "последний час".
    data.value = null
    load()
  },
)

watch(slug, () => {
  data.value = null
  load()
})

const summary = computed(() => {
  const requests = data.value?.requests
  const runs = data.value?.runs

  return [
    {
      label: 'Запросов',
      value: requests ? requests.total.toLocaleString('ru-RU') : '—',
      hint: requests
        ? `${requests.per_hour.toLocaleString('ru-RU')} в час · ${formatBytes(requests.bytes)}`
        : 'за выбранное окно',
    },
    {
      label: 'Успешных запусков',
      value: runs?.success_rate === null || runs?.success_rate === undefined
        ? '—'
        : `${Math.round(runs.success_rate * 1000) / 10}%`,
      hint: runs ? `всего запусков: ${runs.total}` : 'доля от завершённых',
      tone:
        runs?.success_rate === null || runs?.success_rate === undefined
          ? ''
          : runs.success_rate < 0.9
            ? 'bad'
            : 'good',
    },
    {
      label: 'Ошибок',
      value: runs ? runs.by_status.failed.toLocaleString('ru-RU') : '—',
      hint: runs ? `пропущено: ${runs.by_status.skipped}` : 'за выбранное окно',
      tone: runs?.by_status.failed > 0 ? 'bad' : '',
    },
    {
      label: 'Действий',
      value: data.value?.actions ? data.value.actions.total.toLocaleString('ru-RU') : '—',
      hint: data.value?.actions ? 'шагов выполнения' : 'forward, log, transform',
    },
  ]
})

/**
 * The chart's vertical scale. A window with no traffic would divide by zero, and
 * an all-zero chart is still worth drawing, so the floor is 1 — an empty hour is
 * a visible baseline rather than a missing bar.
 */
const chartMax = computed(() => {
  const rows = data.value?.timeline ?? []

  return Math.max(1, ...rows.map((row) => row.requests))
})

/** Bars thinner than this are invisible; a nonzero hour must still show up. */
function barHeight(value) {
  if (!value) {
    return 0
  }

  return Math.max(4, Math.round((value / chartMax.value) * 100))
}

const timeline = computed(() => data.value?.timeline ?? [])

const actionRows = computed(() =>
  (data.value?.actions?.by_type ?? []).map((row) => ({
    ...row,
    failRate: row.total > 0 ? row.failed / row.total : 0,
  })),
)

/**
 * The API speaks `workflow_runs.status`; the page speaks Russian. Reusing the
 * same words as `StatusPill` keeps the run journal and this chart from naming
 * one outcome two different ways.
 */
const statusLabels = {
  pending: 'в очереди',
  running: 'выполняется',
  success: 'успех',
  failed: 'ошибка',
  skipped: 'пропущен',
}

function statusLabel(status) {
  return statusLabels[status] || status
}

function toneClass(tone) {
  return tone === 'bad' ? 'text-rose-600 dark:text-rose-300' : tone === 'good' ? 'text-emerald-700 dark:text-emerald-300' : 'text-ink'
}

onMounted(() => {
  load()

  offRealtime = [
    subscribe('request.created', refreshSoon),
    subscribe('request.updated', refreshSoon),
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
})
</script>

<template>
  <AppShell>
    <div class="mx-auto max-w-7xl space-y-6">
      <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">Наблюдаемость</p>
          <h1 class="mt-1 text-2xl font-semibold text-ink">Аналитика · {{ room?.name || 'комната' }}</h1>
          <p class="mt-1 text-sm text-ink-soft">
            Объём, доля успеха и время действий за окно. Медленная цель видна здесь раньше, чем в журнале.
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <AppSelect v-model="filters.hours" :options="windowOptions" size="sm" aria-label="Окно" class="w-40" />
          <span v-if="updatedAt" class="font-mono text-[11px] text-ink-mute">обновлено {{ formatClock(updatedAt) }}</span>
          <button type="button" class="btn" :disabled="refreshing" @click="refreshNow">
            <AppSpinner v-if="refreshing" size="xs" />
            {{ refreshing ? 'Обновляем…' : 'Обновить' }}
          </button>
        </div>
      </header>

      <p v-if="error" class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-600 dark:text-rose-300">
        {{ error }}
      </p>

      <section v-if="!data && loading" class="rounded-2xl border border-line bg-surface px-6 py-16 text-center">
        <span class="inline-flex items-center gap-2 text-xs text-ink-soft">
          <AppSpinner size="sm" />
          Считаем показатели…
        </span>
      </section>

      <template v-else-if="data">
        <section class="relative grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <Transition name="fade">
            <div
              v-if="refreshing"
              class="absolute inset-0 z-10 grid place-items-center rounded-2xl bg-surface/70 backdrop-blur-[1px]"
            >
              <span class="flex items-center gap-2 text-xs font-medium text-ink-soft">
                <AppSpinner size="sm" />
                Пересчитываем…
              </span>
            </div>
          </Transition>

          <article v-for="card in summary" :key="card.label" class="card p-4">
            <p class="text-[11px] uppercase tracking-wider text-ink-mute">{{ card.label }}</p>
            <p class="mt-2 text-2xl font-semibold" :class="toneClass(card.tone)">{{ card.value }}</p>
            <p class="mt-0.5 text-[11px] text-ink-mute">{{ card.hint }}</p>
          </article>
        </section>

        <section class="card p-4">
          <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
              <h2 class="text-sm font-semibold text-ink">Запросы по часам</h2>
              <p class="mt-0.5 text-[11px] text-ink-mute">
                Всего {{ data.requests.total }} за {{ data.window_hours }} ч · пик {{ chartMax }}
              </p>
            </div>
            <span class="text-[11px] text-ink-mute">
              повторов: {{ data.requests.duplicates }} · с правками: {{ data.requests.replayed }}
            </span>
          </div>

          <div class="mt-4 flex h-40 items-end gap-[2px] overflow-hidden">
            <div
              v-for="row in timeline"
              :key="row.hour"
              class="group relative flex-1 rounded-t bg-indigo-500/70 transition hover:bg-indigo-500"
              :class="{ 'bg-line/60 hover:bg-line': row.requests === 0 }"
              :style="{ height: `${barHeight(row.requests)}%` }"
            >
              <span
                class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded-md bg-ink px-2 py-1 font-mono text-[10px] text-surface group-hover:block"
              >
                {{ formatTime(row.hour) }} · {{ row.requests }}
              </span>
            </div>
          </div>

          <div class="mt-2 flex justify-between font-mono text-[10px] text-ink-mute">
            <span>{{ formatTime(data.since) }}</span>
            <span>сейчас</span>
          </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-2">
          <section class="card p-4">
            <h2 class="text-sm font-semibold text-ink">Действия</h2>
            <p class="mt-0.5 text-[11px] text-ink-mute">
              p95 считается по шагам: у <code class="font-mono">forward</code> это время ответа цели.
            </p>

            <p v-if="actionRows.length === 0" class="mt-6 text-center text-xs text-ink-mute">
              За это окно действий не было — сценарии не срабатывали.
            </p>

            <table v-else class="mt-3 w-full text-left text-xs">
              <thead class="text-[10px] uppercase tracking-wider text-ink-mute">
                <tr>
                  <th class="pb-2 font-medium">Тип</th>
                  <th class="pb-2 text-right font-medium">Шагов</th>
                  <th class="pb-2 text-right font-medium">Ошибок</th>
                  <th class="pb-2 text-right font-medium">p50</th>
                  <th class="pb-2 text-right font-medium">p95</th>
                  <th class="pb-2 text-right font-medium">Макс</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-line">
                <tr v-for="row in actionRows" :key="row.type">
                  <td class="py-2 font-mono text-ink">{{ row.type }}</td>
                  <td class="py-2 text-right text-ink-soft">{{ row.total }}</td>
                  <td class="py-2 text-right" :class="row.failed > 0 ? 'text-rose-600 dark:text-rose-300' : 'text-ink-mute'">
                    {{ row.failed }}
                  </td>
                  <td class="py-2 text-right font-mono text-ink-soft">{{ row.p50_ms }} мс</td>
                  <td class="py-2 text-right font-mono" :class="row.p95_ms > 1000 ? 'text-amber-700 dark:text-amber-300' : 'text-ink-soft'">
                    {{ row.p95_ms }} мс
                  </td>
                  <td class="py-2 text-right font-mono text-ink-mute">{{ row.max_ms }} мс</td>
                </tr>
              </tbody>
            </table>
          </section>

          <section class="card p-4">
            <h2 class="text-sm font-semibold text-ink">Исходы запусков</h2>
            <p class="mt-0.5 text-[11px] text-ink-mute">Доля успеха считается по завершённым, без очереди.</p>

            <div class="mt-4 space-y-2">
              <div v-for="(total, status) in data.runs.by_status" :key="status">
                <div class="flex items-center justify-between text-[11px]">
                  <span class="text-ink-soft">{{ statusLabel(status) }}</span>
                  <span class="font-mono text-ink-mute">{{ total }}</span>
                </div>
                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-surface-2">
                  <div
                    class="h-full rounded-full"
                    :class="{
                      'bg-emerald-500': status === 'success',
                      'bg-rose-500': status === 'failed',
                      'bg-indigo-500': status === 'running',
                      'bg-slate-400 dark:bg-slate-500': status === 'pending' || status === 'skipped',
                    }"
                    :style="{ width: `${data.runs.total > 0 ? Math.round((total / data.runs.total) * 100) : 0}%` }"
                  ></div>
                </div>
              </div>
            </div>
          </section>

          <section class="card p-4">
            <h2 class="text-sm font-semibold text-ink">Эндпоинты</h2>
            <p class="mt-0.5 text-[11px] text-ink-mute">
              Самые частые пути в окне — не больше десяти, чтобы список оставался списком.
            </p>

            <p v-if="data.requests.top_endpoints.length === 0" class="mt-6 text-center text-xs text-ink-mute">
              За это окно запросов не приходило.
            </p>

            <ul v-else class="mt-3 space-y-1.5">
              <li v-for="row in data.requests.top_endpoints" :key="row.endpoint" class="flex items-center gap-3 text-xs">
                <code class="min-w-0 flex-1 truncate font-mono text-ink">{{ row.endpoint }}</code>
                <span class="shrink-0 font-mono text-ink-mute">{{ row.total }}</span>
              </li>
            </ul>
          </section>

          <section class="card p-4">
            <h2 class="text-sm font-semibold text-ink">Методы</h2>
            <p class="mt-0.5 text-[11px] text-ink-mute">Что именно пишут отправители.</p>

            <p v-if="data.requests.by_method.length === 0" class="mt-6 text-center text-xs text-ink-mute">
              Пока пусто.
            </p>

            <ul v-else class="mt-3 space-y-1.5">
              <li v-for="row in data.requests.by_method" :key="row.method" class="flex items-center gap-3 text-xs">
                <span class="w-16 shrink-0 font-mono font-medium text-ink">{{ row.method }}</span>
                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-2">
                  <div
                    class="h-full rounded-full bg-indigo-500"
                    :style="{ width: `${data.requests.total > 0 ? Math.round((row.total / data.requests.total) * 100) : 0}%` }"
                  ></div>
                </div>
                <span class="w-10 shrink-0 text-right font-mono text-ink-mute">{{ row.total }}</span>
              </li>
            </ul>
          </section>
        </div>
      </template>

      <section
        v-else-if="!loading"
        class="rounded-2xl border border-dashed border-line bg-surface px-6 py-16 text-center"
      >
        <h2 class="text-sm font-semibold text-ink">Показателей нет</h2>
        <p class="mx-auto mt-1 max-w-sm text-xs text-ink-mute">
          Аналитика появится, когда в комнату начнут приходить запросы. Пока данных за окно нет.
        </p>
      </section>
    </div>
  </AppShell>
</template>
