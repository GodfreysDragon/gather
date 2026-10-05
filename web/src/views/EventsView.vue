<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ErrorAlert from '@/components/ErrorAlert.vue'
import EventCard from '@/components/EventCard.vue'
import { useResource } from '@/composables/useResource'
import type { EventFilters, EventSort } from '@/domain/types'

const route = useRoute()
const router = useRouter()

const sortOptions: { value: EventSort; label: string }[] = [
  { value: 'starts_at', label: 'Soonest first' },
  { value: '-starts_at', label: 'Latest first' },
  { value: 'title', label: 'Title A–Z' },
  { value: '-created_at', label: 'Newly added' },
]

/** The URL is the source of truth for filters, so every view is linkable and back/forward works. */
const filters = computed<EventFilters>(() => ({
  q: (route.query.q as string) || undefined,
  city: (route.query.city as string) || undefined,
  hasSpots: route.query.has_spots === '1',
  sort: (route.query.sort as EventSort) || 'starts_at',
  from: new Date(new Date().setHours(0, 0, 0, 0)),
}))
const page = computed(() => Number(route.query.page) || 1)

const { data, error, loading, reload } = useResource(
  (repository) => repository.listEvents(filters.value, page.value),
  [filters, page],
)
const { data: venues } = useResource((repository) => repository.listVenues())
const cities = computed(() => [...new Set((venues.value ?? []).map((venue) => venue.city))].sort())

function setQuery(patch: Record<string, string | undefined>): void {
  const query = { ...route.query, ...patch }
  if (!('page' in patch)) delete query.page
  for (const key of Object.keys(query)) if (!query[key]) delete query[key]
  router.replace({ query })
}

// Debounce typing in the search box.
const search = ref(filters.value.q ?? '')
let timer: ReturnType<typeof setTimeout> | undefined
watch(search, (value) => {
  clearTimeout(timer)
  timer = setTimeout(() => setQuery({ q: value.trim() || undefined }), 300)
})
</script>

<template>
  <section>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold tracking-tight">Upcoming events</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400">
          <template v-if="data"
            >{{ data.total }} {{ data.total === 1 ? 'event' : 'events' }}</template
          >
          <template v-else>&nbsp;</template>
        </p>
      </div>
    </div>

    <form
      class="card mb-6 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_auto]"
      @submit.prevent
    >
      <label class="sr-only" for="q">Search</label>
      <input
        id="q"
        v-model="search"
        type="search"
        class="input"
        placeholder="Search events…"
        data-testid="search"
      />

      <label class="sr-only" for="city">City</label>
      <select
        id="city"
        class="input"
        :value="filters.city ?? ''"
        data-testid="city"
        @change="setQuery({ city: ($event.target as HTMLSelectElement).value || undefined })"
      >
        <option value="">All cities</option>
        <option v-for="city in cities" :key="city" :value="city">{{ city }}</option>
      </select>

      <label class="sr-only" for="sort">Sort</label>
      <select
        id="sort"
        class="input"
        :value="filters.sort"
        @change="setQuery({ sort: ($event.target as HTMLSelectElement).value })"
      >
        <option v-for="option in sortOptions" :key="option.value" :value="option.value">
          {{ option.label }}
        </option>
      </select>

      <label class="flex items-center gap-2 text-sm whitespace-nowrap">
        <input
          type="checkbox"
          class="size-4 accent-brand-600"
          :checked="filters.hasSpots"
          data-testid="has-spots"
          @change="
            setQuery({ has_spots: ($event.target as HTMLInputElement).checked ? '1' : undefined })
          "
        />
        Has free spots
      </label>
    </form>

    <ErrorAlert v-if="error" :error="error" retryable @retry="reload" />

    <div
      v-else
      class="grid gap-4 transition-opacity sm:grid-cols-2 lg:grid-cols-3"
      :class="{ 'opacity-50': loading }"
      :aria-busy="loading"
    >
      <EventCard v-for="event in data?.items" :key="event.id" :event="event" />
      <template v-if="!data">
        <div v-for="n in 6" :key="n" class="card h-40 animate-pulse" />
      </template>
    </div>

    <p v-if="data && !error && data.items.length === 0" class="py-16 text-center text-slate-500">
      No events match these filters.
    </p>

    <nav
      v-if="data && !error && data.lastPage > 1"
      class="mt-8 flex items-center justify-center gap-3"
      aria-label="Pagination"
    >
      <button
        type="button"
        class="btn-secondary"
        :disabled="page <= 1"
        @click="setQuery({ page: String(page - 1) })"
      >
        Previous
      </button>
      <span class="text-sm text-slate-500">Page {{ data.page }} of {{ data.lastPage }}</span>
      <button
        type="button"
        class="btn-secondary"
        :disabled="page >= data.lastPage"
        data-testid="next-page"
        @click="setQuery({ page: String(page + 1) })"
      >
        Next
      </button>
    </nav>
  </section>
</template>
