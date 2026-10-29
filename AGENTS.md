# AGENTS.md

Webhook catcher + visual router. Requirements live in `SPEC.md`, usage in `README.md`.

## Shape
- Native PHP 8.3 (no Composer, no framework) + php-fpm, Vue 3 `<script setup>` + Vite + Pinia + Tailwind v4, Postgres 16, nginx.
- UI updates arrive over WebSocket, not polling: `api` publishes `pg_notify` → `ws` container broadcasts. There is no refresh timer in the SPA.
- UI copy is Russian (`ru-RU` dates). Keep new strings and validation messages Russian, including API errors shown in the UI.
- Light theme is the default; `.dark` on `<html>` switches themes. Colors are CSS variables in `frontend/src/assets/main.css` (surfaces, text, lines) exposed as Tailwind tokens (`bg-surface`, `text-ink`, `border-line`). Do not hardcode `slate-*` surfaces in new UI.
- Native controls are wrapped: `frontend/src/components/ui/{AppInput,AppSelect,AppCheckbox,AppToggle,AppTextarea}.vue`. Use these instead of raw `<input>/<select>/<textarea>` so both themes, focus rings and sizes stay consistent.
- Native `window.alert/confirm/prompt` are never used: `notify(message, { tone })` shows a corner toast and `await confirm({ title, message, confirmLabel })` opens a themed dialog (`composables/dialog.js`, mounted once as `ui/AppToast.vue` + `ui/AppDialog.vue` in `App.vue`). Add new cases there instead of a browser popup.
- Manual refresh buttons use `composables/useRefresh.js`: `const { busy, updatedAt, run } = useRefresh(() => load())`, then `:disabled="busy"` + `<AppSpinner size="xs" />` in the button and «обновлено HH:MM:SS» from `formatClock`. `run` holds `busy` for ≥350 ms so the click is visible on a fast local API. Realtime reloads call the loader directly with `{ silent: true }` and must not touch that state.
- `AppSelect` is not a native `<select>`: the trigger is a `role="combobox"` button and the option list is teleported to `<body>` with `position: fixed` (keyboard nav, outside-click close, flip-up near the viewport edge, reposition on scroll). Pass `:options="[{ value, label }]"`; do not expect native `<option>` slots or OS dropdown styling.
- `backend/public/index.php` is the only PHP entrypoint. nginx routes `~ ^/(api|hook)/` to fpm and everything else to `index.html`, so PHP never renders HTML and SPA deep links work.
- No global auth middleware: each dashboard route calls `Auth::requireUser($request)` itself. Webhook routes are intentionally public — do not wrap them.
- Ingestion stores the request first, then runs `Services\WorkflowEngine` synchronously; it records `workflow_runs` (`success`/`failed`/`skipped`) and swallows action errors so the receiver still returns 202.
- **Rooms** partition everything: `incoming_requests.room_id` and `workflows.room_id` are NOT NULL FKs with `ON DELETE CASCADE`. Webhook URL is `/hook/{room}/{endpoint}` (and `/api/webhook/...`); legacy `/hook/{endpoint}` lands in the seeded `default` room («Общая»). Slug→room resolution is `Services\RoomResolver`; `WorkflowEngine` filters `WHERE enabled AND room_id = :room_id`, so a workflow never fires for another room.
- `body`/`headers`/`query` are `jsonb`. `body` is NULL for non-JSON payloads; the original text stays in `raw_body`.
- Migrations: `backend/migrations/*.sql`, tracked in `schema_migrations`, applied by `backend/migrate.php` from the api entrypoint on every boot. Add a new numbered file; never edit an applied one.

