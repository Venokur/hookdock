<script setup>
import { onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { subscribe } from './composables/realtime'
import { useAuthStore } from './stores/auth'
import { useRoomsStore } from './stores/rooms'

const auth = useAuthStore()
const rooms = useRoomsStore()
const route = useRoute()
const router = useRouter()

let offRoomChanged = null

function handleExpired() {
  auth.logout()
  router.push({ name: 'login' })
}

/**
 * Room names, counters and slugs belong to the session that loaded them; the
 * next sign-in must not see the previous account's list.
 */
function handleLogout() {
  rooms.reset()
}

/**
 * A login/logout or a `room.changed` can leave the URL pointing at a slug that no
 * longer exists (renamed or deleted in another tab). This runs once for the whole
 * app — the sidebar renders `RoomList` twice on mobile, so the guard must not live
 * inside it, or a rename would trigger two competing redirects.
 */
async function syncRouteToRooms() {
  if (!auth.token) {
    return
  }

  const slug = typeof route.params.slug === 'string' ? route.params.slug : ''
  const currentId = slug ? rooms.find(slug)?.id : null

  await rooms.load({ silent: true })

  if (!slug || rooms.find(slug)) {
    return
  }

  // Same id under a new slug = renamed in another tab; follow it. Otherwise the
  // room is gone and the user lands on the last visited (or first) remaining one.
  const renamed = currentId ? rooms.rooms.find((room) => room.id === currentId) : null
  const next = renamed?.slug ?? rooms.fallbackSlug()

  await router.replace(
    next ? { name: route.name, params: { ...route.params, slug: next } } : { name: 'home' },
  )
}

onMounted(() => {
  auth.fetchMe()
  window.addEventListener('auth:expired', handleExpired)
  window.addEventListener('auth:logout', handleLogout)

  offRoomChanged = subscribe('room.changed', syncRouteToRooms)
})

onUnmounted(() => {
  window.removeEventListener('auth:expired', handleExpired)
  window.removeEventListener('auth:logout', handleLogout)
  offRoomChanged?.()
})
</script>

<template>
  <router-view />
</template>
