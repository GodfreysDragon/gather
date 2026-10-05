<script setup lang="ts">
import { useRouter } from 'vue-router'
import TransportToggle from './TransportToggle.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function logout(): Promise<void> {
  await auth.logout()
  await router.push({ name: 'events' })
}
</script>

<template>
  <header
    class="sticky top-0 z-20 border-b border-slate-200 bg-white/85 backdrop-blur dark:border-slate-800 dark:bg-slate-950/85"
  >
    <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
      <RouterLink :to="{ name: 'events' }" class="flex items-center gap-2 text-lg font-bold">
        <span class="grid size-8 place-items-center rounded-lg bg-brand-600 text-white">G</span>
        Gather
      </RouterLink>

      <nav class="flex items-center gap-4 text-sm font-medium text-slate-600 dark:text-slate-300">
        <RouterLink
          :to="{ name: 'events' }"
          active-class="text-brand-600 dark:text-brand-100"
          exact-active-class=""
        >
          Events
        </RouterLink>
        <RouterLink
          v-if="auth.isAuthenticated"
          :to="{ name: 'my-events' }"
          active-class="text-brand-600 dark:text-brand-100"
        >
          My events
        </RouterLink>
      </nav>

      <div class="ml-auto flex items-center gap-3">
        <TransportToggle />

        <template v-if="auth.isAuthenticated">
          <RouterLink
            :to="{ name: 'event-create' }"
            class="btn-primary hidden py-1.5 sm:inline-flex"
          >
            New event
          </RouterLink>
          <span class="hidden text-sm text-slate-500 md:inline" data-testid="current-user">
            {{ auth.user?.name }}
          </span>
          <button type="button" class="btn-secondary py-1.5" @click="logout">Log out</button>
        </template>
        <template v-else>
          <RouterLink :to="{ name: 'login' }" class="btn-secondary py-1.5">Log in</RouterLink>
        </template>
      </div>
    </div>
  </header>
</template>
