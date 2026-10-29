<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\Realtime;

/**
 * Rooms isolate incoming requests and workflows. A webhook addressed to
 * /hook/{room}/{endpoint} only ever reaches its own room, and a workflow can only
 * fire for requests of the room it belongs to.
 */
final class RoomsController
{
    private const SLUG_TAKEN = 'Slug комнаты уже занят, сгенерируйте новый';

    public function index(Request $request): void
    {
        Auth::requireUser($request);

        $rows = Database::pdo()->query(
            "SELECT r.id, r.name, r.slug, r.endpoint, r.created_at, r.updated_at,
                    COALESCE(req.total, 0)::int AS requests_total,
                    COALESCE(req.last_24h, 0)::int AS requests_last_24h,
                    req.last_received_at,
                    COALESCE(wf.active, 0)::int AS workflows_active,
                    COALESCE(wf.total, 0)::int AS workflows_total,
                    COALESCE(fail.total, 0)::int AS failed_runs_24h
             FROM rooms r
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
                 JOIN incoming_requests i ON i.id = wr.request_id
                 WHERE i.room_id = r.id
                   AND wr.status = 'failed'
                   AND wr.created_at >= now() - interval '24 hours'
             ) fail ON TRUE
             ORDER BY lower(r.name), r.id"
        )->fetchAll();

        Response::json(['data' => $rows]);
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
        Auth::requireUser($request);
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

        try {
            $row = $this->insert($name, $slug, $endpoint);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                Response::error(self::SLUG_TAKEN, 422);
            }
            throw $e;
        }

        Realtime::publish('room.changed', ['id' => (int) $row['id'], 'slug' => $row['slug'], 'action' => 'created']);

        Response::json(['data' => $row], 201);
    }

    public function update(Request $request, array $params): void
    {
        Auth::requireUser($request);
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

        $stmt = Database::pdo()->prepare(
            'UPDATE rooms SET name = :name, slug = :slug, endpoint = :endpoint, updated_at = now()
             WHERE id = :id
             RETURNING id, name, slug, endpoint, created_at, updated_at'
        );

        try {
            $stmt->execute(['id' => $id, 'name' => $name, 'slug' => $slug, 'endpoint' => $endpoint]);
        } catch (\PDOException $e) {
            // The unique index can still win a race against the check above.
            if ($e->getCode() === '23505') {
                Response::error(self::SLUG_TAKEN, 422);
            }
            throw $e;
        }

        $row = $stmt->fetch();
        Realtime::publish('room.changed', ['id' => $id, 'slug' => $row['slug'], 'action' => 'updated']);

        Response::json(['data' => $row]);
    }

    public function destroy(Request $request, array $params): void
    {
        Auth::requireUser($request);
        $id = (int) ($params['id'] ?? 0);

        $stmt = Database::pdo()->prepare('DELETE FROM rooms WHERE id = :id RETURNING id, slug');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            Response::error('Комната не найдена', 404);
        }

        Realtime::publish('room.changed', ['id' => $id, 'slug' => $row['slug'], 'action' => 'deleted']);

        Response::json(['ok' => true, 'deleted' => true]);
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
            'SELECT id, name, slug, endpoint, created_at, updated_at FROM rooms WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
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
    private function insert(string $name, string $slug, string $endpoint): array
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO rooms (name, slug, endpoint) VALUES (:name, :slug, :endpoint)
             RETURNING id, name, slug, endpoint, created_at, updated_at'
        );
        $stmt->execute(['name' => $name, 'slug' => $slug, 'endpoint' => $endpoint]);

        return $stmt->fetch();
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
