<script setup lang="ts">
import { storeToRefs } from 'pinia'
import type { Transport } from '@/domain/repository'
import { useTransportStore } from '@/stores/transport'

const { transport } = storeToRefs(useTransportStore())

const options: { value: Transport; label: string }[] = [
  { value: 'rest', label: 'REST' },
  { value: 'graphql', label: 'GraphQL' },
]
</script>

<template>
  <!-- Native radios: Tab reaches the group, arrow keys switch, screen readers announce it. -->
  <fieldset
    title="Switch which API the whole UI uses"
    class="inline-flex rounded-lg border border-slate-300 bg-slate-100 p-0.5 text-xs font-semibold dark:border-slate-700 dark:bg-slate-800"
  >
    <legend class="sr-only">API transport</legend>
    <label
      v-for="option in options"
      :key="option.value"
      class="relative rounded-md px-2.5 py-1 transition"
      :class="
        transport === option.value
          ? 'bg-white text-brand-700 shadow-sm dark:bg-slate-950 dark:text-brand-100'
          : 'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'
      "
    >
      <!-- The input covers its label, so clicks land on it and its focus ring outlines the pill. -->
      <input
        v-model="transport"
        type="radio"
        name="transport"
        :value="option.value"
        :data-testid="`transport-${option.value}`"
        class="absolute inset-0 cursor-pointer appearance-none rounded-md"
      />
      {{ option.label }}
    </label>
  </fieldset>
</template>
