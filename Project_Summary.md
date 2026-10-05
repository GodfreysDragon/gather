# Project Summary: Gather (API-first backend + separate Vue SPA)

_Last updated: 2026-10-05_

## What it is

Gather is an events platform: venues, events, and registrations with capacity limits. It is built to show
**API-first development with a frontend decoupled from the backend**:
- a Laravel backend that serves a versioned **REST API** and a **GraphQL API** over one shared domain layer;
- a separate **Vue 3 SPA** that talks to it only over HTTP, from a different origin.

The two halves are joined by a hand-written OpenAPI contract that both sides are checked against. This is how a
backend team and a separate frontend team would work.

**Stack:** Laravel 13 · PHP 8.4 · Sanctum (bearer tokens) · Lighthouse 6 (GraphQL) · SQLite · Vue 3 · TypeScript 6 ·
Vite 8 · Pinia · Tailwind 4 · openapi-typescript + openapi-fetch · PHPUnit · Vitest · playwright-core

## What was built

### Backend (`api/`)
- **Shared domain layer:**
  - actions with their own validation (create, update or delete an event; register or cancel);
  - `EventPolicy`;
  - the `Event::filter()` scope;
  - `DomainException` types (event full, registration closed, capacity conflict, event has registrations, not found).
- **REST `/api/v1`** (17 routes):
  - auth: register, login, logout;
  - `me` and `me/registrations`;
  - events: CRUD with filtering, sorting and pagination;
  - an organizer-only attendee list;
  - an idempotent `PUT`/`DELETE` registration;
  - venues.
- **REST conventions:**
  - RFC 9457 problem documents for every error;
  - `Location` headers on create;
  - weak ETags with `304` responses;
  - rate limits (stricter on auth);
  - CORS limited to the SPA's origin;
  - drafts return 404 rather than 403, so their existence isn't revealed.
- **GraphQL:**
  - `events` (paginated, the same filters as REST), `event`, `venues` and `me: Viewer`;
  - mutations for create, update and delete, and for registering and cancelling;
  - business errors carry `extensions.code`;
  - validation keys use GraphQL field names;
  - query depth and complexity limits.
- **`openapi.yaml` (OpenAPI 3.0)** documents every endpoint, status code and schema.
- **Demo seeder:** 5 venues, about 21 events (one sold out, one cancelled, one draft) and 45 users. Log in as
  `demo@gather.test` / `password`.

### Frontend (`web/`)
- Views depend on an `EventsRepository` interface with two implementations, **REST and GraphQL**. A header toggle
  swaps between them at runtime and no view changes.
- **REST client types are generated from `openapi.yaml`**, so contract drift is a compile error.
- Errors from both transports are normalised into one `ApiError` (code, message, camelCase field errors).
- **Screens:**
  - an event list with search, city, free-spots and sort filters (synced to the URL) and pagination;
  - event detail with register/cancel and organizer tools (edit, cancel, delete, attendees);
  - create/edit forms that show server validation errors per field;
  - "My events";
  - log in and register, including a demo-account shortcut.
- An **API traffic panel** logs each call's transport, endpoint or operation, status, latency and size.
- Responsive layout and automatic dark mode.

## Verification status

| Area | Status |
|---|---|
| API feature tests (PHPUnit) | ✅ 49/49 passing (275 assertions) |
| Every REST response in those tests validated against `openapi.yaml` | ✅ Built into `tests/TestCase.php` |
| REST ↔ GraphQL parity (same filters → same events, same order) | ✅ Tested |
| No N+1 in the event listing | ✅ Tested (constant query count) |
| SPA unit tests (Vitest) | ✅ 9/9 passing |
| SPA type-check, lint, production build | ✅ Clean |
| End-to-end: 13 checks in headless Edge against the real API, through both transports | ✅ Passing |
| Phone-width layout | ✅ Checked by the e2e test (no horizontal scroll) and with screenshots |
| Dark mode | ⚠️ Implemented with Tailwind `dark:` variants, not visually checked |

