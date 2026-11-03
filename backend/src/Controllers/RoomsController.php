<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\Audit;
use App\Services\Realtime;
use App\Support\IpAllowlist;
use App\Support\JsonColumns;
use App\Support\Signature;

/**
 * Rooms isolate incoming requests and workflows. A webhook addressed to
 * /hook/{room}/{endpoint} only ever reaches its own room, and a workflow can only
 * fire for requests of the room it belongs to.
 */
final class RoomsController
{
    private const SLUG_TAKEN = 'Slug комнаты уже занят, сгенерируйте новый';

    /** Rooms the sidebar loads without a search: the latest ones. */
    private const PAGE_SIZE = 20;

    /** A search may legitimately return more than the sidebar page. */
    private const SEARCH_LIMIT = 50;

    /**
     * The sidebar must stay fast with hundreds of rooms, so it never asks for
     * "everything": it loads the latest page and searches the rest by name/slug.
     *
     * `?slug=` is an exact lookup used to resolve a deep link to a room that is
     * older than the page — it answers 200 with an empty list for an unknown
     * slug, because the caller is asking "does this exist", not filtering a log.
     */
    public function index(Request $request): void
    {
        Auth::requireUser($request);

        $q = trim((string) ($request->query['q'] ?? ''));
        $slug = strtolower(trim((string) ($request->query['slug'] ?? '')));

        $requestedLimit = (int) ($request->query['limit'] ?? 0);
        if ($requestedLimit <= 0) {
            $requestedLimit = $q === '' ? self::PAGE_SIZE : self::SEARCH_LIMIT;
        }
        // Clamped and cast: the value is interpolated below, never bound.
        $limit = min(100, max(1, $requestedLimit));

        $where = [];
        $params = [];

        if ($q !== '') {
            // `%` and `_` are LIKE wildcards, so a literal "50%" would otherwise
            // match every room containing "50". Backslash is Postgres' default
            // LIKE escape; a literal `ESCAPE` clause breaks PDO's placeholder
            // parser (see the request log filter).
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where[] = '(r.name ILIKE :q_name OR r.slug ILIKE :q_slug)';
            $params['q_name'] = $like;
            $params['q_slug'] = $like;
        }

        if ($slug !== '') {
            $where[] = 'lower(r.slug) = :slug';
            $params['slug'] = $slug;
        }

        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        // Newest first: the sidebar holds only the latest page, so the order has
        // to make it obvious which rooms were left out. A search keeps the
        // familiar alphabetical order instead.
        $order = $q === '' ? 'r.id DESC' : 'lower(r.name), r.id';

        // The LIMIT lives in a subquery on purpose. The counter laterals below are
        // the expensive part, and a top-level LIMIT would still evaluate them for
        // every room before sorting and discarding the rest.
        $stmt = Database::pdo()->prepare(
            "SELECT r.id, r.name, r.slug, r.endpoint, r.created_at, r.updated_at,
                    (r.secret IS NOT NULL) AS has_secret,
                    r.dedup_header, r.require_timestamp, r.ip_allowlist,
                    COALESCE(req.total, 0)::int AS requests_total,
                    COALESCE(req.last_24h, 0)::int AS requests_last_24h,
                    req.last_received_at,
                    COALESCE(wf.active, 0)::int AS workflows_active,
                    COALESCE(wf.total, 0)::int AS workflows_total,
                    COALESCE(fail.total, 0)::int AS failed_runs_24h
             FROM (
                 SELECT id, name, slug, endpoint, created_at, updated_at, secret,
                        dedup_header, require_timestamp,
                        to_json(ip_allowlist) AS ip_allowlist
                   FROM rooms r" . $clause . "
                  ORDER BY " . $order . "
                  LIMIT " . $limit . "
             ) r
             LEFT JOIN LATERAL (
                 SELECT count(*) AS total,
                        count(*) FILTER (WHERE received_at >= now() - interval '24 hours') AS last_24h,
                        max(received_at) AS last_received_at
                 FROM incoming_requests i
                 WHERE i.room_id = r.id
             ) req ON TRUE
             LEFT JOIN LATERAL (
                 SELECT count(*) AS total,
                        count(*) FILTER (WHERE enabled) AS active
                 FROM workflows w
                 WHERE w.room_id = r.id
             ) wf ON TRUE
             LEFT JOIN LATERAL (
                 SELECT count(*) AS total
                 FROM workflow_runs wr
                 WHERE wr.room_id = r.id
                   AND wr.status = 'failed'
                   AND wr.created_at >= now() - interval '24 hours'
             ) fail ON TRUE
             ORDER BY " . $order
        );
        $stmt->execute($params);
        // `to_json(ip_allowlist)` keeps the column readable through PDO, which
        // hands a `TEXT[]` back as the Postgres literal rather than an array.
        $rows = JsonColumns::decodeMany($stmt->fetchAll(), ['ip_allowlist']);

        $countStmt = Database::pdo()->prepare('SELECT count(*) FROM rooms r' . $clause);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        Response::json([
            'data' => $rows,
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'returned' => count($rows),
                'has_more' => $total > count($rows),
            ],
        ]);
    }

    public function show(Request $request, array $params): void
    {
        Auth::requireUser($request);

        $room = $this->find((int) ($params['id'] ?? 0));
        if ($room === null) {
            Response::error('Комната не найдена', 404);
        }

        Response::json(['data' => $room]);
    }

    public function store(Request $request): void
    {
        Auth::requireUser($request, true);
        $payload = $request->json() ?? [];

        $name = $this->validName((string) ($payload['name'] ?? ''));

        // Empty or missing endpoint: the server assigns a random one. The value is
        // editable afterwards, because it ends up in URLs already handed out.
        $endpoint = $this->validEndpoint((string) ($payload['endpoint'] ?? ''));

        // The slug is random and unrelated to the name, so renaming a room never
        // changes its address. The SPA sends one it generated for the preview; a
        // missing value means the server picks it.
        $requested = trim((string) ($payload['slug'] ?? ''));
        $slug = $requested === '' ? $this->generateSlug() : $this->validSlug($requested);

        // A duplicate is never resolved by silently drawing another value: the
        // client sees 422 and has to generate a new slug explicitly.
        $this->assertSlugFree($slug);

        // Turning the signature on at creation is one INSERT, not "create, then
        // POST /secret": a failed second call would leave a room that is already
        // visible in the sidebar but silently public. `filter_var` is what makes
        // the JSON string "false" false — a plain cast would accept it as true.
        $withSecret = filter_var($payload['require_signature'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $secret = $withSecret ? Signature::generate() : null;

        $dedupHeader = $this->validDedupHeader($payload['dedup_header'] ?? null);
        $requireTimestamp = filter_var($payload['require_timestamp'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $allowlist = $this->validAllowlist($payload['ip_allowlist'] ?? null);

        try {
            $row = $this->insert($name, $slug, $endpoint, $secret, $dedupHeader, $requireTimestamp, $allowlist);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                Response::error(self::SLUG_TAKEN, 422);
            }
            throw $e;
        }

        $row = JsonColumns::decode($row, ['ip_allowlist']);

        Realtime::publish('room.changed', ['id' => (int) $row['id'], 'slug' => $row['slug'], 'action' => 'created']);

        // The room's own secret is never journalled; `has_secret` is the fact that
        // matters and the value cannot be recovered anyway.
        Audit::record([
            'action' => 'room.created',
            'object_type' => 'room',
            'object_id' => (int) $row['id'],
            'object_label' => (string) $row['name'],
            'room_id' => (int) $row['id'],
            'room_slug' => (string) $row['slug'],
            'summary' => 'Создана комната «' . $row['name'] . '»',
            'changes' => [
                'slug' => [null, $row['slug']],
                'endpoint' => [null, $row['endpoint']],
                'has_secret' => [null, $secret !== null],
                'ip_allowlist' => [null, $row['ip_allowlist']],
            ],
        ], $request);

        // The secret is shown exactly once, here and in `rotateSecret`: reads of a
        // room return only `has_secret`. Attaching it to the `201` lets the create
        // form display it without a second round trip (and without a window in
        // which the room exists without its signature).
        if ($secret !== null) {
            $row['secret'] = $secret;
        }

        Response::json(['data' => $row], 201);
    }

    public function update(Request $request, array $params): void
    {
        Auth::requireUser($request, true);
        $id = (int) ($params['id'] ?? 0);

        $existing = $this->find($id);
        if ($existing === null) {
            Response::error('Комната не найдена', 404);
        }

        $payload = $request->json() ?? [];
        $name = $this->validName((string) ($payload['name'] ?? $existing['name']));

        // Changing the slug breaks webhook URLs already configured elsewhere, so it
        // only happens when the client sends one explicitly. An explicitly empty
        // value asks for a fresh random slug, mirroring the endpoint below. A
        // duplicate is rejected so the client regenerates instead of reusing one.
        $slug = $existing['slug'];
        if (array_key_exists('slug', $payload)) {
            $requested = trim((string) $payload['slug']);
            $slug = $requested === '' ? $this->generateSlug() : $this->validSlug($requested);
            if (strcasecmp($slug, (string) $existing['slug']) !== 0) {
                $this->assertSlugFree($slug, $id);
            }
        }

        // Same rule for the endpoint: an omitted key keeps what integrations point
        // at, an explicitly empty value asks for a fresh random one.
        $endpoint = $existing['endpoint'];
        if (array_key_exists('endpoint', $payload)) {
            $endpoint = $this->validEndpoint((string) $payload['endpoint']);
        }

        // Delivery settings follow the same convention: an omitted key keeps the
        // current value, so the SPA's "rename" form does not silently turn
        // deduplication off. `dedup_header: ""` is the explicit "off".
        $dedupHeader = $existing['dedup_header'];
        if (array_key_exists('dedup_header', $payload)) {
            $dedupHeader = $this->validDedupHeader($payload['dedup_header']);
        }

        $requireTimestamp = (bool) $existing['require_timestamp'];
        if (array_key_exists('require_timestamp', $payload)) {
            $requireTimestamp = filter_var($payload['require_timestamp'], FILTER_VALIDATE_BOOLEAN);
        }

        // Same convention as the two settings above, and for a sharper reason: an
        // omitted list keeps the current one, so a rename form that does not show
        // the allowlist cannot silently open the room to everyone. An explicit `[]`
        // is how an operator turns it off, and it means exactly that — not "keep".
        $allowlist = IpAllowlist::fromColumn($existing['ip_allowlist'] ?? null);
        if (array_key_exists('ip_allowlist', $payload)) {
            $allowlist = $this->validAllowlist($payload['ip_allowlist']);
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE rooms
                SET name = :name, slug = :slug, endpoint = :endpoint,
                    dedup_header = :dedup_header, require_timestamp = :require_timestamp,
                    ip_allowlist = :ip_allowlist,
                    updated_at = now()
              WHERE id = :id
              RETURNING id, name, slug, endpoint, created_at, updated_at,
                        (secret IS NOT NULL) AS has_secret,
                        dedup_header, require_timestamp,
                        to_json(ip_allowlist) AS ip_allowlist'
        );

        try {
            $stmt->execute([
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'endpoint' => $endpoint,
                'dedup_header' => $dedupHeader,
                'require_timestamp' => $requireTimestamp ? 'true' : 'false',
                'ip_allowlist' => $this->pgTextArray($allowlist),
            ]);
        } catch (\PDOException $e) {
            // The unique index can still win a race against the check above.
            if ($e->getCode() === '23505') {
                Response::error(self::SLUG_TAKEN, 422);
            }
            throw $e;
        }

        $row = JsonColumns::decode($stmt->fetch(), ['ip_allowlist']);
        Realtime::publish('room.changed', ['id' => $id, 'slug' => $row['slug'], 'action' => 'updated']);

        Audit::record([
            'action' => 'room.updated',
            'object_type' => 'room',
            'object_id' => $id,
            'object_label' => (string) $row['name'],
            'room_id' => $id,
            'room_slug' => (string) $row['slug'],
            'summary' => 'Изменена комната «' . $row['name'] . '»',
            'changes' => Audit::diff($existing, $row, [
                'name', 'slug', 'endpoint', 'dedup_header', 'require_timestamp', 'ip_allowlist',
            ]),
        ], $request);

        Response::json(['data' => $row]);
    }

    public function destroy(Request $request, array $params): void
    {
        Auth::requireUser($request, true);
        $id = (int) ($params['id'] ?? 0);

        $stmt = Database::pdo()->prepare('DELETE FROM rooms WHERE id = :id RETURNING id, slug');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            Response::error('Комната не найдена', 404);
        }

        Realtime::publish('room.changed', ['id' => $id, 'slug' => $row['slug'], 'action' => 'deleted']);

        // `room_id` is left NULL here on purpose: the FK is SET NULL anyway, and
        // the copied slug is what keeps the entry findable afterwards.
        Audit::record([
            'action' => 'room.deleted',
            'object_type' => 'room',
            'object_id' => $id,
            'object_label' => (string) $row['slug'],
            'room_slug' => (string) $row['slug'],
            'summary' => 'Удалена комната «' . $row['slug'] . '» со всеми запросами и сценариями',
        ], $request);

        Response::json(['ok' => true, 'deleted' => true]);
    }

    /**
     * Issues a new HMAC secret for the room.
     *
     * The value is returned exactly once, in this response: later reads expose
     * only `has_secret`, so the operator has to copy it into the sender now.
     * Rotating silently breaks every integration still signing with the old
     * secret, which is why the SPA asks for confirmation first.
     */
    public function rotateSecret(Request $request, array $params): void
    {
        Auth::requireUser($request, true);
        $id = (int) ($params['id'] ?? 0);

        if ($this->find($id) === null) {
            Response::error('Комната не найдена', 404);
        }

        $secret = Signature::generate();
        $stmt = Database::pdo()->prepare(
            'UPDATE rooms SET secret = :secret, secret_set_at = now(), updated_at = now()
              WHERE id = :id
              RETURNING slug'
        );
        $stmt->execute(['id' => $id, 'secret' => $secret]);
        $row = $stmt->fetch();

        Realtime::publish('room.changed', ['id' => $id, 'slug' => $row['slug'], 'action' => 'updated']);

        Audit::record([
            'action' => 'room.secret_issued',
            'object_type' => 'room',
            'object_id' => $id,
            'object_label' => (string) $row['slug'],
            'room_id' => $id,
            'room_slug' => (string) $row['slug'],
            'summary' => 'Выпущен новый секрет подписи комнаты «' . $row['slug'] . '»',
        ], $request);

        Response::json(['data' => ['secret' => $secret, 'has_secret' => true]]);
    }

    /**
     * Removes the secret, so the room accepts unsigned webhooks again. The old
     * secret stops working immediately: there is nothing to keep it for.
     */
    public function clearSecret(Request $request, array $params): void
    {
        Auth::requireUser($request, true);
        $id = (int) ($params['id'] ?? 0);

        if ($this->find($id) === null) {
            Response::error('Комната не найдена', 404);
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE rooms SET secret = NULL, secret_set_at = NULL, updated_at = now()
              WHERE id = :id
              RETURNING slug'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        Realtime::publish('room.changed', ['id' => $id, 'slug' => $row['slug'], 'action' => 'updated']);

        Audit::record([
            'action' => 'room.secret_cleared',
            'object_type' => 'room',
            'object_id' => $id,
            'object_label' => (string) $row['slug'],
            'room_id' => $id,
            'room_slug' => (string) $row['slug'],
            'summary' => 'Комната «' . $row['slug'] . '» снова принимает неподписанные вебхуки',
        ], $request);

        Response::json(['data' => ['has_secret' => false]]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id, name, slug, endpoint, created_at, updated_at,
                    (secret IS NOT NULL) AS has_secret,
                    dedup_header, require_timestamp,
                    to_json(ip_allowlist) AS ip_allowlist
               FROM rooms WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : JsonColumns::decode($row, ['ip_allowlist']);
    }

    private function validName(string $value): string
    {
        $name = trim($value);
        if ($name === '') {
            Response::error('Укажите название комнаты', 422);
        }
        if (mb_strlen($name) > 120) {
            Response::error('Название комнаты должно быть не длиннее 120 символов', 422);
        }

        return $name;
    }

    /**
     * Slugs end up in webhook URLs, so they are restricted to a URL-safe
     * alphabet. They are random rather than derived from the room name, so a
     * rename cannot silently break an address integrations already point at.
     */
    private function validSlug(string $value): string
    {
        $slug = strtolower(trim($value));

        if ($slug === '' || preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug) !== 1) {
            Response::error('Slug комнаты: латинские буквы, цифры и дефис, до 64 символов', 422);
        }

        return $slug;
    }

    /**
     * The endpoint slug appears in copied webhook URLs, so it is restricted to a
     * single URL-safe segment. An empty value asks the server to generate one.
     */
    private function validEndpoint(string $value): string
    {
        $endpoint = strtolower(trim($value));

        if ($endpoint === '') {
            return $this->generateEndpoint();
        }

        if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $endpoint) !== 1) {
            Response::error('Эндпоинт: латинские буквы, цифры и дефис, до 64 символов', 422);
        }

        return $endpoint;
    }

    /**
     * Random and unrelated to the room name, so the address is stable across
     * renames and two identically named rooms never share a URL. Two 12-char
     * hex groups (96 bits) make an accidental repeat practically impossible.
     */
    private function generateSlug(): string
    {
        return bin2hex(random_bytes(6)) . '-' . bin2hex(random_bytes(6));
    }

    /**
     * Random tail so links handed out by different rooms never look alike. The
     * `hook-` prefix keeps it identifiable as a webhook address in logs.
     */
    private function generateEndpoint(): string
    {
        return 'hook-' . bin2hex(random_bytes(3));
    }

    /**
     * @return array<string, mixed>
     */
    private function insert(
        string $name,
        string $slug,
        string $endpoint,
        ?string $secret = null,
        ?string $dedupHeader = null,
        bool $requireTimestamp = false,
        array $allowlist = [],
    ): array {
        // Two statements instead of one with `CASE WHEN :secret IS NULL`: native
        // prepared statements make Postgres infer the parameter type, and a bare
        // `:secret IS NULL` fails with "could not determine data type".
        $columns = 'name, slug, endpoint, dedup_header, require_timestamp, ip_allowlist';
        $values = ':name, :slug, :endpoint, :dedup_header, :require_timestamp, :ip_allowlist';
        $params = [
            'name' => $name,
            'slug' => $slug,
            'endpoint' => $endpoint,
            'dedup_header' => $dedupHeader,
            'require_timestamp' => $requireTimestamp ? 'true' : 'false',
            'ip_allowlist' => $this->pgTextArray($allowlist),
        ];

        if ($secret !== null) {
            $columns .= ', secret, secret_set_at';
            $values .= ', :secret, now()';
            $params['secret'] = $secret;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO rooms (' . $columns . ') VALUES (' . $values . ')
             RETURNING id, name, slug, endpoint, created_at, updated_at,
                       (secret IS NOT NULL) AS has_secret,
                       dedup_header, require_timestamp,
                       to_json(ip_allowlist) AS ip_allowlist'
        );
        $stmt->execute($params);

        return JsonColumns::decode($stmt->fetch(), ['ip_allowlist']);
    }

    /**
     * A header name, or null for "deduplication off".
     *
     * The value is read back from the incoming request with the same name, so it
     * has to be a legal HTTP field name: anything else would make the setting
     * silently never match.
     *
     * @param mixed $value
     */
    private function validDedupHeader(mixed $value): ?string
    {
        $header = trim((string) ($value ?? ''));
        if ($header === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,64}$/', $header) !== 1) {
            Response::error('Заголовок дедупликации: латинские буквы, цифры и дефис, до 64 символов', 422);
        }

        return $header;
    }

    /**
     * The room's allowlist as a validated list of canonical CIDRs.
     *
     * `IpAllowlist::normalizeAll` returns the offending entry, because "неверный
     * формат" in a list of twenty ranges tells the operator nothing about which one
     * to fix.
     *
     * @param mixed $value
     * @return list<string>
     */
    private function validAllowlist(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        [$list, $problem] = IpAllowlist::normalizeAll($value);
        if ($problem !== null) {
            Response::error($problem, 422);
        }

        return $list;
    }

    /**
     * Encodes a list for a `TEXT[]` column.
     *
     * A PHP array cannot be bound to an array column, so the Postgres array
     * literal is built here. Entries are canonical addresses produced by
     * `IpAllowlist::normalize`, so they cannot contain a quote or a backslash;
     * escaping them anyway keeps this safe if the producer ever changes.
     *
     * @param list<string> $values
     */
    private function pgTextArray(array $values): string
    {
        if ($values === []) {
            return '{}';
        }

        $escaped = array_map(
            static fn (string $value): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"',
            $values,
        );

        return '{' . implode(',', $escaped) . '}';
    }

    private function assertSlugFree(string $slug, ?int $ignoreId = null): void
    {
        $sql = 'SELECT 1 FROM rooms WHERE lower(slug) = lower(:slug)';
        $params = ['slug' => $slug];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $ignoreId;
        }

        $stmt = Database::pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        if ($stmt->fetchColumn() !== false) {
            Response::error(self::SLUG_TAKEN, 422);
        }
    }
}
