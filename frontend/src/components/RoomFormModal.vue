<script setup>
import { computed, reactive, ref } from 'vue'
import { useRoomsStore } from '../stores/rooms'
import { api } from '../api/client'
import { confirm } from '../composables/dialog'
import AppInput from './ui/AppInput.vue'
import AppCheckbox from './ui/AppCheckbox.vue'
import AppTextarea from './ui/AppTextarea.vue'
import { randomEndpoint, isValidEndpoint } from '../utils/endpoint'
import { randomSlug, isValidSlug } from '../utils/slug'

const props = defineProps({
  room: { type: Object, default: null },
})

const emit = defineEmits(['close', 'saved'])

const roomsStore = useRoomsStore()

const form = reactive({
  name: props.room?.name ?? '',
  slug: props.room?.slug ?? randomSlug(),
  endpoint: props.room?.endpoint ?? randomEndpoint(),
  // New room only: the signature is part of the same INSERT, so the room never
  // exists for a moment in a state the operator did not ask for.
  requireSignature: false,
  // Idempotent delivery. The header is the sender's own delivery id; empty means
  // "off", which is the default so an upgrade cannot silently drop repeats.
  dedupHeader: props.room?.dedup_header ?? '',
  // Only meaningful together with a secret: there is nothing to sign otherwise.
  requireTimestamp: Boolean(props.room?.require_timestamp),
  // One entry per line rather than a comma-separated field: ranges are pasted from
  // a provider's documentation, and a line break is what that page already uses.
  allowlist: (props.room?.ip_allowlist ?? []).join('\n'),
})
const saving = ref(false)
const error = ref('')

// The secret itself is only ever held in this ref, right after it is generated:
// the API never returns it again, so there is nothing to load on open.
const hasSecret = ref(Boolean(props.room?.has_secret))
const secret = ref('')
const secretBusy = ref(false)
const secretCopied = ref(false)
const secretError = ref('')

// Set when a *created* room came back with a secret. The room is already saved
// (the sidebar shows it), so the form turns into a one-time receipt: closing it
// would throw away a value that cannot be read again.
const createdSecret = computed(() => !isEdit.value && secret.value !== '')

const isEdit = computed(() => Boolean(props.room))

/**
 * Whether the room will verify signatures after this form is saved: an existing
 * room by its issued secret, a new one by the checkbox that issues it in the same
 * INSERT. The timestamp requirement is meaningless without it, and the hint has
 * to say so instead of letting the operator enable a setting that does nothing.
 */
const willSign = computed(() => (isEdit.value ? hasSecret.value : form.requireSignature))

/**
 * The slug is part of the webhook URL and is random, not derived from the room
 * name, so a rename cannot silently break the integrations already pointing at
 * it. A new room sends the generated value, which lets the form preview the
 * address; an edit leaves it out of the payload and the server keeps it.
 */
const slugPreview = computed(() => {
  if (isEdit.value) {
    return props.room.slug
  }

  return form.slug.trim().toLowerCase()
})

/**
 * The endpoint is part of the address users copy into third-party services, so
 * it is shown as a full URL rather than an isolated slug. It is generated here
 * for a new room and can be typed over or regenerated before saving.
 */
const webhookPreview = computed(() => {
  const slug = slugPreview.value
  const endpoint = form.endpoint.trim()

  return slug && endpoint ? `/api/webhook/${slug}/${endpoint}` : ''
})

function regenerateSlug() {
  form.slug = randomSlug()
}

function regenerateEndpoint() {
  form.endpoint = randomEndpoint()
}

/**
 * Turns the HMAC requirement on, or replaces the key. Rotating silently breaks
 * every sender still using the old value, so it is confirmed first.
 */
async function rotateSecret() {
  if (!props.room) {
    return
  }

  const confirmed = await confirm({
    title: hasSecret.value ? 'Перевыпустить секрет?' : 'Включить подпись?',
    message: hasSecret.value
      ? 'Старый секрет перестанет приниматься сразу. Новый нужно передать всем отправителям.'
      : 'Комната начнёт принимать только запросы с заголовком X-Hookdock-Signature. Незаподписанные будут отклонены с 401.',
    confirmLabel: hasSecret.value ? 'Перевыпустить' : 'Включить',
  })

  if (!confirmed) {
    return
  }

  secretBusy.value = true
  secretError.value = ''

  try {
    const data = await api.rotateRoomSecret(props.room.id)
    secret.value = data.data.secret
    hasSecret.value = true
    secretCopied.value = false
    await roomsStore.load({ silent: true })
  } catch (e) {
    secretError.value = e.message
  } finally {
    secretBusy.value = false
  }
}

