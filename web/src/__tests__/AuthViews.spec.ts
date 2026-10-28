import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { clearTokens, getRefreshToken, getToken } from '@/api/token'
import { useAuthStore } from '@/stores/auth'
import AuthView from '@/views/AuthView.vue'
import VerifyEmailView from '@/views/VerifyEmailView.vue'

// openapi-fetch captures `fetch` when the client is created, so stub it before any import runs.
const fetchMock = vi.hoisted(() => {
  const mock = vi.fn<typeof fetch>()
  globalThis.fetch = mock
  return mock
})

const replace = vi.fn<(to: unknown) => Promise<void>>()
const route = vi.hoisted(() => ({ query: {} as Record<string, string> }))
vi.mock('vue-router', async (importOriginal) => {
  const { createMemoryHistory, createRouter } = await importOriginal<typeof import('vue-router')>()
  // A real router for `resolve`, with the app's kinds of routes, including its catch-all.
  const Page = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'events', component: Page },
      { path: '/me', name: 'my-events', component: Page },
      { path: '/:pathMatch(.*)*', name: 'not-found', component: Page },
    ],
  })
  return {
    useRouter: () => ({ replace, resolve: router.resolve }),
    useRoute: () => route,
  }
})

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), {
    status,
    headers: {
      'Content-Type': status >= 400 ? 'application/problem+json' : 'application/json',
    },
  })

const checkInbox = {
  message: "Check your inbox: we've sent you an e-mail to confirm your address.",
}

