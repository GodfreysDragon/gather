<script setup lang="ts">
import type { ApiError } from '@/api/errors'

defineProps<{ error: ApiError; retryable?: boolean }>()
defineEmits<{ retry: [] }>()
</script>

<template>
  <div
    role="alert"
    class="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300"
    data-testid="error-alert"
  >
    <div class="flex-1">
      <p>{{ error.message }}</p>
      <p class="mt-0.5 font-mono text-xs opacity-70">
        {{ error.code }}<template v-if="error.status"> · {{ error.status }}</template>
      </p>
    </div>
    <button v-if="retryable" type="button" class="font-medium underline" @click="$emit('retry')">
      Retry
    </button>
  </div>
</template>
