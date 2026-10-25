import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import EventsView from '@/views/EventsView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/', name: 'events', component: EventsView, meta: { title: 'Events' } },
    {
      path: '/events/new',
      name: 'event-create',
      component: () => import('@/views/EventFormView.vue'),
      meta: { requiresAuth: true, title: 'New event' },
    },
    {
      path: '/events/:id(\\d+)',
      name: 'event',
      component: () => import('@/views/EventDetailView.vue'),
      props: (route) => ({ id: Number(route.params.id) }),
      meta: { title: 'Event' },
    },
    {
      path: '/events/:id(\\d+)/edit',
      name: 'event-edit',
      component: () => import('@/views/EventFormView.vue'),
      props: (route) => ({ id: Number(route.params.id) }),
      meta: { requiresAuth: true, title: 'Edit event' },
    },
    {
      path: '/me',
      name: 'my-events',
      component: () => import('@/views/MyEventsView.vue'),
      meta: { requiresAuth: true, title: 'My events' },
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/AuthView.vue'),
      props: { mode: 'login' },
      meta: { title: 'Log in' },
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/views/AuthView.vue'),
      props: { mode: 'register' },
      meta: { title: 'Create account' },
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/views/NotFoundView.vue'),
      meta: { title: 'Page not found' },
    },
  ],
  scrollBehavior: (to, from, saved) => saved ?? (to.path !== from.path ? { top: 0 } : undefined),
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  await auth.restoreSession()

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · Gather` : 'Gather'
})

declare module 'vue-router' {
  interface RouteMeta {
    requiresAuth?: boolean
    /** Shown in the browser tab as "<title> · Gather". */
    title?: string
  }
}

export default router
