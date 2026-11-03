<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import AppCheckbox from './ui/AppCheckbox.vue'
import AppInput from './ui/AppInput.vue'
import AppNumber from './ui/AppNumber.vue'
import AppPassword from './ui/AppPassword.vue'
import AppSelect from './ui/AppSelect.vue'
import AppSpinner from './ui/AppSpinner.vue'
import AppTextarea from './ui/AppTextarea.vue'
import ConditionGroup from './ConditionGroup.vue'
import JsonPathPicker from './JsonPathPicker.vue'
import { api } from '../api/client'
import { useEndpointSearch } from '../composables/endpoints'
import { formatUtcTime } from '../utils/format'

const props = defineProps({
  workflow: { type: Object, default: null },
  room: { type: Object, default: null },
})

const emit = defineEmits(['close', 'saved'])

/** Mirrors `WorkflowsController::MAX_DEPTH`; the API refuses anything deeper. */
const MAX_DEPTH = 5

const KNOWN_PAYLOADS = ['body', 'transformed', 'extracted', 'response', 'raw']

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
    // `extract` reads one source and publishes its fields as `extracted.*`.
    source: 'body',
    extractText: '{\n  "order_id": "data.order.id"\n}',
    // A per-action branch. Empty means "always run", which is why the editor does
    // not send an empty tree at all.
    when: { mode: 'all', rules: [] },
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
    // `notify` points at a channel of the room instead of carrying an address:
    // the credential and the shape of the message live on the channel, so rotating
    // a token is one edit rather than a hunt through every action list.
    channelId: '',
    notifyText: 'Hookdock: пришло событие {{ body.type }}',
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
      // Version of the action list this editor loaded. Sent back on save so a
      // change made in another tab is refused instead of overwritten.
      actionsVersion: 0,
      // A rule fires either on a webhook or on a schedule; `schedule_cron` is the
      // switch on the server, and this flag is just how the editor shows it.
      trigger: 'webhook',
      scheduleCron: '',
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
    actionsVersion: Number(workflow.actions_version ?? 1),
    stopProcessing: Boolean(workflow.stop_processing),
    trigger: workflow.trigger_type === 'schedule' ? 'schedule' : 'webhook',
    scheduleCron: workflow.schedule_cron ?? '',
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
        source: action.source ?? 'body',
        extractText:
          action.type === 'extract' ? JSON.stringify(action.mapping ?? {}, null, 2) : base.extractText,
        when: toTree(action.when),
        seconds: Number(action.seconds ?? base.seconds),
        timeout: Number(action.timeout ?? 0),
        signed: Boolean(action.signed),
        signTimestamp: Boolean(action.sign_timestamp),
        channelId: action.channel_id ?? '',
        notifyText: action.type === 'notify' ? (action.message ?? '') : base.notifyText,
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
  ['extract', 'Извлечь поля из ответа'],
  ['delay', 'Подождать и продолжить'],
  ['notify', 'Уведомить в чат'],
]

const operatorOptions = operators.map(([value, label]) => ({ value, label }))
const actionTypeOptions = actionTypes.map(([value, label]) => ({ value, label }))
const methodOptions = ['POST', 'PUT', 'PATCH', 'GET', 'DELETE'].map((value) => ({ value, label: value }))
const payloadOptions = [
  { value: 'body', label: 'Исходное JSON-тело' },
  { value: 'transformed', label: 'Трансформированные поля' },
  { value: 'extracted', label: 'Извлечённые поля' },
  { value: 'response', label: 'Ответ прошлой пересылки' },
  { value: 'raw', label: 'Сырое тело' },
  { value: 'custom', label: 'Свой JSON' },
]

/**
 * The room's chat channels, for the `notify` action's dropdown.
 *
 * Loaded once, when the editor opens, rather than on every action type change:
 * the picker needs a name for an id that is already stored, and a stale list would
 * show a channel that was just deleted as a blank option.
 */
