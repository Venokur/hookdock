<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppShell from '../components/AppShell.vue'
import AppSpinner from '../components/ui/AppSpinner.vue'
import AppToggle from '../components/ui/AppToggle.vue'
import WorkflowEditor from '../components/WorkflowEditor.vue'
import { api } from '../api/client'
import { confirm, notify } from '../composables/dialog'
import { retain, subscribe } from '../composables/realtime'
import { useRefresh } from '../composables/useRefresh'
import { useAuthStore } from '../stores/auth'
import { useRoomsStore } from '../stores/rooms'
import { formatClock, timeAgo } from '../utils/format'

const route = useRoute()
const auth = useAuthStore()
const rooms = useRoomsStore()

const slug = computed(() => (typeof route.params.slug === 'string' ? route.params.slug : ''))
const room = computed(() => (slug.value ? rooms.find(slug.value) : null))

// Address advertised for a `*` workflow: the room's own endpoint. The receiver
// still accepts any path, so `*` keeps firing for arbitrary endpoints too.
const roomEndpoint = computed(() => room.value?.endpoint || 'default')

const workflows = ref([])
const loading = ref(false)
const error = ref('')
const editorOpen = ref(false)
const editorWorkflow = ref(null)
const copiedId = ref(null)

// Version history panel: which rule, its versions, and the one being previewed.
const historyOpen = ref(false)
const historyWorkflow = ref(null)
const versions = ref([])
const historyLoading = ref(false)
const historyError = ref('')
const restoring = ref(null)

const changeLabels = {
  baseline: 'исходное состояние',
  created: 'создание',
  update: 'правка',
  toggle: 'включение/выключение',
  import: 'импорт',
  rollback: 'откат',
}

let releaseLive = null
let offRealtime = null
let reloadTimer = null

const operatorLabels = {
  equals: '=',
  not_equals: '≠',
  contains: 'содержит',
  not_contains: 'не содержит',
  exists: 'существует',
  not_exists: 'отсутствует',
  gt: '>',
  gte: '≥',
  lt: '<',
  lte: '≤',
  regex: 'соответствует',
}

const statusLabels = {
  pending: 'в очереди',
  running: 'выполняется',
  success: 'успех',
  failed: 'ошибка',
  skipped: 'пропущен',
}

const activeCount = computed(() => workflows.value.filter((workflow) => workflow.enabled).length)

/**
 * Rules the worker paused on its own. They are the ones worth surfacing: a manual
 * pause is deliberate and needs no explanation, while an automatic one means a
 * target has been failing and the operator was not looking.
 */
const autoPaused = computed(() => workflows.value.filter((workflow) => workflow.auto_paused))

async function load({ silent = false } = {}) {
  if (!silent) {
    loading.value = true
  }

  // A slower answer for the previous room must not repaint this one's list.
  const roomSlug = slug.value

  try {
    const data = await api.workflows({ room: roomSlug })

    if (roomSlug !== slug.value) {
      return
    }

    workflows.value = data.data
    error.value = ''
  } catch (e) {
    if (roomSlug === slug.value) {
      error.value = e.message
    }
  } finally {
    if (!silent) {
      loading.value = false
    }
  }
}

/**
 * Realtime events carry only the affected id; a debounced reload keeps list
 * counts, pause state and last-run timestamps consistent without polling.
 */
function refreshSoon() {
  clearTimeout(reloadTimer)
  reloadTimer = setTimeout(() => {
    load({ silent: true })
  }, 300)
}

/** The button triggers `refreshNow`, never `load` directly (it would receive the click event). */
const { busy: refreshing, updatedAt, run: refreshNow } = useRefresh(() => load())

function createWorkflow() {
  editorWorkflow.value = null
  editorOpen.value = true
}

function editWorkflow(workflow) {
  editorWorkflow.value = workflow
  editorOpen.value = true
}

async function closeEditor() {
  editorOpen.value = false
  editorWorkflow.value = null
}

async function onSaved() {
  await closeEditor()
  await load()
}

/**
 * Opens the saved configurations of one rule.
 *
 * The list is loaded on open rather than with the cards: a room with twenty rules
 * would otherwise fetch twenty histories to show none of them.
 */
