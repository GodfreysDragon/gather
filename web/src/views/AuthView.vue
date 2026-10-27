<script setup lang="ts">
import { reactive, ref, useTemplateRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { toApiError, type ApiError } from '@/api/errors'
import ErrorAlert from '@/components/ErrorAlert.vue'
import { useAuthStore } from '@/stores/auth'
import { focusFirstInvalid } from '@/utils/focus'

const props = defineProps<{ mode: 'login' | 'register' }>()

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const form = reactive({ name: '', email: '', password: '' })
const error = ref<ApiError | null>(null)
const submitting = ref(false)
/** "Check your inbox" after registering or asking for a new link. */
const notice = ref<string | null>(null)
const fieldError = (field: string) => error.value?.fieldErrors[field]?.[0]
/** Links an input to its error message for screen readers. */
const describedBy = (field: string) => (fieldError(field) ? `${field}-error` : undefined)
const formEl = useTemplateRef<HTMLFormElement>('formRef')

async function submit(): Promise<void> {
  submitting.value = true
  error.value = null
  notice.value = null
  try {
    if (props.mode === 'register') {
      notice.value = await auth.register(form.name, form.email, form.password)
      return
    }
    await auth.login(form.email, form.password)

    const redirect = route.query.redirect
    await router.replace(
      typeof redirect === 'string' && redirect.startsWith('/') ? redirect : { name: 'events' },
    )
  } catch (e) {
    error.value = toApiError(e)
    if (error.value.isValidation) void focusFirstInvalid(formEl.value)
  } finally {
    submitting.value = false
  }
}

async function resend(): Promise<void> {
  submitting.value = true
  try {
    notice.value = await auth.resendVerification(form.email)
    error.value = null
  } catch (e) {
    error.value = toApiError(e)
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

    <div
      v-if="notice"
      class="card space-y-3 p-6 text-center"
      role="status"
      data-testid="check-inbox"
    >
      <p class="font-medium">{{ notice }}</p>
      <p class="text-sm text-slate-500 dark:text-slate-400">
        The link works for an hour. Nothing arrived?
      </p>
      <button
        type="button"
        class="text-sm text-brand-600 hover:underline dark:text-brand-300"
        :disabled="submitting"
        @click="resend"
      >
        Send another link
      </button>
    </div>

    <form v-else ref="formRef" class="card space-y-4 p-6" novalidate @submit.prevent="submit">
      <ErrorAlert v-if="error && !error.isValidation" :error="error" />
      <button
        v-if="error?.code === 'email_not_verified'"
        type="button"
        class="w-full text-center text-sm text-brand-600 hover:underline dark:text-brand-300"
        :disabled="submitting"
        data-testid="resend"
        @click="resend"
      >
        Send me a new confirmation link
      </button>

      <div v-if="mode === 'register'">
        <label class="label" for="name">Name</label>
        <input
          id="name"
          v-model="form.name"
          class="input"
          autocomplete="name"
          :aria-invalid="!!fieldError('name')"
          :aria-describedby="describedBy('name')"
        />
        <p
          v-if="fieldError('name')"
          id="name-error"
          class="mt-1 text-sm text-red-600 dark:text-red-400"
        >
          {{ fieldError('name') }}
        </p>
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
          :aria-describedby="describedBy('email')"
          data-testid="email"
        />
        <p
          v-if="fieldError('email')"
          id="email-error"
          class="mt-1 text-sm text-red-600 dark:text-red-400"
          data-testid="email-error"
        >
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
          :aria-describedby="describedBy('password')"
          data-testid="password"
        />
        <p
          v-if="fieldError('password')"
          id="password-error"
          class="mt-1 text-sm text-red-600 dark:text-red-400"
        >
          {{ fieldError('password') }}
        </p>
      </div>

      <button type="submit" class="btn-primary w-full" :disabled="submitting" data-testid="submit">
        {{ mode === 'login' ? 'Log in' : 'Create account' }}
      </button>

      <button
        v-if="mode === 'login'"
        type="button"
        class="w-full text-center text-sm text-brand-600 hover:underline dark:text-brand-300"
        @click="useDemoAccount"
      >
        Use the demo account
      </button>
    </form>

    <p class="mt-4 text-center text-sm text-slate-500 dark:text-slate-400">
      <template v-if="mode === 'login'">
        New here?
        <RouterLink
          :to="{ name: 'register', query: route.query }"
          class="text-brand-600 hover:underline dark:text-brand-300"
          >Create an account</RouterLink
        >
      </template>
      <template v-else>
        Already have an account?
        <RouterLink
          :to="{ name: 'login', query: route.query }"
          class="text-brand-600 hover:underline dark:text-brand-300"
          >Log in</RouterLink
        >
      </template>
    </p>
  </div>
</template>
