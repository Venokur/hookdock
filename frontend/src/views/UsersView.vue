<script setup>
import { computed, onMounted, ref } from 'vue'
import AppShell from '../components/AppShell.vue'
import AppInput from '../components/ui/AppInput.vue'
import AppPassword from '../components/ui/AppPassword.vue'
import AppSelect from '../components/ui/AppSelect.vue'
import AppSpinner from '../components/ui/AppSpinner.vue'
import { api } from '../api/client'
import { confirm, notify } from '../composables/dialog'
import { useRefresh } from '../composables/useRefresh'
import { useAuthStore } from '../stores/auth'
import { formatTime, timeAgo } from '../utils/format'

const auth = useAuthStore()

const users = ref([])
const loading = ref(false)
const error = ref('')

const roleOptions = [
  { value: 'admin', label: 'Администратор — всё, включая пользователей' },
  { value: 'editor', label: 'Редактор — комнаты, сценарии, запуски' },
  { value: 'viewer', label: 'Наблюдатель — только чтение' },
]

const roleLabels = {
  admin: 'администратор',
  editor: 'редактор',
  viewer: 'наблюдатель',
}

const roleTone = {
  admin: 'bg-indigo-500/10 text-indigo-700 ring-indigo-500/25 dark:text-indigo-300',
  editor: 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/25 dark:text-emerald-300',
  viewer: 'bg-ink/5 text-ink-soft ring-ink/10',
}

const form = ref({ email: '', password: '', role: 'editor' })
const saving = ref(false)
const formError = ref('')

const password = ref({ current: '', next: '', repeat: '' })
const passwordSaving = ref(false)
const passwordError = ref('')

const editing = ref(null)
const editForm = ref({ email: '', role: 'editor' })
const editSaving = ref(false)

const adminCount = computed(() => users.value.filter((user) => user.role === 'admin').length)

