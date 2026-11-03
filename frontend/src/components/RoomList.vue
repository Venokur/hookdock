<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { subscribe } from '../composables/realtime'
import { confirm, notify } from '../composables/dialog'
import { useAuthStore } from '../stores/auth'
import { useRoomsStore, rememberRoom } from '../stores/rooms'
import AppInput from './ui/AppInput.vue'
import AppSpinner from './ui/AppSpinner.vue'
import RoomFormModal from './RoomFormModal.vue'

const roomsStore = useRoomsStore()

// Hiding the controls is a convenience, not the permission: the API refuses the
// same calls for a viewer or a read token.
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const modalOpen = ref(false)
const editing = ref(null)
const deleting = ref(false)

let offRealtime = null
let reloadTimer = null
let searchTimer = null

const activeSlug = computed(() => (typeof route.params.slug === 'string' ? route.params.slug : ''))

const isSearching = computed(() => roomsStore.searchQuery.trim() !== '')

/**
 * The list is a page from the server, not the whole table: a few hundred rooms
 * would otherwise load and count on every keystroke. Typing is debounced, while
 * the magnifier (or Enter) searches immediately — the user asked for a result,
 * so waiting 300 ms more would only feel broken.
 */
function onQuery(value) {
  roomsStore.setQuery(value)
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => roomsStore.runSearch(), 300)
}

function searchNow() {
  clearTimeout(searchTimer)
  roomsStore.runSearch()
}

/**
 * Room counters live on the list endpoint, so any request/workflow event
 * invalidates them. `runSearch` refreshes whichever list is on screen (the page
 * or the current search). Debounced because one webhook publishes
 * `request.created` and then `request.updated`.
 */
function refreshSoon() {
  clearTimeout(reloadTimer)
  reloadTimer = setTimeout(() => {
    roomsStore.runSearch()
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

/**
 * The dialog decides when to close itself: a created room with a signature stays
 * open to show the one-time secret, so closing it here would hide that value.
 * Only the redirect for a renamed slug is the parent's business.
 */
async function onSaved(room) {
  const edited = editing.value

  // A renamed slug changes the URL of the room being viewed. `edited` still holds
  // the pre-save object, because the store reload already dropped the old slug.
  if (edited && room.slug && edited.slug !== room.slug && edited.slug === activeSlug.value) {
    await router.replace({ name: route.name, params: { ...route.params, slug: room.slug } })
  }
}

async function removeRoom(room) {
  const confirmed = await confirm({
    title: 'Удалить комнату?',
    message: `«${room.name}» будет удалена вместе с ${room.requests_total} запросами и ${room.workflows_total} сценариями. Отменить это нельзя.`,
    confirmLabel: 'Удалить комнату',
  })

  if (!confirmed) {
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
    notify(e.message)
  } finally {
    deleting.value = false
  }
}

watch(
  () => route.params.slug,
  (slug) => {
    if (typeof slug === 'string' && slug !== '') {
      rememberRoom(slug)
      // The open room is kept in the sidebar list even when it is older than the
      // loaded page, so its header, highlight and counters stay resolvable.
      roomsStore.pin(slug)
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
  clearTimeout(searchTimer)
})
</script>

<template>
  <section class="mt-6">
    <div class="flex items-center justify-between gap-2">
      <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-ink-mute">Комнаты</p>
      <button
        v-if="auth.canWrite"
        type="button"
        class="rounded-lg px-1.5 py-0.5 text-xs font-medium text-indigo-600 transition hover:bg-indigo-500/10 dark:text-indigo-300"
        @click="openCreate"
      >
        + Комната
      </button>
    </div>

    <div class="relative mt-2">
      <AppInput
        :model-value="roomsStore.searchQuery"
        size="sm"
        placeholder="Поиск по названию или slug"
        aria-label="Поиск по комнатам"
        class="pr-8"
        @update:model-value="onQuery"
        @keydown.enter.prevent="searchNow"
      />
      <button
        type="button"
        class="absolute right-1 top-1/2 -translate-y-1/2 rounded-md p-1 text-ink-mute transition hover:bg-surface-2 hover:text-ink"
        aria-label="Найти комнаты"
        :disabled="roomsStore.searching"
        @click="searchNow"
      >
        <AppSpinner v-if="roomsStore.searching" size="xs" />
        <svg v-else class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="11" cy="11" r="7" />
          <path stroke-linecap="round" d="M20 20l-3.5-3.5" />
        </svg>
      </button>
    </div>

    <ul class="mt-2 max-h-72 space-y-0.5 overflow-y-auto pr-0.5">
      <li v-for="room in roomsStore.visible" :key="room.id">
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
            v-if="auth.canWrite"
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
            v-if="auth.canWrite"
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

      <!-- The spinner lives in the magnifier, so the list itself stays quiet while
           a search is running: one surface, one indicator. -->
      <li
        v-if="roomsStore.visible.length === 0 && !roomsStore.searching"
        class="px-2 py-3 text-[11px] text-ink-mute"
      >
        <template v-if="isSearching">Ничего не найдено.</template>
        <template v-else-if="roomsStore.loading">Загрузка…</template>
        <template v-else>Комнат пока нет.</template>
      </li>
    </ul>

    <p v-if="isSearching && roomsStore.searchMeta.has_more" class="mt-1.5 px-2 text-[10px] text-ink-mute">
      Найдено {{ roomsStore.searchMeta.total }}, показаны первые {{ roomsStore.searchMeta.returned }} — уточните запрос.
    </p>
    <p v-else-if="!isSearching && roomsStore.hasMore" class="mt-1.5 px-2 text-[10px] text-ink-mute">
      Последние {{ roomsStore.meta.limit }} из {{ roomsStore.meta.total }} — остальные через поиск.
    </p>

    <RoomFormModal
      v-if="modalOpen"
      :room="editing"
      @close="closeModal"
      @saved="onSaved"
    />
  </section>
</template>