async function clearSecret() {
  if (!props.room) {
    return
  }

  const confirmed = await confirm({
    title: 'Отключить подпись?',
    message: 'Комната снова будет принимать любые запросы без подписи — включая чужие.',
    confirmLabel: 'Отключить',
  })

  if (!confirmed) {
    return
  }

  secretBusy.value = true
  secretError.value = ''

  try {
    await api.clearRoomSecret(props.room.id)
    secret.value = ''
    hasSecret.value = false
    await roomsStore.load({ silent: true })
  } catch (e) {
    secretError.value = e.message
  } finally {
    secretBusy.value = false
  }
}

async function copySecret() {
  try {
    await navigator.clipboard.writeText(secret.value)
    secretCopied.value = true
    setTimeout(() => (secretCopied.value = false), 1500)
  } catch {
    // Буфер обмена может быть недоступен.
  }
}

async function submit() {
  error.value = ''

  // The room already exists and its secret is on screen; Enter must close the
  // receipt, not create a second room.
  if (createdSecret.value) {
    close()
    return
  }

  if (!form.name.trim()) {
    error.value = 'Укажите название комнаты.'
    return
  }

  const slug = form.slug.trim().toLowerCase()
  const endpoint = form.endpoint.trim().toLowerCase()

  // A new room sends its generated slug so the address in the form is the one
  // that gets stored. An empty slug lets the server draw a random one.
  if (!isEdit.value && slug !== '' && !isValidSlug(slug)) {
    error.value = 'Slug: латинские буквы, цифры и дефис, до 64 символов.'
    return
  }

  // An empty field is not an error: the server generates a random endpoint, and
  // an explicitly empty value asks it to replace the current one.
  if (endpoint !== '' && !isValidEndpoint(endpoint)) {
    error.value = 'Эндпоинт: латинские буквы, цифры и дефис, до 64 символов.'
    return
  }

  saving.value = true

  try {
    // The slug is sent only for a new room: an edit must not silently change an
    // address integrations already use, so the server keeps the current one.
    // Parsed here rather than by splitting on every comma: the textarea is a list
    // of lines, and an empty textarea is an explicit `[]` — the operator clearing
    // the field means "accept anyone", not "keep whatever was there".
    const allowlist = form.allowlist
      .split(/\r?\n|,/)
      .map((entry) => entry.trim())
      .filter((entry) => entry !== '')

    const payload = {
      name: form.name.trim(),
      endpoint,
      dedup_header: form.dedupHeader.trim(),
      require_timestamp: form.requireTimestamp,
      ip_allowlist: allowlist,
    }
    if (!isEdit.value) {
      payload.slug = slug
      // The signature is issued inside the same INSERT. Enabling it afterwards
      // would leave a window in which the room is already listed but public.
      payload.require_signature = form.requireSignature
    }

    const saved = isEdit.value
      ? await roomsStore.update(props.room.id, payload)
      : await roomsStore.create(payload)

    // A created room with a signature answers once with the secret. The card is
    // already in the sidebar, so the form stays open to show that value rather
    // than closing over it.
    if (!isEdit.value && saved?.secret) {
      secret.value = saved.secret
      hasSecret.value = true
      secretCopied.value = false
      emit('saved', saved)
      return
    }

    emit('saved', saved)
    emit('close')
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

/** Leaves the one-time receipt: the room is saved, only the dialog remains. */
function close() {
  emit('close')
}
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/30 p-4 backdrop-blur-sm dark:bg-slate-950/80 sm:p-8"
        @click.self="close"
      >
        <form class="card my-auto w-full max-w-md overflow-hidden" @submit.prevent="submit">
          <header class="flex items-start justify-between gap-4 border-b border-line px-6 py-5">
            <div>
              <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">
                {{ createdSecret ? 'Комната создана' : isEdit ? 'Настройки комнаты' : 'Новая комната' }}
              </p>
              <h2 class="mt-1 text-lg font-semibold text-ink">
                {{ createdSecret ? form.name : isEdit ? form.name || 'Комната' : 'Куда складывать запросы' }}
              </h2>
            </div>
            <button
              type="button"
              class="rounded-lg p-1.5 text-ink-mute transition hover:bg-surface-2 hover:text-ink"
              aria-label="Закрыть"
              @click="close"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
              </svg>
            </button>
          </header>

          <div v-if="createdSecret" class="space-y-4 px-6 py-6">
            <p class="text-sm text-ink">Ещё один адрес — теперь с обязательной подписью.</p>
            <p class="text-[11px] text-ink-mute">
              Скопируйте секрет в сервис-отправитель сейчас: показать его снова будет нельзя, только
              перевыпустить (тогда старый перестанет приниматься).
            </p>

            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-3">
              <p class="text-[11px] font-medium uppercase tracking-wider text-emerald-700 dark:text-emerald-300">
                Секрет комнаты
              </p>
              <div class="mt-2 flex items-center gap-2">
                <code class="min-w-0 flex-1 break-all rounded bg-surface px-2 py-1.5 font-mono text-[11px] text-ink">
                  {{ secret }}
                </code>
                <button type="button" class="btn shrink-0 px-2.5 py-1 text-[11px]" @click="copySecret">
                  {{ secretCopied ? 'Скопировано' : 'Копировать' }}
                </button>
              </div>
            </div>

            <div class="rounded-xl border border-line bg-surface-2 p-3 text-[11px] text-ink-mute">
              <p class="font-medium text-ink-soft">Как подписывать запросы</p>
              <p class="mt-1">
                Заголовок <code class="font-mono text-ink">X-Hookdock-Signature: sha256=…</code> —
                HMAC-SHA256 сырого тела с этим секретом. Запрос без верной подписи получает
                <code class="font-mono text-ink">401</code> и не попадает в лог.
              </p>
              <p v-if="webhookPreview" class="mt-1.5 break-all">
                Адрес: <code class="font-mono text-ink">{{ webhookPreview }}</code>
              </p>
            </div>
          </div>

          <div v-else class="space-y-4 px-6 py-6">
            <label class="block">
              <span class="mb-1.5 block text-xs font-medium text-ink-soft">Название</span>
              <AppInput v-model="form.name" placeholder="Стрип · прод" autofocus />
            </label>

            <div class="block">
              <span class="mb-1.5 block text-xs font-medium text-ink-soft">Slug в адресе</span>
              <AppInput
                v-if="isEdit"
                :model-value="slugPreview"
                class="font-mono"
                disabled
              />
              <div v-else class="flex items-center gap-2">
                <AppInput
                  v-model="form.slug"
                  placeholder="785e9e9c1b10-9a7be406d763"
                  aria-label="Slug в адресе"
                  class="font-mono"
                />
                <button
                  type="button"
                  class="btn shrink-0 px-3"
                  title="Сгенерировать случайный slug"
                  @click="regenerateSlug"
                >
                  Сгенерировать
                </button>
              </div>
            </div>

            <div class="block">
              <span class="mb-1.5 block text-xs font-medium text-ink-soft">Эндпоинт в адресе</span>
              <div class="flex items-center gap-2">
                <AppInput
                  v-model="form.endpoint"
                  placeholder="hook-7f3a9c"
                  aria-label="Эндпоинт в адресе"
                  class="font-mono"
                />
                <button
                  type="button"
                  class="btn shrink-0 px-3"
                  title="Сгенерировать случайный эндпоинт"
                  @click="regenerateEndpoint"
                >
                  Сгенерировать
                </button>
              </div>
            </div>

            <!-- Delivery settings: idempotency and the signed-timestamp requirement.
                 Both are opt-in, so an existing room keeps accepting exactly the
                 requests it accepts today until the operator turns them on. -->
            <div class="rounded-xl border border-line bg-surface-2 p-3">
              <p class="text-xs font-medium text-ink-soft">Доставка</p>

              <label class="mt-2 block">
                <span class="mb-1.5 block text-[11px] text-ink-mute">Заголовок дедупликации</span>
                <AppInput
                  v-model="form.dedupHeader"
                  placeholder="X-GitHub-Delivery"
                  class="font-mono"
                  aria-label="Заголовок дедупликации"
                />
              </label>
              <p class="mt-1 text-[11px] text-ink-mute">
                Значение этого заголовка считается идентификатором доставки. Повтор с тем же
                значением сохраняется в логе, но не запускает сценарии второй раз. Пусто — выключено.
              </p>

              <label class="mt-3 block">
                <span class="mb-1.5 block text-[11px] text-ink-mute">Разрешённые адреса отправителей</span>
                <AppTextarea
                  v-model="form.allowlist"
                  :rows="3"
                  size="sm"
                  class="font-mono"
                  placeholder="203.0.113.0/24&#10;198.51.100.7"
                  aria-label="Разрешённые адреса отправителей"
                />
              </label>
              <p class="mt-1 text-[11px] text-ink-mute">
                По одному адресу или маске CIDR в строке. Запрос с чужого адреса получает
                <code class="font-mono">403</code> и не попадает в лог. Пусто — принимаем со всех адресов.
              </p>

              <div class="mt-3">
                <AppCheckbox
                  v-model="form.requireTimestamp"
                  label="Требовать X-Hookdock-Timestamp"
                  description="Подпись считается по «timestamp.тело», запрос со старым timestamp отклоняется."
                />
                <p class="mt-1 text-[11px] text-ink-mute">
                  <template v-if="willSign">
                    Окно допуска — 5 минут. Заголовок обязателен, иначе запрос получит
                    <code class="font-mono">401</code>.
                  </template>
                  <template v-else>
                    Работает только вместе с подписью: у комнаты без секрета проверять нечего,
                    и флаг будет проигнорирован.
                  </template>
                </p>
              </div>
            </div>

            <!-- Creation: the signature is a checkbox that only flags the payload.
                 The room and its secret are written by one INSERT, so no second
                 request can fail and leave a public room behind. -->
            <div v-if="!isEdit" class="rounded-xl border border-line bg-surface-2 p-3">
              <AppCheckbox
                v-model="form.requireSignature"
                label="Требовать подпись вебхуков"
                description="Комната будет принимать только запросы с верным X-Hookdock-Signature."
              />
              <p class="mt-1.5 text-[11px] text-ink-mute">
                Заголовок <code class="font-mono">X-Hookdock-Signature: sha256=…</code> — HMAC-SHA256
                сырого тела. Секрет покажем один раз сразу после создания.
              </p>
            </div>

            <div v-if="isEdit" class="rounded-xl border border-line bg-surface-2 p-3">
              <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-medium text-ink-soft">Подпись вебхуков</span>
                <span
                  class="rounded-md px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider"
                  :class="hasSecret
                    ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                    : 'bg-ink/5 text-ink-mute'"
                >
                  {{ hasSecret ? 'включена' : 'выключена' }}
                </span>
              </div>

              <p class="mt-1.5 text-[11px] text-ink-mute">
                Заголовок <code class="font-mono">X-Hookdock-Signature: sha256=…</code> — HMAC-SHA256
                сырого тела. Запрос без верной подписи отклоняется (401) и не попадает в лог.
              </p>

              <div v-if="secret" class="mt-2 rounded-lg border border-emerald-500/30 bg-emerald-500/5 p-2.5">
                <p class="text-[11px] font-medium text-emerald-700 dark:text-emerald-300">
                  Секрет показывается один раз — сохраните его в сервисе-отправителе.
                </p>
                <div class="mt-1.5 flex items-center gap-2">
                  <code class="min-w-0 flex-1 truncate rounded bg-surface px-2 py-1 font-mono text-[11px] text-ink">
                    {{ secret }}
                  </code>
                  <button type="button" class="btn shrink-0 px-2.5 py-1 text-[11px]" @click="copySecret">
                    {{ secretCopied ? 'Скопировано' : 'Копировать' }}
                  </button>
                </div>
              </div>

              <div class="mt-2 flex flex-wrap gap-2">
                <button
                  type="button"
                  class="btn px-2.5 py-1 text-[11px]"
                  :disabled="secretBusy"
                  @click="rotateSecret"
                >
                  {{ hasSecret ? 'Перевыпустить секрет' : 'Включить подпись' }}
                </button>
                <button
                  v-if="hasSecret"
                  type="button"
                  class="btn px-2.5 py-1 text-[11px]"
                  :disabled="secretBusy"
                  @click="clearSecret"
                >
                  Отключить
                </button>
              </div>

              <p v-if="secretError" class="mt-1.5 text-[11px] text-rose-600 dark:text-rose-300">
                {{ secretError }}
              </p>

            </div>

            <p class="text-[11px] text-ink-mute">
              <template v-if="isEdit">
                Адрес комнаты: <code class="font-mono">{{ webhookPreview }}</code>.
                Slug меняется только через API, чтобы не сломать настроенные интеграции; эндпоинт
                можно изменить здесь.
              </template>
              <template v-else-if="webhookPreview">
                Адрес комнаты: <code class="font-mono">{{ webhookPreview }}</code>.
                Пустые slug или эндпоинт сервер заменит случайными.
              </template>
              <template v-else>
                Slug и эндпоинт присвоятся автоматически, если оставить поля пустыми.
              </template>
            </p>

            <p
              v-if="error"
              class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-600 dark:text-rose-300"
            >
              {{ error }}
            </p>
          </div>

          <footer class="flex items-center justify-end gap-2 border-t border-line bg-surface-2 px-6 py-4">
            <template v-if="createdSecret">
              <button type="submit" class="btn-primary">Готово</button>
            </template>
            <template v-else>
              <button type="button" class="btn" @click="close">Отмена</button>
              <button type="submit" class="btn-primary" :disabled="saving">
                {{ saving ? 'Сохраняем…' : isEdit ? 'Сохранить' : 'Создать' }}
              </button>
            </template>
          </footer>
        </form>
      </div>
    </Transition>
  </Teleport>
</template>
