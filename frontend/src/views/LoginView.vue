<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ThemeToggle from '../components/ThemeToggle.vue'
import AppInput from '../components/ui/AppInput.vue'
import AppSpinner from '../components/ui/AppSpinner.vue'
import { api } from '../api/client'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const form = reactive({ email: '', password: '' })
const loading = ref(false)
const demoLoading = ref(false)
const error = ref('')

// The demo account is opt-in on the server (`DEMO_LOGIN=1`) and described by
// /api/auth/config, so no credentials are baked into the JS bundle.
const demo = ref(null)
const warnings = ref([])

const busy = computed(() => loading.value || demoLoading.value)

onMounted(async () => {
  try {
    const data = await api.config()
    demo.value = data?.data?.demo ?? null
    warnings.value = data?.data?.warnings ?? []
  } catch {
    // The login form still works without this; it only hides the demo shortcut.
  }
})

async function signIn(email, password) {
  error.value = ''

  try {
    await auth.login(email, password)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : null
    router.push(redirect || { name: 'home' })
  } catch (e) {
    error.value = e.message
  }
}

async function submit() {
  loading.value = true
  try {
    await signIn(form.email.trim(), form.password)
  } finally {
    loading.value = false
  }
}

async function demoSignIn() {
  if (!demo.value) return

  demoLoading.value = true
  try {
    await signIn(demo.value.email, demo.value.password)
  } finally {
    demoLoading.value = false
  }
}
</script>

<template>
  <div class="relative grid min-h-full bg-canvas lg:grid-cols-2">
    <div class="absolute right-4 top-4 z-10">
      <ThemeToggle />
    </div>

    <section class="grid-glow relative hidden flex-col justify-between border-r border-line p-12 lg:flex">
      <div class="flex items-center gap-3">
        <div class="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-indigo-500 to-sky-500 font-bold text-white shadow-lg shadow-indigo-500/30">
          H
        </div>
        <span class="text-lg font-semibold text-ink">Hookdock</span>
      </div>

      <div class="max-w-md">
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">
          Приём · Инспекция · Маршрутизация
        </p>
        <h1 class="mt-4 text-4xl font-semibold leading-tight text-ink">
          Каждый вебхук — до того, как он попадёт в ваш стек.
        </h1>
        <p class="mt-4 text-sm leading-relaxed text-ink-soft">
          Сохраняем сырые payload'ы, показываем заголовки и JSON, а затем перенаправляем трафик
          в другие сервисы по визуальным условиям.
        </p>
      </div>

      <div class="flex flex-wrap gap-x-6 gap-y-2 text-xs text-ink-mute">
        <span>POST / GET / PUT / PATCH</span>
        <span>Любой путь</span>
        <span>Хранение в JSONB</span>
      </div>
    </section>

    <section class="flex items-center justify-center px-6 py-16">
      <div class="w-full max-w-sm">
        <div class="mb-8 lg:hidden">
          <div class="mb-3 grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-indigo-500 to-sky-500 font-bold text-white">H</div>
          <p class="text-lg font-semibold text-ink">Hookdock</p>
        </div>

        <div class="card p-7">
          <h2 class="text-2xl font-semibold text-ink">Вход</h2>
          <p class="mt-1 text-sm text-ink-soft">Используйте аккаунт администратора, созданный при первом запуске.</p>

          <form class="mt-7 space-y-4" @submit.prevent="submit">
            <label class="block">
              <span class="mb-1.5 block text-xs font-medium text-ink-soft">Email</span>
              <AppInput
                v-model="form.email"
                type="email"
                autocomplete="username"
                required
              />
            </label>

            <label class="block">
              <span class="mb-1.5 block text-xs font-medium text-ink-soft">Пароль</span>
              <AppInput
                v-model="form.password"
                type="password"
                autocomplete="current-password"
                required
              />
            </label>

            <p v-if="error" class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-600 dark:text-rose-300">
              {{ error }}
            </p>

            <p
              v-for="warning in warnings"
              :key="warning"
              class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-300"
            >
              {{ warning }}
            </p>

            <button type="submit" :disabled="busy" class="btn-primary w-full py-2.5 text-sm">
              <AppSpinner v-if="loading" size="xs" />
              {{ loading ? 'Входим…' : 'Войти' }}
            </button>
          </form>

          <template v-if="demo">
            <div class="mt-6 flex items-center gap-3">
              <span class="h-px flex-1 bg-line" />
              <span class="text-[11px] uppercase tracking-wide text-ink-mute">или</span>
              <span class="h-px flex-1 bg-line" />
            </div>

            <button type="button" :disabled="busy" class="btn mt-4 w-full py-2.5 text-sm" @click="demoSignIn">
              <AppSpinner v-if="demoLoading" size="xs" />
              {{ demoLoading ? 'Открываем демо…' : 'Войти в демо-кабинет' }}
            </button>

            <p class="mt-3 text-center text-xs text-ink-mute">
              Демо-доступ: <span class="font-medium text-ink">{{ demo.email }}</span> /
              <span class="font-medium text-ink">{{ demo.password }}</span>
            </p>
          </template>
        </div>
      </div>
    </section>
  </div>
</template>
