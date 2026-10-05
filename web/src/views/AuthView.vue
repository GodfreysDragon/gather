<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError } from '@/api/errors'
import ErrorAlert from '@/components/ErrorAlert.vue'
import { useAuthStore } from '@/stores/auth'

const props = defineProps<{ mode: 'login' | 'register' }>()

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const form = reactive({ name: '', email: '', password: '' })
const error = ref<ApiError | null>(null)
const submitting = ref(false)
const fieldError = (field: string) => error.value?.fieldErrors[field]?.[0]

async function submit(): Promise<void> {
  submitting.value = true
  error.value = null
  try {
    if (props.mode === 'login') await auth.login(form.email, form.password)
    else await auth.register(form.name, form.email, form.password)

    const redirect = route.query.redirect
    await router.replace(
      typeof redirect === 'string' && redirect.startsWith('/') ? redirect : { name: 'events' },
    )
  } catch (e) {
    error.value = e instanceof ApiError ? e : new ApiError('unknown', String(e))
  } finally {
    submitting.value = false
  }
}

function useDemoAccount(): void {
  form.email = 'demo@gather.test'
  form.password = 'password'
}
</script>

<template>
  <div class="mx-auto mt-8 max-w-sm">
    <h1 class="mb-6 text-center text-2xl font-bold tracking-tight">
      {{ mode === 'login' ? 'Log in to Gather' : 'Create your account' }}
    </h1>

    <form class="card space-y-4 p-6" novalidate @submit.prevent="submit">
      <ErrorAlert v-if="error && !error.isValidation" :error="error" />

      <div v-if="mode === 'register'">
        <label class="label" for="name">Name</label>
        <input
          id="name"
          v-model="form.name"
          class="input"
          autocomplete="name"
          :aria-invalid="!!fieldError('name')"
        />
        <p v-if="fieldError('name')" class="mt-1 text-sm text-red-600">{{ fieldError('name') }}</p>
      </div>
      <div>
        <label class="label" for="email">Email</label>
        <input
          id="email"
          v-model="form.email"
          type="email"
          class="input"
          autocomplete="email"
          :aria-invalid="!!fieldError('email')"
          data-testid="email"
        />
        <p v-if="fieldError('email')" class="mt-1 text-sm text-red-600" data-testid="email-error">
          {{ fieldError('email') }}
        </p>
      </div>
      <div>
        <label class="label" for="password">Password</label>
        <input
          id="password"
          v-model="form.password"
          type="password"
          class="input"
          :autocomplete="mode === 'login' ? 'current-password' : 'new-password'"
          :aria-invalid="!!fieldError('password')"
          data-testid="password"
        />
        <p v-if="fieldError('password')" class="mt-1 text-sm text-red-600">
          {{ fieldError('password') }}
        </p>
      </div>

      <button type="submit" class="btn-primary w-full" :disabled="submitting" data-testid="submit">
        {{ mode === 'login' ? 'Log in' : 'Create account' }}
      </button>

      <button
        v-if="mode === 'login'"
        type="button"
        class="w-full text-center text-sm text-brand-600 hover:underline"
        @click="useDemoAccount"
      >
        Use the demo account
      </button>
    </form>

    <p class="mt-4 text-center text-sm text-slate-500">
      <template v-if="mode === 'login'">
        New here?
        <RouterLink
          :to="{ name: 'register', query: route.query }"
          class="text-brand-600 hover:underline"
          >Create an account</RouterLink
        >
      </template>
      <template v-else>
        Already have an account?
        <RouterLink
          :to="{ name: 'login', query: route.query }"
          class="text-brand-600 hover:underline"
          >Log in</RouterLink
        >
      </template>
    </p>
  </div>
</template>
