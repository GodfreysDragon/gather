<script setup lang="ts">
import { computed } from 'vue'
import type { EventItem } from '@/domain/types'

const props = defineProps<{ event: EventItem }>()

const badges = computed(() => {
  const list: { label: string; tone: string }[] = []
  const { event } = props

  if (event.status === 'draft')
    list.push({
      label: 'Draft',
      tone: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    })
  if (event.status === 'cancelled')
    list.push({
      label: 'Cancelled',
      tone: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    })
  if (event.viewer.isRegistered)
    list.push({
      label: 'Going',
      tone: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    })
  if (event.viewer.isOrganizer)
    list.push({
      label: 'Organizer',
      tone: 'bg-brand-100 text-brand-700 dark:bg-brand-700/30 dark:text-brand-100',
    })

  return list
})
</script>

<template>
  <div v-if="badges.length" class="flex flex-wrap gap-1.5">
    <span
      v-for="badge in badges"
      :key="badge.label"
      class="rounded-full px-2 py-0.5 text-xs font-medium"
      :class="badge.tone"
    >
      {{ badge.label }}
    </span>
  </div>
</template>
