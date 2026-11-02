<script setup>
import { computed, reactive, ref } from 'vue'
import AppCheckbox from './ui/AppCheckbox.vue'
import AppInput from './ui/AppInput.vue'
import AppSelect from './ui/AppSelect.vue'
import AppSpinner from './ui/AppSpinner.vue'
import AppTextarea from './ui/AppTextarea.vue'
import ConditionGroup from './ConditionGroup.vue'
import JsonPathPicker from './JsonPathPicker.vue'
import { api } from '../api/client'

const props = defineProps({
  workflow: { type: Object, default: null },
  room: { type: Object, default: null },
})

const emit = defineEmits(['close', 'saved'])

/** Mirrors `WorkflowsController::MAX_DEPTH`; the API refuses anything deeper. */
const MAX_DEPTH = 5

const KNOWN_PAYLOADS = ['body', 'transformed', 'raw']

function blankAction(type = 'forward') {
  return {
    type,
    url: '',
    method: 'POST',
    payload: 'body',
    payloadText: '{\n  "id": "{{ body.id }}"\n}',
    headers: [],
    message: '',
    mappingText: '{\n  "email": "body.user.email"\n}',
    seconds: 5,
    // 0 = "use the global FORWARD_TIMEOUT". Sent as no `timeout` key at all, so
    // the API does not have to special-case a zero.
    timeout: 0,
    // The stored secret is never sent back by the API — only `signed: true`.
    // Typing into the field replaces it; `clearSignature` removes it.
    secret: '',
    signed: false,
    clearSignature: false,
    // Opt-in: a recipient that verifies a body-only HMAC rejects `"<ts>.<body>"`.
    signTimestamp: false,
  }
}

/**
 * Turns the stored tree into editable groups.
 *
 * The API normalizes everything to `{mode, rules}`, but a value can also arrive as
 * a bare list (an old row, or a draft from a script), so a list is read as an
 * `all` group instead of rendering an empty editor.
 */
function toTree(conditions) {
  const source = Array.isArray(conditions) ? { mode: 'all', rules: conditions } : conditions
  const rules = Array.isArray(source?.rules) ? source.rules : []

  return {
    mode: source?.mode === 'any' ? 'any' : 'all',
    rules: rules.map((rule) => {
      if (rule && Array.isArray(rule.rules)) {
        return toTree(rule)
      }

      return {
        path: rule?.path ?? '',
        operator: rule?.operator ?? 'equals',
        value: rule?.value ?? '',
      }
    }),
  }
}

function toForm(workflow) {
  if (!workflow) {
    return {
      id: null,
      name: '',
      endpoint: '*',
      enabled: true,
      priority: 0,
      stopProcessing: false,
      conditions: { mode: 'all', rules: [] },
      actions: [blankAction()],
    }
  }

  return {
    id: workflow.id,
    name: workflow.name,
    endpoint: workflow.endpoint,
    enabled: workflow.enabled,
    priority: Number(workflow.priority ?? 0),
    stopProcessing: Boolean(workflow.stop_processing),
    conditions: toTree(workflow.conditions),
    actions: (workflow.actions ?? []).map((action) => {
      const base = blankAction(action.type)
      const payload = typeof action.payload === 'string' ? action.payload : null
      const custom = payload !== null && !KNOWN_PAYLOADS.includes(payload)

      return {
        ...base,
        type: action.type,
        url: action.url ?? '',
        method: action.method ?? 'POST',
        payload: custom ? 'custom' : payload ?? 'body',
        payloadText: custom ? payload : base.payloadText,
        headers: Object.entries(action.headers ?? {}).map(([name, value]) => ({ name, value: String(value) })),
        message: action.message ?? '',
        mappingText:
          action.type === 'transform' ? JSON.stringify(action.mapping ?? {}, null, 2) : base.mappingText,
        seconds: Number(action.seconds ?? base.seconds),
        timeout: Number(action.timeout ?? 0),
        signed: Boolean(action.signed),
        signTimestamp: Boolean(action.sign_timestamp),
      }
    }),
  }
}

const form = reactive(toForm(props.workflow))
const saving = ref(false)
const error = ref('')
const pickerTarget = ref(null)

const previewing = ref(false)
const preview = ref(null)
const previewError = ref('')

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
  ['delay', 'Подождать и продолжить'],
]

const operatorOptions = operators.map(([value, label]) => ({ value, label }))
const actionTypeOptions = actionTypes.map(([value, label]) => ({ value, label }))
const methodOptions = ['POST', 'PUT', 'PATCH', 'GET', 'DELETE'].map((value) => ({ value, label: value }))
const payloadOptions = [
  { value: 'body', label: 'Исходное JSON-тело' },
  { value: 'transformed', label: 'Трансформированные поля' },
  { value: 'raw', label: 'Сырое тело' },
  { value: 'custom', label: 'Свой JSON' },
]