async function load() {
  loading.value = true

  try {
    const data = await api.users()
    users.value = data.data
    error.value = ''
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

const { busy: refreshing, updatedAt, run: refreshNow } = useRefresh(() => load())

// One loader for the list: the first load and the refresh button overlap, so the
// inline spinner is derived from both instead of adding a second one.
const listLoading = computed(() => loading.value || refreshing.value)

async function createUser() {
  formError.value = ''

  if (!form.value.email.trim()) {
    formError.value = 'Укажите email.'
    return
  }
  if (form.value.password.length < 8) {
    formError.value = 'Пароль должен быть не короче 8 символов.'
    return
  }

  saving.value = true

  try {
    await api.createUser({
      email: form.value.email.trim(),
      password: form.value.password,
      role: form.value.role,
    })
    form.value = { email: '', password: '', role: 'editor' }
    notify('Пользователь создан', { tone: 'success' })
    await load()
  } catch (e) {
    formError.value = e.message
  } finally {
    saving.value = false
  }
}

function startEdit(user) {
  editing.value = user.id
  editForm.value = { email: user.email, role: user.role }
}

function cancelEdit() {
  editing.value = null
}

async function saveEdit(user) {
  editSaving.value = true

  try {
    await api.updateUser(user.id, {
      email: editForm.value.email.trim(),
      role: editForm.value.role,
    })
    notify('Пользователь сохранён', { tone: 'success' })
    editing.value = null
    await load()
    // The operator may have changed their own role: refresh the flags so the nav
    // stops offering a page the API now refuses.
    await auth.refresh()
  } catch (e) {
    notify(e.message, { tone: 'error' })
  } finally {
    editSaving.value = false
  }
}

async function removeUser(user) {
  const confirmed = await confirm({
    title: 'Удалить пользователя?',
    message: `Учётная запись ${user.email} потеряет доступ. Записи аудита о её действиях останутся.`,
    confirmLabel: 'Удалить пользователя',
  })

  if (!confirmed) {
    return
  }

  try {
    await api.deleteUser(user.id)
    notify('Пользователь удалён', { tone: 'success' })
    await load()
  } catch (e) {
    notify(e.message, { tone: 'error' })
  }
}

/**
 * Changing your own password needs no admin role, only the current password: a
 * stolen token must not be a permanent account takeover.
 */
async function changePassword() {
  passwordError.value = ''

  if (password.value.next.length < 8) {
    passwordError.value = 'Новый пароль должен быть не короче 8 символов.'
    return
  }
  if (password.value.next !== password.value.repeat) {
    passwordError.value = 'Пароли не совпадают.'
    return
  }

  passwordSaving.value = true

  try {
    await api.changePassword({
      current_password: password.value.current,
      password: password.value.next,
    })
    password.value = { current: '', next: '', repeat: '' }
    notify('Пароль изменён', { tone: 'success' })
  } catch (e) {
    passwordError.value = e.message
  } finally {
    passwordSaving.value = false
  }
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
          <h1 class="mt-1 text-2xl font-semibold text-ink">Пользователи</h1>
          <p class="mt-1 text-sm text-ink-soft">
            Роль задаёт, что человек может делать; токен только для чтения ограничивает отдельный клиент.
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

      <section v-if="auth.canAdmin" class="card p-5">
        <h2 class="text-sm font-semibold text-ink">Новый пользователь</h2>
        <p class="mt-1 text-[11px] text-ink-mute">
          Пароль задаётся здесь и больше не показывается. Сменить его можно на этой же странице.
        </p>

        <div class="mt-3 grid gap-3 sm:grid-cols-2">
          <label class="block">
            <span class="mb-1.5 block text-xs font-medium text-ink-soft">Email</span>
            <AppInput v-model="form.email" type="email" placeholder="colleague@example.com" />
          </label>

          <label class="block">
            <span class="mb-1.5 block text-xs font-medium text-ink-soft">Пароль</span>
            <AppPassword v-model="form.password" placeholder="не короче 8 символов" aria-label="Пароль нового пользователя" />
          </label>

          <label class="block sm:col-span-2">
            <span class="mb-1.5 block text-xs font-medium text-ink-soft">Роль</span>
            <AppSelect v-model="form.role" :options="roleOptions" />
          </label>
        </div>

        <p v-if="formError" class="mt-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-600 dark:text-rose-300">
          {{ formError }}
        </p>

        <button type="button" class="btn-primary mt-4" :disabled="saving" @click="createUser">
          <AppSpinner v-if="saving" size="xs" />
          {{ saving ? 'Создаём…' : 'Создать' }}
        </button>
      </section>

      <section class="card divide-y divide-line">
        <div class="flex items-center justify-between px-4 py-3">
          <h2 class="text-sm font-semibold text-ink">Учётные записи</h2>
          <span class="text-[11px] text-ink-mute">
            {{ users.length }} · администраторов: {{ adminCount }}
          </span>
        </div>

        <div v-if="listLoading && users.length === 0" class="flex items-center justify-center gap-2 px-4 py-10 text-xs text-ink-soft">
          <AppSpinner size="sm" />
          Загружаем список…
        </div>

        <div
          v-else-if="!auth.canAdmin"
          class="px-4 py-10 text-center text-xs text-ink-mute"
        >
          Управление пользователями доступно только администратору.
        </div>

        <article v-for="user in users" :key="user.id" class="px-4 py-3">
          <div v-if="editing === user.id" class="flex flex-wrap items-end gap-3">
            <label class="block min-w-48 flex-1">
              <span class="mb-1.5 block text-[11px] text-ink-mute">Email</span>
              <AppInput v-model="editForm.email" type="email" size="sm" />
            </label>
            <label class="block w-56">
              <span class="mb-1.5 block text-[11px] text-ink-mute">Роль</span>
              <AppSelect v-model="editForm.role" :options="roleOptions" size="sm" />
            </label>
            <div class="flex items-center gap-2">
              <button type="button" class="btn px-3 py-1.5 text-xs" @click="cancelEdit">Отмена</button>
              <button
                type="button"
                class="btn-primary px-3 py-1.5 text-xs"
                :disabled="editSaving"
                @click="saveEdit(user)"
              >
                {{ editSaving ? 'Сохраняем…' : 'Сохранить' }}
              </button>
            </div>
          </div>

          <div v-else class="flex flex-wrap items-center gap-2">
            <span class="truncate text-sm text-ink">{{ user.email }}</span>
            <span
              class="rounded-full px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider ring-1 ring-inset"
              :class="roleTone[user.role] || roleTone.viewer"
            >
              {{ roleLabels[user.role] || user.role }}
            </span>
            <span v-if="user.id === auth.user?.id" class="rounded-full bg-indigo-500/10 px-2 py-0.5 text-[10px] text-indigo-700 dark:text-indigo-300">
              это вы
            </span>

            <span class="text-[11px] text-ink-mute">
              вход: {{ user.last_login_at ? timeAgo(user.last_login_at) : 'никогда' }}
            </span>

            <div v-if="auth.canAdmin" class="ml-auto flex items-center gap-1">
              <button
                type="button"
                class="rounded-lg px-2 py-1 text-xs text-ink-soft transition hover:bg-surface-2 hover:text-ink"
                @click="startEdit(user)"
              >
                Изменить
              </button>
              <button
                v-if="user.id !== auth.user?.id"
                type="button"
                class="rounded-lg px-2 py-1 text-xs text-rose-600/80 transition hover:bg-rose-500/10 hover:text-rose-600 dark:text-rose-300/80"
                @click="removeUser(user)"
              >
                Удалить
              </button>
            </div>
          </div>
        </article>
      </section>

      <section class="card p-5">
        <h2 class="text-sm font-semibold text-ink">Сменить свой пароль</h2>
        <p class="mt-1 text-[11px] text-ink-mute">
          Текущий пароль нужен, чтобы один украденный токен не стал постоянным доступом к учётной записи.
        </p>

        <div class="mt-3 grid gap-3 sm:grid-cols-3">
          <label class="block">
            <span class="mb-1.5 block text-xs font-medium text-ink-soft">Текущий пароль</span>
            <AppPassword v-model="password.current" autocomplete="current-password" aria-label="Текущий пароль" />
          </label>
          <label class="block">
            <span class="mb-1.5 block text-xs font-medium text-ink-soft">Новый пароль</span>
            <AppPassword v-model="password.next" placeholder="не короче 8 символов" aria-label="Новый пароль" />
          </label>
          <label class="block">
            <span class="mb-1.5 block text-xs font-medium text-ink-soft">Повторите</span>
            <AppPassword v-model="password.repeat" aria-label="Повтор нового пароля" />
          </label>
        </div>

        <p v-if="passwordError" class="mt-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-600 dark:text-rose-300">
          {{ passwordError }}
        </p>

        <button type="button" class="btn mt-4" :disabled="passwordSaving" @click="changePassword">
          <AppSpinner v-if="passwordSaving" size="xs" />
          {{ passwordSaving ? 'Меняем…' : 'Сменить пароль' }}
        </button>
      </section>
    </div>
  </AppShell>
</template>
