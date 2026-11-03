<script setup>
import { computed, onMounted, ref } from 'vue'
import AppShell from '../components/AppShell.vue'
import AppDatePicker from '../components/ui/AppDatePicker.vue'
import AppInput from '../components/ui/AppInput.vue'
import AppSelect from '../components/ui/AppSelect.vue'
import AppSpinner from '../components/ui/AppSpinner.vue'
import { api } from '../api/client'
import { confirm, notify } from '../composables/dialog'
import { useRefresh } from '../composables/useRefresh'
import { useAuthStore } from '../stores/auth'
import { formatTime, timeAgo } from '../utils/format'

const auth = useAuthStore()

const tokens = ref([])
const loading = ref(false)
const error = ref('')

const scopeOptions = [
  { value: 'read', label: 'Только чтение — логи, запуски, аналитика' },
  { value: 'api', label: 'Полный доступ — как вход в кабинет' },
]

const scopeLabels = {
  read: 'чтение',
  api: 'полный',
}

const scopeTone = {
  read: 'bg-ink/5 text-ink-soft ring-ink/10',
  api: 'bg-indigo-500/10 text-indigo-700 ring-indigo-500/25 dark:text-indigo-300',
}

const form = ref({ name: '', scope: 'read', expires_at: '' })
const saving = ref(false)
const formError = ref('')

// The plaintext is held only until the operator dismisses the receipt: the list
// never returns it, so losing it here means issuing a new token.
const issued = ref(null)
const issuedCopied = ref(false)

const revoking = ref(0)

