<?php

declare(strict_types=1);

namespace App\Auth;

use App\Database;
use App\Http\Request;
use App\Http\Response;

final class Auth
{
    /** @var array<string, mixed>|null|false */
    private static array|null|false $cachedUser = false;

    /** @var string|null scope of the token behind `$cachedUser` */
    private static ?string $cachedScope = null;

    /**
     * Scopes a REST token may carry.
     *
     * `api` is the login token and may do anything. `read` is a deliberately
     * weaker credential: it is what an operator hands to a dashboard, a script or a
     * colleague who only needs to look at the log, so a leaked token cannot delete
     * a room or rewrite a rule. `ws` never reaches here (see below).
     */
    public const SCOPE_API = 'api';
    public const SCOPE_READ = 'read';

    /**
     * Roles, strongest first.
     *
     * A role and a token scope answer different questions and both are enforced. The
     * scope says what the *credential* may do (`read` refuses every write); the role
     * says what the *person* may do (`viewer` refuses every write no matter which
     * token they hold). They are not merged into one column because the same person
     * legitimately holds both a full token for their own machine and a read token
     * pasted into a dashboard.
     *
     * - `admin`  — everything, including accounts and the audit log.
     * - `editor` — the product: rooms, rules, replay, retry, breakers.
     * - `viewer` — reads, exports, previews and the run journal only.
     */
    public const ROLE_ADMIN = 'admin';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_VIEWER = 'viewer';

    /** @var list<string> */
    public const ROLES = [self::ROLE_ADMIN, self::ROLE_EDITOR, self::ROLE_VIEWER];

    /**
     * @return array<string, mixed>|null
     */
    public static function user(Request $request): ?array
    {
        if (self::$cachedUser !== false) {
            return self::$cachedUser;
        }

        $header = (string) $request->header('authorization', '');
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return self::$cachedUser = null;
        }

        $claims = Jwt::decode($matches[1]);
        if ($claims === null || !isset($claims['sub'])) {
            return self::$cachedUser = null;
        }

        // A websocket ticket (`scope: ws`) is deliberately short-lived and rides in
        // a query string. Accepting it here would turn that 60s ticket into a full
        // API credential, so only an unscoped/`api`/`read` token may authenticate a
        // REST request. This is what keeps the ticket single-purpose.
        $scope = isset($claims['scope']) ? (string) $claims['scope'] : self::SCOPE_API;
        if (!in_array($scope, [self::SCOPE_API, self::SCOPE_READ], true)) {
            return self::$cachedUser = null;
        }

        $stmt = Database::pdo()->prepare('SELECT id, email, role, created_at, last_login_at FROM users WHERE id = :id');
        $stmt->execute(['id' => (int) $claims['sub']]);
        $user = $stmt->fetch();

        self::$cachedScope = $scope;

        return self::$cachedUser = ($user === false ? null : $user);
    }

    /**
     * @return array<string, mixed>
     */
    public static function requireUser(Request $request, bool $write = false): array
    {
        $user = self::user($request);

        if ($user === null) {
            Response::error('Требуется авторизация', 401);
        }

        // `$write` is passed explicitly by every handler that changes state. A
        // read-only token is refused here rather than at the route level, because
        // the same path may be a safe operation (`GET`) or not, and a blanket
        // method check in the router would also block `POST /auth/login`.
        if ($write && self::$cachedScope === self::SCOPE_READ) {
            Response::error('Токен только для чтения: изменение данных недоступно', 403);
        }

        // The role is enforced here too, not only in the SPA: hiding a button is a
        // convenience, not a permission. A viewer is refused by the same `$write`
        // flag every mutation already passes, so no handler had to learn a new rule.
        if ($write && self::roleOf($user) === self::ROLE_VIEWER) {
            Response::error('Роль «наблюдатель»: изменение данных недоступно', 403);
        }

        return $user;
    }

    /**
     * Admin-only operations: account management and the audit log.
     *
     * Kept separate from `requireUser($request, true)` because most writes are
     * editor work. An installation where an editor could grant themselves `admin`
     * has no roles at all, so this is its own check rather than a fourth argument.
     *
     * @return array<string, mixed>
     */
    public static function requireAdmin(Request $request): array
    {
        $user = self::requireUser($request, true);

        if (self::roleOf($user) !== self::ROLE_ADMIN) {
            Response::error('Нужна роль «администратор»', 403);
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function roleOf(array $user): string
    {
        $role = strtolower(trim((string) ($user['role'] ?? '')));

        // An older row, or one written by hand before the column existed, is treated
        // as the strongest role: the seeded account is the only one that can be in
        // that state, and locking it out of the role page would need a psql session
        // to fix.
        return in_array($role, self::ROLES, true) ? $role : self::ROLE_ADMIN;
    }

    /**
     * What the SPA may show for this user.
     *
     * `can_write` folds the credential and the role into one flag, because that is
     * the question the UI actually asks. `role` is reported separately so the
     * accounts page can say why a control is missing.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function describe(array $user): array
    {
        $role = self::roleOf($user);
        $scope = self::scope();

        return [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'role' => $role,
            'scope' => $scope,
            'can_write' => $scope !== self::SCOPE_READ && $role !== self::ROLE_VIEWER,
            'can_admin' => $scope !== self::SCOPE_READ && $role === self::ROLE_ADMIN,
        ];
    }

    /**
     * @return string one of the SCOPE_* constants (defaults to `api` for an
     *         unscoped token, which is what older clients still hold)
     */
    public static function scope(): string
    {
        return self::$cachedScope ?? self::SCOPE_API;
    }
}
