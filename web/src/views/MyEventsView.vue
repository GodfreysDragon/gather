<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'
import ErrorAlert from '@/components/ErrorAlert.vue'
import EventCard from '@/components/EventCard.vue'
import { useResource } from '@/composables/useResource'

const auth = useAuthStore()

const { data, error, reload } = useResource(async (repository) => {
  const [going, organizing] = await Promise.all([
    repository.myRegistrations(),
    repository.myOrganizedEvents(auth.user!.id),
  ])
  return { going, organizing }
})
</script>

<template>
  <div class="space-y-10">
    <ErrorAlert v-if="error" :error="error" retryable @retry="reload" />

    <section>
      <h1 class="mb-4 text-2xl font-bold tracking-tight">Going</h1>
      <div v-if="data" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="going">
        <EventCard v-for="event in data.going" :key="event.id" :event="event" />
      </div>
      <p v-if="data && data.going.length === 0" class="text-slate-500">
        You haven't registered for any upcoming events.
        <RouterLink :to="{ name: 'events' }" class="text-brand-600 underline"
          >Browse events</RouterLink
        >
      </p>
    </section>

    <section>
      <div class="mb-4 flex items-center justify-between">
        <h2 class="text-2xl font-bold tracking-tight">Organizing</h2>
        <RouterLink :to="{ name: 'event-create' }" class="btn-primary">New event</RouterLink>
      </div>
      <div v-if="data" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <EventCard v-for="event in data.organizing" :key="event.id" :event="event" />
      </div>
      <p v-if="data && data.organizing.length === 0" class="text-slate-500">
        You aren't organizing anything yet.
      </p>
    </section>
  </div>
</template>