## Commands (Windows PowerShell host)
- `docker compose up -d --build` — app at <http://localhost:8080> (`APP_PORT`).
- `powershell -ExecutionPolicy Bypass -File scripts/smoke.ps1` — e2e check: login, 401, room CRUD + validation, per-room ingestion and isolation, workflow scoping, conditions/skip, non-JSON body, forward, `/hook/{x}` resolution, pagination, realtime ticket + websocket delivery (`room_id` included), cascade delete, cleanup.
- `docker compose logs -f api` · `docker compose logs -f ws` · `docker compose exec api php migrate.php` · `docker compose exec db psql -U webhooks -d webhooks`.
- `docker compose down -v` wipes pgdata; admin is re-seeded on next boot.
- Frontend: `cd frontend && npm.cmd install && npm.cmd run build`. Use `npm.cmd` — `npm.ps1` is blocked by execution policy on this machine.
- Host `php -l` is fine for syntax, but host PHP has **no `pdo_pgsql`** — any migration or query must run inside the api container.
- No test/lint/typecheck tooling exists. Verify via `npm.cmd run build` + smoke script; do not claim success without running them.
- `scripts/smoke.ps1` now asserts JSONB columns arrive as arrays/objects, that a paused workflow can be created, and that rooms isolate requests and workflows.

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
- nginx needs `location = /api/ws` (exact) for the websocket: `^~ /api/ws` would swallow `POST /api/ws/ticket`, and a plain prefix would lose to the `~ ^/(api|hook)/` regex.
- The `ws` container runs with `RUN_MIGRATIONS=0`; only `api` applies migrations, otherwise both boots race on `schema_migrations`.
- `backend/bin/ws-server.php` loads `src/autoload.php`, not `bootstrap.php` — bootstrap installs an HTTP exception handler that calls `exit`.
- Never write frames to a client that has not finished the handshake (a heartbeat ping before `HTTP/1.1 101` breaks the upgrade). `WebSocketServer::heartbeat()` skips non-open clients.
- `Realtime::publish` is best effort: catch and `error_log`, so a notify failure cannot turn a stored webhook into a non-202 response. Payloads are ids only (Postgres caps `NOTIFY` at 8000 bytes).
- One `ws` process = one node. Horizontal scaling needs a shared broker (Redis pub/sub); each replica gets its own `LISTEN` notification.
- WebSocket auth is `POST /api/ws/ticket` → 60s JWT with `scope: ws`. Browser `WebSocket` cannot send headers, and the 12h JWT must not go in the query string; `/api/ws` has `access_log off`.
- `/hook/{x}` is ambiguous (room vs endpoint). `WebhookController::resolveTarget()` resolves it: a known room slug wins, otherwise the segment is an endpoint of room `default`. `/hook/{room}` with no endpoint stores `endpoint = 'default'`; an unknown room in a two-segment URL is a 404 — never auto-create a room or fall back.
- Room slugs are unique case-insensitively (`rooms_slug_lower_uniq` on `lower(slug)`) and validated by `RoomsController::validSlug` (`^[a-z0-9][a-z0-9-]{0,63}$`). `slugify` transliterates Cyrillic, so «Стрип Прод» → `strip-prod`.
- Slugs are generated, not typed: the room form submits only `name`, `RoomsController::store` derives the slug from it and retries `-2`, `-3`, … on the unique index, and `utils/slug.js` (`uniqueSlug`) mirrors that to preview it in a disabled field. Do not add a slug input back to the form.
- A room's slug changes only when the client sends `slug` explicitly (API only now) — renaming must not silently break external integrations. The SPA follows the slug the API returns after an edit.
- Workflows cannot move rooms: `update` returns 422 if `room_id` differs, because it would silently change which webhooks fire. `store` requires `room_id` or `room` (slug).
- `?room=<slug>` on `/api/requests`, `/api/stats` and `/api/workflows` is optional (no `room` = installation-wide) but an unknown slug is a 404, not an empty list.
- Realtime events carry `room_id`; each view ignores events for other rooms. Do not add a polling timer as a substitute — a missed event is covered by the `resync` event.
- `backend/` is baked into the image: after editing PHP or adding a migration, `docker compose up -d --build api ws` before `docker compose exec api php migrate.php`, otherwise the new SQL file is not in `/var/www/backend/migrations`.
- Migrations that truncate (like `002_rooms.sql`) run once; `schema_migrations` prevents a re-run, so a volume already on `002` will not be re-truncated.

## Conventions
- API success shape `{"data": …}` (lists add `meta`); errors via `Response::error` → `{"error":{"message":"…"}}`.
- Register routes in `backend/public/index.php`; handlers take `(Request $request, array $params)`.
- Pagination: `page` ≥ 1, `per_page` 1–100 (default 25).
- Frontend always calls relative `/api/...` paths; the Vite dev server proxies `/api` and `/hook` to `:8080`.
- SPA routes are room-scoped: `/rooms/:slug` (requests) and `/rooms/:slug/workflows`; `/` redirects to the last room (`localStorage.wr_room`) or the first one, and the guard in `router/index.js` sends a deep link to a deleted room to an existing one. Add new pages under `/rooms/:slug/...` and register the room in `AppShell.vue`'s `nav` so the sidebar highlights it.
- `stores/rooms.js` is the single source for the room list and slug→room lookup; it is reset on `auth:logout` so a new session never inherits the previous one's rooms.
