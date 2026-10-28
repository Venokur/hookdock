<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Jwt;
use App\Database;
use App\Http\Request;
use App\Http\Response;

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
            'token' => Jwt::issue(['sub' => (int) $user['id'], 'email' => $user['email']]),
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
}