const channels = ref([])
const channelsLoading = ref(false)

const channelOptions = computed(() =>
  channels.value.map((channel) => ({
    value: channel.id,
    label: `${channel.name} · ${channel.kind === 'telegram' ? 'Telegram' : 'Slack'}${channel.enabled ? '' : ' (выключен)'}`,
  })),
)

async function loadChannels() {
  if (!props.room?.id) {
    return
  }

  channelsLoading.value = true

  try {
    const data = await api.channels(props.room.id)
    channels.value = data.data
  } catch {
    // The editor stays usable: a missing list means the picker says "нет каналов",
    // which is the same answer an empty room gives.
    channels.value = []
  } finally {
    channelsLoading.value = false
  }
}

onMounted(loadChannels)

/**
 * The room's endpoints, for the trigger's picker.
 *
 * A workflow names an endpoint by its slug, and the operator had to type it from
 * memory while the same list was a dropdown one page away in the request log. The
 * search is the one that log uses, with `allowCustom` on: a rule is often written
 * before the first delivery arrives, so the picker must accept a slug nobody has
 * seen yet instead of refusing the very case it is meant to help set up.
 *
 * `stats` is loaded here rather than through props: the editor can be opened from
 * a page that never asked for request statistics, and the closed list has to have
 * something in it without a second round trip from the caller.
 */
const {
  options: endpointOptionList,
  hint: endpointHintText,
  search: searchEndpoints,
  searching: endpointsSearching,
} = useEndpointSearch(() => props.room?.slug ?? '')

const endpointTop = ref(null)
const endpointsLoading = ref(false)
const endpointsFailed = ref(false)

/** "Listen to everything" is the default, so it stays a leading row. */
const ALL_ENDPOINTS = { value: '*', label: '* — все эндпоинты комнаты' }

const endpointOptions = computed(() =>
  endpointOptionList(endpointTop.value, {
    allowCustom: true,
    blank: ALL_ENDPOINTS,
    // `*` is the blank row above; repeating it as "the current value" would list
    // it twice.
    current: form.endpoint === '*' ? '' : form.endpoint,
  }),
)

/**
 * The line under the list has four different truths to tell, and saying the wrong
 * one is worse than saying nothing: "можно ввести свой slug" over a list that is
 * still loading reads as an empty room, and a room with forty endpoints would look
 * complete while the panel shows fifteen.
 */
const endpointHint = computed(() => {
  const total = endpointTop.value?.endpoints_total ?? 0
  const shown = (endpointTop.value?.endpoints ?? []).length

  // A search of its own: the panel already spins in the search field, so this
  // line stays empty instead of repeating "ищу" twice.
  const base = endpointHintText({ total, shown, failedText: 'Не удалось загрузить — введите slug вручную.' })

  if (base !== '') {
    return base
  }

  // Nothing about the room is worth saying while its answer is pending: the
  // search field shows the spinner, and "в комнате пока нет эндпоинтов" a moment
  // before the list arrives reads as a wrong answer, not as a wait.
  if (endpointsSearching.value) {
    return ''
  }

  if (endpointsLoading.value) {
    return 'Загружаю список эндпоинтов…'
  }

  if (endpointsFailed.value) {
    return 'Список не загрузился — введите slug вручную.'
  }

  if (total === 0) {
    // Nothing to pick from, but the control still works: a rule is routinely
    // written before the first delivery, and the endpoint appears in the list
    // after it.
    return 'В комнате пока нет эндпоинтов — введите свой slug, он появится после первого запроса.'
  }

  // A complete list needs no line, and the paragraph under the field already
  // explains that a slug can be typed: repeating it here would be noise.
  return ''
})

