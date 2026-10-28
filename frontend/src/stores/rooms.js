import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../api/client'

/**
 * Named rooms isolate incoming requests and workflows.
 *
 * The list is shared by the sidebar and by the room pages, so it lives in one
 * store. Counters come from `GET /api/rooms` and are refreshed on realtime
 * events instead of a timer.
 */

const LAST_ROOM_KEY = 'wr_room'

export function getLastRoomSlug() {
  try {
    return localStorage.getItem(LAST_ROOM_KEY) || ''
  } catch {
    return ''
  }
}

export function rememberRoom(slug) {
  try {
    if (slug) {
      localStorage.setItem(LAST_ROOM_KEY, slug)
    } else {
      localStorage.removeItem(LAST_ROOM_KEY)
    }
  } catch {
    // Private mode / disabled storage: navigation still works for this visit.
  }
}

export const useRoomsStore = defineStore('rooms', () => {
  const rooms = ref([])
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref('')

  const bySlug = computed(() => {
    const map = new Map()

    for (const room of rooms.value) {
      map.set(room.slug, room)
    }

    return map
  })

  function find(slug) {
    return bySlug.value.get(slug) ?? null
  }

  /**
   * The room the user opens by default: the last visited one, else the first in
   * the alphabetical list the API returns.
   */
  function fallbackSlug() {
    const last = getLastRoomSlug()
    if (last && bySlug.value.has(last)) {
      return last
    }

    return rooms.value[0]?.slug ?? ''
  }

  async function load({ silent = false } = {}) {
    if (!silent) {
      loading.value = true
    }

    try {
      const data = await api.rooms()
      rooms.value = data.data
      error.value = ''

      // Only a successful read proves the list is known; `loaded` gates the router
      // guard, so a failed call must retry on the next navigation.
      loaded.value = true

      // A room deleted elsewhere must not stay as the remembered target.
      const last = getLastRoomSlug()
      if (last && !bySlug.value.has(last)) {
        rememberRoom(fallbackSlug())
      }
    } catch (e) {
      error.value = e.message
    } finally {
      loading.value = false
    }
  }

  async function create(payload) {
    const data = await api.createRoom(payload)
    await load({ silent: true })

    return data.data
  }

  async function update(id, payload) {
    const data = await api.updateRoom(id, payload)
    const previous = getLastRoomSlug()
    await load({ silent: true })

    // Renaming the room the user is sitting in must keep it as the remembered
    // one; the slug changed, the id did not.
    if (previous && !bySlug.value.has(previous)) {
      rememberRoom(data.data.slug ?? fallbackSlug())
    }

    return data.data
  }

  async function remove(id) {
    await api.deleteRoom(id)
    await load({ silent: true })

    const last = getLastRoomSlug()
    if (last && !bySlug.value.has(last)) {
      rememberRoom(fallbackSlug())
    }
  }

  function reset() {
    rooms.value = []
    loading.value = false
    loaded.value = false
    error.value = ''
  }

  return {
    rooms,
    loading,
    loaded,
    error,
    bySlug,
    find,
    fallbackSlug,
    load,
    create,
    update,
    remove,
    reset,
  }
})
