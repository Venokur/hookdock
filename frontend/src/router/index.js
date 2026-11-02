import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { getLastRoomSlug, rememberRoom, useRoomsStore } from '../stores/rooms'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('../views/LoginView.vue'),
      meta: { guest: true },
    },
    {
      path: '/',
      name: 'home',
      component: () => import('../views/NoRoomsView.vue'),
    },
    {
      path: '/rooms/:slug',
      name: 'room-requests',
      component: () => import('../views/RequestsView.vue'),
    },
    {
      path: '/rooms/:slug/workflows',
      name: 'room-workflows',
      component: () => import('../views/WorkflowsView.vue'),
    },
    {
      path: '/rooms/:slug/runs',
      name: 'room-runs',
      component: () => import('../views/RunsView.vue'),
    },
    {
      path: '/rooms/:slug/delivery',
      name: 'room-delivery',
      component: () => import('../views/DeliveryView.vue'),
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

/**
 * Rooms own every page, so `/` is a redirect, not a view: go to the last room
 * the user opened, else the first one, else the empty state that offers to
 * create the first room.
 */
router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (!auth.token && !to.meta.guest) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (auth.token && to.meta.guest) {
    return { name: 'home' }
  }

  if (auth.token && (to.name === 'home' || to.params.slug)) {
    const rooms = useRoomsStore()

    if (!rooms.loaded) {
      await rooms.load()
    }

    // A failed request must not be mistaken for "there are no rooms": leave the
    // user on the requested page, which renders the error and its retry.
    if (rooms.error) {
      return true
    }

    const requested = typeof to.params.slug === 'string' ? to.params.slug : ''

    if (to.name === 'home') {
      // The last visited room may be older than the sidebar page, so it is
      // resolved through the API before the redirect. A remembered slug that no
      // longer exists is dropped, otherwise `/` would redirect to it forever.
      const last = getLastRoomSlug()
      if (last && (await rooms.ensure(last))) {
        return { name: 'room-requests', params: { slug: last } }
      }
      if (last && !rooms.error) {
        rememberRoom('')
      }

      const slug = rooms.fallbackSlug()

      return slug ? { name: 'room-requests', params: { slug } } : true
    }

    // A deep link to a room older than the loaded page is not a deleted room:
    // ask the API before redirecting. `ensure()` also pins it, so the sidebar
    // keeps showing the room the user is in.
    if (requested && !rooms.find(requested)) {
      const found = await rooms.ensure(requested)

      if (!found && !rooms.error) {
        if (getLastRoomSlug() === requested) {
          rememberRoom('')
        }

        const slug = rooms.fallbackSlug()

        return slug ? { name: to.name, params: { slug } } : { name: 'home' }
      }
    }
  }

  return true
})

export default router