async function openHistory(workflow) {
  historyWorkflow.value = workflow
  historyOpen.value = true
  versions.value = []
  historyError.value = ''
  historyLoading.value = true

  try {
    const data = await api.workflowVersions(workflow.id)
    if (historyWorkflow.value?.id !== workflow.id) {
      return
    }
    versions.value = data.data
  } catch (e) {
    historyError.value = e.message
  } finally {
    if (historyWorkflow.value?.id === workflow.id) {
      historyLoading.value = false
    }
  }
}

function closeHistory() {
  historyOpen.value = false
  historyWorkflow.value = null
  versions.value = []
}

/**
 * A rollback is a new save, not a rewrite: the current state is snapshotted first
 * by the server, so the rollback itself can be undone from the same list.
 */
async function restoreVersion(version) {
  const confirmed = await confirm({
    title: 'Вернуть эту версию?',
    message: `Сценарий вернётся к состоянию «${version.name}» (версия ${version.version}, ${formatTime(version.created_at)}). Текущее состояние останется в истории.`,
    confirmLabel: 'Вернуть версию',
    tone: 'primary',
  })

  if (!confirmed) {
    return
  }

  restoring.value = version.version

  try {
    await api.restoreWorkflowVersion(historyWorkflow.value.id, version.version)
    notify(`Сценарий возвращён к версии ${version.version}`, { tone: 'success' })
    await load()
    await openHistory(historyWorkflow.value)
  } catch (e) {
    notify(e.message, { tone: 'error' })
  } finally {
    restoring.value = null
  }
}

async function toggleWorkflow(workflow) {
  try {
    await api.toggleWorkflow(workflow.id)
    await load()
  } catch (e) {
    error.value = e.message
  }
}

async function removeWorkflow(workflow) {
  const confirmed = await confirm({
    title: 'Удалить сценарий?',
    message: `«${workflow.name}» и история его запусков будут удалены.`,
    confirmLabel: 'Удалить сценарий',
  })

  if (!confirmed) {
    return
  }

  try {
    await api.deleteWorkflow(workflow.id)
    await load()
  } catch (e) {
    error.value = e.message
  }
}

/**
 * Copies a rule, disabled.
 *
 * The API creates the copy paused, so duplicating a live rule to tweak it cannot
 * double every forward while the operator is still editing. The copy opens in the
 * editor at once — a paused duplicate nobody can find is not useful.
 */
async function duplicateWorkflow(workflow) {
  try {
    const created = await api.duplicateWorkflow(workflow.id)
    await load()
    editorWorkflow.value = created.data
    editorOpen.value = true
  } catch (e) {
    error.value = e.message
  }
}

function webhookUrl(workflow) {
  const endpoint = workflow.endpoint === '*' ? roomEndpoint.value : workflow.endpoint
  const prefix = slug.value ? `/${slug.value}` : ''

  return `${window.location.origin}/api/webhook${prefix}/${endpoint}`
}

function triggerLabel(workflow) {
  const prefix = slug.value ? `/${slug.value}` : ''

  return workflow.endpoint === '*'
    ? `любой эндпоинт комнаты, например /api/webhook${prefix}/${roomEndpoint.value}`
    : `/api/webhook${prefix}/${workflow.endpoint}`
}

function conditionSummary(condition) {
  const label = operatorLabels[condition.operator] || condition.operator
  if (condition.operator === 'exists' || condition.operator === 'not_exists') {
    return `${condition.path} ${label}`
  }

  return `${condition.path} ${label} ${JSON.stringify(condition.value)}`
}

/**
 * Renders the condition tree as flat chips.
 *
 * The stored shape is nested (`{mode, rules}`), and the card used to read a plain
 * list — which is now an empty array, so every rule looked unconditional. A nested
 * group is flattened with its joiner in parentheses instead of being rendered as a
 * tree: the card is a summary, the editor is where the structure is edited.
 *
 * @returns {Array<{text: string, depth: number}>}
 */
function conditionChips(node, depth = 0) {
  if (!node || !Array.isArray(node.rules)) {
    return node && node.path ? [{ text: conditionSummary(node), depth }] : []
  }

  const chips = []
  if (depth > 0 && node.rules.length > 0) {
    chips.push({ text: node.mode === 'any' ? '(любое из' : '(все из', depth })
  }

  for (const rule of node.rules) {
    chips.push(...conditionChips(rule, depth + (depth > 0 ? 1 : 0)))
  }

  if (depth > 0 && node.rules.length > 0) {
    chips.push({ text: ')', depth })
  }

  return chips
}

