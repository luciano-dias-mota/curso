<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Auth
{
    private const DUMMY_PASSWORD_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

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

        if (!$user || ($user['status'] ?? null) !== 'active') {
            password_verify($password, self::DUMMY_PASSWORD_HASH);
            return false;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
                ->execute(['hash' => $newHash, 'id' => $user['id']]);
            $user['password_hash'] = $newHash;
        }

        Session::regenerate();
        Session::forget('_csrf_token');
        Session::put('auth_user_id', (int) $user['id']);
        Session::put(
            'auth_session_version',
            max(1, (int) ($user['session_version'] ?? 1))
        );

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
            return null;
        }

        if (self::$cachedUser !== null && (int) (self::$cachedUser['id'] ?? 0) === $id) {
            $cachedVersion = max(1, (int) (self::$cachedUser['session_version'] ?? 1));
            $sessionVersion = (int) Session::get('auth_session_version', 0);

            if (
                (self::$cachedUser['status'] ?? null) !== 'active'
                || $sessionVersion !== $cachedVersion
            ) {
                self::invalidateAuthentication();
                return null;
            }

            return self::$cachedUser;
        }

        $stmt = Database::connection()->prepare(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || ($user['status'] ?? null) !== 'active') {
            self::invalidateAuthentication();
            return null;
        }

        $sessionVersion = (int) Session::get('auth_session_version', 0);
        $userVersion = max(1, (int) ($user['session_version'] ?? 1));

        if ($sessionVersion !== $userVersion) {
            self::invalidateAuthentication();
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

    private static function invalidateAuthentication(): void
    {
        self::$cachedUser = null;
        Session::forget('auth_user_id');
        Session::forget('auth_session_version');
        Session::forget('_csrf_token');
        Session::regenerate();
    }

    public static function logout(): void
    {
        self::$cachedUser = null;
        Session::destroy();
    }
}
