# Gather web

A standalone Vue 3 + TypeScript SPA for Gather. It talks to the API only over HTTP and can switch between the REST
and GraphQL APIs at runtime. See the [project README](../README.md) for setup and design notes.

| Command | What it does |
|---|---|
| `npm run dev` | Dev server. Set `VITE_API_URL` in `.env` |
| `npm run gen:api` | Regenerate `src/api/schema.d.ts` from `../openapi.yaml` (run after changing the contract) |
| `npm run gen:graphql` | Regenerate `src/api/graphql.generated.ts` from `../schema.graphql` and validate every GraphQL operation against it |
| `npm run build` | Type-check and build |
| `npx vitest run` | Unit tests |
| `npm run lint` | oxlint + ESLint, applying fixes |
| `npm run lint:check` | oxlint + ESLint without fixing (for CI) |
| `npm run test:e2e` | End-to-end smoke test in headless Chromium (needs the API and dev server running; see below) |
| `npm run test:e2e -- --dark` | The same in dark mode, saving `*-dark.png` screenshots |
| `npm run capture` | Regenerate the README screenshots and toggle GIF in `../docs/screenshots/` (same setup as the e2e test; the GIF needs ffmpeg) |

The e2e test uses Playwright's bundled Chromium by default; install it once with
`npx playwright-core install chromium`. To use an installed browser instead, set `E2E_BROWSER` to a channel such as
`msedge` or `chrome`. `E2E_URL` points it at the SPA (default `http://localhost:5173`), and `E2E_DARK=1` is the same as
`--dark`. Screenshots land in `e2e/screenshots/`.

**Note:** `openapi-typescript` declares a peer dependency on TypeScript 5, but this project uses TypeScript 6. An
`overrides` entry in `package.json` lets it use the project's TypeScript. It is a code generator only, and its output
was checked by the type-check, tests and build.

Layout: `src/api` (HTTP clients, generated types, errors), `src/domain` (UI model, `EventsRepository` and its REST and
GraphQL implementations), `src/stores` (auth, transport), `src/views`, `src/components`.
