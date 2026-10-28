<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import RoomList from './RoomList.vue'
import ThemeToggle from './ThemeToggle.vue'
import { useAuthStore } from '../stores/auth'
import { useRoomsStore } from '../stores/rooms'

const auth = useAuthStore()
const rooms = useRoomsStore()
const route = useRoute()
const router = useRouter()

const roomsOpen = ref(false)

const slug = computed(() => (typeof route.params.slug === 'string' ? route.params.slug : ''))
const currentRoom = computed(() => (slug.value ? rooms.find(slug.value) : null))

const nav = computed(() => {
  if (!slug.value) {
    return []
  }

  return [
    { name: 'room-requests', label: 'Входящие', hint: 'Пойманные запросы' },
    { name: 'room-workflows', label: 'Сценарии', hint: 'Правила маршрутизации' },
  ]
})

function logout() {
  auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="flex min-h-full bg-canvas">
    <aside class="sticky top-0 hidden h-screen w-72 shrink-0 flex-col overflow-y-auto border-r border-line bg-surface p-5 lg:flex">
      <div class="flex items-center justify-between gap-2">
        <div class="flex items-center gap-2.5">
          <div class="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br from-indigo-500 to-sky-500 text-sm font-bold text-white shadow-lg shadow-indigo-500/25">
            H
          </div>
          <div>
            <p class="text-sm font-semibold text-ink">Hookdock</p>
            <p class="text-[11px] uppercase tracking-wider text-ink-mute">вебхук-роутер</p>
          </div>
        </div>
        <ThemeToggle />
      </div>

      <RoomList />

      <nav v-if="nav.length" class="mt-5 space-y-1 border-t border-line pt-4">
        <RouterLink
          v-for="item in nav"
          :key="item.name"
          :to="{ name: item.name, params: { slug } }"
          class="block rounded-xl px-3 py-2.5 text-sm text-ink-soft transition hover:bg-surface-2 hover:text-ink"
          active-class="!bg-indigo-50 !text-indigo-700 dark:!bg-white/10 dark:!text-white"
        >
          <span class="font-medium">{{ item.label }}</span>
          <span class="mt-0.5 block text-[11px] text-ink-mute">{{ item.hint }}</span>
        </RouterLink>
      </nav>

      <div class="mt-auto rounded-xl border border-line bg-surface-2 p-3">
        <p class="text-[11px] uppercase tracking-wider text-ink-mute">Вы вошли как</p>
        <p class="mt-1 truncate text-xs text-ink">{{ auth.user?.email || 'загрузка…' }}</p>
        <button
          type="button"
          class="mt-2 text-xs font-medium text-ink-mute transition hover:text-ink"
          @click="logout"
        >
          Выйти
        </button>
      </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
      <header class="border-b border-line bg-surface/90 px-4 py-3 backdrop-blur lg:hidden">
        <div class="flex items-center justify-between gap-3">
          <div class="flex min-w-0 items-center gap-2">
            <div class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-gradient-to-br from-indigo-500 to-sky-500 text-xs font-bold text-white">H</div>
            <span class="truncate text-sm font-semibold text-ink">{{ currentRoom?.name || 'Hookdock' }}</span>
          </div>
          <div class="flex shrink-0 items-center gap-1">
            <ThemeToggle />
            <button type="button" class="rounded-lg px-2.5 py-1.5 text-xs text-ink-mute" @click="logout">Выйти</button>
          </div>
        </div>

        <div class="mt-2 flex items-center gap-1 overflow-x-auto">
          <RouterLink
            v-for="item in nav"
            :key="item.name"
            :to="{ name: item.name, params: { slug } }"
            class="whitespace-nowrap rounded-lg px-3 py-1.5 text-xs text-ink-soft"
            active-class="bg-indigo-50 text-indigo-700 dark:bg-white/10 dark:text-white"
          >
            {{ item.label }}
          </RouterLink>
          <button
            type="button"
            class="ml-auto shrink-0 whitespace-nowrap rounded-lg px-2.5 py-1.5 text-xs text-ink-mute"
            :aria-expanded="roomsOpen"
            @click="roomsOpen = !roomsOpen"
          >
            Комнаты {{ roomsOpen ? '▲' : '▼' }}
          </button>
        </div>

        <div v-if="roomsOpen" class="mt-2 rounded-xl border border-line bg-surface px-3 pb-3">
          <RoomList />
        </div>
      </header>

      <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <slot />
      </main>
    </div>
  </div>
</template>
