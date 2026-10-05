# Gather web

A standalone Vue 3 + TypeScript SPA for Gather. It talks to the API only over HTTP and can switch between the REST
and GraphQL APIs at runtime. See the [project README](../README.md) for setup and design notes.

| Command | What it does |
|---|---|
| `npm run dev` | Dev server. Set `VITE_API_URL` in `.env` |
| `npm run gen:api` | Regenerate `src/api/schema.d.ts` from `../openapi.yaml` (run after changing the contract) |
| `npm run build` | Type-check and build |
| `npx vitest run` | Unit tests |
| `npm run lint` | oxlint + ESLint |
| `npm run test:e2e` | End-to-end smoke test in headless Edge (needs the API and dev server running; set `E2E_URL`) |

**Note:** `openapi-typescript` declares a peer dependency on TypeScript 5, but this project uses TypeScript 6. An
`overrides` entry in `package.json` lets it use the project's TypeScript. It is a code generator only, and its output
was checked by the type-check, tests and build.

Layout: `src/api` (HTTP clients, generated types, errors), `src/domain` (UI model, `EventsRepository` and its REST and
GraphQL implementations), `src/stores` (auth, transport), `src/views`, `src/components`.
