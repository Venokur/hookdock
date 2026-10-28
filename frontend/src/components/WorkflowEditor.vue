<script setup>
import { reactive, ref } from 'vue'
import AppCheckbox from './ui/AppCheckbox.vue'
import AppInput from './ui/AppInput.vue'
import AppSelect from './ui/AppSelect.vue'
import AppTextarea from './ui/AppTextarea.vue'
import { api } from '../api/client'

const props = defineProps({
  workflow: { type: Object, default: null },
})

const emit = defineEmits(['close', 'saved'])

function blankAction() {
  return { type: 'forward', url: '', method: 'POST', payload: 'body', message: '', mappingText: '{}' }
}

function toForm(workflow) {
  if (!workflow) {
    return { id: null, name: '', endpoint: '*', enabled: true, conditions: [], actions: [blankAction()] }
  }

  return {
    id: workflow.id,
    name: workflow.name,
    endpoint: workflow.endpoint,
    enabled: workflow.enabled,
    conditions: (workflow.conditions ?? []).map((condition) => ({
      path: condition.path,
      operator: condition.operator,
      value: condition.value ?? '',
    })),
    actions: (workflow.actions ?? []).map((action) => ({
      type: action.type,
      url: action.url ?? '',
      method: action.method ?? 'POST',
      payload: typeof action.payload === 'string' ? action.payload : 'body',
      message: action.message ?? '',
      mappingText: action.type === 'transform' ? JSON.stringify(action.mapping ?? {}, null, 2) : '{}',
    })),
  }
}

const form = reactive(toForm(props.workflow))
const saving = ref(false)
const error = ref('')

const operators = [
  ['equals', 'равно'],
  ['not_equals', 'не равно'],
  ['contains', 'содержит'],
  ['not_contains', 'не содержит'],
  ['exists', 'существует'],
  ['not_exists', 'отсутствует'],
  ['gt', 'больше'],
  ['gte', 'больше или равно'],
  ['lt', 'меньше'],
  ['lte', 'меньше или равно'],
  ['regex', 'соответствует regex'],
]

const actionTypes = [
  ['forward', 'Переслать на URL'],
  ['log', 'Записать в лог'],
  ['transform', 'Трансформировать поля'],
]

const operatorOptions = operators.map(([value, label]) => ({ value, label }))
const actionTypeOptions = actionTypes.map(([value, label]) => ({ value, label }))
const methodOptions = ['POST', 'PUT', 'PATCH', 'GET', 'DELETE'].map((value) => ({ value, label: value }))
const payloadOptions = [
  { value: 'body', label: 'Исходное JSON-тело' },
  { value: 'transformed', label: 'Трансформированные поля' },
  { value: 'raw', label: 'Сырое тело' },
]

function addCondition() {
  form.conditions.push({ path: '', operator: 'equals', value: '' })
}

function removeCondition(index) {
  form.conditions.splice(index, 1)
}

function addAction() {
  form.actions.push(blankAction())
}

function removeAction(index) {
  form.actions.splice(index, 1)
}

function needsValue(operator) {
  return !['exists', 'not_exists'].includes(operator)
}