function requestTo(index: number): Request {
  return fetchMock.mock.calls[index]![0] as Request
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const mountView = (component: any, props: Record<string, unknown>) =>
  mount(component, { props, global: { stubs: { RouterLink: RouterLinkStub } } })

describe('auth screens', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    clearTokens()
  })

  afterEach(() => {
    fetchMock.mockReset()
    replace.mockReset()
    route.query = {}
  })

  async function logIn(wrapper: ReturnType<typeof mountView>): Promise<void> {
    fetchMock.mockResolvedValueOnce(
      json({
        token: 'access-1',
        token_type: 'Bearer',
        expires_at: '2026-11-01T19:00:00Z',
        refresh_token: 'refresh-1',
        refresh_token_expires_at: '2026-12-01T18:00:00Z',
        user: { id: 7, name: 'Ada', email: 'ada@example.com' },
      }),
    )
    await wrapper.get('[data-testid="email"]').setValue('ada@example.com')
    await wrapper.get('[data-testid="password"]').setValue('correct horse')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
  }

  it('goes back to the page that asked for a login', async () => {
    route.query = { redirect: '/me?page=2' }
    await logIn(mountView(AuthView, { mode: 'login' }))

    expect(replace).toHaveBeenCalledWith('/me?page=2')
  })

  it.each([
    '//evil.example',
    '/\\evil.example',
    '/\\/evil.example',
    'https://evil.example',
    '/no-such-page',
  ])('ignores a redirect to %s and goes to the events', async (redirect) => {
    route.query = { redirect }
    await logIn(mountView(AuthView, { mode: 'login' }))

    expect(replace).toHaveBeenCalledWith({ name: 'events' })
  })

  it('switching from register to log in drops the "check your inbox" notice', async () => {
    fetchMock.mockResolvedValueOnce(json(checkInbox, 202))
    const wrapper = mountView(AuthView, { mode: 'register' })
    await wrapper.get('#name').setValue('Ada')
    await wrapper.get('[data-testid="email"]').setValue('ada@example.com')
    await wrapper.get('[data-testid="password"]').setValue('correct horse')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.find('[data-testid="check-inbox"]').exists()).toBe(true)

    await wrapper.setProps({ mode: 'login' })

    expect(wrapper.find('[data-testid="check-inbox"]').exists()).toBe(false)
    expect(wrapper.get('h1').text()).toBe('Log in to Gather')
    expect((wrapper.get('[data-testid="password"]').element as HTMLInputElement).value).toBe('')
    expect((wrapper.get('[data-testid="email"]').element as HTMLInputElement).value).toBe(
      'ada@example.com',
    )
  })

  it('registering shows "check your inbox" and logs nobody in', async () => {
    fetchMock.mockResolvedValueOnce(json(checkInbox, 202))
    const wrapper = mountView(AuthView, { mode: 'register' })

    await wrapper.get('#name').setValue('Ada')
    await wrapper.get('[data-testid="email"]').setValue('ada@example.com')
    await wrapper.get('[data-testid="password"]').setValue('correct horse')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[data-testid="check-inbox"]').text()).toContain('Check your inbox')
    expect(getToken()).toBeNull()
    expect(useAuthStore().isAuthenticated).toBe(false)
    expect(replace).not.toHaveBeenCalled()
  })

  it('an unconfirmed login offers a new confirmation link', async () => {
    fetchMock.mockResolvedValueOnce(
      json(
        {
          type: 'http://api.test/problems/email-not-verified',
          title: 'E-mail address not confirmed',
          status: 403,
          detail: 'Confirm your e-mail address first.',
        },
        403,
      ),
    )
    const wrapper = mountView(AuthView, { mode: 'login' })
    await wrapper.get('[data-testid="email"]').setValue('ada@example.com')
    await wrapper.get('[data-testid="password"]').setValue('correct horse')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    fetchMock.mockResolvedValueOnce(json(checkInbox, 202))
    await wrapper.get('[data-testid="resend"]').trigger('click')
    await flushPromises()

    expect(new URL(requestTo(1).url).pathname).toBe('/api/v1/auth/email/resend')
    expect(await requestTo(1).json()).toEqual({ email: 'ada@example.com' })
    expect(wrapper.find('[data-testid="check-inbox"]').exists()).toBe(true)
  })

  it('the e-mailed link confirms the address, logs in and goes to the events', async () => {
    fetchMock.mockResolvedValueOnce(
      json({
        token: 'access-1',
        token_type: 'Bearer',
        expires_at: '2026-11-01T19:00:00Z',
        refresh_token: 'refresh-1',
        refresh_token_expires_at: '2026-12-01T18:00:00Z',
        user: { id: 7, name: 'Ada', email: 'ada@example.com' },
      }),
    )

    mountView(VerifyEmailView, { id: 7, hash: 'abc', expires: 1793865600, signature: 'sig' })
    await flushPromises()

    const url = new URL(requestTo(0).url)
    expect(url.pathname).toBe('/api/v1/auth/email/verify/7/abc')
    expect(Object.fromEntries(url.searchParams)).toEqual({
      expires: '1793865600',
      signature: 'sig',
    })
    expect(getToken()).toBe('access-1')
    expect(getRefreshToken()).toBe('refresh-1')
    expect(useAuthStore().user?.email).toBe('ada@example.com')
    expect(replace).toHaveBeenCalledWith({ name: 'events' })
  })

  it('a used or expired link explains what to do next', async () => {
    fetchMock.mockResolvedValueOnce(
      json(
        {
          type: 'http://api.test/problems/verification-link-invalid',
          title: 'Confirmation link no longer valid',
          status: 410,
          detail: 'This confirmation link has already been used or is no longer valid.',
        },
        410,
      ),
    )

    const wrapper = mountView(VerifyEmailView, { id: 7, hash: 'abc', expires: 1, signature: 'sig' })
    await flushPromises()

    expect(wrapper.get('[data-testid="verify-error"]').text()).toContain('already been used')
    expect(getToken()).toBeNull()
    expect(replace).not.toHaveBeenCalled()
  })

  it('an incomplete link never reaches the API', async () => {
    const wrapper = mountView(VerifyEmailView, { id: 0, hash: '', expires: 0, signature: '' })
    await flushPromises()

    expect(fetchMock).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="verify-error"]').text()).toContain('incomplete')
  })
})
