<script setup lang="ts">
import { storeToRefs } from 'pinia'
import { computed, reactive, ref, useTemplateRef, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError, toApiError, type FieldErrors } from '@/api/errors'
import ErrorAlert from '@/components/ErrorAlert.vue'
import { useResource } from '@/composables/useResource'
import type { EventInput, EventItem, EventStatus } from '@/domain/types'
import { useAuthStore } from '@/stores/auth'
import { useTransportStore } from '@/stores/transport'
import { focusFirstInvalid } from '@/utils/focus'
import { toLocalInput } from '@/utils/format'

const props = defineProps<{ id?: number }>()

const router = useRouter()
const auth = useAuthStore()
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

const sameForm = (a: FormState, b: FormState) =>
  (Object.keys(a) as (keyof FormState)[]).every((key) => a[key] === b[key])

// In edit mode, (re)load the event whenever the id, the transport or the user changes.
const {
  data: loaded,
  error: eventError,
  loading: eventLoading,
} = useResource(
  (repo) => (props.id === undefined ? Promise.resolve(null) : repo.getEvent(props.id)),
  [() => props.id, () => auth.user?.id],
)

/** Snapshot of the loaded event, so an edit only PATCHes the fields that changed. */
const original = ref<FormState | null>(null)

watch(loaded, (event) => {
  if (!event?.viewer.isOrganizer) return
  const next = toForm(event)
  // A reload (e.g. after switching transport) must not throw away edits in progress.
  if (!original.value || sameForm(form, original.value)) Object.assign(form, next)
  original.value = next
})

const loadError = computed<ApiError | null>(() => {
  if (!isEdit.value) return null
  if (eventError.value) return eventError.value
  if (eventLoading.value) return null
  if (!loaded.value?.viewer.isOrganizer) {
    return new ApiError('not_found', "This event doesn't exist or you don't organize it.", 404)
  }
  return null
})

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
  if (!original.value) return next
  const before = toInput(original.value)
  return Object.fromEntries(
    (Object.keys(next) as (keyof EventInput)[])
      .filter((key) => String(next[key]) !== String(before[key]))
      .map((key) => [key, next[key]]),
  )
}

/** Checks the browser can turn the date fields into instants; everything else is the server's job. */
function clientErrors(): FieldErrors {
  const errors: FieldErrors = {}
  if (Number.isNaN(new Date(form.startsAt).getTime())) {
    errors.startsAt = ['Enter a start date and time.']
  }
  if (Number.isNaN(new Date(form.endsAt).getTime())) {
    errors.endsAt = ['Enter an end date and time.']
  }
  return errors
}

const saving = ref(false)
const error = ref<ApiError | null>(null)
const fieldError = (field: keyof EventInput) => error.value?.fieldErrors[field]?.[0]
/** Links an input to its error message for screen readers. */
const describedBy = (field: keyof EventInput) => (fieldError(field) ? `${field}-error` : undefined)
const formEl = useTemplateRef<HTMLFormElement>('formRef')

const detailRoute = computed(() =>
  props.id === undefined ? { name: 'events' } : { name: 'event', params: { id: props.id } },
)

async function submit(): Promise<void> {
  error.value = null

  const invalid = clientErrors()
  if (Object.keys(invalid).length > 0) {
    error.value = new ApiError('validation_failed', 'Validation failed', null, invalid)
    void focusFirstInvalid(formEl.value)
    return
  }

  saving.value = true
  try {
    if (props.id === undefined) {
      const event = await repository.value.createEvent(toInput(form))
      await router.push({ name: 'event', params: { id: event.id } })
      return
    }

    const changes = changedFields()
    if (Object.keys(changes).length > 0) await repository.value.updateEvent(props.id, changes)
    await router.push(detailRoute.value)
  } catch (e) {
    error.value = toApiError(e)
    if (error.value.isValidation) void focusFirstInvalid(formEl.value)
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

    <form v-else ref="formRef" class="card space-y-5 p-6" novalidate @submit.prevent="submit">
      <ErrorAlert v-if="error && !error.isValidation" :error="error" />
      <p v-if="error?.isValidation" class="text-sm text-red-600 dark:text-red-400" role="alert">
        Please fix the highlighted fields.
      </p>

      <div>
        <label class="label" for="title">Title</label>
        <input
          id="title"
          v-model="form.title"
          class="input"
          :aria-invalid="!!fieldError('title')"
          :aria-describedby="describedBy('title')"
          data-testid="title"
        />
        <p
          v-if="fieldError('title')"
          id="title-error"
          class="mt-1 text-sm text-red-600 dark:text-red-400"
        >
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
          :aria-describedby="describedBy('description')"
          data-testid="description"
        />
        <p
          v-if="fieldError('description')"
          id="description-error"
          class="mt-1 text-sm text-red-600 dark:text-red-400"
        >
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
            :aria-describedby="describedBy('venueId')"
            data-testid="venue"
          >
            <option value="" disabled>Choose a venue</option>
            <option v-for="venue in venues" :key="venue.id" :value="String(venue.id)">
              {{ venue.name }} ({{ venue.city }})
            </option>
          </select>
          <p
            v-if="fieldError('venueId')"
            id="venueId-error"
            class="mt-1 text-sm text-red-600 dark:text-red-400"
          >
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
            :aria-describedby="describedBy('capacity')"
            data-testid="capacity"
          />
          <p
            v-if="fieldError('capacity')"
            id="capacity-error"
            class="mt-1 text-sm text-red-600 dark:text-red-400"
          >
            {{ fieldError('capacity') }}
          </p>
          <p v-else-if="selectedVenue" class="mt-1 text-xs text-slate-500 dark:text-slate-400">
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
            :aria-describedby="describedBy('startsAt')"
          />
          <p
            v-if="fieldError('startsAt')"
            id="startsAt-error"
            class="mt-1 text-sm text-red-600 dark:text-red-400"
          >
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
            :aria-describedby="describedBy('endsAt')"
            data-testid="ends"
          />
          <p
            v-if="fieldError('endsAt')"
            id="endsAt-error"
            class="mt-1 text-sm text-red-600 dark:text-red-400"
            data-testid="ends-error"
          >
            {{ fieldError('endsAt') }}
          </p>
        </div>
      </div>

      <fieldset :aria-describedby="describedBy('status')">
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
        <p
          v-if="fieldError('status')"
          id="status-error"
          class="mt-1 text-sm text-red-600 dark:text-red-400"
        >
          {{ fieldError('status') }}
        </p>
      </fieldset>

      <div class="flex justify-end gap-3 pt-2">
        <RouterLink :to="detailRoute" class="btn-secondary">Cancel</RouterLink>
        <button
          type="submit"
          class="btn-primary"
          :disabled="saving || (isEdit && !original)"
          data-testid="save"
        >
          {{ saving ? 'Saving…' : isEdit ? 'Save changes' : 'Create event' }}
        </button>
      </div>
    </form>
  </div>
</template>