function fieldsLabel(count) {
  const mod10 = count % 10
  const mod100 = count % 100

  if (mod10 === 1 && mod100 !== 11) {
    return `${count} поле`
  }
  if (mod10 >= 2 && mod10 <= 4 && !(mod100 >= 12 && mod100 <= 14)) {
    return `${count} поля`
  }

  return `${count} полей`
}

function actionSummary(action) {
  if (action.type === 'forward') {
    const signed = action.signed ? ' · подпись' : ''
    return `Переслать ${action.method || 'POST'} → ${action.url}${signed}`
  }
  if (action.type === 'log') {
    return `Лог: ${action.message || 'совпадение'}`
  }
  if (action.type === 'delay') {
    return `Пауза ${action.seconds} с`
  }

  return `Трансформация: ${fieldsLabel(Object.keys(action.mapping || {}).length)}`
}

async function copyUrl(workflow) {
  try {
    await navigator.clipboard.writeText(webhookUrl(workflow))
    copiedId.value = workflow.id
    setTimeout(() => (copiedId.value = null), 1500)
  } catch {
    // Буфер обмена может быть недоступен.
  }
}

onMounted(() => {
  load()

  const offChanged = subscribe('workflow.changed', onWorkflowChanged)
  const offResync = subscribe('resync', refreshSoon)

  releaseLive = retain()

  offRealtime = () => {
    offChanged()
    offResync()
  }
})

/**
 * Events are room-scoped: a workflow edited in another room must not reload this
 * list, otherwise every open tab would flicker on unrelated changes.
 */
function onWorkflowChanged(payload) {
  if (payload?.room_id && room.value && payload.room_id !== room.value.id) {
    return
  }

  refreshSoon()
}

