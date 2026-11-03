<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import AppShell from '../components/AppShell.vue'
import AppInput from '../components/ui/AppInput.vue'
import AppFilePicker from '../components/ui/AppFilePicker.vue'
import AppSpinner from '../components/ui/AppSpinner.vue'
import { api } from '../api/client'
import { confirm, notify } from '../composables/dialog'
import { retain, subscribe } from '../composables/realtime'
import { useRefresh } from '../composables/useRefresh'
import { useRoomsStore } from '../stores/rooms'
import { formatClock, timeAgo } from '../utils/format'

const route = useRoute()
const rooms = useRoomsStore()

const slug = computed(() => (typeof route.params.slug === 'string' ? route.params.slug : ''))
const room = computed(() => (slug.value ? rooms.find(slug.value) : null))

const targets = ref([])
const loading = ref(false)
const error = ref('')
const resetting = ref('')

const importing = ref(false)
const exporting = ref(false)

let releaseLive = null
let offRealtime = null
let reloadTimer = null

/**
 * `open` means the address is refused right now; `half_open` means one probe is
 * allowed, so it is still a target that just failed. Both are worth a banner,
 * `closed` rows are here only because they failed recently.
 */
const broken = computed(() => targets.value.filter((target) => target.state !== 'closed'))

async function load({ silent = false } = {}) {
  const roomSlug = slug.value

  if (!silent) {
    loading.value = true
  }

  try {
    const data = await api.breakers()

    if (roomSlug !== slug.value) {
      return
    }

    targets.value = data.data
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

const { busy: refreshing, updatedAt, run: refreshNow } = useRefresh(() => load())

function refreshSoon() {
  clearTimeout(reloadTimer)
  reloadTimer = setTimeout(() => load({ silent: true }), 400)
}

function stateLabel(target) {
  if (target.state === 'half_open') {
    return 'проверяем'
  }
  if (target.state === 'open') {
    return 'не отвечает'
  }

  return 'работает'
}

function stateClass(target) {
  if (target.state === 'half_open') {
    return 'bg-indigo-500/10 text-indigo-700 ring-indigo-500/25 dark:text-indigo-300'
  }
  if (target.state === 'open') {
    return 'bg-rose-500/10 text-rose-700 ring-rose-500/25 dark:text-rose-300'
  }

  return 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/25 dark:text-emerald-300'
}

/**
 * Clears an address after the target was fixed.
 *
 * The row is deleted server-side, so the failure counter does not survive: leaving
 * it would re-open the breaker on the very next error and the reset would look
 * like it did nothing.
 */
async function resetTarget(target) {
  const confirmed = await confirm({
    title: 'Проверить адрес заново?',
    message: `${target.address} перестанет считаться недоступным, и ближайшая пересылка попробует его снова.`,
    confirmLabel: 'Попробовать снова',
  })

  if (!confirmed) {
    return
  }

  resetting.value = target.address

  try {
    await api.resetBreaker(target.address)
    notify('Адрес снова в работе: следующая пересылка его проверит', { tone: 'success' })
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    resetting.value = ''
  }
}

const roomId = computed(() => room.value?.id ?? 0)

async function exportRules() {
  if (!roomId.value) {
    return
  }

  exporting.value = true

  try {
    const { blob, filename } = await api.exportRoomWorkflows(roomId.value)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
    notify('Файл со сценариями скачан', { tone: 'success' })
  } catch (e) {
    error.value = e.message
  } finally {
    exporting.value = false
  }
}

/**
 * Imports into this room only.
 *
 * The document names its own room; the server ignores that and uses the id in the
 * address, so a staging export cannot be imported into production by accident when
 * the file is what the operator drags in. Rules are created, never replaced — the
 * import cannot delete a scenario.
 */
async function onFileChosen(file) {
  if (!file || !roomId.value) {
    return
  }

  let document_
  try {
    document_ = JSON.parse(await file.text())
  } catch {
    error.value = 'Файл не является JSON-документом Hookdock.'
    return
  }

  const count = Array.isArray(document_?.workflows) ? document_.workflows.length : 0
  if (count === 0) {
    error.value = 'В файле нет сценариев (поле workflows пустое).'
    return
  }

  const confirmed = await confirm({
    title: 'Импортировать сценарии?',
    message: `В комнату «${room.value?.name || ''}» будет добавлено сценариев: ${count}. Существующие сценарии не изменятся, совпадающие имена получат суффикс.`,
    confirmLabel: 'Импортировать',
  })

  if (!confirmed) {
    return
  }

  importing.value = true

  try {
    const result = await api.importRoomWorkflows(roomId.value, document_)
    const created = result?.data?.created ?? 0
    const unsigned = result?.data?.secrets_not_imported ?? 0

    notify(`Импортировано сценариев: ${created}`, { tone: 'success' })

    if (unsigned > 0) {
      // A secret cannot travel in a file, so an imported signed forward is unsigned
      // until someone types the secret back. Saying nothing here would make the
      // import look complete while the target silently rejects every request.
      notify(
        `Подписи не перенесены: ${unsigned}. Откройте сценарии и укажите секреты заново.`,
        { tone: 'warning', timeout: 9000 },
      )
    }

    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    importing.value = false
  }
}

onMounted(() => {
  load()

  // Nothing publishes a breaker event: the state is only written by the worker, and
  // a run finishing is the moment it can change. Reloading on the run events keeps
  // the page honest without a timer.
  const offCreated = subscribe('request.created', refreshSoon)
  const offResync = subscribe('resync', refreshSoon)

  releaseLive = retain()

  offRealtime = () => {
    offCreated()
    offResync()
  }
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
    <div class="mx-auto max-w-6xl space-y-6">
      <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">Эксплуатация</p>
          <h1 class="mt-1 text-2xl font-semibold text-ink">Доставка · {{ room?.name || 'комната' }}</h1>
          <p class="mt-1 text-sm text-ink-soft">
            Адреса пересылки, которые перестали отвечать, и перенос сценариев между комнатами.
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
        </div>
      </header>

      <p v-if="error" class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-600 dark:text-rose-300">
        {{ error }}
      </p>

      <!-- One banner, not one per row: the state is "something is not being
           delivered", and a stack of nearly identical warnings hides the count. -->
      <section
        v-if="broken.length"
        class="rounded-2xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-xs text-rose-800 dark:text-rose-200"
      >
        <p class="font-semibold">
          {{ broken.length === 1 ? 'Адрес не отвечает' : `Адресов не отвечает: ${broken.length}` }} —
          пересылки на них пропускаются, а не повторяются вхолостую
        </p>
        <p class="mt-1 text-[11px] text-rose-800/80 dark:text-rose-200/80">
          Пропуск не считается ошибкой сценария, поэтому он не выключается. Исправьте адрес и нажмите
          «Попробовать снова», чтобы проверить его немедленно.
        </p>
      </section>

      <section class="relative">
        <div
          v-if="refreshing"
          class="absolute inset-0 z-10 grid place-items-center rounded-2xl bg-surface/70 backdrop-blur-[1px]"
        >
          <span class="flex items-center gap-2 text-xs font-medium text-ink-soft">
            <AppSpinner size="sm" />
            Обновляем адреса…
          </span>
        </div>

        <div class="card overflow-hidden">
          <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-3">
            <div>
              <h2 class="text-sm font-semibold text-ink">Адреса пересылки</h2>
              <p class="mt-0.5 text-[11px] text-ink-mute">
                Состояние по адресу (схема и хост), общее для всех сценариев комнаты.
              </p>
            </div>
            <span class="font-mono text-[11px] text-ink-mute">{{ targets.length }}</span>
          </div>

          <p v-if="!loading && targets.length === 0" class="px-5 py-12 text-center text-xs text-ink-mute">
            Пока ни один адрес не отмечен как проблемный: пересылки ещё не падали.
          </p>

          <span v-else-if="loading && targets.length === 0" class="flex items-center justify-center gap-2 px-5 py-12 text-xs text-ink-soft">
            <AppSpinner size="sm" />
            Загружаем адреса…
          </span>

          <ul v-else class="divide-y divide-line">
            <li v-for="target in targets" :key="target.address" class="px-5 py-4">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <code class="break-all font-mono text-xs text-ink">{{ target.address }}</code>
                    <span
                      class="rounded-full px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider ring-1 ring-inset"
                      :class="stateClass(target)"
                    >
                      {{ stateLabel(target) }}
                    </span>
                    <span
                      v-if="target.consecutive_failures > 0"
                      class="font-mono text-[10px] text-rose-600 dark:text-rose-300"
                    >
                      {{ target.consecutive_failures }} подряд
                    </span>
                  </div>

                  <p class="mt-1 text-[11px] text-ink-mute">
                    <template v-if="target.state === 'closed'">
                      Последняя ошибка {{ timeAgo(target.last_failure_at) }} · порог {{ target.threshold }}
                    </template>
                    <template v-else>
                      Проверка через {{ target.retry_in }} с · порог {{ target.threshold }},
                      пауза {{ target.cooldown_seconds }} с
                    </template>
                  </p>

                  <p v-if="target.last_error" class="mt-1 break-words text-[11px] text-ink-soft">
                    {{ target.last_error }}
                    <span v-if="target.last_status" class="font-mono text-ink-mute">· HTTP {{ target.last_status }}</span>
                  </p>
                </div>

                <button
                  type="button"
                  class="btn shrink-0"
                  :disabled="resetting === target.address"
                  @click="resetTarget(target)"
                >
                  <AppSpinner v-if="resetting === target.address" size="xs" />
                  Попробовать снова
                </button>
              </div>
            </li>
          </ul>
        </div>
      </section>

      <!-- Export and import: one rule set, two rooms. Secrets deliberately do not
           travel, so the import reports what it could not re-sign. -->
      <section class="card p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <h2 class="text-sm font-semibold text-ink">Сценарии комнаты файлом</h2>
            <p class="mt-1 max-w-2xl text-[11px] text-ink-mute">
              Экспорт содержит условия, действия, приоритеты и флаги. Секреты подписи не выгружаются:
              их нельзя проверить по хешу, поэтому после импорта подпись нужно указать заново.
              Импорт только добавляет сценарии и никогда не удаляет существующие.
            </p>
          </div>

          <div class="flex shrink-0 flex-wrap items-center gap-2">
            <button type="button" class="btn" :disabled="exporting || !roomId" @click="exportRules">
              <AppSpinner v-if="exporting" size="xs" />
              {{ exporting ? 'Готовим файл…' : 'Скачать JSON' }}
            </button>
            <AppFilePicker
              accept="application/json,.json"
              variant="primary"
              :label="importing ? 'Импортируем…' : 'Импортировать'"
              :disabled="importing || !roomId"
              @change="onFileChosen"
            />
          </div>
        </div>

        <div v-if="!roomId" class="mt-3 rounded-xl border border-line bg-surface-2 px-3 py-2 text-[11px] text-ink-mute">
          Дождитесь загрузки комнаты.
        </div>
      </section>

      <p class="text-[11px] text-ink-mute">
        Состояние хранится по адресу, а не по сценарию: список общий для всей установки, поэтому адрес,
        используемый другой комнатой, показан здесь тоже.
      </p>
    </div>
  </AppShell>
</template>
