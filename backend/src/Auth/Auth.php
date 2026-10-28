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

        $stmt = Database::pdo()->prepare('SELECT id, email, created_at, last_login_at FROM users WHERE id = :id');
        $stmt->execute(['id' => (int) $claims['sub']]);
        $user = $stmt->fetch();

        return self::$cachedUser = ($user === false ? null : $user);
    }

    /**
     * @return array<string, mixed>
     */
    public static function requireUser(Request $request): array
    {
        $user = self::user($request);

        if ($user === null) {
            Response::error('Требуется авторизация', 401);
        }

        return $user;
    }
}
