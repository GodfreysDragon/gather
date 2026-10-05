# Gather API

The Laravel backend for Gather: a REST API (`/api/v1`) and a GraphQL API (`/graphql`) over one shared domain layer.
It has no web UI. The SPA lives in `../web`.

See the [project README](../README.md) for setup, design notes and tests. Quick reference:

| Where | What |
|---|---|
| `../openapi.yaml` | REST contract; every REST response in the tests is validated against it |
| `graphql/schema.graphql` | GraphQL schema (Lighthouse) |
| `app/Actions` | Business operations and validation, shared by both transports |
| `app/Policies/EventPolicy.php` | Authorization, shared by both transports |
| `app/Http/Problem.php` | RFC 9457 error rendering |
| `app/Http/Controllers/Api/V1`, `app/GraphQL` | Thin transport adapters |