watch(slug, () => {
  closeEditor()
  workflows.value = []
  load()
})
onUnmounted(() => {
  offRealtime?.()
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
          <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">Автоматизация</p>
          <h1 class="mt-1 text-2xl font-semibold text-ink">Сценарии · {{ room?.name || 'комната' }}</h1>
          <p class="mt-1 text-sm text-ink-soft">
            Активных: {{ activeCount }} · сценарии срабатывают только на запросы этой комнаты.
          </p>
        </div>

        <div class="flex items-center gap-2">
          <span v-if="updatedAt" class="font-mono text-[11px] text-ink-mute">
            обновлено {{ formatClock(updatedAt) }}
          </span>
          <button type="button" class="btn" :disabled="refreshing" @click="refreshNow">
            <AppSpinner v-if="refreshing" size="xs" />
            {{ refreshing ? 'Обновляем…' : 'Обновить' }}
          </button>
          <button v-if="auth.canWrite" type="button" class="btn-primary" @click="createWorkflow">
            + Новый сценарий
          </button>
        </div>
      </header>

      <p v-if="error" class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-600 dark:text-rose-300">
        {{ error }}
      </p>

      <!-- A rule that stops on its own must say so. Without this banner the only
           difference from a deliberate pause is a card further down the page. -->
      <section
        v-if="autoPaused.length"
        class="flex flex-wrap items-start gap-3 rounded-2xl border border-amber-500/30 bg-amber-500/10 px-4 py-3"
      >
        <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full bg-amber-500/20 text-amber-700 dark:text-amber-300">
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
            <path d="M12 8v5" />
            <path d="M12 16.5v.01" />
            <circle cx="12" cy="12" r="9" />
          </svg>
        </span>
        <div class="min-w-0 flex-1 text-xs text-amber-800 dark:text-amber-200">
          <p class="font-semibold">
            {{ autoPaused.length === 1 ? 'Сценарий выключен' : `Сценарии выключены (${autoPaused.length})` }}
            автоматически после нескольких ошибок подряд
          </p>
          <p class="mt-1 text-[11px] text-amber-800/80 dark:text-amber-200/80">
            {{ autoPaused.map((workflow) => workflow.name).join(', ') }} — проверьте адрес пересылки и включите
            сценарий снова. Счётчик ошибок сбросится.
          </p>
        </div>
      </section>

      <section class="relative">
        <Transition name="fade">
          <div
            v-if="refreshing"
            class="absolute inset-0 z-10 grid place-items-center rounded-2xl bg-surface/70 backdrop-blur-[1px]"
          >
            <span class="flex items-center gap-2 text-xs font-medium text-ink-soft">
              <AppSpinner size="sm" />
              Обновляем сценарии…
            </span>
          </div>
        </Transition>

        <section
          v-if="!loading && workflows.length === 0"
          class="rounded-2xl border border-dashed border-line bg-surface px-6 py-16 text-center"
        >
          <div class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-300">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h6l2 3h8M4 17h4l2-3h10" />
              <circle cx="20" cy="7" r="1.5" />
              <circle cx="20" cy="17" r="1.5" />
            </svg>
          </div>
          <h2 class="mt-4 text-sm font-semibold text-ink">Сценариев пока нет</h2>
          <p class="mx-auto mt-1 max-w-sm text-xs text-ink-mute">
            Сначала поймайте запрос, затем пересылайте его в Slack, CRM или другой API, когда поле JSON совпадёт.
          </p>
          <button v-if="auth.canWrite" type="button" class="btn-primary mt-5" @click="createWorkflow">
            Создать первый сценарий
          </button>
        </section>

        <!-- A room switch clears the list before the response arrives. Without this
             branch the view shows a blank area: the empty state is suppressed while
             `loading` is true and the grid has nothing to render. -->
        <section
          v-else-if="loading && workflows.length === 0"
          class="rounded-2xl border border-line bg-surface px-6 py-16 text-center"
        >
          <span class="inline-flex items-center gap-2 text-xs text-ink-soft">
            <AppSpinner size="sm" />
            Загружаем сценарии…
          </span>
        </section>

        <section v-else class="grid gap-4 xl:grid-cols-2" :class="{ 'opacity-60': loading }">
          <article
            v-for="workflow in workflows"
            :key="workflow.id"
            class="card flex flex-col p-5"
          >
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <h2 class="truncate text-sm font-semibold text-ink">{{ workflow.name }}</h2>
                  <span
                    class="rounded-full px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider ring-1 ring-inset"
                    :class="workflow.enabled
                      ? 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/25 dark:text-emerald-300'
                      : workflow.auto_paused
                        ? 'bg-amber-500/10 text-amber-700 ring-amber-500/30 dark:text-amber-300'
                        : 'bg-ink/5 text-ink-mute ring-line'"
                  >
                    {{ workflow.enabled ? 'активен' : workflow.auto_paused ? 'выключен' : 'на паузе' }}
                  </span>
                  <span
                    v-if="workflow.priority > 0"
                    class="rounded-full bg-indigo-500/10 px-2 py-0.5 font-mono text-[10px] font-medium text-indigo-700 ring-1 ring-inset ring-indigo-500/25 dark:text-indigo-300"
                    title="Приоритет: больший выполняется первым"
                  >
                    P{{ workflow.priority }}
                  </span>
                  <span
                    v-if="workflow.stop_processing"
                    class="rounded-full bg-ink/5 px-2 py-0.5 text-[10px] font-medium text-ink-soft ring-1 ring-inset ring-line"
                    title="После успеха остальные сценарии этого запроса пропускаются"
                  >
                    останавливает
                  </span>
                  <span
                    v-if="!workflow.enabled && workflow.consecutive_failures > 0"
                    class="font-mono text-[10px] text-rose-600 dark:text-rose-300"
                    :title="`Ошибок подряд: ${workflow.consecutive_failures}`"
                  >
                    {{ workflow.consecutive_failures }} подряд
                  </span>
                </div>
                <p class="mt-1 text-[11px] text-ink-mute">Обновлён {{ timeAgo(workflow.updated_at) }}</p>
              </div>

              <AppToggle
                :model-value="workflow.enabled"
                :aria-label="workflow.enabled ? 'Поставить на паузу' : 'Активировать сценарий'"
                @update:model-value="toggleWorkflow(workflow)"
              />
            </div>

            <!-- The reason is stored by the worker, so it survives a reload and can
                 be read by whoever opens the page next, not only in the toast. -->
            <p
              v-if="workflow.auto_paused && workflow.disabled_reason"
              class="mt-3 rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-[11px] text-amber-800 dark:text-amber-200"
            >
              {{ workflow.disabled_reason }}
            </p>

            <div class="mt-4 rounded-xl border border-line bg-surface-2 px-3 py-2.5">
              <p class="text-[10px] uppercase tracking-wider text-ink-mute">Триггер</p>
              <div class="mt-1 flex items-center gap-2">
                <code class="min-w-0 flex-1 truncate font-mono text-[11px] text-ink-soft">
                  {{ triggerLabel(workflow) }}
                </code>
                <button
                  type="button"
                  class="shrink-0 rounded-md px-1.5 py-0.5 text-[10px] font-medium text-indigo-600 transition hover:bg-indigo-500/10 dark:text-indigo-300"
                  @click="copyUrl(workflow)"
                >
                  {{ copiedId === workflow.id ? 'Скопировано' : 'Копировать' }}
                </button>
              </div>
            </div>

            <div class="mt-3 space-y-2">
              <div>
                <p class="text-[10px] uppercase tracking-wider text-ink-mute">Условия</p>
                <div v-if="conditionChips(workflow.conditions).length" class="mt-1 flex flex-wrap items-center gap-1">
                  <span
                    v-for="(chip, index) in conditionChips(workflow.conditions)"
                    :key="index"
                    class="rounded-md bg-ink/5 px-2 py-0.5 font-mono text-[10px] text-ink-soft"
                  >
                    {{ chip.text }}
                  </span>
                </div>
                <p v-else class="mt-1 text-[11px] text-ink-mute">Срабатывает на каждый запрос.</p>
              </div>

              <div>
                <p class="text-[10px] uppercase tracking-wider text-ink-mute">Действия</p>
                <ul class="mt-1 space-y-1">
                  <li
                    v-for="(action, index) in workflow.actions ?? []"
                    :key="index"
                    class="flex items-start gap-2 text-[11px] text-ink-soft"
                  >
                    <span class="mt-1 h-1 w-1 shrink-0 rounded-full bg-indigo-500"></span>
                    <span class="break-words">{{ actionSummary(action) }}</span>
                  </li>
                </ul>
              </div>
            </div>

            <footer class="mt-auto flex items-center justify-between gap-2 border-t border-line pt-3 text-[11px] text-ink-mute">
              <span>
                <template v-if="workflow.last_run_at">
                  Последний запуск {{ timeAgo(workflow.last_run_at) }} ·
                  <span :class="workflow.last_run_status === 'failed' ? 'text-rose-600 dark:text-rose-300' : 'text-ink-soft'">
                    {{ statusLabels[workflow.last_run_status] || workflow.last_run_status }}
                  </span>
                </template>
                <template v-else>Ещё не запускался</template>
              </span>

              <div class="flex items-center gap-1">
                <!-- History is readable by every role: seeing what changed is the
                     reason it is kept, and a viewer is exactly who needs it. -->
                <button
                  type="button"
                  class="rounded-lg px-2 py-1 text-xs text-ink-soft transition hover:bg-surface-2 hover:text-ink"
                  @click="openHistory(workflow)"
                >
                  История
                </button>
                <button
                  v-if="auth.canWrite"
                  type="button"
                  class="rounded-lg px-2 py-1 text-xs text-ink-soft transition hover:bg-surface-2 hover:text-ink"
                  @click="duplicateWorkflow(workflow)"
                >
                  Дублировать
                </button>
                <button
                  v-if="auth.canWrite"
                  type="button"
                  class="rounded-lg px-2 py-1 text-xs text-ink-soft transition hover:bg-surface-2 hover:text-ink"
                  @click="editWorkflow(workflow)"
                >
                  Изменить
                </button>
                <button
                  v-if="auth.canWrite"
                  type="button"
                  class="rounded-lg px-2 py-1 text-xs text-rose-600/80 transition hover:bg-rose-500/10 hover:text-rose-600 dark:text-rose-300/80 dark:hover:text-rose-300"
                  @click="removeWorkflow(workflow)"
                >
                  Удалить
                </button>
              </div>
            </footer>
          </article>
        </section>
      </section>

      <p class="text-[11px] text-ink-mute">
        Пути условий читают поля JSON, например
        <code class="font-mono text-ink-soft">body.event.type</code>,
        <code class="font-mono text-ink-soft">query.source</code> или
        <code class="font-mono text-ink-soft">headers.x-github-event</code>.
      </p>
    </div>

    <WorkflowEditor
      v-if="editorOpen"
      :workflow="editorWorkflow"
      :room="room"
      @close="closeEditor"
      @saved="onSaved"
    />

    <!-- Version history. A drawer rather than a route: it belongs to one card,
         and leaving the list to read history would lose the operator's place. -->
    <Teleport to="body">
      <Transition name="fade">
        <div
          v-if="historyOpen"
          class="fixed inset-0 z-40 bg-slate-900/30 backdrop-blur-sm dark:bg-slate-950/70"
          @click="closeHistory"
        ></div>
      </Transition>

      <Transition name="fade">
        <aside
          v-if="historyOpen"
          class="fixed inset-y-0 right-0 z-50 flex w-full max-w-lg flex-col border-l border-line bg-surface shadow-2xl"
        >
          <header class="flex items-start justify-between gap-3 border-b border-line px-5 py-4">
            <div class="min-w-0">
              <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">
                История версий
              </p>
              <h2 class="mt-1 truncate text-base font-semibold text-ink">
                {{ historyWorkflow?.name || 'сценарий' }}
              </h2>
              <p class="mt-1 text-[11px] text-ink-mute">
                Каждое сохранение — отдельная запись. Откат не стирает историю: он становится новой версией.
              </p>
            </div>
            <button
              type="button"
              class="rounded-lg p-1.5 text-ink-mute transition hover:bg-surface-2 hover:text-ink"
              aria-label="Закрыть историю"
              @click="closeHistory"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
              </svg>
            </button>
          </header>

          <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
            <p
              v-if="historyError"
              class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-600 dark:text-rose-300"
            >
              {{ historyError }}
            </p>

            <div v-else-if="historyLoading" class="flex items-center justify-center gap-2 py-10 text-xs text-ink-soft">
              <AppSpinner size="sm" />
              Загружаем версии…
            </div>

            <p v-else-if="versions.length === 0" class="py-10 text-center text-xs text-ink-mute">
              Версий пока нет.
            </p>

            <ol v-else class="space-y-2">
              <li
                v-for="version in versions"
                :key="version.version"
                class="rounded-xl border border-line bg-surface-2 px-3 py-2.5"
              >
                <div class="flex flex-wrap items-center gap-2">
                  <span class="font-mono text-[11px] text-ink-mute">v{{ version.version }}</span>
                  <span class="text-[11px] font-medium text-ink-soft">
                    {{ changeLabels[version.change] || version.change }}
                  </span>
                  <span
                    class="rounded-full px-1.5 py-0.5 text-[10px] ring-1 ring-inset"
                    :class="version.enabled
                      ? 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/25 dark:text-emerald-300'
                      : 'bg-ink/5 text-ink-mute ring-ink/10'"
                  >
                    {{ version.enabled ? 'включён' : 'выключен' }}
                  </span>
                  <span v-if="version.version === versions[0]?.version" class="text-[10px] text-indigo-600 dark:text-indigo-300">
                    текущая
                  </span>
                </div>

                <p class="mt-1 truncate text-[12px] text-ink">{{ version.name }}</p>

                <p class="mt-1 text-[11px] text-ink-mute">
                  {{ formatTime(version.created_at) }}
                  · {{ version.actor_email || 'система' }}
                  · действий: v{{ version.actions_version }}
                </p>

                <button
                  v-if="auth.canWrite && version.version !== versions[0]?.version"
                  type="button"
                  class="btn mt-2 px-2.5 py-1 text-[11px]"
                  :disabled="restoring !== null"
                  @click="restoreVersion(version)"
                >
                  <AppSpinner v-if="restoring === version.version" size="xs" />
                  {{ restoring === version.version ? 'Возвращаем…' : 'Вернуть эту версию' }}
                </button>
              </li>
            </ol>
          </div>
        </aside>
      </Transition>
    </Teleport>
  </AppShell>
</template>