## Notes from the build
- **Bug found by the e2e test: Lighthouse's query cache vs. Laravel 13.** Laravel 13 restricts which classes the cache
  may unserialize (`cache.serializable_classes`). Lighthouse's default `store` query-cache mode therefore returned
  `__PHP_Incomplete_Class` the second time a query ran, which caused a 500. The fix was the `opcache` mode, which is
  also faster. A regression test fails under the old mode and passes under the new one. The unit tests didn't catch
  this because the array cache in tests doesn't serialize.
- **Contract test caught a bug:** a newly created event had a null `status`, because the column's database default
  isn't loaded onto the model. The undocumented 500 failed contract validation, and the model now has a matching
  default.
- **Port clashes on this machine:** ports 8000, 5173 and 8020 were already used by other projects, and Windows let a
  second server bind the same port without an error. The e2e run used free ports and pointed the two sides at each
  other through env vars:
  ```sh
  # api/
  CORS_ALLOWED_ORIGINS=http://localhost:5181 php artisan serve --port=8765 --no-reload
  # web/
  VITE_API_URL=http://localhost:8765 npx vite --port 5181
  E2E_URL=http://localhost:5181 npm run test:e2e
  ```
  (In PowerShell, set the variables with `$env:NAME='…'` first.) `--no-reload` is needed so `artisan serve` passes
  the environment variable through.
- **One intermittent failure:** in one of five e2e runs, a login request failed in the browser with a network error
  even though the server logged it. It didn't reproduce in a dedicated debug run or in the later full runs. The likely
  cause is PHP's single-threaded development server on Windows, but this isn't confirmed. Worth watching if it recurs.
- **The "has spots" filter included unbookable events:** cancelled events and the viewer's own drafts matched
  `has_spots`, so the e2e test sometimes opened one and found no Register button, depending on the random seed data.
  The filter now matches published events only, through both REST and GraphQL, and a regression test covers it.
- `openapi-typescript` declares a TypeScript 5 peer dependency. An npm `overrides` entry lets it use TypeScript 6.

## To-Dos
Loose ends in what already exists:
- [ ] **Check dark mode visually.** It's implemented but has never been looked at. Take e2e screenshots with
      `colorScheme: 'dark'` and review them.
- [ ] **Watch the intermittent login network error** (see Notes above). If it recurs, try the e2e run against
      `php artisan serve` with `PHP_CLI_SERVER_WORKERS=4`, or against Herd/nginx, to rule out the single-threaded server.
- [ ] **Make the e2e data deterministic.** The seeder uses random Faker data, which is how the "has spots" bug stayed
      hidden. Seed Faker with a fixed value (`fake()->seed(…)`) in the demo seeder, or give the e2e test its own fixture
      seeder.
- [ ] **Make local ports configurable.** The defaults (8000/5173) clash with other projects here. Document a
      `.env` pair (`APP_URL`/`CORS_ALLOWED_ORIGINS` in `api/`, `VITE_API_URL` in `web/`) or add a `composer dev` script
      that starts both on agreed ports.
- [ ] **Initialise git.** `gather/` isn't a repository yet. Run `git init`, check both `.gitignore` files (keep
      `.env`, `database.sqlite`, `dist/`, `e2e/screenshots/` out), and make a first commit.
- [ ] **Prepare for production.** Swap SQLite for Postgres or MySQL, set `CORS_ALLOWED_ORIGINS` and the token
      expiry (`sanctum.expiration`) for the real SPA domain, and serve `web/dist` from a static host or CDN.

## Next steps
1. Generate GraphQL types too (`graphql-codegen`), so the GraphQL adapter is checked at compile time like REST.
2. Cursor pagination for the event list, plus a `GET /events?updated_since=` feed for sync clients.
3. Idempotency keys (`Idempotency-Key` header) for `POST /events`.
4. Publish the OpenAPI docs (Redoc or Scalar) and the GraphQL playground at the API root.
5. CI pipeline: PHPUnit, Vitest, type-check, plus a check that `schema.d.ts` is regenerated whenever `openapi.yaml`
   changes.