const conditionCount = computed(() => countLeaves(form.conditions))

function countLeaves(group) {
  return (group.rules ?? []).reduce(
    (total, rule) => total + (Array.isArray(rule.rules) ? countLeaves(rule) : 1),
    0,
  )
}

/**
 * Drops half-filled rules instead of sending them.
 *
 * A row with an empty path is what an operator leaves behind after clicking "+"
 * and changing their mind; the API would reject it, and the error would point at a
 * condition that is not visibly wrong. An empty group is dropped too — it would
 * otherwise read as "passes everything" inside an AND.
 */
function pruneTree(group, isRoot = false) {
  const rules = []

  for (const rule of group.rules ?? []) {
    if (Array.isArray(rule.rules)) {
      const nested = pruneTree(rule, false)
      if (nested.rules.length > 0) {
        rules.push(nested)
      }
      continue
    }

    if (String(rule.path ?? '').trim() === '') {
      continue
    }

    rules.push({
      path: String(rule.path).trim(),
      operator: rule.operator,
      value: rule.value,
    })
  }

  return { mode: group.mode === 'any' ? 'any' : 'all', rules: isRoot ? rules : rules }
}

function addAction() {
  form.actions.push(blankAction())
}

function removeAction(index) {
  form.actions.splice(index, 1)
}

function addHeader(action) {
  action.headers.push({ name: '', value: '' })
}

function removeHeader(action, index) {
  action.headers.splice(index, 1)
}

function openPicker(rule) {
  pickerTarget.value = rule
}

function closePicker() {
  pickerTarget.value = null
}

/**
 * Picking a scalar pre-fills the comparison so the common case (compare this
 * exact field) needs no typing. Objects and arrays of objects stay path-only:
 * `equals` on them would need a hand-written JSON literal.
 */
function applyPick(result) {
  const rule = pickerTarget.value
  if (!rule) {
    closePicker()
    return
  }

  rule.path = result.path

  if (result.pathOnly || result.value === undefined || result.value === null) {
    closePicker()
    return
  }

  if (result.type === 'boolean') {
    rule.operator = 'equals'
    rule.value = result.value ? 'true' : 'false'
  } else if (result.type === 'array') {
    rule.operator = 'contains'
    rule.value = String(result.value)
  } else {
    rule.operator = 'equals'
    rule.value = String(result.value)
  }

  closePicker()
}

/**
 * Builds the request body once, for both "save" and "check".
 *
 * `check` must exercise the same actions that `save` would store — a preview of a
 * different body is a preview of nothing.
 */
function buildPayload() {
  error.value = ''

  if (!form.name.trim()) {
    error.value = 'Укажите название сценария.'
    return null
  }

  const actions = []

  for (const action of form.actions) {
    if (action.type === 'forward') {
      if (!action.url.trim()) {
        error.value = 'Для действия пересылки нужен URL.'
        return null
      }

      // Validating the header list here, not only in the API: an empty name row is
      // a leftover, a name with a colon can never be sent.
      const headers = {}
      for (const header of action.headers) {
        const name = String(header.name ?? '').trim()
        if (name === '') {
          continue
        }
        if (name.includes(':')) {
          error.value = `Недопустимое имя заголовка: ${name}`
          return null
        }
        headers[name] = String(header.value ?? '')
      }

      const payload = action.payload === 'custom'
        ? action.payloadText
        : action.payload || 'body'

      const body = {
        type: 'forward',
        url: action.url.trim(),
        method: action.method || 'POST',
        payload,
        headers,
      }

      // The API never returns the secret, so an untouched signed forward must send
      // nothing — an empty value means "keep the stored one". `clearSignature`
      // removes it, and typing a value replaces it.
      if (action.signed && !action.clearSignature && action.secret === '') {
        // nothing: keep as is
      } else if (action.secret !== '') {
        body.secret = action.secret
      }
      if (action.clearSignature) {
        body.clear_signature = true
      }

      // 0/empty means "use FORWARD_TIMEOUT": the key is omitted rather than sent
      // as 0, so the API stores no timeout instead of a value it has to ignore.
      const timeout = Math.round(Number(action.timeout) || 0)
      if (timeout >= 1) {
        body.timeout = Math.min(60, timeout)
      }

      if (action.signTimestamp) {
        body.sign_timestamp = true
      }

      actions.push(body)
      continue
    }

    if (action.type === 'log') {
      actions.push({ type: 'log', message: action.message.trim() })
      continue
    }

    if (action.type === 'delay') {
      const seconds = Number(action.seconds)
      if (!Number.isFinite(seconds) || seconds < 1) {
        error.value = 'Задержка должна быть не меньше 1 секунды.'
        return null
      }
      actions.push({ type: 'delay', seconds: Math.round(seconds) })
      continue
    }

    let mapping = {}
    try {
      mapping = action.mappingText.trim() ? JSON.parse(action.mappingText) : {}
    } catch {
      error.value = 'Маппинг трансформации должен быть корректным JSON.'
      return null
    }
    actions.push({ type: 'transform', mapping })
  }

  return {
    id: form.id,
    room_id: props.room?.id,
    name: form.name.trim(),
    endpoint: form.endpoint.trim() || '*',
    enabled: form.enabled,
    priority: Math.min(1000, Math.max(0, Number(form.priority) || 0)),
    stop_processing: form.stopProcessing,
    conditions: pruneTree(form.conditions, true),
    actions,
  }
}