async function save() {
  error.value = ''

  if (!form.name.trim()) {
    error.value = 'Укажите название сценария.'
    return
  }

  const actions = []

  for (const action of form.actions) {
    if (action.type === 'forward') {
      if (!action.url.trim()) {
        error.value = 'Для действия пересылки нужен URL.'
        return
      }
      actions.push({
        type: 'forward',
        url: action.url.trim(),
        method: action.method || 'POST',
        payload: action.payload || 'body',
      })
      continue
    }

    if (action.type === 'log') {
      actions.push({ type: 'log', message: action.message.trim() })
      continue
    }

    let mapping = {}
    try {
      mapping = action.mappingText.trim() ? JSON.parse(action.mappingText) : {}
    } catch {
      error.value = 'Маппинг трансформации должен быть корректным JSON.'
      return
    }
    actions.push({ type: 'transform', mapping })
  }

  const payload = {
    id: form.id,
    name: form.name.trim(),
    endpoint: form.endpoint.trim() || '*',
    enabled: form.enabled,
    conditions: form.conditions
      .filter((condition) => condition.path.trim() !== '')
      .map((condition) => ({
        path: condition.path.trim(),
        operator: condition.operator,
        value: condition.value,
      })),
    actions,
  }

  saving.value = true

  try {
    await api.saveWorkflow(payload)
    emit('saved')
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/30 p-4 backdrop-blur-sm dark:bg-slate-950/80 sm:p-8">
    <div class="card my-auto w-full max-w-3xl overflow-hidden">
      <header class="flex items-start justify-between gap-4 border-b border-line px-6 py-5">
        <div>
          <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">
            {{ form.id ? 'Редактирование сценария' : 'Новый сценарий' }}
          </p>
          <h2 class="mt-1 text-lg font-semibold text-ink">Триггер → Условия → Действия</h2>
        </div>
        <button
          type="button"
          class="rounded-lg p-1.5 text-ink-mute transition hover:bg-surface-2 hover:text-ink"
          aria-label="Закрыть редактор"
          @click="emit('close')"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
          </svg>
        </button>
      </header>

      <div class="max-h-[70vh] space-y-7 overflow-y-auto px-6 py-6">
        <section>
          <div class="flex items-center gap-2">
            <span class="grid h-6 w-6 place-items-center rounded-full bg-indigo-500/10 text-[11px] font-semibold text-indigo-600 dark:text-indigo-300">1</span>
            <h3 class="text-sm font-semibold text-ink">Триггер</h3>
          </div>

          <div class="mt-3 grid gap-3 pl-8 sm:grid-cols-2">
            <label class="block">
              <span class="mb-1.5 block text-xs font-medium text-ink-soft">Название сценария</span>
              <AppInput v-model="form.name" placeholder="Уведомления в Slack" />
            </label>

            <label class="block">
              <span class="mb-1.5 block text-xs font-medium text-ink-soft">Slug эндпоинта</span>
              <AppInput v-model="form.endpoint" placeholder="*" class="font-mono" />
            </label>
          </div>

          <p class="mt-2 pl-8 text-[11px] text-ink-mute">
            Укажите <code class="font-mono text-ink-soft">*</code>, чтобы слушать все эндпоинты, или slug,
            например <code class="font-mono text-ink-soft">stripe</code> для
            <code class="font-mono text-ink-soft">/api/webhook/stripe</code>.
          </p>
        </section>

        <section>
          <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2">
              <span class="grid h-6 w-6 place-items-center rounded-full bg-indigo-500/10 text-[11px] font-semibold text-indigo-600 dark:text-indigo-300">2</span>
              <h3 class="text-sm font-semibold text-ink">Условия</h3>
              <span class="text-[11px] text-ink-mute">все должны совпасть</span>
            </div>
            <button type="button" class="btn px-2.5 py-1" @click="addCondition">+ Условие</button>
          </div>

          <div class="mt-3 space-y-2 pl-8">
            <p v-if="form.conditions.length === 0" class="rounded-xl border border-dashed border-line px-3 py-3 text-xs text-ink-mute">
              Условий нет — сценарий сработает на каждый запрос этого эндпоинта.
            </p>

            <div
              v-for="(condition, index) in form.conditions"
              :key="index"
              class="grid grid-cols-1 gap-2 rounded-xl border border-line bg-surface-2 p-3 sm:grid-cols-[1.4fr_1fr_1fr_auto]"
            >
              <AppInput
                v-model="condition.path"
                size="sm"
                placeholder="body.event.type"
                class="font-mono"
              />
              <AppSelect v-model="condition.operator" :options="operatorOptions" size="sm" />
              <AppInput
                v-if="needsValue(condition.operator)"
                v-model="condition.value"
                size="sm"
                placeholder="значение"
              />
              <span v-else class="hidden sm:block"></span>
              <button
                type="button"
                class="justify-self-start rounded-lg p-1.5 text-ink-mute transition hover:bg-rose-500/10 hover:text-rose-600 dark:hover:text-rose-300 sm:justify-self-end"
                aria-label="Удалить условие"
                @click="removeCondition(index)"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" d="M6 7h12M9 7V5h6v2m-8 0l1 13h8l1-13" />
                </svg>
              </button>
            </div>
          </div>
        </section>

        <section>
          <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2">
              <span class="grid h-6 w-6 place-items-center rounded-full bg-indigo-500/10 text-[11px] font-semibold text-indigo-600 dark:text-indigo-300">3</span>
              <h3 class="text-sm font-semibold text-ink">Действия</h3>
              <span class="text-[11px] text-ink-mute">выполняются по порядку</span>
            </div>
            <button type="button" class="btn px-2.5 py-1" @click="addAction">+ Действие</button>
          </div>

          <div class="mt-3 space-y-3 pl-8">
            <div
              v-for="(action, index) in form.actions"
              :key="index"
              class="rounded-xl border border-line bg-surface-2 p-3"
            >
              <div class="flex items-center justify-between gap-2">
                <AppSelect v-model="action.type" :options="actionTypeOptions" size="sm" class="w-48" />
                <button
                  v-if="form.actions.length > 1"
                  type="button"
                  class="rounded-lg p-1.5 text-ink-mute transition hover:bg-rose-500/10 hover:text-rose-600 dark:hover:text-rose-300"
                  aria-label="Удалить действие"
                  @click="removeAction(index)"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" d="M6 7h12M9 7V5h6v2m-8 0l1 13h8l1-13" />
                  </svg>
                </button>
              </div>

              <div v-if="action.type === 'forward'" class="mt-3 space-y-2">
                <AppInput
                  v-model="action.url"
                  size="sm"
                  placeholder="https://hooks.example.com/incoming/…"
                  class="font-mono"
                />
                <div class="grid grid-cols-2 gap-2">
                  <AppSelect v-model="action.method" :options="methodOptions" size="sm" />
                  <AppSelect v-model="action.payload" :options="payloadOptions" size="sm" />
                </div>
                <p class="text-[11px] text-ink-mute">
                  Плейсхолдеры вида <code class="font-mono text-ink-soft" v-pre>{{ body.customer.email }}</code>
                  работают в URL и заголовках.
                </p>
              </div>

              <div v-else-if="action.type === 'log'" class="mt-3">
                <AppInput v-model="action.message" size="sm" placeholder="Оплата от клиента" />
                <p class="mt-1 text-[11px] text-ink-mute">
                  Поддерживаются шаблоны вида
                  <code class="font-mono text-ink-soft" v-pre>{{ body.customer.name }}</code>.
                </p>
              </div>

              <div v-else class="mt-3">
                <AppTextarea
                  v-model="action.mappingText"
                  :rows="4"
                  size="sm"
                  class="font-mono"
                />
                <p class="mt-1 text-[11px] text-ink-mute">
                  Сопоставьте ключи результата с путями источника, например
                  <code class="font-mono text-ink-soft">{ "email": "body.user.email" }</code>.
                </p>
              </div>
            </div>
          </div>
        </section>

        <div class="ml-8">
          <AppCheckbox v-model="form.enabled" label="Сценарий активен" />
        </div>

        <p v-if="error" class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-600 dark:text-rose-300">
          {{ error }}
        </p>
      </div>

      <footer class="flex items-center justify-end gap-2 border-t border-line px-6 py-4">
        <button type="button" class="btn px-4" @click="emit('close')">Отмена</button>
        <button type="button" :disabled="saving" class="btn-primary" @click="save">
          {{ saving ? 'Сохраняем…' : 'Сохранить сценарий' }}
        </button>
      </footer>
    </div>
  </div>
</template>
