# Design decisions and build notes

Why Gather is built the way it is, the bugs its tests caught, and the trade-offs it deliberately leaves open.

## Decisions

### Two deployables joined by a written contract
The API and the SPA are separate projects on separate origins. They share nothing but two contract files, `openapi.yaml` for REST and `schema.graphql` for GraphQL, so they work the way a backend team and a frontend team would. Keeping the contract honest is automated on both sides:

- **API:** `tests/TestCase.php` overrides `json()`. Every REST call in the PHPUnit suite is validated against the spec: the response always, and the request whenever the API accepts it. An undocumented status code, a missing field, or input the server accepts but the contract doesn't describe all fail the test.
- **SPA:** the REST client's types are generated from the same file (`npm run gen:api`). CI regenerates them and fails if the committed `schema.d.ts` is stale.
- **GraphQL:** the Lighthouse schema is written for the server (directives, resolvers), so the compiled client-facing schema is printed to `schema.graphql` and committed. CI reprints it and fails on any difference, then regenerates the SPA's operation types from it with `graphql-codegen` and fails if they are stale. The hand-written query strings stay as they are; codegen validates them against the schema and types their results and variables.

### One domain layer, two transports
REST controllers and GraphQL resolvers are thin adapters over `app/Actions`, `EventPolicy`, the `Event::filter()` scope and the `DomainException` hierarchy. A test asserts that both APIs return the same events in the same order for the same filters. Validation rules live in the actions rather than in FormRequests, so GraphQL gets them too. The GraphQL layer only renames error keys (`starts_at` → `input.startsAt`).

### Bearer tokens, not cookies
Sanctum personal access tokens keep the API stateless. Any client on any origin (the SPA, a mobile app, `curl`) authenticates the same way, with no CSRF dance. CORS lists only the SPA's origin.

### Errors are RFC 9457 problem documents
Every error uses one shape, `application/problem+json`. Business rules get stable `type` URIs (`/problems/event-full`). GraphQL carries the same codes in `extensions.code` (`EVENT_FULL`), and the SPA normalises both into one `ApiError`.

### Hide drafts with 404, not 403
Someone else's draft is "not found" for reads *and* writes (`PATCH`, `DELETE`, `/attendees`, and the GraphQL mutations). A 403 would confirm that the draft exists.

### UTC everywhere, offsets required
Input must be ISO 8601 with an offset. `Iso8601DateTime` is shared by REST validation and the GraphQL `DateTime` scalar. Instants are stored and returned in UTC (`…Z`). Without this, Eloquent silently drops a client's offset, and Laravel's `date` rule accepts strings like "next friday".

## Bugs the tests caught

- **Lighthouse's query cache vs. Laravel 13.** Laravel 13 restricts which classes the cache may unserialize (`cache.serializable_classes`). Under Lighthouse's default `store` mode, the second run of a query got back `__PHP_Incomplete_Class` and failed with a 500. The fix is the `opcache` mode, which is also faster. The e2e test found this, not the unit tests: the array cache used in tests never serializes. A regression test now runs against a serializing store.
- **A null `status` on new events.** The column's database default isn't loaded onto a freshly created model, so the response had `status: null`. Contract validation rejected the response, and the model now declares a matching default.
- **"Has spots" matched unbookable events.** Cancelled events and the viewer's own drafts matched `has_spots`. The e2e test sometimes opened one and found no Register button, depending on the random seed data. The filter now matches published events only, in both APIs. That bug is also why the demo seeder now uses a fixed Faker seed.

## Known trade-offs

These are deliberate omissions for a demo, and each would come up before a production launch:

- **Token lifetime.** Tokens don't expire (`sanctum.expiration` is `null`). Production would set an expiry and add refresh or re-login.
- **Venue creation.** Any signed-in user can create venues. A real product would add a policy or an admin role.
- **Account enumeration.** Registration reports a taken e-mail address, and login only hashes when the user exists, so timing differs. Both are common trade-offs for usability. Hardening would mean a generic response plus a dummy hash.
- **Locking on SQLite.** Registration and capacity changes lock the event row. That serialises them on MySQL and PostgreSQL. SQLite ignores `FOR UPDATE` but serialises writers anyway, and a duplicate concurrent registration falls back to the unique index.
- **`/me/registrations` isn't paginated.** It returns upcoming events only, which stays small for a person.
