# Design decisions and build notes

Why Gather is built the way it is, the bugs its tests caught, and the trade-offs it deliberately leaves open.

## Decisions

### Two deployables joined by a written contract
The API and the SPA are separate projects on separate origins. They share nothing but two contract files, `openapi.yaml` for REST and `schema.graphql` for GraphQL, so they work the way a backend team and a frontend team would. Keeping the contract honest is automated on both sides:

- **API:** `tests/TestCase.php` overrides `json()`. Every REST call in the PHPUnit suite is validated against the spec: the response always, and the request whenever the API accepts it. An undocumented status code, a missing or mistyped field, or an accepted request that breaks the documented constraints all fail the test. Extra fields and query parameters aren't rejected (the schemas don't use `additionalProperties: false`), so the contract guards what is documented rather than proving nothing else exists.
- **SPA:** the REST client's types are generated from the same file (`npm run gen:api`). CI regenerates them and fails if the committed `schema.d.ts` is stale.
- **GraphQL:** the Lighthouse schema is written for the server (directives, resolvers), so the compiled client-facing schema is exported to `schema.graphql` (`composer schema:graphql`) and committed. CI re-exports it and fails on any difference, then regenerates the SPA's operation types from it with `graphql-codegen` and fails if they are stale. The hand-written query strings stay as they are; codegen validates them against the schema and types their results and variables.

### One domain layer, two transports
REST controllers and GraphQL resolvers are thin adapters over `app/Actions`, `EventPolicy`, the `Event::filter()` scope and the `DomainException` hierarchy. A test asserts that both APIs return the same events in the same order for the same filters. Validation rules live in the actions rather than in FormRequests, so GraphQL gets them too. The GraphQL layer only renames error keys (`starts_at` → `input.startsAt`).

### Bearer tokens, not cookies
Sanctum personal access tokens keep the API stateless. Any client on any origin (the SPA, a mobile app, `curl`) authenticates the same way, with no CSRF dance. CORS lists only the SPA's origin.

### Errors are RFC 9457 problem documents
Every error uses one shape, `application/problem+json`. Business rules get stable `type` URIs (`/problems/event-full`). GraphQL carries the same codes in `extensions.code` (`EVENT_FULL`), and the SPA normalises both into one `ApiError`.

### Hide drafts with 404, not 403
Someone else's draft is "not found" for reads *and* writes (`PATCH`, `DELETE`, `/attendees`, and the GraphQL mutations). A 403 would confirm that the draft exists.

