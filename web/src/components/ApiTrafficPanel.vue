<script setup lang="ts">
import { ref } from 'vue'
import { apiLog, clearApiLog } from '@/api/apiLog'
import { formatBytes } from '@/utils/format'

const open = ref(false)
</script>

<template>
  <aside
    class="fixed right-4 bottom-4 z-30 w-[min(26rem,calc(100vw-2rem))] text-xs"
    aria-label="API traffic"
  >
    <div v-if="open" class="card mb-2 max-h-80 overflow-y-auto shadow-lg" data-testid="api-log">
      <div
        class="sticky top-0 flex items-center justify-between border-b border-slate-200 bg-white px-3 py-2 dark:border-slate-800 dark:bg-slate-900"
      >
        <span class="font-semibold">API traffic</span>
        <button
          type="button"
          class="text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200"
          @click="clearApiLog"
        >
          Clear
        </button>
      </div>
      <p v-if="apiLog.entries.length === 0" class="px-3 py-4 text-slate-500 dark:text-slate-400">
        No requests yet.
      </p>
      <ol class="divide-y divide-slate-100 dark:divide-slate-800">
        <li
          v-for="entry in apiLog.entries"
          :key="entry.id"
          class="flex items-center gap-2 px-3 py-1.5 font-mono"
        >
          <span
            class="w-14 shrink-0 rounded px-1 text-center text-[10px] font-bold"
            :class="
              entry.transport === 'REST'
                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                : 'bg-fuchsia-100 text-fuchsia-800 dark:bg-fuchsia-950 dark:text-fuchsia-300'
            "
          >
            {{ entry.transport }}
          </span>
          <span class="min-w-0 flex-1 truncate" :title="entry.label">{{ entry.label }}</span>
          <span
            :class="
              entry.status === 'error' || (typeof entry.status === 'number' && entry.status >= 400)
                ? 'text-red-600 dark:text-red-400'
                : 'text-slate-500 dark:text-slate-400'
            "
          >
            {{ entry.status }}
          </span>
          <span class="w-12 text-right text-slate-500 dark:text-slate-400"
            >{{ entry.durationMs }}ms</span
          >
          <span class="w-14 text-right text-slate-500 dark:text-slate-400">{{
            formatBytes(entry.bytes)
          }}</span>
        </li>
      </ol>
    </div>

    <button
      type="button"
      class="btn-secondary ml-auto flex py-1.5 text-xs shadow-md"
      :aria-expanded="open"
      data-testid="api-log-toggle"
      @click="open = !open"
    >
      <span class="size-2 rounded-full bg-emerald-500" aria-hidden="true" />
      API traffic ({{ apiLog.entries.length }})
    </button>
  </aside>
</template>
