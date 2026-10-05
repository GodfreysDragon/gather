<script setup lang="ts">
import ApiTrafficPanel from '@/components/ApiTrafficPanel.vue'
import AppHeader from '@/components/AppHeader.vue'
</script>

<template>
  <AppHeader />
  <main class="mx-auto max-w-6xl px-4 pt-6 pb-24">
    <RouterView />
  </main>
  <ApiTrafficPanel />
</template>
