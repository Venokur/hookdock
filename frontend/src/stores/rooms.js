import { computed, reactive, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../api/client'

/**
 * Named rooms isolate incoming requests and workflows.
 *
 * There can be many rooms, so the sidebar never downloads all of them: it holds
 * the latest page (`PAGE_SIZE`) and searches the rest by name or slug. Two
 * separate lists make that safe:
 *
 *   rooms   – the page (or the room the user is in, pinned to it); the lookup
 *             `find()` and every page header read from here;
 *   results – the current search matches, rendered instead of `rooms`.
 *
 * Keeping search results out of `rooms` is what stops a search from making the
 * open room disappear: `find(activeSlug)` must keep answering while the sidebar
 * shows matches. `ensure()` resolves a deep link to a room that is older than
 * the page, so `/rooms/<old-slug>` still opens instead of looking deleted.
 */

const LAST_ROOM_KEY = 'wr_room'

/** Must match `RoomsController::PAGE_SIZE`; the server clamps it anyway. */
const PAGE_SIZE = 20

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
  const results = ref(null)
  const meta = reactive({ total: 0, limit: PAGE_SIZE, returned: 0, has_more: false })
  const searchMeta = reactive({ total: 0, limit: 0, returned: 0, has_more: false })

  const loading = ref(false)
  const searching = ref(false)
  const loaded = ref(false)
  const error = ref('')

  /** Text of the sidebar search; empty means "show the latest page". */
  const searchQuery = ref('')

  /**
   * The room the user is currently inside. It is always kept in `rooms`, even
   * when it is older than the page, so the sidebar can highlight it and every
   * page header can resolve it.
   */
  const pinnedSlug = ref('')

  // Overlapping loads (a realtime refresh during a room switch, a fast second
  // search) must not repaint out of order or clear each other's spinner.
  let seq = 0

  const visible = computed(() => results.value ?? rooms.value)
  const hasMore = computed(() => meta.total > meta.returned)

  function find(slug) {
    return rooms.value.find((room) => room.slug === slug) ?? null
  }

  function findById(id) {
    return rooms.value.find((room) => room.id === id) ?? null
  }

  function pin(slug) {
    pinnedSlug.value = typeof slug === 'string' ? slug : ''
  }

  /**
   * The room the user opens by default: the last visited one, else the newest in
   * the loaded page. A remembered room outside the page is resolved by the router
   * guard through `ensure()` — `fallbackSlug` itself stays synchronous, because a
   * deleted room must never be returned here or the redirect would loop.
   */
  function fallbackSlug() {
    const last = getLastRoomSlug()
    if (last && find(last)) {
      return last
    }

    return rooms.value[0]?.slug ?? ''
  }

  /**
   * Loads the latest page, keeping the open room in the list even when it is
   * older. Counters come from the server, so a realtime refresh is just a reload.
   */
  async function load({ silent = false } = {}) {
    const current = ++seq
    if (!silent) {
      loading.value = true
    }

    try {
      const data = await api.rooms({ limit: PAGE_SIZE })
      if (current !== seq) {
        return
      }

      let list = data.data ?? []
      meta.total = data.meta?.total ?? list.length
      meta.limit = data.meta?.limit ?? PAGE_SIZE
      meta.returned = data.meta?.returned ?? list.length
      meta.has_more = Boolean(data.meta?.has_more)

      const pinned = pinnedSlug.value
      if (pinned !== '' && !list.some((room) => room.slug === pinned)) {
        const extra = await api.rooms({ slug: pinned })
        if (current !== seq) {
          return
        }

        const room = extra.data?.[0] ?? null
        if (room) {
          // Not counted in `meta`: the hint still means "N rooms in total".
          list = [room, ...list]
        } else {
          // Deleted or renamed in another tab. `App.vue` follows the rename by id;
          // dropping the pin here stops the next reload from asking again.
          pinnedSlug.value = ''
        }
      }

      rooms.value = list
      error.value = ''
      loaded.value = true
    } catch (e) {
      if (current === seq) {
        error.value = e.message
      }
    } finally {
      if (current === seq) {
        loading.value = false
      }
    }
  }

  /**
   * Server-side search over name and slug. Results are kept apart from `rooms`,
   * so the open room stays resolvable while the sidebar is filtered.
   */
  async function search(query) {
    const current = ++seq
    searching.value = true

    try {
      const data = await api.rooms({ q: query, limit: 50 })
      if (current !== seq) {
        return
      }

      results.value = data.data ?? []
      searchMeta.total = data.meta?.total ?? results.value.length
      searchMeta.limit = data.meta?.limit ?? results.value.length
      searchMeta.returned = data.meta?.returned ?? results.value.length
      searchMeta.has_more = Boolean(data.meta?.has_more)
      error.value = ''
    } catch (e) {
      if (current === seq) {
        error.value = e.message
      }
    } finally {
      if (current === seq) {
        searching.value = false
      }
    }
  }

  function setQuery(value) {
    searchQuery.value = typeof value === 'string' ? value : ''

    // Clearing the field must show the latest page at once. Waiting for the
    // debounce would leave the previous matches on screen under a hint that
    // already says "latest 20".
    if (searchQuery.value.trim() === '') {
      results.value = null
    }
  }

  /** Runs the search for the current text, or returns to the latest page. */
  async function runSearch() {
    const query = searchQuery.value.trim()

    if (query === '') {
      results.value = null
      return load({ silent: true })
    }

    return search(query)
  }

  /**
   * Resolves a room by slug, asking the server when it is older than the page.
   * Returns `null` for "no such room" and also on a failed request — callers must
   * check `error` before treating it as deleted.
   */
  async function ensure(slug) {
    const existing = find(slug)
    if (existing) {
      pin(slug)
      return existing
    }

    try {
      const data = await api.rooms({ slug })
      const room = data.data?.[0] ?? null

      if (!room) {
        if (pinnedSlug.value === slug) {
          pinnedSlug.value = ''
        }

        return null
      }

      rooms.value = [room, ...rooms.value.filter((item) => item.slug !== room.slug)]
      pinnedSlug.value = slug
      loaded.value = true

      return room
    } catch (e) {
      error.value = e.message
      return null
    }
  }

  /**
   * Server truth by id, for following a rename that happened in another tab. The
   * room is not added to the list: the caller only needs its new slug.
   */
  async function ensureById(id) {
    if (!id) {
      return null
    }

    try {
      const data = await api.room(id)
      return data.data ?? null
    } catch {
      return null
    }
  }

  /** Refreshes whatever the sidebar is showing: the page, or the current search. */
  async function refreshVisible() {
    const query = searchQuery.value.trim()

    return query === '' ? load({ silent: true }) : search(query)
  }

  async function create(payload) {
    const data = await api.createRoom(payload)

    // A new room is the newest one, so it belongs on the first page — leaving the
    // user in a filtered list where their new room is invisible would be a bug.
    searchQuery.value = ''
    results.value = null
    await load({ silent: true })

    return data.data
  }

  async function update(id, payload) {
    const target = findById(id)
    const wasPinned = target !== null && pinnedSlug.value === target.slug
    const data = await api.updateRoom(id, payload)
    const renamed = target !== null && data.data.slug && data.data.slug !== target.slug

    // The user is managing the list they filtered, so an edit must not silently
    // drop the filter; re-running it also removes a room that no longer matches.
    await refreshVisible()

    // A renamed slug changes the URL of the room being viewed. `load()` could not
    // keep it (the old slug no longer resolves), so pin the new one and fetch it
    // with its counters; the sidebar then highlights the right room again.
    if (renamed && wasPinned) {
      pinnedSlug.value = data.data.slug
      await ensure(data.data.slug)
    }

    if (renamed && getLastRoomSlug() === target.slug) {
      rememberRoom(data.data.slug)
    }

    return data.data
  }

  async function remove(id) {
    const target = findById(id)

    await api.deleteRoom(id)
    await refreshVisible()

    // Only clear the remembered slug when the deleted room was the remembered
    // one: a room missing from the page for paging reasons is not "gone".
    if (target && getLastRoomSlug() === target.slug) {
      rememberRoom(fallbackSlug())
    }
  }

  function reset() {
    rooms.value = []
    results.value = null
    meta.total = 0
    meta.limit = PAGE_SIZE
    meta.returned = 0
    meta.has_more = false
    searchMeta.total = 0
    searchMeta.limit = 0
    searchMeta.returned = 0
    searchMeta.has_more = false
    searchQuery.value = ''
    pinnedSlug.value = ''
    loading.value = false
    searching.value = false
    loaded.value = false
    error.value = ''
    seq++
  }

  return {
    rooms,
    results,
    visible,
    meta,
    searchMeta,
    hasMore,
    loading,
    searching,
    loaded,
    error,
    searchQuery,
    pinnedSlug,
    find,
    findById,
    pin,
    fallbackSlug,
    load,
    search,
    setQuery,
    runSearch,
    ensure,
    ensureById,
    create,
    update,
    remove,
    reset,
  }
})
