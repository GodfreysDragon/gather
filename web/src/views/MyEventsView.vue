<script setup lang="ts">
import { ApiError } from '@/api/errors'
import ErrorAlert from '@/components/ErrorAlert.vue'
import EventCard from '@/components/EventCard.vue'
import { useResource } from '@/composables/useResource'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const { data, error, reload } = useResource(
  async (repository) => {
    // The route requires a session, but it can expire while this page is open.
    const viewer = auth.user
    if (!viewer) throw new ApiError('unauthenticated', 'Log in to see your events.', 401)

    return repository.myEvents(viewer.id)
  },
  [() => auth.user?.id],
  // Switching transport refetches the same lists; keep them on screen meanwhile.
  { keepPreviousData: true },
)
</script>

<template>
  <div class="space-y-10">
    <div class="flex flex-wrap items-center justify-between gap-4">
      <h1 class="text-2xl font-bold tracking-tight">My events</h1>
      <RouterLink :to="{ name: 'event-create' }" class="btn-primary">New event</RouterLink>
    </div>

    <ErrorAlert v-if="error" :error="error" retryable @retry="reload" />

    <section aria-labelledby="going-heading">
      <h2 id="going-heading" class="mb-4 text-lg font-semibold">Going</h2>
      <div v-if="data" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="going">
        <EventCard v-for="event in data.going" :key="event.id" :event="event" :heading-level="3" />
      </div>
      <p v-if="data && data.going.length === 0" class="text-slate-500 dark:text-slate-400">
        You haven't registered for any upcoming events.
        <RouterLink :to="{ name: 'events' }" class="text-brand-600 underline dark:text-brand-300"
          >Browse events</RouterLink
        >
      </p>
    </section>

    <section aria-labelledby="organizing-heading">
      <h2 id="organizing-heading" class="mb-4 text-lg font-semibold">Organizing</h2>
      <div v-if="data" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <EventCard
          v-for="event in data.organizing"
          :key="event.id"
          :event="event"
          :heading-level="3"
        />
      </div>
      <p v-if="data && data.organizing.length === 0" class="text-slate-500 dark:text-slate-400">
        You aren't organizing anything yet.
      </p>
    </section>
  </div>
</template>
