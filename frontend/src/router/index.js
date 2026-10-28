import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useRoomsStore } from '../stores/rooms'

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
      const slug = rooms.fallbackSlug()

      return slug ? { name: 'room-requests', params: { slug } } : true
    }

    // A deep link to a deleted (or mistyped) room must not render an empty page.
    if (requested && !rooms.find(requested)) {
      const slug = rooms.fallbackSlug()

      return slug ? { name: to.name, params: { slug } } : { name: 'home' }
    }
  }

  return true
})

export default router
