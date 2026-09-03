# WebMCP evals (portal skills)

The dependency lives in `evals/package.json` (its own package — deliberately NOT in the app's root `package.json`, so `npm ci` for the app never pulls the eval tool's ~111 transitive packages). Run `npm install` inside `evals/` on the eval machine, or rely on `npx` fetching it. Running `npx webmcp-evals local` needs a model API key on the **eval machine only**. Never put a key in the sandbox, `.env` committed to this repo, or the worktree.

## Running the evals

The harness needs an API key for the eval backend; keep it on the eval side (`evals/.env`), never in the app.

## Against the demo

The one-container demo (`docker compose up` from this repo) is the demo surface. Do **not** run the Gemini-backed `npx webmcp-evals local` job against it on billed quota.

| | |
|---|---|
| Storefront | http://localhost:8090 |
| Portal login | http://app.localhost:8090/login |
| Email | `demo@camino.example` |
| Password | `webmcp-demo` |
| Pages | http://app.localhost:8090/sites/64 |
| Editor shell | http://app.localhost:8090/sites/64/pages/{homePageId}/editor |

`EDITOR_AGENT_TOOLS=true`, `EDITOR_AGENT_TOOLS_ROLES=staff,client`, and `EDITOR_AGENT_TOOLS_CLIENT_PORTAL=true` are already in `.env.demo`. After login, portal Pages / Design seed the `portal_base` set; Shop pages seed `CommerceOperations::SANDBOX`; the editor shell seeds the site's exposure set. Demo mode hides `generate_image`, `generate_logo_concepts`, `regenerate_hero`, and `manage_video`.

Subset command — local schema/case validation only (Pest, SQLite in-memory, no model key):

```bash
# after a local `composer install` (the demo image is built --no-dev, so Pest is not inside the container)
vendor/bin/pest --compact tests/Feature/Shop/Agent/WebmcpEvalsCasesTest.php
```

That pins: cases.json is well-formed, every expected tool exists in `OperationRegistry` / `schemas.json`, and no case calls a `publish` write. It does **not** score an LLM.

Front-2 dogfood (fake `document.modelContext`, `sync()`, one read + one write) is `docs/provenance/demo/dogfood.mjs`.

## Deterministic vs probabilistic

Pest on this repo pins **our** side of the contract: the three skill ops are registered, zero-argument, read-only (no revision bump), gated like `describe_import_products`, and they return live `current_state` plus a protocol that names real tools. That is deterministic.

`webmcp-evals local` pins **the model's** side: given the advertised schemas, does an LLM pick the right tool with the right parameters (skill first on "import this flyer", `media_id` not a path, `expected_revision` on mutations, no publish call). That is probabilistic. A red eval is a measurement, not a Layer 0 bug.
