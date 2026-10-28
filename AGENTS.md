# AGENTS.md

Webhook catcher + visual router. Requirements live in `SPEC.md`, usage in `README.md`.

## Shape
- Native PHP 8.3 (no Composer, no framework) + php-fpm, Vue 3 `<script setup>` + Vite + Pinia + Tailwind v4, Postgres 16, nginx.
- UI copy is Russian (`ru-RU` dates). Keep new strings and validation messages Russian, including API errors shown in the UI.
- Light theme is the default; `.dark` on `<html>` switches themes. Colors are CSS variables in `frontend/src/assets/main.css` (surfaces, text, lines) exposed as Tailwind tokens (`bg-surface`, `text-ink`, `border-line`). Do not hardcode `slate-*` surfaces in new UI.
- Native controls are wrapped: `frontend/src/components/ui/{AppInput,AppSelect,AppCheckbox,AppToggle,AppTextarea}.vue`. Use these instead of raw `<input>/<select>/<textarea>` so both themes, focus rings and sizes stay consistent.
- `AppSelect` is not a native `<select>`: the trigger is a `role="combobox"` button and the option list is teleported to `<body>` with `position: fixed` (keyboard nav, outside-click close, flip-up near the viewport edge, reposition on scroll). Pass `:options="[{ value, label }]"`; do not expect native `<option>` slots or OS dropdown styling.
- `backend/public/index.php` is the only PHP entrypoint. nginx routes `~ ^/(api|hook)/` to fpm and everything else to `index.html`, so PHP never renders HTML and SPA deep links work.
- No global auth middleware: each dashboard route calls `Auth::requireUser($request)` itself. Webhook routes are intentionally public — do not wrap them.
- Ingestion stores the request first, then runs `Services\WorkflowEngine` synchronously; it records `workflow_runs` (`success`/`failed`/`skipped`) and swallows action errors so the receiver still returns 202.
- `body`/`headers`/`query` are `jsonb`. `body` is NULL for non-JSON payloads; the original text stays in `raw_body`.
- Migrations: `backend/migrations/*.sql`, tracked in `schema_migrations`, applied by `backend/migrate.php` from the api entrypoint on every boot. Add a new numbered file; never edit an applied one.

## Commands (Windows PowerShell host)
- `docker compose up -d --build` — app at <http://localhost:8080> (`APP_PORT`).
- `powershell -ExecutionPolicy Bypass -File scripts/smoke.ps1` — e2e check: login, 401, ingestion, conditions/skip, non-JSON body, forward, pagination, cleanup.
- `docker compose logs -f api` · `docker compose exec api php migrate.php` · `docker compose exec db psql -U webhooks -d webhooks`.
- `docker compose down -v` wipes pgdata; admin is re-seeded on next boot.
- Frontend: `cd frontend && npm.cmd install && npm.cmd run build`. Use `npm.cmd` — `npm.ps1` is blocked by execution policy on this machine.
- Host `php -l` is fine for syntax, but host PHP has **no `pdo_pgsql`** — any migration or query must run inside the api container.
- No test/lint/typecheck tooling exists. Verify via `npm.cmd run build` + smoke script; do not claim success without running them.
- `scripts/smoke.ps1` now asserts JSONB columns arrive as arrays/objects and that a paused workflow can be created.

## Gotchas
- `docker/php/Dockerfile` must keep `libpq` installed at runtime; removing `postgresql-dev` without it breaks `pdo_pgsql` with a missing `libpq.so.5`.
- Build context is the repo root. `.dockerignore` excludes `node_modules`, `dist`, `scripts`; keep it lean — the checkout is OneDrive-synced and large contexts are slow.
- Container-bound scripts and nginx config need LF line endings.
- Tailwind v4 has no `tailwind.config.js`/PostCSS: theme is in `frontend/src/assets/main.css`, wired through `@tailwindcss/vite`.
- `{{ … }}` in Vue text is interpolation. For literal placeholders in UI hints use `v-pre` (see `WorkflowEditor.vue`).
- JWT is HS256 in `localStorage` (`wr_token`); `frontend/src/api/client.js` clears it and fires `auth:expired` on 401.
- Seeded admin `admin@l.loc` / `123456` is a bcrypt hash in `001_init.sql`. Editing that file will not update an existing volume.
- Bind `jsonb` params as JSON-encoded strings (see `WebhookController`), not PHP arrays.
- PDO/pgsql returns `jsonb` columns as strings. Decode them at the API boundary with `Support\JsonColumns::decode()`, otherwise the SPA receives a string where it expects arrays (`conditions`, `actions`, `headers`, `query`, `body`).
- Boolean columns need string params (`'true'`/`'false'`), not PHP booleans: `false` is sent as `''` and Postgres rejects it (`invalid input syntax for type boolean`).
- Forward actions call URLs from inside the api container: use service DNS (`http://web/...`), not `localhost:8080`.

## Conventions
- API success shape `{"data": …}` (lists add `meta`); errors via `Response::error` → `{"error":{"message":"…"}}`.
- Register routes in `backend/public/index.php`; handlers take `(Request $request, array $params)`.
- Pagination: `page` ≥ 1, `per_page` 1–100 (default 25).
- Frontend always calls relative `/api/...` paths; the Vite dev server proxies `/api` and `/hook` to `:8080`.
