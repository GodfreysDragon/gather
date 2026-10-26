/**
 * Captures the README screenshots and the REST/GraphQL toggle GIF into ../docs/screenshots/.
 *
 * Needs the same setup as the smoke test (API on a freshly seeded database, SPA running):
 *   web/  E2E_URL=http://localhost:5173 npm run capture
 * The API's docs pages load their UI from public CDNs; API_URL points at the API
 * (default http://localhost:8000).
 * The GIF needs ffmpeg on the PATH; without it the WebM recording is kept instead.
 */
import { execFileSync } from 'node:child_process'
import { mkdirSync, renameSync, rmSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright-core'

const BASE = process.env.E2E_URL ?? 'http://localhost:5173'
const API = process.env.API_URL ?? 'http://localhost:8000'
const CHANNEL = process.env.E2E_BROWSER || undefined
const OUT = fileURLToPath(new URL('../../docs/screenshots/', import.meta.url))
const VIDEO_DIR = fileURLToPath(new URL('./screenshots/video/', import.meta.url))
mkdirSync(OUT, { recursive: true })

const browser = await chromium.launch({ channel: CHANNEL, headless: true })

async function session({ colorScheme = 'light', viewport = { width: 1280, height: 800 }, video = false } = {}) {
  const context = await browser.newContext({
    viewport,
    colorScheme,
    deviceScaleFactor: viewport.width < 600 ? 3 : 2,
    ...(video ? { recordVideo: { dir: VIDEO_DIR, size: viewport } } : {}),
  })
  const page = await context.newPage()
  await page.goto(`${BASE}/login`)
  await page.fill('[data-testid="email"]', 'demo@gather.test')
  await page.fill('[data-testid="password"]', 'password')
  await page.click('[data-testid="submit"]')
  await page.waitForURL((url) => url.pathname !== '/login')
  return { context, page }
}

async function settle(page) {
  await page.waitForFunction(() => !document.querySelector('[aria-busy="true"]'))
  await page.waitForTimeout(300)
}

async function useTransport(page, name) {
  await page.click(`[data-testid="transport-${name}"]`)
  await settle(page)
}

async function openTrafficPanel(page) {
  if (!(await page.locator('[data-testid="api-log"]').isVisible())) {
    await page.click('[data-testid="api-log-toggle"]')
  }
}

const shot = (page, name) => page.screenshot({ path: `${OUT}${name}.png` })

// Event list, light and dark, after a REST load and a GraphQL load so the panel shows both.
for (const colorScheme of ['light', 'dark']) {
  const { context, page } = await session({ colorScheme })
  await useTransport(page, 'rest')
  await page.goto(BASE)
  await page.locator('[data-testid="event-card"]').first().waitFor()
  await settle(page)
  await openTrafficPanel(page)
  await useTransport(page, 'graphql')
  await shot(page, `events-${colorScheme}`)
  await context.close()
}

// Event detail, dark.
{
  const { context, page } = await session({ colorScheme: 'dark' })
  await page.goto(`${BASE}/?q=GraphQL+in+production`)
  await page.locator('[data-testid="event-card"]').first().click()
  await page.locator('[data-testid="detail-spots-left"]').waitFor()
  await settle(page)
  await shot(page, 'event-detail-dark')
  await context.close()
}

// Phone width.
{
  const { context, page } = await session({ viewport: { width: 390, height: 844 } })
  await page.goto(BASE)
  await page.locator('[data-testid="event-card"]').first().waitFor()
  await settle(page)
  await shot(page, 'events-mobile')
  await context.close()
}

// The toggle on "My events": two REST calls become one GraphQL query.
{
  const { context, page } = await session({ video: true, viewport: { width: 1280, height: 720 } })
  await useTransport(page, 'rest')
  await page.goto(`${BASE}/me`)
  await settle(page)
  await openTrafficPanel(page)
  await page.getByRole('button', { name: 'Clear' }).click()
  await page.waitForTimeout(800)
  for (const transport of ['graphql', 'rest', 'graphql']) {
    await useTransport(page, transport)
    await page.waitForTimeout(1600)
  }
  const video = page.video()
  await context.close()
  const webm = await video.path()

  try {
    execFileSync('ffmpeg', [
      '-y', '-loglevel', 'error', '-i', webm,
      '-vf', 'fps=10,scale=800:-1:flags=lanczos,split[a][b];[a]palettegen=stats_mode=diff[p];[b][p]paletteuse=dither=bayer:bayer_scale=4',
      `${OUT}transport-toggle.gif`,
    ])
  } catch {
    console.warn('ffmpeg not available; keeping the WebM recording instead of a GIF')
    renameSync(webm, `${OUT}transport-toggle.webm`)
  }
  rmSync(VIDEO_DIR, { recursive: true, force: true })
}

// The API's own docs: the REST reference rendered from openapi.yaml, and GraphiQL
// with its default query run against the seeded data.
{
  const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 })
  const page = await context.newPage()
  await page.goto(`${API}/docs`)
  await page.getByRole('heading', { name: 'Gather REST API' }).waitFor()
  await page.waitForTimeout(500)
  await shot(page, 'api-docs')

  await page.goto(`${API}/graphiql`)
  await page.locator('.graphiql-execute-button').click()
  await page.waitForFunction(() => document.querySelector('.result-window')?.textContent?.includes('spotsLeft'))
  await shot(page, 'graphiql')
  await context.close()
}

await browser.close()
console.log(`Saved to ${OUT}`)
