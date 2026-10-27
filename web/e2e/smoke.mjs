/**
 * End-to-end smoke test: drives the real SPA against the real API in headless
 * Chromium, through both transports.
 *
 * Needs the API running on a freshly seeded database and the SPA dev server:
 *   api/  php artisan migrate:fresh --seed && php artisan serve
 *   web/  npm run dev
 *   web/  E2E_URL=http://localhost:5173 npm run test:e2e
 *
 * Environment:
 *   E2E_URL      SPA origin (default http://localhost:5173)
 *   E2E_BROWSER  Use an installed browser channel instead of Playwright's bundled
 *                Chromium, e.g. msedge or chrome. Without it, install the bundled
 *                one once with `npx playwright-core install chromium`.
 *   E2E_DARK=1   Run in dark mode (prefers-color-scheme: dark); screenshots get a
 *                `-dark` suffix so both themes can be kept side by side. Passing
 *                `--dark` does the same: `npm run test:e2e -- --dark`.
 *   E2E_MAIL_LOG Where the API writes e-mails (MAIL_MAILER=log), to follow the
 *                confirmation link (default ../api/storage/logs/laravel.log).
 */
import { existsSync, mkdirSync, readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright-core'

const BASE = process.env.E2E_URL ?? 'http://localhost:5173'
const CHANNEL = process.env.E2E_BROWSER || undefined
const DARK = process.env.E2E_DARK === '1' || process.argv.includes('--dark')
const MAIL_LOG =
  process.env.E2E_MAIL_LOG ??
  fileURLToPath(new URL('../../api/storage/logs/laravel.log', import.meta.url))
const SHOTS = new URL('./screenshots/', import.meta.url)
mkdirSync(SHOTS, { recursive: true })
const shotPath = (name) => fileURLToPath(new URL(`${name}${DARK ? '-dark' : ''}.png`, SHOTS))

let step = 0
async function check(name, fn) {
  step++
  try {
    await fn()
    console.log(`  ✓ ${step}. ${name}`)
  } catch (error) {
    console.error(`  ✗ ${step}. ${name}\n    ${error.message.split('\n')[0]}`)
    await page.screenshot({ path: shotPath(`failure-${step}`) })
    await browser.close()
    process.exit(1)
  }
}

function assert(condition, message) {
  if (!condition) throw new Error(message)
}

const shot = (name) => page.screenshot({ path: shotPath(name), fullPage: true })

const browser = await chromium.launch({ channel: CHANNEL, headless: true })
const context = await browser.newContext({
  viewport: { width: 1280, height: 900 },
  colorScheme: DARK ? 'dark' : 'light',
})
const page = await context.newPage()
const consoleErrors = []
page.on('console', (msg) => msg.type() === 'error' && consoleErrors.push(msg.text()))
page.on('dialog', (dialog) => dialog.accept())

async function cardTitles() {
  await page.locator('[data-testid="event-card"]').first().waitFor()
  await page.waitForFunction(() => !document.querySelector('[aria-busy="true"]'))
  return page.locator('[data-testid="event-card-title"]').allInnerTexts()
}

async function useTransport(name) {
  await page.click(`[data-testid="transport-${name}"]`)
  await page.waitForFunction(
    (n) => document.querySelector(`[data-testid="transport-${n}"]`)?.checked === true,
    name,
  )
}

console.log(
  `Gather e2e against ${BASE} (${CHANNEL ?? 'bundled chromium'}, ${DARK ? 'dark' : 'light'} mode)`,
)

let restTitles = []

await check('event list loads over REST', async () => {
  await page.goto(BASE)
  await useTransport('rest')
  restTitles = await cardTitles()
  assert(restTitles.length === 12, `expected a full first page of 12, got ${restTitles.length}`)
  await page.click('[data-testid="api-log-toggle"]')
  const log = await page.locator('[data-testid="api-log"]').innerText()
  assert(log.includes('GET /events'), 'API log should show the REST request')
  await shot('events-rest')
})

await check('switching to GraphQL renders the same events', async () => {
  await useTransport('graphql')
  await page.waitForFunction(() =>
    document.querySelector('[data-testid="api-log"]')?.textContent?.includes('ListEvents'),
  )
  const graphqlTitles = await cardTitles()
  assert(
    JSON.stringify(graphqlTitles) === JSON.stringify(restTitles),
    `REST and GraphQL lists differ:\n${restTitles}\n${graphqlTitles}`,
  )
})

await check('filters are applied server-side and reflected in the URL', async () => {
  await page.fill('[data-testid="search"]', 'meetup')
  await page.waitForURL(/q=meetup/)
  const titles = await cardTitles()
  assert(titles.length >= 1 && titles.every((t) => /meetup/i.test(t)), `unexpected results: ${titles}`)
  await page.goto(BASE)
})

await check('logging in with the demo account', async () => {
  await page.goto(`${BASE}/login`)
  await page.fill('[data-testid="email"]', 'demo@gather.test')
  await page.fill('[data-testid="password"]', 'wrong-password')
  await page.click('[data-testid="submit"]')
  await page.locator('[data-testid="email-error"]').waitFor()
  await page.fill('[data-testid="password"]', 'password')
  await page.click('[data-testid="submit"]')
  await page.locator('[data-testid="current-user"]', { hasText: 'Demo User' }).waitFor()
})

for (const transport of ['graphql', 'rest']) {
  await check(`[${transport}] a sold-out event rejects registration with the server's reason`, async () => {
    await useTransport(transport)
    await page.goto(`${BASE}/?q=sold+out`)
    await page.locator('[data-testid="event-card"]').first().click()
    await page.click('[data-testid="register"]')
    const alert = await page.locator('[data-testid="error-alert"]').innerText()
    assert(alert.includes('has no spots left') && alert.includes('event_full'), `got: ${alert}`)
  })

  await check(`[${transport}] registering and cancelling update the counts`, async () => {
    await page.goto(`${BASE}/?has_spots=1`)
    await page.locator('[data-testid="event-card"]').filter({ hasNotText: 'Going' }).first().click()
    const before = Number(await page.locator('[data-testid="detail-spots-left"]').innerText())
    await page.click('[data-testid="register"]')
    await page.locator('[data-testid="cancel-registration"]').waitFor()
    const after = Number(await page.locator('[data-testid="detail-spots-left"]').innerText())
    assert(after === before - 1, `spots ${before} → ${after}`)
    if (transport === 'rest') await shot('event-registered')
    await page.click('[data-testid="cancel-registration"]')
    await page.locator('[data-testid="register"]').waitFor()
    const restored = Number(await page.locator('[data-testid="detail-spots-left"]').innerText())
    assert(restored === before, `spots after cancel: ${restored}`)
  })
}

for (const transport of ['graphql', 'rest']) {
  await check(`[${transport}] creating an event shows server validation, then succeeds`, async () => {
    await useTransport(transport)
    await page.goto(`${BASE}/events/new`)
    const title = `E2E ${transport} workshop ${Date.now()}`
    await page.fill('[data-testid="title"]', title)
    await page.fill('[data-testid="description"]', 'Created by the end-to-end test.')
    await page.selectOption('[data-testid="venue"]', { index: 1 })
    await page.fill('[data-testid="capacity"]', '25')
    // End before start: only the server knows this is invalid.
    const start = await page.inputValue('#starts')
    await page.fill('[data-testid="ends"]', start.replace(/T\d\d/, 'T09'))
    await page.click('[data-testid="save"]')
    const message = await page.locator('[data-testid="ends-error"]').innerText()
    assert(/after/i.test(message), `unexpected validation message: ${message}`)
    if (transport === 'rest') await shot('validation-error')

    await page.fill('[data-testid="ends"]', start.replace(/T\d\d/, 'T23'))
    await page.click('[data-testid="save"]')
    await page.locator('[data-testid="event-title"]', { hasText: title }).waitFor()
  })
}

await check('"My events" lists the organized events', async () => {
  await page.goto(`${BASE}/me`)
  // .first(): reruns against the same database leave earlier test events behind.
  await page.locator('[data-testid="event-card"]', { hasText: 'E2E rest workshop' }).first().waitFor()
  await page.locator('[data-testid="event-card"]', { hasText: 'Draft: Spring hack night' }).waitFor()
  await shot('my-events')
})

await check('an expired access token is refreshed without logging out', async () => {
  const errorsBefore = consoleErrors.length
  await page.evaluate(() => localStorage.setItem('gather.token', 'expired'))
  // A full reload: restoring the session hits /me with the dead token first.
  await page.goto(`${BASE}/me`)
  await page.locator('[data-testid="event-card"]', { hasText: 'Draft: Spring hack night' }).waitFor()
  const token = await page.evaluate(() => localStorage.getItem('gather.token'))
  assert(token && token !== 'expired', 'the access token was not replaced')
  // The browser logs the deliberate 401 that triggered the refresh; nothing else may appear.
  const added = consoleErrors.splice(errorsBefore)
  const unexpected = added.filter((e) => !/status of 401/.test(e))
  assert(unexpected.length === 0, unexpected.join('\n'))
})

/** The confirmation link from the newest e-mail to `email` in the API's mail log. */
function confirmationLink(email) {
  if (!existsSync(MAIL_LOG)) return null
  const log = readFileSync(MAIL_LOG, 'utf8')
  const mail = log
    .slice(log.lastIndexOf(email))
    // The log holds the raw MIME message, which may be quoted-printable: undo soft line
    // breaks and the escapes a URL can contain. (A general decoder would mangle the
    // link when it isn't encoded: "hash=50…" would become "hashP…".) The link's own
    // values are lowercase hex, so they can't contain "=3D" or "=26".
    .replace(/=\r?\n/g, '')
    .replaceAll('=3D', '=')
    .replaceAll('=26', '&')
  const link = mail.match(/https?:\/\/[^\s"'<>)\]]+\/verify-email\?[^\s"'<>)\]]+/)?.[0]
  return link?.replaceAll('&amp;', '&') ?? null
}

await check('registering, then confirming by e-mail, logs the new user in', async () => {
  const email = `e2e-${Date.now()}@example.com`
  const visitor = await (await browser.newContext()).newPage()
  await visitor.goto(`${BASE}/register`)
  await visitor.fill('#name', 'E2E Newcomer')
  await visitor.fill('[data-testid="email"]', email)
  await visitor.fill('[data-testid="password"]', 'correct horse battery')
  await visitor.click('[data-testid="submit"]')
  await visitor.locator('[data-testid="check-inbox"]').waitFor()
  const before = await visitor.evaluate(() => localStorage.getItem('gather.token'))
  assert(before === null, 'registering must not log in before the address is confirmed')

  let link = null
  for (let attempt = 0; attempt < 20 && !link; attempt++) {
    link = confirmationLink(email)
    if (!link) await visitor.waitForTimeout(250)
  }
  assert(link, `no confirmation e-mail for ${email} in ${MAIL_LOG}`)

  // The e-mail points at the SPA configured on the API (FRONTEND_URL); open it on ours.
  const url = new URL(link)
  await visitor.goto(`${BASE}${url.pathname}${url.search}`)
  await visitor.locator('[data-testid="current-user"]', { hasText: 'E2E Newcomer' }).waitFor()
  await visitor.context().close()
})

await check('layout works at phone width', async () => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto(BASE)
  await cardTitles()
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)
  assert(!overflow, 'page scrolls horizontally on mobile')
  await shot('events-mobile')
})

await check('no console errors', async () => {
  const unexpected = consoleErrors.filter((e) => !/status of (409|422)/.test(e))
  assert(unexpected.length === 0, unexpected.join('\n'))
})

await browser.close()
console.log(`\nAll ${step} checks passed. Screenshots in e2e/screenshots/`)
