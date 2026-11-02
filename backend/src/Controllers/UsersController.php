<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\Audit;
use App\Support\JsonColumns;
use PDO;

/**
 * Accounts, for an installation that is shared rather than personal.
 *
 * Until now a user was something only the seed SQL created: the only way to add a
 * colleague was a psql session, and the only way to limit them was to hand out a
 * read token. Both were workarounds. This is the actual feature.
 *
 * Every handler is admin-only (`Auth::requireAdmin`). An editor who could grant
 * themselves `admin` would make the roles decorative, so the check is separate
 * from the `$write` flag every other mutation uses.
 */
final class UsersController
{
    /** Bcrypt cost, matching the seeded hash in `001_init.sql`. */
    private const BCRYPT_COST = 12;

    public function index(Request $request): void
    {
        Auth::requireAdmin($request);

        $stmt = Database::pdo()->query(
            'SELECT id, email, role, created_at, last_login_at
               FROM users
              ORDER BY id'
        );

        $users = $stmt->fetchAll();
        foreach ($users as &$user) {
            $user['id'] = (int) $user['id'];
        }
        unset($user);

        Response::json(['data' => $users]);
    }

    public function store(Request $request): void
    {
        Auth::requireAdmin($request);
        $payload = $request->json() ?? [];

        $email = $this->validEmail((string) ($payload['email'] ?? ''));
        $password = $this->validPassword((string) ($payload['password'] ?? ''));
        $role = $this->validRole($payload['role'] ?? Auth::ROLE_EDITOR);

        $this->assertEmailFree($email);

        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO users (email, password_hash, role)
                 VALUES (:email, :hash, :role)
                 RETURNING id, email, role, created_at, last_login_at'
            );
            // `password_hash` column is derived from the payload rather than named
            // in it, so a client cannot send a pre-hashed value.
            $stmt->execute([
                'email' => $email,
                'hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]),
                'role' => $role,
            ]);
        } catch (\PDOException $e) {
            // The unique index on lower(email) can still win a race against the
            // check above.
            if ($e->getCode() === '23505') {
                Response::error('Пользователь с таким email уже существует', 422);
            }
            throw $e;
        }

        $row = $stmt->fetch();
        $row['id'] = (int) $row['id'];

        Audit::record([
            'action' => 'user.created',
            'object_type' => 'user',
            'object_id' => $row['id'],
            'object_label' => $row['email'],
            'summary' => 'Создан пользователь ' . $row['email'] . ' с ролью ' . $row['role'],
            'changes' => ['email' => [null, $row['email']], 'role' => [null, $row['role']]],
        ], $request);

        Response::json(['data' => $row], 201);
    }

    public function update(Request $request, array $params): void
    {
        $actor = Auth::requireAdmin($request);
        $id = (int) ($params['id'] ?? 0);

        $existing = $this->find($id);
        if ($existing === null) {
            Response::error('Пользователь не найден', 404);
        }

        $payload = $request->json() ?? [];
        $changes = null;

        $email = $existing['email'];
        if (array_key_exists('email', $payload)) {
            $email = $this->validEmail((string) $payload['email']);
            if (strcasecmp($email, (string) $existing['email']) !== 0) {
                $this->assertEmailFree($email, $id);
            }
        }

        $role = $existing['role'];
        if (array_key_exists('role', $payload)) {
            $role = $this->validRole($payload['role']);

            // Two guards that keep an installation recoverable: the last admin
            // cannot demote themselves, and nobody can demote themselves at all
            // while they are the one holding the only admin account. Both reduce
            // to "there is always at least one admin left", which is checked once.
            if ($role !== Auth::ROLE_ADMIN && (int) $existing['id'] === (int) $actor['id']) {
                Response::error('Нельзя снять роль администратора с самого себя', 422);
            }
            if ($role !== Auth::ROLE_ADMIN && $existing['role'] === Auth::ROLE_ADMIN) {
                $this->assertNotLastAdmin($id);
            }
        }

        $hash = null;
        if (array_key_exists('password', $payload) && (string) $payload['password'] !== '') {
            $hash = password_hash(
                $this->validPassword((string) $payload['password']),
                PASSWORD_BCRYPT,
                ['cost' => self::BCRYPT_COST],
            );
        }

        $sql = 'UPDATE users SET email = :email, role = :role'
            . ($hash === null ? '' : ', password_hash = :hash')
            . ' WHERE id = :id
             RETURNING id, email, role, created_at, last_login_at';

        $bind = ['id' => $id, 'email' => $email, 'role' => $role];
        if ($hash !== null) {
            $bind['hash'] = $hash;
        }

        try {
            $stmt = Database::pdo()->prepare($sql);
            $stmt->execute($bind);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                Response::error('Пользователь с таким email уже существует', 422);
            }
            throw $e;
        }

        $row = $stmt->fetch();
        $row['id'] = (int) $row['id'];

        $changes = Audit::diff($existing, ['email' => $row['email'], 'role' => $row['role']], ['email', 'role']);
        if ($hash !== null) {
            // The hash itself is never journalled: only the fact that it changed,
            // which is what an operator asking "who reset this" needs.
            $changes['password'] = ['—', 'изменён'];
        }

        Audit::record([
            'action' => 'user.updated',
            'object_type' => 'user',
            'object_id' => $row['id'],
            'object_label' => $row['email'],
            'summary' => 'Изменён пользователь ' . $row['email'],
            'changes' => $changes,
        ], $request);

        Response::json(['data' => $row]);
    }

    public function destroy(Request $request, array $params): void
    {
        $actor = Auth::requireAdmin($request);
        $id = (int) ($params['id'] ?? 0);

        $existing = $this->find($id);
        if ($existing === null) {
            Response::error('Пользователь не найден', 404);
        }

        if ((int) $existing['id'] === (int) $actor['id']) {
            Response::error('Нельзя удалить собственную учётную запись', 422);
        }

        if ($existing['role'] === Auth::ROLE_ADMIN) {
            $this->assertNotLastAdmin($id);
        }

        $stmt = Database::pdo()->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);

        // The journal keeps the address after the row is gone: `actor_id` and
        // `object_id` become NULL, `actor_email`/`object_label` were copied.
        Audit::record([
            'action' => 'user.deleted',
            'object_type' => 'user',
            'object_id' => $id,
            'object_label' => $existing['email'],
            'summary' => 'Удалён пользователь ' . $existing['email'],
            'changes' => ['role' => [$existing['role'], null]],
        ], $request);

        Response::json(['ok' => true, 'deleted' => true]);
    }

    /**
     * The user's own password, which needs no `admin` role but does need the old
     * one — otherwise a stolen token is a permanent account takeover.
     */
    public function changePassword(Request $request): void
    {
        $user = Auth::requireUser($request, true);
        $payload = $request->json() ?? [];

        $current = (string) ($payload['current_password'] ?? '');
        $next = $this->validPassword((string) ($payload['password'] ?? ''));

        if (Auth::scope() === Auth::SCOPE_READ) {
            Response::error('Токен только для чтения: смена пароля недоступна', 403);
        }

        $stmt = Database::pdo()->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute(['id' => (int) $user['id']]);
        $hash = (string) $stmt->fetchColumn();

        if ($hash === '' || !password_verify($current, $hash)) {
            Response::error('Текущий пароль указан неверно', 422);
        }

        $update = Database::pdo()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $update->execute([
            'hash' => password_hash($next, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]),
            'id' => (int) $user['id'],
        ]);

        // Not journalled through `Audit::record` with the actor helper because the
        // actor is the subject; the address is enough to explain the entry.
        Audit::record([
            'action' => 'user.password_changed',
            'object_type' => 'user',
            'object_id' => (int) $user['id'],
            'object_label' => (string) $user['email'],
            'summary' => 'Пользователь сменил свой пароль',
        ], $request);

        Response::json(['ok' => true]);
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
            'SELECT id, email, role, created_at, last_login_at FROM users WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $row['id'] = (int) $row['id'];

        return $row;
    }

    /**
     * Refuses the demotion/deletion that would leave the installation without an
     * administrator, which no endpoint could undo.
     */
    private function assertNotLastAdmin(int $id): void
    {
        $stmt = Database::pdo()->prepare(
            "SELECT count(*) FROM users WHERE role = 'admin' AND id <> :id"
        );
        $stmt->execute(['id' => $id]);

        if ((int) $stmt->fetchColumn() === 0) {
            Response::error('Это единственный администратор: сначала назначьте другого', 422);
        }
    }

    private function assertEmailFree(string $email, ?int $exceptId = null): void
    {
        $sql = 'SELECT id FROM users WHERE lower(email) = :email';
        $params = ['email' => mb_strtolower($email)];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        if ($stmt->fetch() !== false) {
            Response::error('Пользователь с таким email уже существует', 422);
        }
    }

    private function validEmail(string $value): string
    {
        $email = mb_strtolower(trim($value));

        if ($email === '' || mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            Response::error('Укажите корректный email', 422);
        }

        return $email;
    }

    /**
     * Length only, deliberately.
     *
     * Composition rules ("one digit, one symbol") are a documented way to make
     * passwords worse: they push people towards `Password1!` and are not what makes
     * a credential strong. Length is the property that actually matters, and bcrypt
     * silently truncates at 72 bytes — so the upper bound is not cosmetic.
     */
    private function validPassword(string $value): string
    {
        if (mb_strlen($value) < 8) {
            Response::error('Пароль должен быть не короче 8 символов', 422);
        }
        if (strlen($value) > 72) {
            Response::error('Пароль должен быть не длиннее 72 байт', 422);
        }

        return $value;
    }

    private function validRole(mixed $value): string
    {
        $role = strtolower(trim((string) $value));

        if (!in_array($role, Auth::ROLES, true)) {
            Response::error('Неизвестная роль: допустимы admin, editor, viewer', 422);
        }

        return $role;
    }
}
