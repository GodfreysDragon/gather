<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError, toApiError } from '@/api/errors'
import ErrorAlert from '@/components/ErrorAlert.vue'
import { useAuthStore, type VerificationLink } from '@/stores/auth'

const props = defineProps<VerificationLink>()

const auth = useAuthStore()
const router = useRouter()
const error = ref<ApiError | null>(null)

onMounted(async () => {
  if (!props.id || !props.hash || !props.expires || !props.signature) {
    error.value = new ApiError(
      'verification_link_invalid',
      'This link is incomplete. Open it straight from the e-mail, or ask for a new one by logging in.',
    )
    return
  }

  try {
    await auth.verifyEmail(props)
    await router.replace({ name: 'events' })
  } catch (e) {
    error.value = toApiError(e)
  }
})
</script>

<template>
  <div class="mx-auto mt-8 max-w-sm text-center">
    <h1 class="mb-6 text-2xl font-bold tracking-tight">Confirm your address</h1>

    <div v-if="error" class="card space-y-4 p-6 text-left" data-testid="verify-error">
      <ErrorAlert :error="error" />
      <p class="text-sm text-slate-500 dark:text-slate-400">
        Links expire after an hour and work once. If your address is already confirmed, just log in;
        if not, logging in offers to send a new link.
      </p>
      <RouterLink :to="{ name: 'login' }" class="btn-primary block w-full text-center"
        >Log in</RouterLink
      >
    </div>

    <p v-else class="text-slate-500 dark:text-slate-400" role="status">Confirming…</p>
  </div>
</template>
