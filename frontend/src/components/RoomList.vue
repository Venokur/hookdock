<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { subscribe } from '../composables/realtime'
import { useRoomsStore, rememberRoom } from '../stores/rooms'
import AppInput from './ui/AppInput.vue'
import RoomFormModal from './RoomFormModal.vue'

const roomsStore = useRoomsStore()
const route = useRoute()
const router = useRouter()

const query = ref('')
const modalOpen = ref(false)
const editing = ref(null)
const deleting = ref(false)

let offRealtime = null
let reloadTimer = null

const activeSlug = computed(() => (typeof route.params.slug === 'string' ? route.params.slug : ''))

const filtered = computed(() => {
  const needle = query.value.trim().toLowerCase()
  if (needle === '') {
    return roomsStore.rooms
  }

  return roomsStore.rooms.filter((room) => {
    const name = room.name.toLowerCase()
    const slug = room.slug.toLowerCase()

    return name.includes(needle) || slug.includes(needle)
  })
})

/**
 * Room counters live on the list endpoint, so any request/workflow event
 * invalidates them. Debounced because one webhook publishes `request.created`
 * and then `request.updated`.
 */
function refreshSoon() {
  clearTimeout(reloadTimer)
  reloadTimer = setTimeout(() => {
    roomsStore.load({ silent: true })
  }, 500)
}

function openCreate() {
  editing.value = null
  modalOpen.value = true
}

function openEdit(room) {
  editing.value = room
  modalOpen.value = true
}

function closeModal() {
  modalOpen.value = false
  editing.value = null
}

async function onSaved(room) {
  const edited = editing.value
  closeModal()

  // A renamed slug changes the URL of the room being viewed. `edited` still holds
  // the pre-save object, because the store reload already dropped the old slug.
  if (edited && room.slug && edited.slug !== room.slug && edited.slug === activeSlug.value) {
    await router.replace({ name: route.name, params: { ...route.params, slug: room.slug } })
  }
}

async function removeRoom(room) {
  if (
    !window.confirm(
      `Удалить комнату «${room.name}»? Вместе с ней удалятся ${room.requests_total} запросов и ${room.workflows_total} сценариев.`,
    )
  ) {
    return
  }

  deleting.value = true

  try {
    await roomsStore.remove(room.id)

    if (room.slug === activeSlug.value) {
      const next = roomsStore.fallbackSlug()
      if (next) {
        await router.push({ name: route.name, params: { ...route.params, slug: next } })
      } else {
        await router.push({ name: 'home' })
      }
    }
  } catch (e) {
    window.alert(e.message)
  } finally {
    deleting.value = false
  }
}

watch(
  () => route.params.slug,
  (slug) => {
    if (typeof slug === 'string' && slug !== '') {
      rememberRoom(slug)
    }
  },
  { immediate: true },
)

onMounted(() => {
  // Counters only; a renamed or deleted room is handled once in `App.vue`, which
  // also fixes the URL (this component renders twice on mobile).
  const offCreated = subscribe('request.created', refreshSoon)
  const offDeleted = subscribe('request.deleted', refreshSoon)
  const offWorkflow = subscribe('workflow.changed', refreshSoon)
  const offResync = subscribe('resync', refreshSoon)

  offRealtime = () => {
    offCreated()
    offDeleted()
    offWorkflow()
    offResync()
  }
})

onUnmounted(() => {
  offRealtime?.()
  clearTimeout(reloadTimer)
})
</script>

<template>
  <section class="mt-6">
    <div class="flex items-center justify-between gap-2">
      <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-ink-mute">Комнаты</p>
      <button
        type="button"
        class="rounded-lg px-1.5 py-0.5 text-xs font-medium text-indigo-600 transition hover:bg-indigo-500/10 dark:text-indigo-300"
        @click="openCreate"
      >
        + Комната
      </button>
    </div>

    <AppInput
      v-model="query"
      type="search"
      size="sm"
      placeholder="Поиск по названию"
      aria-label="Поиск по комнатам"
      class="mt-2"
    />

    <ul class="mt-2 max-h-72 space-y-0.5 overflow-y-auto pr-0.5">
      <li v-for="room in filtered" :key="room.id">
        <div
          class="group flex items-center gap-1 rounded-xl px-2 py-1.5 transition"
          :class="room.slug === activeSlug ? 'bg-indigo-50 dark:bg-white/10' : 'hover:bg-surface-2'"
        >
          <RouterLink
            :to="{ name: 'room-requests', params: { slug: room.slug } }"
            class="min-w-0 flex-1"
          >
            <span
              class="block truncate text-[13px]"
              :class="room.slug === activeSlug ? 'font-semibold text-indigo-700 dark:text-white' : 'text-ink-soft'"
            >
              {{ room.name }}
            </span>
            <span class="mt-0.5 block truncate font-mono text-[10px] text-ink-mute">
              {{ room.slug }} · {{ room.requests_total }}
            </span>
          </RouterLink>

          <button
            type="button"
            class="hidden shrink-0 rounded-md p-1 text-ink-mute transition hover:bg-surface-2 hover:text-ink group-hover:block"
            :aria-label="`Изменить комнату ${room.name}`"
            @click.stop="openEdit(room)"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z" />
            </svg>
          </button>

          <button
            type="button"
            class="hidden shrink-0 rounded-md p-1 text-ink-mute transition hover:bg-rose-500/10 hover:text-rose-600 group-hover:block dark:hover:text-rose-300"
            :aria-label="`Удалить комнату ${room.name}`"
            :disabled="deleting"
            @click.stop="removeRoom(room)"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12M9 7V5h6v2m-8 0l1 13h8l1-13" />
            </svg>
          </button>
        </div>
      </li>

      <li v-if="filtered.length === 0" class="px-2 py-3 text-[11px] text-ink-mute">
        {{ roomsStore.rooms.length === 0 ? 'Комнат пока нет.' : 'Ничего не найдено.' }}
      </li>
    </ul>

    <RoomFormModal
      v-if="modalOpen"
      :room="editing"
      @close="closeModal"
      @saved="onSaved"
    />
  </section>
</template>