### A change feed with tombstones
Sync clients (a mobile app's offline cache, a calendar export) need "what changed since I last looked", not page 1 again. `GET /events?updated_since=` and GraphQL's `eventChanges` return changes oldest first through keyset cursors (`updated_at`, then `id`). Offset pages would skip rows here: an event updated while a client pages through the feed moves to the end, shifting everything after it. Lighthouse's built-in connection cursors are offsets in disguise, so `eventChanges` uses its own resolver. Deletions matter as much as updates, so events are soft-deleted and appear in the feed as tombstones with `deleted_at` set; everywhere else a deleted event is simply a 404.

### Idempotent creates
A client that times out on `POST /events` can't tell whether the event was created. With an `Idempotency-Key` it can just retry: the first response is stored for 24 hours (keyed on caller, route and key) and replayed for repeats, a lock turns concurrent duplicates into a 409, and a different body under the same key is rejected instead of silently returning an unrelated event. Server errors aren't stored, so a retry after a 500 really runs again. The SPA keeps a key only while retrying after network failures; any answer from the server starts a new attempt, so fixing a validation error doesn't trip the reused-key check.

### Accounts confirmed by e-mail, without revealing who has one
Registration never says "that address is taken": it always answers `202 Check your inbox`. A new address gets a confirmation link; an address that already has an account gets an e-mail telling its owner so. An address that registered but never confirmed gets a fresh link, and the new name and password replace the old ones: until someone proves they own the inbox the account belongs to nobody, and keeping the first password would let someone register another person's address in advance and keep access after they confirm it. The password is hashed on every path, and the e-mails are queued, so the paths take about as long. Resending a link and failed logins answer the same way, so no endpoint can be used to test which addresses are registered. Only someone with the right password learns that an account is unconfirmed (`403 email-not-verified`), and that tells them nothing new.

The link is a signed URL with a one-hour expiry. The e-mail points at the SPA (`/verify-email`), which posts the signed parameters to the API; the signature is relative (path and query only), so it doesn't depend on the API's host. Confirming logs the user in, so no tokens exist for unconfirmed accounts at all.

### Short-lived access tokens, rotating refresh tokens
Access tokens last an hour, so a leaked one is useful only briefly. Each login also gets a 30-day refresh token (stored hashed, like Sanctum's tokens) that `POST /auth/refresh` exchanges for a new pair; the old pair is retired. All tokens descended from one login share a family, and presenting an already-used refresh token revokes the whole family: either an attacker or the real client is replaying a token, and the server can't tell which, so both must log in again. Logging out revokes the family too. A token is claimed with a single conditional `UPDATE … WHERE used_at IS NULL`, so of two concurrent refreshes exactly one wins (row locks would do nothing on SQLite); a duplicate within ten seconds of the first use is refused without revoking anything, because that's a client race (two tabs, a retry after a lost response), not theft.

The SPA refreshes on the first 401 and retries the request once, for REST and GraphQL alike. Refreshes never overlap: requests in one tab share a refresh, tabs take turns through a Web Lock (a tab that waited finds the token already rotated and just uses the new pair), and tokens are read from `localStorage` on every use, so tabs never hold a stale copy. A request that was sent with an older access token is retried with the current one instead of triggering another rotation. Only a definite "no" from the API ends the session; a network failure or 5xx keeps both tokens for later.

### UTC everywhere, offsets required
Input must be ISO 8601 with an offset. `Iso8601DateTime` is shared by REST validation and the GraphQL `DateTime` scalar. Instants are stored and returned in UTC (`…Z`). Without this, Eloquent silently drops a client's offset, and Laravel's `date` rule accepts strings like "next friday".

## Bugs the tests caught

- **Lighthouse's query cache vs. Laravel 13.** Laravel 13 restricts which classes the cache may unserialize (`cache.serializable_classes`). Under Lighthouse's default `store` mode, the second run of a query got back `__PHP_Incomplete_Class` and failed with a 500. The fix is the `opcache` mode, which is also faster. The e2e test found this, not the unit tests: the array cache used in tests never serializes. A regression test now runs against a serializing store.
- **A null `status` on new events.** The column's database default isn't loaded onto a freshly created model, so the response had `status: null`. Contract validation rejected the response, and the model now declares a matching default.
- **"Has spots" matched unbookable events.** Cancelled events and the viewer's own drafts matched `has_spots`. The e2e test sometimes opened one and found no Register button, depending on the random seed data. The filter now matches published events only, in both APIs. That bug is also why the demo seeder now uses a fixed Faker seed.

## Known trade-offs

These are deliberate omissions for a demo, and each would come up before a production launch:

- **Refresh tokens in `localStorage`.** Like the access token, the refresh token is readable by any script on the SPA's origin, so an XSS bug could steal a login for up to 30 days (rotation and reuse detection limit, but don't prevent, that). The alternative, an `HttpOnly` cookie on a refresh endpoint, would mean cookies and CSRF handling for one route, against the "no cookies, any client on any origin" design.
- **The queue runs inline locally.** Account e-mails are queued so that whether one is sent doesn't show in the response time, but `.env.example` uses the `sync` queue (mail goes to the log), which sends them during the request. Production needs a real queue worker for the timing guarantee to hold.
- **Moving an event back to draft hides it from the change feed.** Other users can't see drafts, so the feed can't send them a tombstone without revealing the draft (and any edits to it). The event simply stops appearing; long-lived sync clients should re-sync from scratch now and then.
- **No password reset.** It would reuse the signed-link flow the confirmation e-mail already uses.
- **Locking on SQLite.** Registration and capacity changes lock the event row. That serialises them on MySQL and PostgreSQL. SQLite ignores `FOR UPDATE` but serialises writers anyway, and a duplicate concurrent registration falls back to the unique index.
- **Idempotency only on `POST /events`.** The middleware works on any write, but only event creation needs it: registration is already an idempotent `PUT`, and updates and deletes are naturally safe to repeat. GraphQL mutations don't take a key.
- **`/me/registrations` isn't paginated.** It returns upcoming events only, which stays small for a person.
