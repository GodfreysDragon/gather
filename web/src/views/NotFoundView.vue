<template>
  <div class="py-24 text-center">
    <p class="text-sm font-semibold text-brand-600 dark:text-brand-300">404</p>
    <h1 class="mt-2 text-2xl font-bold">Page not found</h1>
    <RouterLink :to="{ name: 'events' }" class="btn-primary mt-6">Back to events</RouterLink>
  </div>
</template>