async function save() {
  const payload = buildPayload()
  if (!payload) {
    return
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

/**
 * Runs the draft against the newest request of this endpoint.
 *
 * There is nothing else to check a rule against: a condition tree is only wrong
 * for a particular payload, and the newest one is the reason the operator opened
 * the editor. Nothing is queued and nothing is sent, so a typo in the URL costs
 * one request to the API instead of one broken delivery.
 */
async function check() {
  previewError.value = ''
  preview.value = null

  const payload = buildPayload()
  if (!payload) {
    return
  }

  if (!props.room?.slug) {
    previewError.value = 'Проверка доступна внутри комнаты.'
    return
  }

  previewing.value = true

  try {
    const endpoint = payload.endpoint === '*' ? '' : payload.endpoint
    const list = await api.requests({
      room: props.room.slug,
      endpoint,
      per_page: 1,
      include: 'body',
    })

    const sample = list?.data?.[0]
    if (!sample) {
      previewError.value = 'В комнате ещё нет запросов — проверять не на чем.'
      return
    }

    const result = await api.previewRequest(sample.id, {
      conditions: payload.conditions,
      actions: payload.actions,
    })

    preview.value = {
      requestId: sample.id,
      method: sample.method,
      endpoint: sample.endpoint,
      receivedAt: sample.received_at,
      matched: Boolean(result?.data?.workflows?.[0]?.matched),
      steps: result?.data?.workflows?.[0]?.steps ?? [],
    }
  } catch (e) {
    previewError.value = e.message
  } finally {
    previewing.value = false
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
          <p v-if="room" class="mt-1 text-[11px] text-ink-mute">
            Комната: <span class="font-medium text-ink-soft">{{ room.name }}</span>
            · <code class="font-mono">{{ room.slug }}</code>
          </p>
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
            Укажите <code class="font-mono text-ink-soft">*</code>, чтобы слушать все эндпоинты комнаты,
            или конкретный slug. Адрес комнаты по умолчанию:
            <code class="font-mono text-ink-soft">/api/webhook{{ room ? `/${room.slug}` : '' }}/{{ room?.endpoint || 'hook-…' }}</code>.
          </p>
        </section>

        <section>
          <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2">
              <span class="grid h-6 w-6 place-items-center rounded-full bg-indigo-500/10 text-[11px] font-semibold text-indigo-600 dark:text-indigo-300">2</span>
              <h3 class="text-sm font-semibold text-ink">Условия</h3>
              <span class="text-[11px] text-ink-mute">группы И / ИЛИ, вложенность до {{ MAX_DEPTH }}</span>
            </div>
          </div>

          <div class="mt-3 pl-8">
            <ConditionGroup
              :group="form.conditions"
              :operators="operatorOptions"
              :max-depth="MAX_DEPTH"
              @pick="openPicker"
            />
            <p class="mt-2 text-[11px] text-ink-mute">
              Условий: {{ conditionCount }} · вложенность: {{ MAX_DEPTH }} уровней
            </p>
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
                <AppSelect v-model="action.type" :options="actionTypeOptions" size="sm" class="w-52" />
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

                <label class="block">
                  <span class="mb-1.5 block text-[11px] text-ink-mute">
                    Таймаут, секунд (пусто — как у сервера)
                  </span>
                  <AppInput
                    v-model.number="action.timeout"
                    size="sm"
                    type="number"
                    min="0"
                    max="60"
                    placeholder="8"
                    aria-label="Таймаут пересылки"
                  />
                </label>
                <p class="text-[11px] text-ink-mute">
                  От 1 до 60 секунд. Пустое поле — общий таймаут сервера. Долгий таймаут держит
                  очередь, поэтому он ограничен.
                </p>

                <AppTextarea
                  v-if="action.payload === 'custom'"
                  v-model="action.payloadText"
                  :rows="5"
                  size="sm"
                  class="font-mono"
                  aria-label="Своё тело запроса"
                />
                <p v-if="action.payload === 'custom'" class="text-[11px] text-ink-mute">
                  Своё тело — шаблон. Подставляйте поля через
                  <code class="font-mono text-ink-soft" v-pre>{{ body.event.type }}</code>, значение будет
                  строкой; чтобы отправить число или объект, используйте действие «Трансформировать поля».
                </p>

                <div class="rounded-lg border border-line bg-surface p-2">
                  <div class="flex items-center justify-between gap-2">
                    <span class="text-[11px] font-medium text-ink-soft">Заголовки</span>
                    <button type="button" class="btn px-2 py-0.5 text-[11px]" @click="addHeader(action)">
                      + Заголовок
                    </button>
                  </div>

                  <p v-if="action.headers.length === 0" class="mt-1 text-[11px] text-ink-mute">
                    Кроме <code class="font-mono">Content-Type: application/json</code> заголовков нет.
                  </p>

                  <div
                    v-for="(header, headerIndex) in action.headers"
                    :key="headerIndex"
                    class="mt-2 grid grid-cols-[1fr_1.4fr_auto] gap-1.5"
                  >
                    <AppInput v-model="header.name" size="sm" placeholder="X-Event" class="font-mono" />
                    <AppInput v-model="header.value" size="sm" placeholder="payment" class="font-mono" />
                    <button
                      type="button"
                      class="rounded-lg p-1.5 text-ink-mute transition hover:bg-rose-500/10 hover:text-rose-600 dark:hover:text-rose-300"
                      aria-label="Удалить заголовок"
                      @click="removeHeader(action, headerIndex)"
                    >
                      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
                      </svg>
                    </button>
                  </div>
                </div>

                <div class="rounded-lg border border-line bg-surface p-2">
                  <AppCheckbox
                    v-model="action.clearSignature"
                    :label="action.signed ? 'Убрать подпись исходящего запроса' : 'Убрать подпись (её нет)'"
                    :disabled="!action.signed"
                  />
                  <p class="mt-1 text-[11px] text-ink-mute">
                    <template v-if="action.signed && !action.clearSignature">
                      Подпись включена. Поле ниже — чтобы заменить секрет; оставьте пустым, чтобы сохранить текущий.
                    </template>
                    <template v-else>
                      Секрет подписи: запрос уйдёт с
                      <code class="font-mono text-ink-soft">X-Hookdock-Signature</code> (HMAC-SHA256 тела).
                    </template>
                  </p>
                  <AppInput
                    v-model="action.secret"
                    size="sm"
                    type="password"
                    class="mt-2 font-mono"
                    :placeholder="action.signed ? 'оставьте пустым, чтобы сохранить' : 'секрет получателя'"
                    :disabled="action.clearSignature"
                  />

                  <div class="mt-2">
                    <AppCheckbox
                      v-model="action.signTimestamp"
                      label="Подписывать с timestamp"
                      description="X-Hookdock-Timestamp и подпись по «timestamp.тело»."
                      :disabled="action.clearSignature || (!action.signed && action.secret === '')"
                    />
                    <p class="mt-1 text-[11px] text-ink-mute">
                      Включайте, только если получатель умеет проверять такую подпись: проверка
                      «HMAC только тела» ответит отказом.
                    </p>
                  </div>
                </div>

                <p class="text-[11px] text-ink-mute">
                  Плейсхолдеры вида <code class="font-mono text-ink-soft" v-pre>{{ body.customer.email }}</code>
                  работают в URL, заголовках и своём теле.
                </p>
              </div>

              <div v-else-if="action.type === 'log'" class="mt-3">
                <AppInput v-model="action.message" size="sm" placeholder="Оплата от клиента" />
                <p class="mt-1 text-[11px] text-ink-mute">
                  Поддерживаются шаблоны вида
                  <code class="font-mono text-ink-soft" v-pre>{{ body.customer.name }}</code>.
                </p>
              </div>

              <div v-else-if="action.type === 'delay'" class="mt-3 space-y-2">
                <label class="block">
                  <span class="mb-1.5 block text-xs font-medium text-ink-soft">Подождать, секунд</span>
                  <AppInput v-model.number="action.seconds" size="sm" type="number" min="1" max="86400" />
                </label>
                <p class="text-[11px] text-ink-mute">
                  Остальные действия выполнятся позже. Пауза не расходует попытки и не повторяется при
                  ретрае — это удобно, чтобы дождаться, пока источник данных обновится.
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

        <section>
          <div class="flex items-center gap-2">
            <span class="grid h-6 w-6 place-items-center rounded-full bg-indigo-500/10 text-[11px] font-semibold text-indigo-600 dark:text-indigo-300">4</span>
            <h3 class="text-sm font-semibold text-ink">Порядок и остановка</h3>
          </div>

          <div class="mt-3 space-y-3 pl-8">
            <div class="grid gap-3 sm:grid-cols-2">
              <label class="block">
                <span class="mb-1.5 block text-xs font-medium text-ink-soft">Приоритет</span>
                <AppInput v-model.number="form.priority" type="number" min="0" max="1000" />
              </label>
              <div class="self-end">
                <AppCheckbox v-model="form.enabled" label="Сценарий активен" />
              </div>
            </div>
            <p class="text-[11px] text-ink-mute">
              Сценарии с большим приоритетом выполняются первыми. При равном приоритете — по порядку
              создания.
            </p>

            <AppCheckbox
              v-model="form.stopProcessing"
              label="Остановить остальные сценарии после успеха"
            />
            <p class="text-[11px] text-ink-mute">
              Превращает набор сценариев в «срабатывает первое подходящее»: остальные правила этого
              запроса перейдут в статус «пропущено» с пометкой, кто их остановил. Если это правило
              упадёт, остальные всё равно выполнятся.
            </p>
          </div>
        </section>

        <section>
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
              <h3 class="text-sm font-semibold text-ink">Проверка</h3>
              <p class="text-[11px] text-ink-mute">
                Прогон на последнем запросе комнаты: ничего не отправляется и не ставится в очередь.
              </p>
            </div>
            <button type="button" class="btn px-3 py-1.5" :disabled="previewing" @click="check">
              <AppSpinner v-if="previewing" size="xs" />
              <span>{{ previewing ? 'Проверяем…' : 'Проверить правила' }}</span>
            </button>
          </div>

          <p
            v-if="previewError"
            class="mt-3 rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-300"
          >
            {{ previewError }}
          </p>

          <div v-if="preview" class="mt-3 space-y-2">
            <p class="text-[11px] text-ink-mute">
              Запрос #{{ preview.requestId }} · {{ preview.method }} · /{{ preview.endpoint }}
            </p>

            <p
              class="rounded-xl border px-3 py-2 text-xs"
              :class="preview.matched
                ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                : 'border-line bg-surface-2 text-ink-soft'"
            >
              {{ preview.matched
                ? 'Условия совпали — сценарий выполнился бы.'
                : 'Условия не совпали — на этом запросе сценарий пропущен.' }}
            </p>

            <div
              v-for="step in preview.steps"
              :key="step.index"
              class="rounded-xl border border-line bg-surface-2 px-3 py-2 text-[11px]"
            >
              <p class="font-medium text-ink-soft">
                {{ step.index + 1 }}. {{ step.type }}
              </p>

              <p v-if="step.type === 'log'" class="mt-1 text-ink-mute">{{ step.message || 'совпадение' }}</p>

              <p v-else-if="step.type === 'delay'" class="mt-1 text-ink-mute">
                пауза {{ step.seconds }} с, затем остальные действия
              </p>

              <template v-else-if="step.type === 'transform'">
                <pre class="mt-1 overflow-x-auto whitespace-pre-wrap break-all text-ink-mute">{{ JSON.stringify(step.mapping, null, 2) }}</pre>
              </template>

              <template v-else-if="step.type === 'forward'">
                <p class="mt-1 font-mono text-ink-mute break-all">{{ step.method }} {{ step.url }}</p>
                <p v-if="step.timeout" class="mt-1 text-ink-mute">таймаут {{ step.timeout }} с</p>
                <p
                  v-if="!step.allowed"
                  class="mt-1 rounded-lg border border-rose-500/30 bg-rose-500/10 px-2 py-1 text-rose-600 dark:text-rose-300"
                >
                  Адрес будет отклонён: {{ step.reason || 'недопустимый URL' }}
                </p>
                <pre class="mt-1 overflow-x-auto whitespace-pre-wrap break-all text-ink-mute">{{ step.body }}</pre>
              </template>

              <p v-else class="mt-1 text-ink-mute">{{ step.message }}</p>
            </div>
          </div>
        </section>

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

    <JsonPathPicker
      v-if="pickerTarget"
      :room="room"
      :endpoint="form.endpoint"
      @close="closePicker"
      @pick="applyPick"
    />
  </div>
</template>
