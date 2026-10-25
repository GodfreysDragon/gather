<script setup lang="ts">
import { onUnmounted, useTemplateRef } from 'vue'
import { useRouter } from 'vue-router'
import ApiTrafficPanel from '@/components/ApiTrafficPanel.vue'
import AppHeader from '@/components/AppHeader.vue'

const main = useTemplateRef<HTMLElement>('mainRef')

// A SPA navigation doesn't move focus like a page load does. Move it to the content so
// keyboard and screen reader users start on the new page, not on the link they used.
// Query-only changes (filters, paging) keep focus where it is.
const removeHook = useRouter().afterEach((to, from, failure) => {
  if (failure || from.matched.length === 0 || to.path === from.path) return
  main.value?.focus({ preventScroll: true })
})
onUnmounted(removeHook)
</script>

<template>
  <AppHeader />
  <main ref="mainRef" tabindex="-1" class="mx-auto max-w-6xl px-4 pt-6 pb-24 outline-none">
    <RouterView />
  </main>
  <ApiTrafficPanel />
</template>
