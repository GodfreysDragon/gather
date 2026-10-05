<script setup lang="ts">
import { storeToRefs } from 'pinia'
import { computed, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError } from '@/api/errors'
import ErrorAlert from '@/components/ErrorAlert.vue'
import { useResource } from '@/composables/useResource'
import type { EventInput, EventItem, EventStatus } from '@/domain/types'
import { useTransportStore } from '@/stores/transport'
import { toLocalInput } from '@/utils/format'

const props = defineProps<{ id?: number }>()

const router = useRouter()
const { repository } = storeToRefs(useTransportStore())
const isEdit = computed(() => props.id !== undefined)

const { data: venues } = useResource((repo) => repo.listVenues())

interface FormState {
  title: string
  description: string
  venueId: string
  startsAt: string
  endsAt: string
  capacity: string
  status: EventStatus
}

const nextWeek = new Date(Date.now() + 7 * 86_400_000)
nextWeek.setHours(18, 0, 0, 0)

const form = reactive<FormState>({
  title: '',
  description: '',
  venueId: '',
  startsAt: toLocalInput(nextWeek),
  endsAt: toLocalInput(new Date(nextWeek.getTime() + 3 * 3_600_000)),
  capacity: '30',
  status: 'published',
})

/** Snapshot of the loaded event, so an edit only PATCHes the fields that changed. */
let original: FormState | null = null
const loadError = ref<ApiError | null>(null)

function toForm(event: EventItem): FormState {
  return {
    title: event.title,
    description: event.description,
    venueId: String(event.venue.id),
    startsAt: toLocalInput(event.startsAt),
    endsAt: toLocalInput(event.endsAt),
    capacity: String(event.capacity),
    status: event.status,
  }
}

watch(
  () => props.id,
  async (id) => {
    if (id === undefined) return
    try {
      const event = await repository.value.getEvent(id)
      if (!event || !event.viewer.isOrganizer) {
        loadError.value = new ApiError(
          'not_found',
          "This event doesn't exist or you don't organize it.",
          404,
        )
        return
      }
      original = toForm(event)
      Object.assign(form, original)
    } catch (e) {
      loadError.value = e instanceof ApiError ? e : new ApiError('unknown', String(e))
    }
  },
  { immediate: true },
)

const selectedVenue = computed(() =>
  venues.value?.find((venue) => String(venue.id) === form.venueId),
)

function toInput(state: FormState): EventInput {
  return {
    title: state.title.trim(),
    description: state.description.trim(),
    venueId: Number(state.venueId),
    startsAt: new Date(state.startsAt),
    endsAt: new Date(state.endsAt),
    capacity: Number(state.capacity),
    status: state.status,
  }
}

function changedFields(): Partial<EventInput> {
  const next = toInput(form)
  if (!original) return next
  const before = toInput(original)
  return Object.fromEntries(
    (Object.keys(next) as (keyof EventInput)[])
      .filter((key) => String(next[key]) !== String(before[key]))
      .map((key) => [key, next[key]]),
  )
}

const saving = ref(false)
const error = ref<ApiError | null>(null)
const fieldError = (field: keyof EventInput) => error.value?.fieldErrors[field]?.[0]

