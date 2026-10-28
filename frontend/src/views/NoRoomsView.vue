<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppShell from '../components/AppShell.vue'
import RoomFormModal from '../components/RoomFormModal.vue'
import { rememberRoom, useRoomsStore } from '../stores/rooms'

const roomsStore = useRoomsStore()
const router = useRouter()

const modalOpen = ref(false)

const canCreate = computed(() => !roomsStore.loading || roomsStore.rooms.length > 0)

function openCreate() {
  modalOpen.value = true
}

async function retry() {
  await roomsStore.load()

  const slug = roomsStore.fallbackSlug()
  if (slug) {
    await router.replace({ name: 'room-requests', params: { slug } })
  }
}

async function onSaved(room) {
  modalOpen.value = false
  rememberRoom(room.slug)
  await router.push({ name: 'room-requests', params: { slug: room.slug } })
}
</script>

<template>
  <AppShell>
    <div class="mx-auto flex max-w-2xl flex-col items-center justify-center py-16 text-center">
      <div class="grid h-14 w-14 place-items-center rounded-2xl bg-gradient-to-br from-indigo-500 to-sky-500 text-lg font-bold text-white shadow-lg shadow-indigo-500/25">
        H
      </div>

      <!-- An unreachable API is not an empty installation: say so instead of
           inviting the user to create a room that already exists. -->
      <template v-if="roomsStore.error">
        <h1 class="mt-6 text-2xl font-semibold text-ink">Не удалось загрузить комнаты</h1>
        <p class="mt-2 max-w-md text-sm text-ink-soft">{{ roomsStore.error }}</p>
        <button type="button" class="btn-primary mt-6" :disabled="roomsStore.loading" @click="retry">
          {{ roomsStore.loading ? 'Пробуем снова…' : 'Повторить' }}
        </button>
      </template>

      <template v-else>
        <h1 class="mt-6 text-2xl font-semibold text-ink">Создайте первую комнату</h1>
        <p class="mt-2 max-w-md text-sm text-ink-soft">
          Комната — это отдельное хранилище входящих запросов и сценариев со своим адресом вебхука.
          Запросы одной комнаты никогда не попадут в другую.
        </p>

        <button type="button" class="btn-primary mt-6" :disabled="!canCreate" @click="openCreate">
          + Новая комната
        </button>

        <p class="mt-4 font-mono text-[11px] text-ink-mute">/hook/&lt;комната&gt;/&lt;эндпоинт&gt;</p>
      </template>
    </div>

    <RoomFormModal v-if="modalOpen" @close="modalOpen = false" @saved="onSaved" />
  </AppShell>
</template>
