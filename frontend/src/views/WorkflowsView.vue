<script setup>
import { computed, onMounted, ref } from 'vue'
import AppShell from '../components/AppShell.vue'
import AppToggle from '../components/ui/AppToggle.vue'
import WorkflowEditor from '../components/WorkflowEditor.vue'
import { api } from '../api/client'
import { timeAgo } from '../utils/format'

const workflows = ref([])
const loading = ref(false)
const error = ref('')
const editorOpen = ref(false)
const editorWorkflow = ref(null)
const copiedId = ref(null)

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
  success: 'успех',
  failed: 'ошибка',
  skipped: 'пропущен',
}

const activeCount = computed(() => workflows.value.filter((workflow) => workflow.enabled).length)

async function load() {
  loading.value = true

  try {
    const data = await api.workflows()
    workflows.value = data.data
    error.value = ''
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

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

async function toggleWorkflow(workflow) {
  try {
    await api.toggleWorkflow(workflow.id)
    await load()
  } catch (e) {
    error.value = e.message
  }
}

async function removeWorkflow(workflow) {
  if (!window.confirm(`Удалить «${workflow.name}»? История запусков тоже будет удалена.`)) {
    return
  }

  try {
    await api.deleteWorkflow(workflow.id)
    await load()
  } catch (e) {
    error.value = e.message
  }
}

function webhookUrl(workflow) {
  const slug = workflow.endpoint === '*' ? 'my-endpoint' : workflow.endpoint

  return `${window.location.origin}/api/webhook/${slug}`
}

function conditionSummary(condition) {
  const label = operatorLabels[condition.operator] || condition.operator
  if (condition.operator === 'exists' || condition.operator === 'not_exists') {
    return `${condition.path} ${label}`
  }

  return `${condition.path} ${label} ${JSON.stringify(condition.value)}`
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
    return `Переслать ${action.method || 'POST'} → ${action.url}`
  }
  if (action.type === 'log') {
    return `Лог: ${action.message || 'совпадение'}`
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

onMounted(load)
</script>

<template>
  <AppShell>
    <div class="mx-auto max-w-7xl space-y-6">
      <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">Автоматизация</p>
          <h1 class="mt-1 text-2xl font-semibold text-ink">Сценарии</h1>
          <p class="mt-1 text-sm text-ink-soft">
            Активных: {{ activeCount }} · маршрутизируйте пойманные запросы в другие сервисы по условиям и действиям.
          </p>
        </div>

        <div class="flex items-center gap-2">
          <button type="button" class="btn" @click="load">Обновить</button>
          <button type="button" class="btn-primary" @click="createWorkflow">+ Новый сценарий</button>
        </div>
      </header>

      <p v-if="error" class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-600 dark:text-rose-300">
        {{ error }}
      </p>

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
        <button type="button" class="btn-primary mt-5" @click="createWorkflow">
          Создать первый сценарий
        </button>
      </section>

      <section v-else class="grid gap-4 xl:grid-cols-2" :class="{ 'opacity-60': loading }">
        <article
          v-for="workflow in workflows"
          :key="workflow.id"
          class="card flex flex-col p-5"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <h2 class="truncate text-sm font-semibold text-ink">{{ workflow.name }}</h2>
                <span
                  class="rounded-full px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider ring-1 ring-inset"
                  :class="workflow.enabled
                    ? 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/25 dark:text-emerald-300'
                    : 'bg-ink/5 text-ink-mute ring-line'"
                >
                  {{ workflow.enabled ? 'активен' : 'на паузе' }}
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

          <div class="mt-4 rounded-xl border border-line bg-surface-2 px-3 py-2.5">
            <p class="text-[10px] uppercase tracking-wider text-ink-mute">Триггер</p>
            <div class="mt-1 flex items-center gap-2">
              <code class="min-w-0 flex-1 truncate font-mono text-[11px] text-ink-soft">
                {{ workflow.endpoint === '*' ? 'любой эндпоинт (например, /api/webhook/my-endpoint)' : `/api/webhook/${workflow.endpoint}` }}
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
              <div v-if="(workflow.conditions ?? []).length" class="mt-1 flex flex-wrap gap-1">
                <span
                  v-for="(condition, index) in workflow.conditions"
                  :key="index"
                  class="rounded-md bg-ink/5 px-2 py-0.5 font-mono text-[10px] text-ink-soft"
                >
                  {{ conditionSummary(condition) }}
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
              <button
                type="button"
                class="rounded-lg px-2 py-1 text-xs text-ink-soft transition hover:bg-surface-2 hover:text-ink"
                @click="editWorkflow(workflow)"
              >
                Изменить
              </button>
              <button
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
      @close="closeEditor"
      @saved="onSaved"
    />
  </AppShell>
</template>
