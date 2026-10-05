<script setup lang="ts">
import { computed } from 'vue'
import EventBadges from './EventBadges.vue'
import type { EventItem } from '@/domain/types'
import { formatDay, formatEventTime } from '@/utils/format'

const props = defineProps<{ event: EventItem }>()

const day = computed(() => formatDay(props.event.startsAt))
const fill = computed(() =>
  Math.min(100, Math.round((props.event.registrationCount / props.event.capacity) * 100)),
)
</script>

<template>
  <RouterLink
    :to="{ name: 'event', params: { id: event.id } }"
    class="card group flex flex-col p-4 transition hover:-translate-y-0.5 hover:shadow-md"
    data-testid="event-card"
  >
    <div class="flex gap-4">
      <div
        class="flex size-14 shrink-0 flex-col items-center justify-center rounded-lg bg-brand-50 text-brand-700 dark:bg-brand-700/20 dark:text-brand-100"
      >
        <span class="text-[10px] font-semibold tracking-wider">{{ day.month }}</span>
        <span class="text-xl leading-none font-bold">{{ day.day }}</span>
      </div>
      <div class="min-w-0">
        <h3 class="font-semibold group-hover:text-brand-600 dark:group-hover:text-brand-100">
          {{ event.title }}
        </h3>
        <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
          {{ event.venue.name }} · {{ event.venue.city }}
        </p>
        <p class="text-sm text-slate-500 dark:text-slate-400">
          {{ formatEventTime(event.startsAt, event.endsAt) }}
        </p>
      </div>
    </div>

    <div class="mt-4 flex items-center gap-3">
      <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
        <div
          class="h-full rounded-full"
          :class="event.spotsLeft === 0 ? 'bg-red-500' : 'bg-brand-500'"
          :style="{ width: `${fill}%` }"
        />
      </div>
      <span class="text-xs text-slate-500" data-testid="spots-left">
        {{ event.spotsLeft === 0 ? 'Full' : `${event.spotsLeft} of ${event.capacity} left` }}
      </span>
    </div>

    <EventBadges :event="event" class="mt-3" />
  </RouterLink>
</template>