async function load() {
  loading.value = true

  try {
    const data = await api.tokens()
    tokens.value = data.data
    error.value = ''
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

const { busy: refreshing, updatedAt, run: refreshNow } = useRefresh(() => load())

// One loader for the list: the first load and the refresh button overlap.
const listLoading = computed(() => loading.value || refreshing.value)

const activeCount = computed(() => tokens.value.filter((token) => !token.revoked && !token.expired).length)

async function issue() {
  formError.value = ''

  if (!form.value.name.trim()) {
    formError.value = 'Укажите название — по нему токен будет видно в списке.'
    return
  }

  saving.value = true

  try {
    const data = await api.createToken({
      name: form.value.name.trim(),
      scope: form.value.scope,
      // The calendar yields `YYYY-MM-DD`; an empty value means "does not
      // expire", which the server spells as `null` and not as a far-future date.
      expires_at: form.value.expires_at || null,
    })
    issued.value = data.data
    issuedCopied.value = false
    form.value = { name: '', scope: 'read', expires_at: '' }
    notify('Токен выпущен', { tone: 'success' })
    await load()
  } catch (e) {
    formError.value = e.message
  } finally {
    saving.value = false
  }
}

async function copyIssued() {
  if (!issued.value?.token) {
    return
  }

  try {
    await navigator.clipboard.writeText(issued.value.token)
    issuedCopied.value = true
    setTimeout(() => (issuedCopied.value = false), 1500)
  } catch {
    // Буфер обмена может быть недоступен.
  }
}

function dismissIssued() {
  issued.value = null
}

async function revoke(token) {
  const confirmed = await confirm({
    title: 'Отозвать токен?',
    message: `«${token.name}» перестанет работать сразу — на следующем же запросе. Выпустить новый можно здесь же.`,
    confirmLabel: 'Отозвать',
  })

  if (!confirmed) {
    return
  }

  revoking.value = token.id

  try {
    await api.revokeToken(token.id)
    notify('Токен отозван', { tone: 'success' })
    await load()
  } catch (e) {
    notify(e.message, { tone: 'error' })
  } finally {
    revoking.value = 0
  }
}

/**
 * What the row says about a key that no longer authenticates. Revoked wins over
 * expired: an operator who revoked a key should not have to reason about whether
 * its date also passed in the meantime.
 */
function stateOf(token) {
  if (token.revoked) {
    return { label: 'отозван', tone: 'bg-rose-500/10 text-rose-700 ring-rose-500/25 dark:text-rose-300' }
  }
  if (token.expired) {
    return { label: 'истёк', tone: 'bg-amber-500/10 text-amber-700 ring-amber-500/25 dark:text-amber-300' }
  }

  return { label: 'активен', tone: 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/25 dark:text-emerald-300' }
}

onMounted(() => {
  load()

  if (!auth.user?.role) {
    auth.fetchMe()
  }
})
</script>

<template>
  <AppShell>
    <div class="mx-auto max-w-4xl space-y-6">
      <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">Доступ</p>
          <h1 class="mt-1 text-2xl font-semibold text-ink">Токены</h1>
          <p class="mt-1 text-sm text-ink-soft">
            Долгоживущие ключи для скриптов и CI: их можно отозвать, в отличие от сессии в браузере.
          </p>
        </div>

        <div class="flex items-center gap-2">
          <span v-if="updatedAt" class="font-mono text-[11px] text-ink-mute">обновлено {{ formatTime(updatedAt) }}</span>
          <button type="button" class="btn" :disabled="refreshing" @click="refreshNow">
            <AppSpinner v-if="refreshing" size="xs" />
            {{ refreshing ? 'Обновляем…' : 'Обновить' }}
          </button>
        </div>
      </header>

      <p v-if="error" class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-600 dark:text-rose-300">
        {{ error }}
      </p>

      <section v-if="issued" class="card border-emerald-500/30 p-5">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h2 class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">
              Токен «{{ issued.name }}» выпущен
            </h2>
            <p class="mt-1 text-[11px] text-ink-mute">
              Скопируйте его сейчас: показать снова нельзя, только отозвать и выпустить новый.
            </p>
          </div>
          <button type="button" class="rounded-lg p-1.5 text-ink-mute transition hover:bg-surface-2 hover:text-ink" aria-label="Закрыть" @click="dismissIssued">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
            </svg>
          </button>
        </div>

        <div class="mt-3 flex items-center gap-2">
          <code class="min-w-0 flex-1 break-all rounded-lg bg-surface px-2.5 py-2 font-mono text-[11px] text-ink">
            {{ issued.token }}
          </code>
          <button type="button" class="btn shrink-0 px-3 py-1.5 text-xs" @click="copyIssued">
            {{ issuedCopied ? 'Скопировано' : 'Копировать' }}
          </button>
        </div>

        <p class="mt-2 text-[11px] text-ink-mute">
          Использование: <code class="font-mono text-ink">Authorization: Bearer …</code>.
          <template v-if="issued.scope === 'read'">
            Токен только для чтения: изменения получат <code class="font-mono">403</code>.
          </template>
          <template v-else>
            Полный доступ: он может всё то же, что вход в кабинет под вашей учётной записью.
          </template>
        </p>
      </section>

      <section class="card p-5">
        <h2 class="text-sm font-semibold text-ink">Новый токен</h2>
        <p class="mt-1 text-[11px] text-ink-mute">
          Владелец токена — ваша учётная запись: роль и её ограничения продолжают действовать.
        </p>

        <div class="mt-3 grid gap-3 sm:grid-cols-3">
          <label class="block">
            <span class="mb-1.5 block text-xs font-medium text-ink-soft">Название</span>
            <AppInput v-model="form.name" placeholder="Ночной экспорт" />
          </label>

          <label class="block">
            <span class="mb-1.5 block text-xs font-medium text-ink-soft">Права</span>
            <AppSelect v-model="form.scope" :options="scopeOptions" />
          </label>

          <label class="block">
            <span class="mb-1.5 block text-xs font-medium text-ink-soft">Истекает</span>
            <AppDatePicker v-model="form.expires_at" placeholder="Без срока" aria-label="Дата истечения" />
          </label>
        </div>

        <p class="mt-2 text-[11px] text-ink-mute">
          Пустая дата — токен не истекает. Это нормально для интеграции, которую никто не собирается
          перевыпускать: отозвать его можно в любой момент.
        </p>

        <p v-if="formError" class="mt-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-600 dark:text-rose-300">
          {{ formError }}
        </p>

        <button type="button" class="btn-primary mt-4" :disabled="saving" @click="issue">
          <AppSpinner v-if="saving" size="xs" />
          {{ saving ? 'Выпускаем…' : 'Выпустить токен' }}
        </button>
      </section>

      <section class="card divide-y divide-line">
        <div class="flex items-center justify-between px-4 py-3">
          <h2 class="text-sm font-semibold text-ink">Ключи установки</h2>
          <span class="text-[11px] text-ink-mute">{{ tokens.length }} · активных: {{ activeCount }}</span>
        </div>

        <div v-if="listLoading && tokens.length === 0" class="flex items-center justify-center gap-2 px-4 py-10 text-xs text-ink-soft">
          <AppSpinner size="sm" />
          Загружаем токены…
        </div>

        <div v-else-if="tokens.length === 0" class="px-4 py-10 text-center text-xs text-ink-mute">
          Токенов пока нет. Сессия в браузере живёт 12 часов, а скрипту нужен ключ, который переживёт ночь.
        </div>

        <article v-for="token in tokens" :key="token.id" class="px-4 py-3">
          <div class="flex flex-wrap items-center gap-2">
            <span class="truncate text-sm text-ink">{{ token.name }}</span>

            <span
              class="rounded-full px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider ring-1 ring-inset"
              :class="scopeTone[token.scope] || scopeTone.read"
            >
              {{ scopeLabels[token.scope] || token.scope }}
            </span>

            <span
              class="rounded-full px-2 py-0.5 text-[10px] font-medium ring-1 ring-inset"
              :class="stateOf(token).tone"
            >
              {{ stateOf(token).label }}
            </span>

            <code class="font-mono text-[11px] text-ink-mute">{{ token.prefix }}…</code>

            <span class="text-[11px] text-ink-mute">
              {{ token.last_used_at ? `использован ${timeAgo(token.last_used_at)}` : 'ещё не использовался' }}
            </span>

            <span class="text-[11px] text-ink-mute">
              {{ token.expires_at ? `до ${formatTime(token.expires_at)}` : 'без срока' }}
            </span>

            <button
              v-if="!token.revoked"
              type="button"
              class="ml-auto rounded-lg px-2 py-1 text-xs text-rose-600/80 transition hover:bg-rose-500/10 hover:text-rose-600 disabled:opacity-50 dark:text-rose-300/80"
              :disabled="revoking === token.id"
              @click="revoke(token)"
            >
              {{ revoking === token.id ? 'Отзываем…' : 'Отозвать' }}
            </button>
          </div>

          <p class="mt-1 text-[11px] text-ink-mute">
            выпущен {{ timeAgo(token.created_at) }} · {{ token.user_email }}
          </p>
        </article>
      </section>
    </div>
  </AppShell>
</template>
