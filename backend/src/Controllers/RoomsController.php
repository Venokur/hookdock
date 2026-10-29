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
    public function index(Request $request): void
    {
        Auth::requireUser($request);

        $rows = Database::pdo()->query(
            "SELECT r.id, r.name, r.slug, r.created_at, r.updated_at,
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

        // The SPA never sends a slug: it is derived from the name and made unique
        // here, because only the server sees every room in the installation.
        $requested = trim((string) ($payload['slug'] ?? ''));

        if ($requested === '') {
            $row = $this->insertWithGeneratedSlug($name);
        } else {
            $slug = $this->validSlug($requested, $name);
            $this->assertSlugFree($slug);
            $row = $this->insert($name, $slug);
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
        // only happens when the client sends one explicitly.
        $slug = $existing['slug'];
        if (array_key_exists('slug', $payload)) {
            $slug = $this->validSlug((string) $payload['slug'], $name);
            if (strcasecmp($slug, (string) $existing['slug']) !== 0) {
                $this->assertSlugFree($slug, $id);
            }
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE rooms SET name = :name, slug = :slug, updated_at = now()
             WHERE id = :id
             RETURNING id, name, slug, created_at, updated_at'
        );
        $stmt->execute(['id' => $id, 'name' => $name, 'slug' => $slug]);

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
            'SELECT id, name, slug, created_at, updated_at FROM rooms WHERE id = :id'
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
     * Slugs end up in webhook URLs, so they are restricted to a URL-safe alphabet.
     * An empty value falls back to a transliterated project name.
     */
    private function validSlug(string $value, string $fallbackName): string
    {
        $slug = strtolower(trim($value));

        if ($slug === '') {
            $slug = $this->slugify($fallbackName);
        }

        if ($slug === '' || preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug) !== 1) {
            Response::error('Slug комнаты: латинские буквы, цифры и дефис, до 64 символов', 422);
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function insert(string $name, string $slug): array
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO rooms (name, slug) VALUES (:name, :slug)
             RETURNING id, name, slug, created_at, updated_at'
        );
        $stmt->execute(['name' => $name, 'slug' => $slug]);

        return $stmt->fetch();
    }

    /**
     * Derives a slug from the room name and retries with -2, -3, … while the
     * unique index on lower(slug) rejects the candidate. Relying on the index
     * instead of a SELECT keeps two concurrent creates from picking the same
     * slug: the loser simply moves to the next suffix.
     *
     * @return array<string, mixed>
     */
    private function insertWithGeneratedSlug(string $name): array
    {
        $base = $this->slugify($name);
        if ($base === '') {
            $base = 'room';
        }

        for ($n = 1; $n <= 100; $n++) {
            $slug = $n === 1 ? $base : $this->withSuffix($base, $n);

            try {
                return $this->insert($name, $slug);
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23505') {
                    throw $e;
                }
            }
        }

        Response::error('Не удалось подобрать свободный slug для комнаты', 422);
    }

    /** Keeps the 64-character limit intact when a numeric suffix is appended. */
    private function withSuffix(string $base, int $n): string
    {
        $suffix = '-' . $n;
        $trimmed = rtrim(mb_substr($base, 0, 64 - mb_strlen($suffix)), '-');

        return ($trimmed === '' ? 'room' : $trimmed) . $suffix;
    }

    private function slugify(string $value): string
    {
        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
            'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k',
            'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
            'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts',
            'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];

        $transliterated = strtr(mb_strtolower($value), $map);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $transliterated) ?? '';

        return trim(mb_substr($slug, 0, 64), '-');
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
            Response::error('Комната с таким slug уже существует', 422);
        }
    }
}