async function submit(): Promise<void> {
  saving.value = true
  error.value = null
  try {
    const event =
      props.id === undefined
        ? await repository.value.createEvent(toInput(form))
        : await repository.value.updateEvent(props.id, changedFields())
    await router.push({ name: 'event', params: { id: event.id } })
  } catch (e) {
    error.value = e instanceof ApiError ? e : new ApiError('unknown', String(e))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-2xl">
    <h1 class="mb-6 text-2xl font-bold tracking-tight">
      {{ isEdit ? 'Edit event' : 'New event' }}
    </h1>

    <ErrorAlert v-if="loadError" :error="loadError" />

    <form v-else class="card space-y-5 p-6" novalidate @submit.prevent="submit">
      <ErrorAlert v-if="error && !error.isValidation" :error="error" />
      <p v-if="error?.isValidation" class="text-sm text-red-600" role="alert">
        Please fix the highlighted fields.
      </p>

      <div>
        <label class="label" for="title">Title</label>
        <input
          id="title"
          v-model="form.title"
          class="input"
          :aria-invalid="!!fieldError('title')"
          data-testid="title"
        />
        <p v-if="fieldError('title')" class="mt-1 text-sm text-red-600">
          {{ fieldError('title') }}
        </p>
      </div>

      <div>
        <label class="label" for="description">Description</label>
        <textarea
          id="description"
          v-model="form.description"
          rows="4"
          class="input"
          :aria-invalid="!!fieldError('description')"
          data-testid="description"
        />
        <p v-if="fieldError('description')" class="mt-1 text-sm text-red-600">
          {{ fieldError('description') }}
        </p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="label" for="venue">Venue</label>
          <select
            id="venue"
            v-model="form.venueId"
            class="input"
            :aria-invalid="!!fieldError('venueId')"
            data-testid="venue"
          >
            <option value="" disabled>Choose a venue</option>
            <option v-for="venue in venues" :key="venue.id" :value="String(venue.id)">
              {{ venue.name }} ({{ venue.city }})
            </option>
          </select>
          <p v-if="fieldError('venueId')" class="mt-1 text-sm text-red-600">
            {{ fieldError('venueId') }}
          </p>
        </div>
        <div>
          <label class="label" for="capacity">Capacity</label>
          <input
            id="capacity"
            v-model="form.capacity"
            type="number"
            min="1"
            class="input"
            :aria-invalid="!!fieldError('capacity')"
            data-testid="capacity"
          />
          <p v-if="fieldError('capacity')" class="mt-1 text-sm text-red-600">
            {{ fieldError('capacity') }}
          </p>
          <p v-else-if="selectedVenue" class="mt-1 text-xs text-slate-500">
            Venue holds {{ selectedVenue.capacity }}.
          </p>
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="label" for="starts">Starts</label>
          <input
            id="starts"
            v-model="form.startsAt"
            type="datetime-local"
            class="input"
            :aria-invalid="!!fieldError('startsAt')"
          />
          <p v-if="fieldError('startsAt')" class="mt-1 text-sm text-red-600">
            {{ fieldError('startsAt') }}
          </p>
        </div>
        <div>
          <label class="label" for="ends">Ends</label>
          <input
            id="ends"
            v-model="form.endsAt"
            type="datetime-local"
            class="input"
            :aria-invalid="!!fieldError('endsAt')"
            data-testid="ends"
          />
          <p v-if="fieldError('endsAt')" class="mt-1 text-sm text-red-600" data-testid="ends-error">
            {{ fieldError('endsAt') }}
          </p>
        </div>
      </div>

      <fieldset>
        <legend class="label">Status</legend>
        <div class="flex flex-wrap gap-4 text-sm">
          <label class="flex items-center gap-2">
            <input v-model="form.status" type="radio" value="published" class="accent-brand-600" />
            Published
          </label>
          <label class="flex items-center gap-2">
            <input v-model="form.status" type="radio" value="draft" class="accent-brand-600" />
            Draft (only you can see it)
          </label>
          <label v-if="isEdit" class="flex items-center gap-2">
            <input v-model="form.status" type="radio" value="cancelled" class="accent-brand-600" />
            Cancelled
          </label>
        </div>
        <p v-if="fieldError('status')" class="mt-1 text-sm text-red-600">
          {{ fieldError('status') }}
        </p>
      </fieldset>

      <div class="flex justify-end gap-3 pt-2">
        <button type="button" class="btn-secondary" @click="router.back()">Cancel</button>
        <button type="submit" class="btn-primary" :disabled="saving" data-testid="save">
          {{ saving ? 'Saving…' : isEdit ? 'Save changes' : 'Create event' }}
        </button>
      </div>
    </form>
  </div>
</template>