async function loadEndpoints() {
  if (!props.room?.id) {
    return
  }

  endpointsLoading.value = true
  endpointsFailed.value = false

  try {
    // The picker needs a list of endpoints, not the room's dashboard. `stats`
    // answers six questions (volume per hour, failure streak, queue depth, active
    // rules, the endpoint page and their count) and the editor would pay for all of
    // them on every open; this route asks the one it uses.
    const data = await api.endpoints({ room: props.room.slug, limit: 15 })
    endpointTop.value = {
      endpoints: data.data,
      endpoints_total: data.meta?.total ?? data.data.length,
    }
  } catch {
    // The picker stays usable: without the short list it still accepts a typed
    // slug, which is the whole reason `allowCustom` is on. The hint says so
    // instead of claiming the room has no endpoints.
    endpointTop.value = null
    endpointsFailed.value = true
  } finally {
    endpointsLoading.value = false
  }
}

onMounted(loadEndpoints)

const extractSources = [
  { value: 'body', label: 'Тело запроса' },
  { value: 'query', label: 'Параметры URL' },
  { value: 'headers', label: 'Заголовки' },
  { value: 'meta', label: 'Служебные поля' },
  { value: 'transformed', label: 'Трансформированные поля' },
  { value: 'extracted', label: 'Уже извлечённые поля' },
  { value: 'response', label: 'Ответ прошлой пересылки' },
]

const conditionCount = computed(() => countLeaves(form.conditions))

/**
 * A scheduled rule has no payload, so its conditions can never match — the server
 * evaluates an empty context. Showing the tree would invite the operator to write
 * a filter that silently never passes, so the editor hides it and says why.
 */
