# Gather

An events platform built **API-first**: a Laravel backend that serves a versioned **REST API** and a **GraphQL API**
over the same domain, plus a **separate Vue 3 SPA** that talks to it only over HTTP. They are two independent
deployables joined by a written contract ([`openapi.yaml`](openapi.yaml)), the way a backend team and a frontend team
would work.

**Status, open to-dos and next steps:** see [`Project_Summary.md`](Project_Summary.md).

```
gather/
├── openapi.yaml   ← the REST contract (source of truth for both sides)
├── api/           ← Laravel 13 · Sanctum · Lighthouse GraphQL · SQLite
└── web/           ← Vue 3 · TypeScript · Vite · Pinia · Tailwind 4
```

## Running it

Requirements: PHP 8.3+, Composer, Node 22.18+ (or 24.12+).

```sh
# API (http://localhost:8000)
cd api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed       # demo data; log in as demo@gather.test / password
php artisan serve

# SPA (http://localhost:5173), in a second terminal
cd web
npm install
cp .env.example .env             # VITE_API_URL=http://localhost:8000
npm run dev
```

The two run on different origins on purpose. If you change either port, keep `VITE_API_URL` (web) and
`CORS_ALLOWED_ORIGINS` (api) in sync.

- REST: `http://localhost:8000/api/v1/…`, documented in `openapi.yaml` (also served at `/openapi.yaml`).
- GraphQL: `POST http://localhost:8000/graphql`, schema in `api/graphql/schema.graphql`.

## What it demonstrates

### One domain, two transports, no duplication
REST controllers and GraphQL resolvers are thin adapters over the same layer:

| Concern | Shared implementation |
|---|---|
| Business operations and their validation | `app/Actions/*` (`CreateEvent`, `UpdateEvent`, `DeleteEvent`, `RegisterForEvent`, `CancelRegistration`) |
| Authorization | `app/Policies/EventPolicy.php` (`Gate::authorize` in REST, `@canRoot` and `Gate` in GraphQL) |
| Filtering, visibility and sorting | `Event::filter()` scope, used by `GET /events` and the `events` query |
| Business-rule errors | `app/Exceptions/DomainException` subclasses, rendered as a problem document in REST and as `extensions.code` in GraphQL |

A test asserts that REST and GraphQL return the **same events in the same order** for the same filters.

### Contract-first REST
- `openapi.yaml` is hand-written and is the source of truth.
- **Every REST request in the PHP test suite is also a contract test.** `tests/TestCase.php` validates each response
  against the spec for its route, method and status code. An undocumented status code or a missing or wrongly typed
  field fails the test. This caught a real bug during development: an undocumented 500 on `POST /events`.
- The SPA **generates its TypeScript types from the same file** (`npm run gen:api`) and calls the API through
  `openapi-fetch`, so paths, parameters, bodies and responses are checked at compile time. If a field changes in the
  spec, the frontend stops compiling.

### API design details
- **Versioned** under `/api/v1`, with consistent `{ "data": … }` envelopes and pagination `links` and `meta`.
- **RFC 9457 problem details** (`application/problem+json`) for every error: 401, 403, 404, 409, 422 (with
  per-field `errors`), 429 and 500. Business errors have stable `type` URIs (`/problems/event-full`,
  `/problems/capacity-conflict`, …).
- **Idempotent registration** as a singleton sub-resource: `PUT /events/{id}/registration` returns 201 the first time
  and 200 after that, and `DELETE` can be retried safely. The last spot is protected by a row lock.
- **Conditional GETs**: weak `ETag` and `If-None-Match` → `304`.
- **Rate limiting**: 120 requests/min per user or IP, and 10/min on the auth endpoints, keyed on IP + e-mail.
- **Stateless bearer tokens** (Sanctum personal access tokens). No cookies, so any client on any origin works the same
  way. CORS lists only the SPA's origin and exposes `ETag`, `Location` and `Retry-After`.
- **No information leaks**: drafts return 404 rather than 403 to non-organizers, e-mail addresses appear only on your
  own profile, and attendee lists are organizer-only.
- **UTC everywhere**: input with any offset is normalised and output is always `…Z`. Eloquent would otherwise silently
  drop the offset.
- **No N+1 queries**: a test asserts that the listing runs the same number of queries for 2 events as for 12.

### GraphQL design details
- Idiomatic schema: camelCase fields, `UPPER_CASE` enums, a `Viewer` type for private fields, nullable lookups
  (`event(id)` returns `null` when an event is hidden), `RegistrationPayload` mutation results, and Lighthouse
  pagination with `paginatorInfo`.
- **Query complexity and depth limits** reject expensive queries (for example aliasing several 50-item pages) before
  any SQL runs.
- Validation errors use GraphQL field names (`input.endsAt`), even though the validation rules are shared with REST.
- Auth is REST's job: GraphQL accepts the same bearer token but doesn't issue tokens.

### A decoupled SPA
- The views depend on an `EventsRepository` interface (`web/src/domain/repository.ts`), not on a transport. It has
  two implementations, `restRepository` and `graphqlRepository`.
- The **REST | GraphQL toggle** in the header swaps the implementation at runtime, and every screen keeps working
  unchanged.
- The **API traffic** panel (bottom right) logs each call with its transport, endpoint or operation, status, latency
  and size, so you can watch the difference. For example, "My events" takes two REST calls but one GraphQL query.
- Both transports' errors are normalised into one `ApiError`. A REST 409 `event-full` and a GraphQL `EVENT_FULL` look
  the same in the UI, and so do 422 field errors.

## Tests

| Suite | Command | What it covers |
|---|---|---|
| API feature tests | `cd api && php artisan test` | 49 tests: auth, listing/filtering, CRUD, policies, registration rules, problem documents, ETags, rate limits, N+1, GraphQL queries/mutations/errors/limits, REST↔GraphQL parity. Every REST response is validated against `openapi.yaml`. |
| SPA unit tests | `cd web && npx vitest run` | Error normalisation for both transports, REST and GraphQL mappers producing identical UI models, component rendering |
| Type check / lint | `cd web && npm run type-check && npm run lint` | Including the generated API types |
| End-to-end | `cd web && E2E_URL=http://localhost:5173 npm run test:e2e` | 13 checks in headless Edge against the real API, run through **both** transports: listing, filters, login, `event_full` rejection, register/cancel, server-side validation, create, "My events", phone layout, no console errors. Needs both servers running on a freshly seeded database. Set `E2E_BROWSER=chrome` to use Chrome. |

## Example requests

```sh
TOKEN=$(curl -s -X POST localhost:8000/api/v1/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"demo@gather.test","password":"password"}' | jq -r .token)

curl -s 'localhost:8000/api/v1/events?city=Berlin&has_spots=true&sort=-starts_at' -H "Authorization: Bearer $TOKEN"

# Event 19 is the seeded sold-out coffee cupping
curl -s -X PUT localhost:8000/api/v1/events/19/registration -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
# → 409 application/problem+json {"type":".../problems/event-full","title":"Event is full",...}

curl -s localhost:8000/graphql -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" -d '{"query":
  "{ me { name registeredEvents { title startsAt venue { city } } } }"}'
```
