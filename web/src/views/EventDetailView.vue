<script setup lang="ts">
import { storeToRefs } from 'pinia'
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { toApiError, type ApiError } from '@/api/errors'
import ErrorAlert from '@/components/ErrorAlert.vue'
import EventBadges from '@/components/EventBadges.vue'
import { useResource } from '@/composables/useResource'
import type { EventItem } from '@/domain/types'
import { useAuthStore } from '@/stores/auth'
import { useTransportStore } from '@/stores/transport'
import { formatEventTime } from '@/utils/format'

const props = defineProps<{ id: number }>()

const auth = useAuthStore()
const { repository } = storeToRefs(useTransportStore())
const router = useRouter()
const route = useRoute()

const {
  data: loaded,
  error,
  loading,
  reload,
} = useResource((repo) => repo.getEvent(props.id), [() => props.id, () => auth.user?.id])

/** Local copy so mutations can show their result without refetching. */
const event = ref<EventItem | null>(null)
watch(loaded, (value) => (event.value = value))

const actionError = ref<ApiError | null>(null)
const busy = ref(false)

const isPast = computed(() => (event.value ? event.value.startsAt < new Date() : false))
const canRegister = computed(
  () => event.value?.status === 'published' && !isPast.value && !event.value.viewer.isRegistered,
)

async function run(action: () => Promise<EventItem | void>): Promise<void> {
  busy.value = true
  actionError.value = null
  try {
    const updated = await action()
    if (updated) event.value = updated
  } catch (e) {
    actionError.value = toApiError(e)
  } finally {
    busy.value = false
  }
}

function register(): void {
  if (!auth.isAuthenticated) {
    router.push({ name: 'login', query: { redirect: route.fullPath } })
    return
  }
  run(() => repository.value.register(props.id))
}

const cancelRegistration = () => run(() => repository.value.cancelRegistration(props.id))

const cancelEvent = () => {
  if (confirm('Cancel this event? Attendees will see it as cancelled.')) {
    run(() => repository.value.updateEvent(props.id, { status: 'cancelled' }))
  }
}

const deleteEvent = () => {
  if (confirm('Delete this event permanently?')) {
    run(async () => {
      await repository.value.deleteEvent(props.id)
      await router.push({ name: 'my-events' })
    })
  }
}

// Attendees are only loaded for the organizer, and refreshed when the count changes.
const {
  data: attendees,
  error: attendeesError,
  reload: reloadAttendees,
} = useResource(
  (repo) =>
    event.value?.viewer.isOrganizer ? repo.listAttendees(props.id) : Promise.resolve(null),
  [() => props.id, () => event.value?.viewer.isOrganizer, () => event.value?.registrationCount],
  { keepPreviousData: true },
)
</script>

<template>
  <div>
    <RouterLink
      :to="{ name: 'events' }"
      class="text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200"
    >
      ← All events
    </RouterLink>

    <ErrorAlert v-if="error" class="mt-4" :error="error" retryable @retry="reload" />

    <div v-else-if="!loading && loaded === null" class="py-20 text-center">
      <h1 class="text-xl font-semibold">Event not found</h1>
      <p class="mt-1 text-slate-500 dark:text-slate-400">
        It may have been deleted, or it's a draft you don't organize.
      </p>
    </div>

    <div v-else-if="event" class="mt-4 grid gap-6 lg:grid-cols-[1fr_20rem]">
      <article class="card p-6">
        <EventBadges :event="event" class="mb-3" />
        <h1 class="text-2xl font-bold tracking-tight" data-testid="event-title">
          {{ event.title }}
        </h1>
        <p class="mt-1 text-slate-500 dark:text-slate-400">
          Organized by {{ event.organizer.name }}
        </p>

        <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
          <div>
            <dt class="font-medium text-slate-500 dark:text-slate-400">When</dt>
            <dd>{{ formatEventTime(event.startsAt, event.endsAt) }}</dd>
          </div>
          <div>
            <dt class="font-medium text-slate-500 dark:text-slate-400">Where</dt>
            <dd>{{ event.venue.name }}, {{ event.venue.address }}, {{ event.venue.city }}</dd>
          </div>
        </dl>

        <p class="mt-6 leading-relaxed whitespace-pre-line text-slate-700 dark:text-slate-300">
          {{ event.description }}
        </p>
      </article>

      <aside class="space-y-4">
        <div class="card p-5">
          <p class="text-3xl font-bold" data-testid="detail-spots-left">{{ event.spotsLeft }}</p>
          <p class="text-sm text-slate-500 dark:text-slate-400">
            of {{ event.capacity }} spots left · {{ event.registrationCount }} going
          </p>

          <div class="mt-4 space-y-2">
            <p
              v-if="event.viewer.isRegistered"
              class="text-sm font-medium text-emerald-700 dark:text-emerald-400"
            >
              ✓ You're going
            </p>
            <button
              v-if="canRegister"
              type="button"
              class="btn-primary w-full"
              :disabled="busy"
              data-testid="register"
              @click="register"
            >
              {{ event.spotsLeft === 0 ? 'Try to register' : 'Register' }}
            </button>
            <button
              v-if="event.viewer.isRegistered"
              type="button"
              class="btn-secondary w-full"
              :disabled="busy"
              data-testid="cancel-registration"
              @click="cancelRegistration"
            >
              Cancel my registration
            </button>
            <p v-if="event.status === 'cancelled'" class="text-sm text-red-600 dark:text-red-400">
              This event was cancelled.
            </p>
            <p v-else-if="isPast" class="text-sm text-slate-500 dark:text-slate-400">
              This event has already started.
            </p>
          </div>

          <ErrorAlert v-if="actionError" class="mt-4" :error="actionError" />
        </div>

        <div v-if="event.viewer.isOrganizer" class="card space-y-2 p-5">
          <h2 class="font-semibold">Organizer tools</h2>
          <RouterLink
            :to="{ name: 'event-edit', params: { id: event.id } }"
            class="btn-secondary w-full"
          >
            Edit event
          </RouterLink>
          <button
            v-if="event.status !== 'cancelled' && event.registrationCount > 0"
            type="button"
            class="btn-danger w-full"
            :disabled="busy"
            @click="cancelEvent"
          >
            Cancel event
          </button>
          <button
            v-if="event.registrationCount === 0"
            type="button"
            class="btn-danger w-full"
            :disabled="busy"
            @click="deleteEvent"
          >
            Delete event
          </button>

          <ErrorAlert
            v-if="attendeesError"
            class="mt-3"
            :error="attendeesError"
            retryable
            @retry="reloadAttendees"
          />
          <div v-else-if="attendees" class="pt-3">
            <h3 class="mb-2 text-sm font-medium text-slate-500 dark:text-slate-400">
              Attendees ({{ attendees.length }})
            </h3>
            <ul class="max-h-64 space-y-1 overflow-y-auto text-sm">
              <li
                v-for="attendee in attendees"
                :key="attendee.id"
                class="flex justify-between gap-2"
              >
                <span class="truncate">{{ attendee.name }}</span>
                <span class="shrink-0 text-xs text-slate-500 dark:text-slate-400">{{
                  attendee.registeredAt.toLocaleDateString()
                }}</span>
              </li>
              <li v-if="attendees.length === 0" class="text-slate-500 dark:text-slate-400">
                Nobody yet.
              </li>
            </ul>
          </div>
        </div>
      </aside>
    </div>
  </div>
</template>