const isScheduled = computed(() => form.trigger === 'schedule')

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

  // The API is the authority (it also refuses an expression that never fires), but
  // an empty field must not travel as "no schedule": that would quietly save the
  // rule as a webhook one and the operator would think it fires on a timer.
  if (isScheduled.value && form.scheduleCron.trim() === '') {
    error.value = 'Укажите cron-выражение, например 0 9 * * 1-5.'
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

    if (action.type === 'notify') {
      if (!action.channelId) {
        error.value = 'Для уведомления выберите канал: он задаёт и адрес, и секрет.'
        return null
      }
      if (!String(action.notifyText ?? '').trim()) {
        error.value = 'Для уведомления укажите текст сообщения.'
        return null
      }

      actions.push({
        type: 'notify',
        channel_id: Number(action.channelId),
        message: String(action.notifyText).trim(),
      })
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

    if (action.type === 'extract') {
      let mapping = {}
      try {
        mapping = action.extractText.trim() ? JSON.parse(action.extractText) : {}
      } catch {
        error.value = 'Маппинг извлечения должен быть корректным JSON.'
        return null
      }

      if (Object.keys(mapping).length === 0) {
        error.value = 'Для извлечения укажите хотя бы одно поле.'
        return null
      }

      actions.push({ type: 'extract', source: action.source || 'body', mapping })
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

  // Attach the per-action branch condition. An empty tree is "always run" and is
  // not sent at all: the API stores `when` only when there is something to check,
  // so a rule that predates branches keeps its exact shape.
  form.actions.forEach((action, index) => {
    if (!actions[index]) {
      return
    }

    const when = pruneTree(action.when, true)
    if (countLeaves(when) > 0) {
      actions[index].when = when
    }
  })

  return {
    id: form.id,
    room_id: props.room?.id,
    // Only when editing: a new rule has no stored version to conflict with, and
    // sending `0` for it would look like a stale draft.
    actions_version: form.id ? form.actionsVersion : undefined,
    name: form.name.trim(),
    // A scheduled rule has no endpoint to listen to, so the server keeps the old
    // one untouched instead of letting an empty string match nothing.
    endpoint: form.endpoint.trim() || '*',
    enabled: form.enabled,
    priority: Math.min(1000, Math.max(0, Number(form.priority) || 0)),
    stop_processing: form.stopProcessing,
    // Empty string clears the schedule, which is how a rule goes back to being a
    // webhook rule. The API treats `null`/`''` the same way.
    schedule_cron: isScheduled.value ? form.scheduleCron.trim() : '',
    conditions: isScheduled.value ? { mode: 'all', rules: [] } : pruneTree(form.conditions, true),
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
    const saved = await api.saveWorkflow(payload)
    // Adopt the version the server assigned, so saving the same open editor twice
    // does not conflict with the worker's own save.
    const version = saved?.data?.actions_version
    if (version !== undefined) {
      form.actionsVersion = Number(version)
    }
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

          <div class="mt-3 space-y-3 pl-8">
            <div class="grid gap-3 sm:grid-cols-2">
              <label class="block">
                <span class="mb-1.5 block text-xs font-medium text-ink-soft">Название сценария</span>
                <AppInput v-model="form.name" placeholder="Уведомления в Slack" />
              </label>

              <div class="block">
                <span class="mb-1.5 block text-xs font-medium text-ink-soft">Когда запускать</span>
                <div class="inline-flex rounded-xl border border-line bg-surface-2 p-1" role="group" aria-label="Триггер сценария">
                  <button
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-xs font-medium transition"
                    :class="!isScheduled
                      ? 'bg-surface text-ink shadow-sm'
                      : 'text-ink-mute hover:text-ink'"
                    :aria-pressed="!isScheduled"
                    @click="form.trigger = 'webhook'"
                  >
                    Вебхук
                  </button>
                  <button
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-xs font-medium transition"
                    :class="isScheduled
                      ? 'bg-surface text-ink shadow-sm'
                      : 'text-ink-mute hover:text-ink'"
                    :aria-pressed="isScheduled"
                    @click="form.trigger = 'schedule'"
                  >
                    Расписание
                  </button>
                </div>
              </div>
            </div>

            <template v-if="!isScheduled">
              <div class="block">
                <span class="mb-1.5 block text-xs font-medium text-ink-soft">Slug эндпоинта</span>
                <AppSelect
                  v-model="form.endpoint"
                  :options="endpointOptions"
                  searchable
                  search-placeholder="Поиск или новый slug"
                  :loading="endpointsLoading"
                  :searching="endpointsSearching"
                  :hint="endpointHint"
                  aria-label="Slug эндпоинта"
                  @search="searchEndpoints"
                />
              </div>

              <p class="text-[11px] text-ink-mute">
                Выберите эндпоинт из списка или введите свой — правило можно создать до первого
                запроса. <code class="font-mono text-ink-soft">*</code> слушает все эндпоинты комнаты.
                Адрес комнаты по умолчанию:
                <code class="font-mono text-ink-soft">/api/webhook{{ room ? `/${room.slug}` : '' }}/{{ form.endpoint === '*' || form.endpoint === '' ? (room?.endpoint || 'hook-…') : form.endpoint }}</code>.
              </p>
            </template>

            <template v-else>
              <label class="block">
                <span class="mb-1.5 block text-xs font-medium text-ink-soft">Cron-выражение</span>
                <AppInput
                  v-model="form.scheduleCron"
                  placeholder="0 9 * * 1-5"
                  class="font-mono sm:max-w-sm"
                />
              </label>

              <p class="text-[11px] text-ink-mute">
                Пять полей: минуты, часы, день месяца, месяц, день недели.
                Время — <strong class="font-semibold text-ink-soft">UTC</strong>, поэтому «9:00» здесь —
                это 12:00 по Москве. Примеры: <code class="font-mono text-ink-soft">*/15 * * * *</code> —
                каждые 15 минут, <code class="font-mono text-ink-soft">0 9 * * 1-5</code> — по будням в 9:00.
              </p>

              <p
                v-if="workflow?.schedule_description"
                class="rounded-xl border border-line bg-surface-2 px-3 py-2 text-[11px] text-ink-soft"
              >
                Сейчас: {{ workflow.schedule_description }}
                <template v-if="workflow.schedule_next_at">
                  · следующий запуск {{ formatUtcTime(workflow.schedule_next_at) }} UTC
                </template>
              </p>

              <p class="text-[11px] text-ink-mute">
                Запуск по расписанию приходит без запроса: тело, заголовки и параметры пусты,
                а в шаблоне доступны <code class="font-mono text-ink-soft">meta.workflow</code> и
                <code class="font-mono text-ink-soft">meta.schedule</code>. Условия такому запуску
                не нужны — они проверяются только у вебхуков.
              </p>
            </template>
          </div>
        </section>

        <section>
          <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2">
              <span class="grid h-6 w-6 place-items-center rounded-full bg-indigo-500/10 text-[11px] font-semibold text-indigo-600 dark:text-indigo-300">2</span>
              <h3 class="text-sm font-semibold text-ink">Условия</h3>
              <span class="text-[11px] text-ink-mute">группы И / ИЛИ, вложенность до {{ MAX_DEPTH }}</span>
            </div>
          </div>

          <div v-if="!isScheduled" class="mt-3 pl-8">
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

          <p
            v-else
            class="mt-3 ml-8 rounded-xl border border-line bg-surface-2 px-3 py-2 text-[11px] text-ink-mute"
          >
            У запланированного сценария нет запроса, поэтому и условий нет: правило выполняется
            на каждом слоте. Отбор по полям имеет смысл только для вебхуков — переключите триггер
            выше, чтобы вернуть условия.
          </p>
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
                  <AppNumber
                    v-model.number="action.timeout"
                    size="sm"
                    :min="0"
                    :max="60"
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
                  <AppPassword
                    v-model="action.secret"
                    size="sm"
                    class="mt-2"
                    :placeholder="action.signed ? 'оставьте пустым, чтобы сохранить' : 'секрет получателя'"
                    :disabled="action.clearSignature"
                    aria-label="Секрет подписи"
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

              <div v-else-if="action.type === 'notify'" class="mt-3 space-y-2">
                <label class="block">
                  <span class="mb-1.5 block text-[11px] text-ink-mute">Канал</span>
                  <AppSelect
                    v-model="action.channelId"
                    :options="channelOptions"
                    :loading="channelsLoading"
                    size="sm"
                    placeholder="Выберите канал комнаты"
                    class="sm:max-w-xs"
                  />
                </label>

                <p v-if="!channelsLoading && channelOptions.length === 0" class="text-[11px] text-ink-mute">
                  В комнате нет каналов. Создайте их на странице «Уведомления» — адрес и секрет
                  хранятся там, а не в сценарии.
                </p>

                <label class="block">
                  <span class="mb-1.5 block text-[11px] text-ink-mute">Сообщение</span>
                  <AppTextarea
                    v-model="action.notifyText"
                    :rows="3"
                    placeholder="Оплата {{ body.amount }} от {{ body.customer.name }}"
                  />
                </label>

                <p class="text-[11px] text-ink-mute">
                  Поддерживаются шаблоны вида
                  <code class="font-mono text-ink-soft" v-pre>{{ body.amount }}</code>, а также
                  <code class="font-mono text-ink-soft" v-pre>{{ extracted.order_id }}</code> —
                  так в сообщение попадают поля, полученные действием «Извлечь».
                  Канал другой комнаты выбрать нельзя.
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
                  <AppNumber v-model.number="action.seconds" size="sm" :min="1" :max="86400" aria-label="Пауза в секундах" />
                </label>
                <p class="text-[11px] text-ink-mute">
                  Остальные действия выполнятся позже. Пауза не расходует попытки и не повторяется при
                  ретрае — это удобно, чтобы дождаться, пока источник данных обновится.
                </p>
              </div>

              <div v-else-if="action.type === 'extract'" class="mt-3 space-y-2">
                <label class="block">
                  <span class="mb-1.5 block text-[11px] text-ink-mute">Откуда взять поля</span>
                  <AppSelect v-model="action.source" :options="extractSources" size="sm" class="w-64" />
                </label>

                <AppTextarea
                  v-model="action.extractText"
                  :rows="4"
                  size="sm"
                  class="font-mono"
                  aria-label="Маппинг извлечения"
                />

                <p class="text-[11px] text-ink-mute">
                  Ключ результата → путь внутри источника, например
                  <code class="font-mono text-ink-soft">{ "order_id": "data.order.id" }</code>.
                  Путь <code class="font-mono text-ink-soft">""</code> берёт источник целиком.
                  Извлечённые поля доступны дальше как
                  <code class="font-mono text-ink-soft" v-pre>{{ extracted.order_id }}</code>
                  или как готовое тело — «Извлечённые поля» в пересылке.
                </p>
                <p class="text-[11px] text-ink-mute">
                  Порядок важен: пересылка кладёт свой ответ в источник «Ответ прошлой пересылки», поэтому
                  извлекать из него можно только действиями ниже.
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

              <!-- A branch on a single action: the same tree the rule itself uses,
                   so "send to Slack only if the amount is big" needs no second rule
                   and no duplicated conditions. -->
              <div class="mt-3 rounded-lg border border-dashed border-line bg-surface p-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <span class="text-[11px] font-medium text-ink-soft">
                    Выполнять, только если
                    <span v-if="countLeaves(action.when) === 0" class="font-normal text-ink-mute">— всегда</span>
                  </span>
                  <button
                    type="button"
                    class="btn px-2 py-0.5 text-[11px]"
                    @click="action.when.rules.push({ path: '', operator: 'equals', value: '' })"
                  >
                    + Условие
                  </button>
                </div>

                <ConditionGroup
                  v-if="countLeaves(action.when) > 0"
                  :group="action.when"
                  :operators="operatorOptions"
                  :max-depth="MAX_DEPTH"
                  class="mt-2"
                  @pick="openPicker"
                />
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
                <AppNumber v-model.number="form.priority" :min="0" :max="1000" aria-label="Приоритет сценария" />
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

        <section v-if="!isScheduled">
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

              <p
                v-if="step.skipped"
                class="mt-1 rounded-lg border border-line bg-surface px-2 py-1 text-ink-mute"
              >
                пропущено: {{ step.reason || 'условие действия не выполнено' }}
              </p>

              <p v-else-if="step.type === 'log'" class="mt-1 text-ink-mute">{{ step.message || 'совпадение' }}</p>

              <p v-else-if="step.type === 'delay'" class="mt-1 text-ink-mute">
                пауза {{ step.seconds }} с, затем остальные действия
              </p>

              <template v-else-if="step.type === 'transform'">
                <pre class="mt-1 overflow-x-auto whitespace-pre-wrap break-all text-ink-mute">{{ JSON.stringify(step.mapping, null, 2) }}</pre>
              </template>

              <template v-else-if="step.type === 'extract'">
                <p class="mt-1 text-ink-mute">источник: {{ step.source }}</p>
                <pre class="mt-1 overflow-x-auto whitespace-pre-wrap break-all text-ink-mute">{{ JSON.stringify(step.extracted, null, 2) }}</pre>
              </template>

              <template v-else-if="step.type === 'notify'">
                <p class="mt-1 text-ink-mute">
                  {{ step.target || 'канал' }}
                  <span v-if="!step.enabled" class="text-amber-600 dark:text-amber-300">· канал выключен</span>
                  <span v-if="step.breaker && step.breaker !== 'closed'" class="text-rose-600 dark:text-rose-300">
                    · адрес не отвечает, повтор через {{ step.breaker_retry_in }} с
                  </span>
                </p>
                <p v-if="step.error" class="mt-1 text-rose-600 dark:text-rose-300">{{ step.error }}</p>
                <pre class="mt-1 overflow-x-auto whitespace-pre-wrap break-all text-ink-mute">{{ step.message }}</pre>
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
