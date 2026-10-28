# Gather

[![CI](https://github.com/GodfreysDragon/gather/actions/workflows/ci.yml/badge.svg)](https://github.com/GodfreysDragon/gather/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

An events platform built **API-first**. A Laravel backend serves a versioned **REST API** and a **GraphQL API** over one
shared domain layer. A **separate Vue 3 SPA** talks to the backend only over HTTP, from another origin, and can switch
between the two APIs at runtime. The two halves are joined by a written contract ([`openapi.yaml`](openapi.yaml)) that
both sides are checked against in CI. This is how a backend team and a frontend team would work.

![Event list with the API traffic panel showing the same page loaded over REST and then GraphQL](docs/screenshots/events-light.png)

<p align="center">
  <img src="docs/screenshots/transport-toggle.gif" width="720"
       alt="Toggling between REST and GraphQL on the My events page: two REST calls become one GraphQL query">
  <br><em>Same screen, two transports: "My events" takes two REST calls or one GraphQL query.</em>
</p>

| Dark mode | Phone |
|---|---|
| <img src="docs/screenshots/event-detail-dark.png" alt="Event detail page in dark mode" width="560"> | <img src="docs/screenshots/events-mobile.png" alt="Event list at phone width" width="220"> |

| REST reference at `/docs` | GraphQL playground at `/graphiql` |
|---|---|
| <img src="docs/screenshots/api-docs.png" alt="Interactive REST API reference rendered from openapi.yaml" width="420"> | <img src="docs/screenshots/graphiql.png" alt="GraphiQL running a query against the API" width="420"> |

## Architecture

```mermaid
flowchart LR
    subgraph web["web/ · Vue 3 SPA"]
        views["Views"] --> repo{{"EventsRepository"}}
        repo --> restRepo["restRepository<br/>(openapi-fetch)"]
        repo --> gqlRepo["graphqlRepository"]
    end

    subgraph api["api/ · Laravel 13"]
        rest["REST /api/v1<br/>controllers"] --> domain
        gql["GraphQL /graphql<br/>Lighthouse resolvers"] --> domain
        domain["Actions · EventPolicy<br/>Event::filter() · DomainExceptions"] --> db[("SQLite /<br/>MySQL / Postgres")]
    end

    restRepo -- "bearer token" --> rest
    gqlRepo -- "bearer token" --> gql

    spec[/"openapi.yaml"/]
    spec -. "validates every request &amp; response<br/>in the PHPUnit suite" .-> rest
    spec -. "generates TypeScript types<br/>(npm run gen:api)" .-> restRepo

    sdl[/"schema.graphql"/]
    gql -. "exported by<br/>composer schema:graphql" .-> sdl
    sdl -. "generates operation types<br/>(npm run gen:graphql)" .-> gqlRepo
```

```
gather/
├── openapi.yaml   ← the REST contract (source of truth for both sides)
├── schema.graphql ← the compiled GraphQL schema the SPA's types are generated from
├── api/           ← Laravel 13 · Sanctum · Lighthouse GraphQL · SQLite
├── web/           ← Vue 3 · TypeScript · Vite · Pinia · Tailwind 4
└── docs/          ← design decisions, screenshots
```

## Running it

Requirements: PHP 8.3+ with `pdo_sqlite`, Composer, and Node 22.18+ (or 24.12+).

```sh
# API on http://localhost:8000
cd api
composer setup                   # install, .env, app key, SQLite file, migrate + seed
php artisan serve

# SPA on http://localhost:5173, in a second terminal
cd web
npm install
cp .env.example .env             # VITE_API_URL=http://localhost:8000
npm run dev
```

Log in as **`demo@gather.test` / `password`**, or as **`admin@gather.test` / `password`** to create venues. The demo
data is the same on every seed. New accounts confirm their address by e-mail; locally, mail goes to the log
(`MAIL_MAILER=log`), so the confirmation link is in `api/storage/logs/laravel.log`.

The two run on different origins on purpose. If you change either port, keep `VITE_API_URL` (web) and
`CORS_ALLOWED_ORIGINS` (api) in sync.

- REST: `http://localhost:8000/api/v1/…`, documented in `openapi.yaml` (also served at `/openapi.yaml`).
- GraphQL: `POST http://localhost:8000/graphql`, with the schema in `api/graphql/schema.graphql`.
- **Interactive docs:** the REST reference at `http://localhost:8000/docs` (Scalar, rendered from `openapi.yaml`, with
  "try it" against the running API) and a GraphQL playground at `http://localhost:8000/graphiql`.

## What it demonstrates

### One domain, two transports, no duplication
REST controllers and GraphQL resolvers are thin adapters over the same layer:

| Concern | Shared implementation |
|---|---|
| Business operations and their validation | `app/Actions/*` (`CreateEvent`, `UpdateEvent`, `DeleteEvent`, `RegisterForEvent`, `CancelRegistration`) |
| Authorization | `app/Policies/EventPolicy.php` (`Gate::authorize` in REST, `@canRoot` and `Gate` in GraphQL) |
| Filtering, visibility and sorting | `Event::filter()` scope, used by `GET /events` and the `events` query |
| Business-rule errors | `app/Exceptions/DomainException` subclasses, rendered as a problem document in REST and as `extensions.code` in GraphQL |
| Date-time input | `app/Rules/Iso8601DateTime`, used by REST validation and the GraphQL `DateTime` scalar |

A test asserts that REST and GraphQL return the **same events in the same order** for the same filters.

### Contract-first REST
- `openapi.yaml` is hand-written, and it is the source of truth.
- **Every REST call in the PHP test suite is also a contract test.** `tests/TestCase.php` validates each response
  against the spec for its route, method and status code. Each request the API accepts is validated as well. The
  test fails on an undocumented status code, a missing or wrongly typed field, or a request the server accepts that
  breaks the documented constraints. (Like most OpenAPI validators it doesn't reject *extra* fields or query
  parameters, and GraphQL calls are checked by the GraphQL schema instead.)
- The SPA **generates its TypeScript types from the same file** (`npm run gen:api`) and calls the API through
  `openapi-fetch`, so paths, parameters, bodies and responses are checked at compile time. CI fails if the generated
  types are stale.

### Typed GraphQL too
- The compiled schema is committed as [`schema.graphql`](schema.graphql) (`composer schema:graphql` in `api/`), and
  CI fails if it no longer matches the API.
- `graphql-codegen` validates every operation in `graphqlRepository.ts` against it and generates their result and
  variable types (`npm run gen:graphql`). Querying a removed field fails code generation; changing a field's type
  fails `npm run type-check`. CI fails if the generated types are stale.

### API design details
- **Versioned** under `/api/v1`, with consistent `{ "data": … }` envelopes and pagination `links` and `meta`.
- **Page numbers or cursors**: `GET /events` pages by number by default; `pagination=cursor` switches to keyset
  cursors, which stay correct while events are added.
- **A change feed for sync clients**: `GET /events?updated_since=…` returns everything changed since then, oldest
  first, always cursor-paginated so a client can resume where it stopped. Deleted events are soft-deleted and show up
  as tombstones (`deleted_at` set), so clients learn about deletions too. GraphQL has the same feed as `eventChanges`.
- **RFC 9457 problem details** (`application/problem+json`) for every error: 401, 403, 404, 409, 422 (with per-field
  `errors`), 429 and 500. Business errors have stable `type` URIs, such as `/problems/event-full` and
  `/problems/capacity-conflict`.
- **Idempotent registration** as a singleton sub-resource:
  - `PUT /events/{id}/registration` returns 201 the first time and 200 after that;
  - `DELETE` can be retried safely;
  - the last spot is protected by a lock on the event row, and duplicate concurrent requests resolve to the same
    registration.
- **Retry-safe creates**: `POST /events` accepts an `Idempotency-Key` header. A repeat with the same key and body
  replays the first response (`Idempotent-Replayed: true`) instead of creating a second event; the same key with a
  different body is a 422, and a duplicate of a request still running is a 409. The SPA sends one key per attempt, so
  a double click or a retry after a dropped connection creates one event.
- **Business rules hold through every path:**
  - capacity can't drop below the current registrations;
  - an event's capacity must fit its venue, also after a venue change;
  - an event people registered for can't go back to draft.
- **Conditional GETs**: weak `ETag` and `If-None-Match` → `304`.
- **Rate limiting**: 120 requests/min per user or IP. The auth endpoints allow 10/min per IP for each thing they
  guard (an e-mail address, a refresh token, a confirmation link), so people sharing an office IP don't block each
  other, plus 30 credential attempts/min per IP across addresses.
- **Stateless bearer tokens with rotating refresh tokens**: Sanctum access tokens last an hour; `POST /auth/refresh`
  trades the single-use refresh token (30 days) for a new pair. Replaying a used refresh token is treated as theft
  and revokes the whole login (a duplicate within seconds, such as two tabs racing, is refused but not punished). The
  SPA refreshes and retries transparently, over REST and GraphQL alike: one refresh at a time across all open tabs,
  a request that raced a refresh is simply retried with the new token, and a refresh that fails because the API is
  unreachable keeps the session instead of logging out. There are no cookies, so any client on any origin works the same way. CORS lists
  only the SPA's origin and exposes `ETag`, `Location`, `Retry-After` and `Idempotent-Replayed`.
- **Accounts confirmed by e-mail**: registering sends a signed, one-hour link; following it confirms the address and
  logs the user in. No tokens are issued before that.
- **Roles where data is shared**: only admins can add venues, since every organizer uses them.
- **No existence leaks**:
  - someone else's draft is a 404, never a 403, for reads and writes alike;
  - registering, resending a link and failed logins answer the same whether or not the e-mail has an account (a
    taken address gets a "you already have an account" e-mail instead of an error);
  - e-mail addresses appear only on your own profile;
  - attendee lists are organizer-only.
- **UTC everywhere**: input must carry an offset, and output is always `…Z`.
- **No N+1 queries**: a test asserts that the listing runs the same number of queries for 2 events as for 12.

### GraphQL design details
- An idiomatic schema:
  - camelCase fields and `UPPER_CASE` enums;
  - a `Viewer` type for private fields;
  - nullable lookups (`event(id)` returns `null` when an event is hidden);
  - `RegistrationPayload` mutation results;
  - Lighthouse pagination with `paginatorInfo`.
- **Query complexity and depth limits** reject expensive queries, such as aliasing several 50-item pages, before any
  SQL runs. Introspection gets its own, higher depth cap (15 instead of 8), so GraphiQL and code generators work but a
  cyclic `ofType { fields { type … } }` query can't ask for an exponentially large response.
- Validation errors use GraphQL field names (`input.endsAt`), even though the validation rules are shared with REST.
- Authentication belongs to REST: GraphQL accepts the same bearer token but doesn't issue tokens.

### A decoupled SPA
- The views depend on an `EventsRepository` interface (`web/src/domain/repository.ts`), not on a transport. It has
  two implementations, `restRepository` and `graphqlRepository`.
- The **REST | GraphQL toggle** in the header swaps the implementation at runtime, and every screen reloads through
  the other API unchanged.
- The **API traffic** panel logs each call with its transport, endpoint or operation, status, latency and size.
- Both transports' errors are normalised into one `ApiError`. A REST 409 `event-full` and a GraphQL `EVENT_FULL`
  look the same in the UI, and so do 422 field errors.
- **Robust data loading**: only the latest response wins, so a slow request never overwrites a newer one. An expired
  access token is refreshed silently; only when the login itself has ended does the app send you to log in, then back
  to where you were (local paths only, so `?redirect=` can't send you off-site).
- **Accessible**:
  - labelled form errors (`aria-describedby`), with focus moved to the first invalid field;
  - focus moved to the page on navigation;
  - a native radio group for the transport toggle;
  - a `meter` for capacity.
- Responsive, with automatic dark mode.

## Tests

| Suite | Command | What it covers |
|---|---|---|
| API feature tests | `cd api && php artisan test` | 97 tests: auth, e-mail confirmation, token refresh and reuse detection, listing/filtering, cursor pagination and the change feed, CRUD, policies, registration rules, idempotent creates, problem documents, ETags, rate limits, N+1, GraphQL queries/mutations/errors/limits, REST↔GraphQL parity, docs pages. Every REST request and response is validated against `openapi.yaml`. |
| SPA unit tests | `cd web && npx vitest run` | 74 tests: error normalisation for both transports, REST and GraphQL mappers producing identical UI models, stale-response handling, session expiry, transparent token refresh, registration and e-mail confirmation screens, idempotency keys, form validation and focus, components |
| Type check / lint | `cd web && npm run type-check && npm run lint:check` | Including the generated REST and GraphQL types |
| End-to-end | `cd web && E2E_URL=http://localhost:5173 npm run test:e2e` | 15 checks in headless Chromium against the real API, through **both** transports: listing, filters, login, `event_full` rejection, register/cancel, server-side validation, create, "My events", a transparent token refresh, signing up through the e-mailed confirmation link (read from the API's mail log), phone layout, no console errors. `npm run test:e2e -- --dark` repeats it in dark mode. Needs both servers running on a freshly seeded database. |

All four run in [GitHub Actions](.github/workflows/ci.yml) on every push. The end-to-end job seeds a database, serves
the production build and uploads its screenshots as an artifact. `npm run capture` regenerates the images in this
README.

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

## Design notes

[`docs/DECISIONS.md`](docs/DECISIONS.md) covers why the project is built this way, the bugs the contract and
end-to-end tests caught during development, and the trade-offs it still leaves open.

## What's next

1. A production deployment: PostgreSQL, the API on a container host, the SPA on a CDN, and a real mail service.
2. Password reset, reusing the signed-link flow that confirms e-mail addresses.

## License

[MIT](LICENSE)
