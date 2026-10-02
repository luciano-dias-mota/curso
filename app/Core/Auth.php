<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Auth
{
    private static ?array $cachedUser = null;

    public static function attempt(string $email, string $password): bool
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.email = :email
             LIMIT 1'
        );
        $stmt->execute(['email' => mb_strtolower(trim($email))]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || $user['status'] !== 'active') {
            return false;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        Session::regenerate();
        Session::put('auth_user_id', (int) $user['id']);

        $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
            ->execute(['id' => $user['id']]);

        self::$cachedUser = $user;

        return true;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $id = Session::get('auth_user_id');
        return $id !== null ? (int) $id : null;
    }

    public static function user(): ?array
    {
        $id = self::id();

        if ($id === null) {
            self::$cachedUser = null;
            return null;
        }

        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }

        $stmt = Database::connection()->prepare(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id
               AND u.status = \'active\'
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            self::logout();
            return null;
        }

        self::$cachedUser = $user;

        return self::$cachedUser;
    }

    public static function role(): ?string
    {
        return self::user()['role_slug'] ?? null;
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    public static function isStudent(): bool
    {
        return self::role() === 'student';
    }

    public static function logout(): void
    {
        self::$cachedUser = null;

        if (session_status() === PHP_SESSION_ACTIVE) {
            Session::destroy();
        }
    }
}
