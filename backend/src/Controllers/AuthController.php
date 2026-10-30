<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Jwt;
use App\Config;
use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Support\RateLimiter;

final class AuthController
{
    public function login(Request $request): void
    {
        $payload = $request->json() ?? [];
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');

        if ($email === '' || $password === '') {
            Response::error('Укажите email и пароль', 422);
        }

        // Two buckets: per-address stops one host from spraying many accounts, and
        // per-account stops a botnet from spraying one address. The address limit is
        // looser on purpose — a whole office or NAT shares one address, so a strict
        // per-IP cap would lock out innocent users before it slows an attacker down.
        // Counting happens before the password check, so a correct password does not
        // bypass the limit.
        $this->enforceLimit(
            'login-ip',
            $request->ip,
            'LOGIN_IP_MAX_ATTEMPTS',
            50,
            'Слишком много попыток входа с этого адреса. Повторите через %d с.',
        );
        $this->enforceLimit(
            'login-email',
            $email,
            'LOGIN_MAX_ATTEMPTS',
            10,
            'Слишком много попыток входа для этой учётной записи. Повторите через %d с.',
        );

        $stmt = Database::pdo()->prepare('SELECT id, email, password_hash FROM users WHERE lower(email) = :email');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user === false || !password_verify($password, (string) $user['password_hash'])) {
            Response::error('Неверный email или пароль', 401);
        }

        $pdo = Database::pdo();

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_BCRYPT)) {
            $rehash = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $rehash->execute([
                'hash' => password_hash($password, PASSWORD_BCRYPT),
                'id' => (int) $user['id'],
            ]);
        }

        $touch = $pdo->prepare('UPDATE users SET last_login_at = now() WHERE id = :id');
        $touch->execute(['id' => (int) $user['id']]);

        Response::json([
            'token' => Jwt::issue(['sub' => (int) $user['id'], 'email' => $user['email'], 'scope' => 'api']),
            'user' => [
                'id' => (int) $user['id'],
                'email' => $user['email'],
            ],
        ]);
    }

    public function me(Request $request): void
    {
        $user = \App\Auth\Auth::requireUser($request);

        Response::json([
            'user' => [
                'id' => (int) $user['id'],
                'email' => $user['email'],
                'last_login_at' => $user['last_login_at'],
            ],
        ]);
    }

    public function logout(): void
    {
        // Tokens are stateless; the client discards it. Kept for API symmetry.
        Response::json(['ok' => true]);
    }

    /**
     * What the login page is allowed to reveal.
     *
     * The seeded demo account is convenient on `localhost` and a liability anywhere
     * else, so it is opt-in (`DEMO_LOGIN=1`) and rendered from this response instead
     * of a literal baked into the JS bundle. `JWT_SECRET` must not be left at its
     * default either, so that is reported as a warning rather than silently used.
     */
    public function config(): void
    {
        $demo = Config::get('DEMO_LOGIN', '0') === '1';

        Response::json([
            'data' => [
                'demo' => $demo
                    ? [
                        'email' => (string) Config::get('DEMO_EMAIL', 'admin@hookdock.local'),
                        'password' => (string) Config::get('DEMO_PASSWORD', '123456'),
                    ]
                    : null,
                'warnings' => $this->configWarnings(),
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function configWarnings(): array
    {
        $warnings = [];

        if (Config::get('JWT_SECRET', 'dev-only-change-me') === 'dev-only-change-me') {
            $warnings[] = 'JWT_SECRET оставлен по умолчанию — задайте свой перед публикацией наружу.';
        }

        if (Config::get('DEMO_LOGIN', '0') === '1') {
            $warnings[] = 'Демо-вход включён — выключите DEMO_LOGIN на публичной установке.';
        }

        return $warnings;
    }

    /**
     * Short-lived ticket for the websocket handshake.
     *
     * Browsers cannot send an Authorization header on WebSocket(), and putting the
     * 12h JWT in the query string would leak it into access logs. The ticket is a
     * 60s JWT with scope=ws and is accepted only by the ws container.
     */
    public function wsTicket(Request $request): void
    {
        $user = \App\Auth\Auth::requireUser($request);
        $ttl = max(10, Config::int('WS_TICKET_TTL', 60));

        Response::json([
            'data' => [
                'ticket' => Jwt::issue(['sub' => (int) $user['id'], 'scope' => 'ws'], $ttl),
                'expires_in' => $ttl,
            ],
        ]);
    }

    private function enforceLimit(string $bucket, string $subject, string $limitKey, int $defaultLimit, string $message): void
    {
        $result = RateLimiter::hit(
            $bucket,
            $subject,
            RateLimiter::limit($limitKey, $defaultLimit),
            RateLimiter::window('LOGIN_WINDOW_SECONDS', 60),
        );

        if ($result['allowed']) {
            return;
        }

        header('Retry-After: ' . $result['retry_after']);

        Response::error(sprintf($message, $result['retry_after']), 429);
    }
}
