<script setup>
import { computed, reactive, ref } from 'vue'
import { api } from '../api/client'
import AppCheckbox from './ui/AppCheckbox.vue'
import AppInput from './ui/AppInput.vue'
import AppSelect from './ui/AppSelect.vue'
import AppSpinner from './ui/AppSpinner.vue'

const props = defineProps({
  roomId: { type: Number, required: true },
  channel: { type: Object, default: null },
})

const emit = defineEmits(['close', 'saved'])

const isEdit = computed(() => Boolean(props.channel?.id))

const kindOptions = [
  { value: 'slack', label: 'Slack — входящий вебхук' },
  { value: 'telegram', label: 'Telegram — бот и чат' },
]

const form = reactive({
  name: props.channel?.name ?? '',
  kind: props.channel?.kind ?? 'slack',
  // Both credentials are write-only: the API never returns them, so an empty
  // field on edit means "keep the stored one". Typing a value rotates it.
  url: '',
  bot_token: '',
  chat_id: props.channel?.chat_id ?? '',
  notify_on_failure: Boolean(props.channel?.notify_on_failure),
  enabled: props.channel?.enabled !== false,
})

const saving = ref(false)
const error = ref('')

/** The stored secret is not readable, so the form says what it will do instead. */
const secretHint = computed(() => {
  if (!isEdit.value) {
    return ''
  }

  if (form.kind === 'slack') {
    return props.channel?.has_url
      ? 'URL уже сохранён. Пустое поле оставит его без изменений.'
      : 'URL не задан — укажите его, иначе канал не отправит ни одного сообщения.'
  }

  return props.channel?.has_token
    ? 'Токен уже сохранён. Пустое поле оставит его без изменений.'
    : 'Токен не задан — укажите его, иначе канал не отправит ни одного сообщения.'
})

async function save() {
  error.value = ''

  if (!form.name.trim()) {
    error.value = 'Укажите название канала.'
    return
  }

  // Validated here as well as in the API: the form is the place where a typo is
  // fixable, and a 422 from the server after a submit reads like a broken form.
  if (form.kind === 'slack' && !form.url.trim() && !props.channel?.has_url) {
    error.value = 'Для канала Slack нужен URL входящего вебхука.'
    return
  }
  if (form.kind === 'telegram' && !form.bot_token.trim() && !props.channel?.has_token) {
    error.value = 'Для канала Telegram нужен токен бота от @BotFather.'
    return
  }
  if (form.kind === 'telegram' && !form.chat_id.trim()) {
    error.value = 'Укажите chat_id: число вида -1001234567890 или @username.'
    return
  }

  const body = {
    name: form.name.trim(),
    kind: form.kind,
    chat_id: form.kind === 'telegram' ? form.chat_id.trim() : null,
    notify_on_failure: form.notify_on_failure,
    enabled: form.enabled,
  }

  // Only a non-empty value is sent: `url: ""` would be read as "keep", but sending
  // the key at all on an unrelated edit is the kind of thing that later becomes
  // "clear" by accident.
  if (form.kind === 'slack' && form.url.trim()) {
    body.url = form.url.trim()
  }
  if (form.kind === 'telegram' && form.bot_token.trim()) {
    body.bot_token = form.bot_token.trim()
  }

  saving.value = true

  try {
    if (isEdit.value) {
      await api.updateChannel(props.channel.id, body)
    } else {
      await api.createChannel(props.roomId, body)
    }

    emit('saved')
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/40 p-4 backdrop-blur-[2px] sm:items-center">
    <div class="card w-full max-w-lg p-5">
      <div class="flex items-start justify-between gap-3">
        <div>
          <h2 class="text-sm font-semibold text-ink">
            {{ isEdit ? 'Канал уведомлений' : 'Новый канал' }}
          </h2>
          <p class="mt-1 text-[11px] text-ink-mute">
            Куда сценарии этой комнаты смогут отправить сообщение.
          </p>
        </div>
        <button
          type="button"
          class="rounded-lg p-1.5 text-ink-mute transition hover:bg-surface-2 hover:text-ink"
          aria-label="Закрыть"
          @click="emit('close')"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
          </svg>
        </button>
      </div>

      <div class="mt-4 space-y-3">
        <label class="block">
          <span class="mb-1.5 block text-xs font-medium text-ink-soft">Название</span>
          <AppInput v-model="form.name" placeholder="Ops, дежурная смена" />
          <span class="mt-1 block text-[11px] text-ink-mute">
            Видно в редакторе сценария при выборе канала. В комнате название уникально.
          </span>
        </label>

        <label class="block">
          <span class="mb-1.5 block text-xs font-medium text-ink-soft">Тип</span>
          <AppSelect v-model="form.kind" :options="kindOptions" :disabled="isEdit" />
          <span v-if="isEdit" class="mt-1 block text-[11px] text-ink-mute">
            Тип не меняется: у Slack и Telegram разные адреса и разные поля.
          </span>
        </label>

        <label v-if="form.kind === 'slack'" class="block">
          <span class="mb-1.5 block text-xs font-medium text-ink-soft">URL входящего вебхука</span>
          <AppInput
            v-model="form.url"
            :placeholder="isEdit && channel?.has_url ? `сохранён · ${channel.url_hint}` : 'https://hooks.slack.com/services/…'"
            type="password"
          />
          <span class="mt-1 block text-[11px] text-ink-mute">
            Приложение → Incoming Webhooks → Add New Webhook to Workspace.
            {{ secretHint }}
          </span>
        </label>

        <template v-else>
          <label class="block">
            <span class="mb-1.5 block text-xs font-medium text-ink-soft">Токен бота</span>
            <AppInput
              v-model="form.bot_token"
              :placeholder="isEdit && channel?.has_token ? 'сохранён' : '123456789:AAH…'"
              type="password"
            />
            <span class="mt-1 block text-[11px] text-ink-mute">
              Выдаёт @BotFather. {{ secretHint }}
            </span>
          </label>

          <label class="block">
            <span class="mb-1.5 block text-xs font-medium text-ink-soft">chat_id</span>
            <AppInput v-model="form.chat_id" placeholder="-1001234567890 или @channel" />
            <span class="mt-1 block text-[11px] text-ink-mute">
              Бот должен состоять в чате: иначе Telegram ответит «chat not found».
            </span>
          </label>
        </template>

        <div class="space-y-2.5 rounded-xl border border-line bg-surface-2 px-3 py-2.5">
          <AppCheckbox
            v-model="form.notify_on_failure"
            label="Сообщать о падениях сценариев комнаты"
            description="Отправляется только по окончательной ошибке, не по каждой попытке, и при автоматическом выключении. Значение — на канал, а не на сценарий: одно правило может упасть, а сообщить о нём захочет вся комната."
          />

          <AppCheckbox
            v-model="form.enabled"
            label="Канал включён"
            description="Выключенный канал не отправляет ничего, а действие в сценарии записывается как пропущенное, а не как ошибка — иначе пауза чата выглядела бы сломанным правилом."
          />
        </div>

        <p v-if="error" class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-600 dark:text-rose-300">
          {{ error }}
        </p>
      </div>

      <div class="mt-4 flex items-center justify-end gap-2">
        <button type="button" class="btn" @click="emit('close')">Отмена</button>
        <button type="button" class="btn-primary" :disabled="saving" @click="save">
          <AppSpinner v-if="saving" size="xs" />
          {{ saving ? 'Сохраняем…' : 'Сохранить' }}
        </button>
      </div>
    </div>
  </div>
</template>
