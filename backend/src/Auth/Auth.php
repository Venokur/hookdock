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

        $stmt = Database::pdo()->prepare('SELECT id, email, created_at, last_login_at FROM users WHERE id = :id');
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

        return $user;
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
