<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import AppShell from '../components/AppShell.vue'
import ChannelFormModal from '../components/ChannelFormModal.vue'
import AppSpinner from '../components/ui/AppSpinner.vue'
import { api } from '../api/client'
import { confirm, notify } from '../composables/dialog'
import { subscribe } from '../composables/realtime'
import { useRefresh } from '../composables/useRefresh'
import { useRoomsStore } from '../stores/rooms'
import { formatClock, timeAgo } from '../utils/format'

const route = useRoute()
const rooms = useRoomsStore()

const slug = computed(() => (typeof route.params.slug === 'string' ? route.params.slug : ''))
const room = computed(() => (slug.value ? rooms.find(slug.value) : null))
const roomId = computed(() => room.value?.id ?? 0)

const channels = ref([])
const loading = ref(false)
const error = ref('')
const testing = ref(0)
const removing = ref(0)

const editing = ref(null)
const formOpen = ref(false)

let offRealtime = null
let reloadTimer = null

async function load() {
  if (!roomId.value) {
    return
  }

  loading.value = true

  try {
    const data = await api.channels(roomId.value)
    channels.value = data.data
    error.value = ''
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

const { busy: refreshing, updatedAt, run: refreshNow } = useRefresh(() => load())

// One loader per surface: the first load and the refresh button overlap.
const listLoading = computed(() => loading.value || refreshing.value)

/**
 * Realtime reloads are silent: a socket event must not flash the overlay or
 * touch the "обновлено" clock, which belongs to the button.
 */
function refreshSoon() {
  clearTimeout(reloadTimer)
  reloadTimer = setTimeout(() => load(), 300)
}

function openCreate() {
  editing.value = null
  formOpen.value = true
}

function openEdit(channel) {
  editing.value = channel
  formOpen.value = true
}

function closeForm() {
  formOpen.value = false
  editing.value = null
}

async function onSaved() {
  closeForm()
  await load()
}

/**
 * A real send. "Does this work" is the question a token raises, and the moment an
 * operator finds out should not be the moment a rule fails.
 */
async function test(channel) {
  testing.value = channel.id

  try {
    const result = await api.testChannel(channel.id)

    if (result.ok) {
      notify(`Канал «${channel.name}» ответил`, { tone: 'success' })
    } else {
      // A failed probe is a successful request: the message is the point, not a
      // toast that vanishes.
      notify(
        `Канал «${channel.name}» не ответил${result.status ? ` (HTTP ${result.status})` : ''}: ${result.error || 'причина неизвестна'}`,
        { tone: 'error', timeout: 12000 },
      )
    }

    await load()
  } catch (e) {
    notify(e.message, { tone: 'error' })
  } finally {
    testing.value = 0
  }
}

async function remove(channel) {
  const confirmed = await confirm({
    title: 'Удалить канал?',
    message: `«${channel.name}» исчезнет из редактора сценариев. Действия, которые на него ссылались, начнут падать с «канал не найден», пока вы не выберете другой.`,
    confirmLabel: 'Удалить',
  })

  if (!confirmed) {
    return
  }

  removing.value = channel.id

  try {
    await api.deleteChannel(channel.id)
    notify('Канал удалён', { tone: 'success' })
    await load()
  } catch (e) {
    notify(e.message, { tone: 'error' })
  } finally {
    removing.value = 0
  }
}

function kindLabel(kind) {
  return kind === 'telegram' ? 'Telegram' : 'Slack'
}

/** What the card can say about a channel that has never sent anything. */
function stateOf(channel) {
  if (!channel.enabled) {
    return {
      label: 'выключен',
      tone: 'bg-ink/5 text-ink-soft ring-ink/10',
    }
  }
  if (channel.last_error) {
    return {
      label: 'ошибка',
      tone: 'bg-rose-500/10 text-rose-700 ring-rose-500/25 dark:text-rose-300',
    }
  }
  if (channel.last_sent_at) {
    return {
      label: 'работает',
      tone: 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/25 dark:text-emerald-300',
    }
  }

  return {
    label: 'не проверялся',
    tone: 'bg-amber-500/10 text-amber-700 ring-amber-500/25 dark:text-amber-300',
  }
}

onMounted(() => {
  load()
  offRealtime = subscribe('resync', refreshSoon)
})

onUnmounted(() => {
  offRealtime?.()
  offRealtime = null
  clearTimeout(reloadTimer)
})
</script>

<template>
  <AppShell>
    <div class="mx-auto max-w-4xl space-y-6">
      <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">Оповещения</p>
          <h1 class="mt-1 text-2xl font-semibold text-ink">Уведомления · {{ room?.name || 'комната' }}</h1>
          <p class="mt-1 text-sm text-ink-soft">
            Slack и Telegram для сценариев этой комнаты и сообщения о падениях.
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
          <button type="button" class="btn-primary" :disabled="!roomId" @click="openCreate">
            Новый канал
          </button>
        </div>
      </header>

      <p v-if="error" class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-600 dark:text-rose-300">
        {{ error }}
      </p>

      <p class="text-[11px] text-ink-mute">
        Канал принадлежит комнате, а не сценарию: два правила в один чат делят одну запись,
        и замена протухшего токена — одна правка, а не поиск по всем действиям.
      </p>

      <section class="card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-3">
          <div>
            <h2 class="text-sm font-semibold text-ink">Каналы комнаты</h2>
            <p class="mt-0.5 text-[11px] text-ink-mute">
              Секреты не показываются: адрес Slack-вебхука и токен бота — это учётные данные.
            </p>
          </div>
          <span class="font-mono text-[11px] text-ink-mute">{{ channels.length }}</span>
        </div>

        <div v-if="listLoading && channels.length === 0" class="flex items-center justify-center gap-2 px-5 py-12 text-xs text-ink-soft">
          <AppSpinner size="sm" />
          Загружаем каналы…
        </div>

        <p v-else-if="channels.length === 0" class="px-5 py-12 text-center text-xs text-ink-mute">
          Каналов пока нет. Создайте один, чтобы действие «Уведомить в чат» стало доступно в редакторе.
        </p>

        <ul v-else class="divide-y divide-line">
          <li v-for="channel in channels" :key="channel.id" class="px-5 py-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="truncate text-sm font-medium text-ink">{{ channel.name }}</span>

                  <span class="rounded-full bg-ink/5 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider text-ink-soft ring-1 ring-inset ring-ink/10">
                    {{ kindLabel(channel.kind) }}
                  </span>

                  <span
                    class="rounded-full px-2 py-0.5 text-[10px] font-medium ring-1 ring-inset"
                    :class="stateOf(channel).tone"
                  >
                    {{ stateOf(channel).label }}
                  </span>

                  <span
                    v-if="channel.notify_on_failure"
                    class="rounded-full bg-indigo-500/10 px-2 py-0.5 text-[10px] font-medium text-indigo-700 ring-1 ring-inset ring-indigo-500/25 dark:text-indigo-300"
                  >
                    о падениях
                  </span>
                </div>

                <p class="mt-1 text-[11px] text-ink-mute">
                  <template v-if="channel.kind === 'slack'">
                    <code class="font-mono text-ink-soft">{{ channel.url_hint || 'адрес не задан' }}</code>
                  </template>
                  <template v-else>
                    chat_id <code class="font-mono text-ink-soft">{{ channel.chat_id || '—' }}</code>
                  </template>
                  · {{ channel.last_sent_at ? `последняя отправка ${timeAgo(channel.last_sent_at)}` : 'ещё ничего не отправлял' }}
                </p>

                <p v-if="channel.last_error" class="mt-1 break-words text-[11px] text-rose-600 dark:text-rose-300">
                  {{ channel.last_error }}
                </p>
              </div>

              <div class="flex shrink-0 items-center gap-1.5">
                <button
                  type="button"
                  class="btn px-3 py-1.5 text-xs"
                  :disabled="testing === channel.id"
                  @click="test(channel)"
                >
                  <AppSpinner v-if="testing === channel.id" size="xs" />
                  {{ testing === channel.id ? 'Проверяем…' : 'Проверить' }}
                </button>
                <button type="button" class="btn px-3 py-1.5 text-xs" @click="openEdit(channel)">
                  Изменить
                </button>
                <button
                  type="button"
                  class="rounded-lg px-2 py-1.5 text-xs text-rose-600/80 transition hover:bg-rose-500/10 hover:text-rose-600 disabled:opacity-50 dark:text-rose-300/80"
                  :disabled="removing === channel.id"
                  @click="remove(channel)"
                >
                  {{ removing === channel.id ? 'Удаляем…' : 'Удалить' }}
                </button>
              </div>
            </div>
          </li>
        </ul>
      </section>

      <p class="text-[11px] text-ink-mute">
        «Проверить» действительно отправляет сообщение в чат: другого способа убедиться, что токен
        и права верны, нет. Сообщение о падении приходит только по окончательной ошибке —
        повторная попытка, которая закончилась успешно, ничего не присылает.
      </p>
    </div>

    <ChannelFormModal
      v-if="formOpen && roomId"
      :room-id="roomId"
      :channel="editing"
      @close="closeForm"
      @saved="onSaved"
    />
  </AppShell>
</template>
