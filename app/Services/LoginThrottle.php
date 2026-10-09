<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Session;
use Throwable;

final class LoginThrottle
{
    private int $maxAttempts;
    private int $windowSeconds;
    private int $lockSeconds;

    public function __construct()
    {
        $this->maxAttempts = max(2, (int) env('LOGIN_MAX_ATTEMPTS', 5));
        $this->windowSeconds = max(60, (int) env('LOGIN_WINDOW_SECONDS', 300));
        $this->lockSeconds = max(60, (int) env('LOGIN_LOCK_SECONDS', 600));
    }

    public function isBlocked(string $email): bool
    {
        [$emailHash, $ipHash] = $this->keys($email);

        try {
            $stmt = Database::connection()->prepare(
                "SELECT locked_until
                 FROM login_attempts
                 WHERE email_hash = :email_hash
                   AND ip_hash = :ip_hash
                 LIMIT 1"
            );
            $stmt->execute([
                'email_hash' => $emailHash,
                'ip_hash' => $ipHash,
            ]);

            $lockedUntil = $stmt->fetchColumn();

            if (!is_string($lockedUntil) || $lockedUntil === '') {
                return false;
            }

            if (strtotime($lockedUntil) > time()) {
                return true;
            }

            $this->clear($email);

            return false;
        } catch (Throwable $e) {
            $this->logStorageFailure($e);

            return $this->sessionIsBlocked($emailHash, $ipHash);
        }
    }

    public function recordFailure(string $email): void
    {
        [$emailHash, $ipHash] = $this->keys($email);
        $pdo = null;

        try {
            $pdo = Database::connection();
            $pdo->beginTransaction();

            $pdo->prepare(
                "INSERT INTO login_attempts
                    (email_hash, ip_hash, attempts, window_started_at, locked_until, updated_at)
                 VALUES
                    (:email_hash, :ip_hash, 0, NOW(), NULL, NOW())
                 ON DUPLICATE KEY UPDATE
                    updated_at = NOW()"
            )->execute([
                'email_hash' => $emailHash,
                'ip_hash' => $ipHash,
            ]);

            $stmt = $pdo->prepare(
                "SELECT id, attempts, window_started_at, locked_until
                 FROM login_attempts
                 WHERE email_hash = :email_hash
                   AND ip_hash = :ip_hash
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute([
                'email_hash' => $emailHash,
                'ip_hash' => $ipHash,
            ]);
            $row = $stmt->fetch();

            if (!$row) {
                throw new \RuntimeException('Não foi possível registrar a tentativa de login.');
            }

            $now = time();
            $windowStarted = strtotime((string) $row['window_started_at']) ?: $now;
            $attempts = (int) $row['attempts'];

            if (($now - $windowStarted) > $this->windowSeconds) {
                $attempts = 0;
                $windowStarted = $now;
            }

            $attempts++;
            $lockedUntil = null;

            if ($attempts >= $this->maxAttempts) {
                $lockedUntil = date('Y-m-d H:i:s', $now + $this->lockSeconds);
            }

            $pdo->prepare(
                "UPDATE login_attempts
                 SET attempts = :attempts,
                     window_started_at = :window_started_at,
                     locked_until = :locked_until,
                     updated_at = NOW()
                 WHERE id = :id"
            )->execute([
                'attempts' => $attempts,
                'window_started_at' => date('Y-m-d H:i:s', $windowStarted),
                'locked_until' => $lockedUntil,
                'id' => (int) $row['id'],
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo !== null && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->logStorageFailure($e);
            $this->sessionRecordFailure($emailHash, $ipHash);
        }
    }

    public function clear(string $email): void
    {
        [$emailHash, $ipHash] = $this->keys($email);

        try {
            $stmt = Database::connection()->prepare(
                "DELETE FROM login_attempts
                 WHERE email_hash = :email_hash
                   AND ip_hash = :ip_hash"
            );
            $stmt->execute([
                'email_hash' => $emailHash,
                'ip_hash' => $ipHash,
            ]);
        } catch (Throwable $e) {
            $this->logStorageFailure($e);
        }

        $state = Session::get('_login_throttle', []);
        if (is_array($state)) {
            unset($state[$this->sessionKey($emailHash, $ipHash)]);
            Session::put('_login_throttle', $state);
        }
    }

    private function keys(string $email): array
    {
        $normalized = mb_strtolower(trim($email));
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

        return [
            hash('sha256', $normalized),
            hash('sha256', $ip),
        ];
    }

    private function sessionIsBlocked(string $emailHash, string $ipHash): bool
    {
        $state = Session::get('_login_throttle', []);
        if (!is_array($state)) {
            return false;
        }

        $row = $state[$this->sessionKey($emailHash, $ipHash)] ?? null;
        if (!is_array($row)) {
            return false;
        }

        return (int) ($row['locked_until'] ?? 0) > time();
    }

    private function sessionRecordFailure(string $emailHash, string $ipHash): void
    {
        $state = Session::get('_login_throttle', []);
        if (!is_array($state)) {
            $state = [];
        }

        $key = $this->sessionKey($emailHash, $ipHash);
        $now = time();
        $row = $state[$key] ?? [
            'attempts' => 0,
            'window_started_at' => $now,
            'locked_until' => 0,
        ];

        if (($now - (int) $row['window_started_at']) > $this->windowSeconds) {
            $row = [
                'attempts' => 0,
                'window_started_at' => $now,
                'locked_until' => 0,
            ];
        }

        $row['attempts'] = (int) $row['attempts'] + 1;

        if ($row['attempts'] >= $this->maxAttempts) {
            $row['locked_until'] = $now + $this->lockSeconds;
        }

        $state[$key] = $row;
        Session::put('_login_throttle', $state);
    }

    private function sessionKey(string $emailHash, string $ipHash): string
    {
        return $emailHash . ':' . $ipHash;
    }

    private function logStorageFailure(Throwable $e): void
    {
        static $logged = false;

        if ($logged) {
            return;
        }

        $logged = true;
        error_log(
            '[LoginThrottle] Persistência indisponível; usando fallback de sessão. '
            . $e::class . ': ' . $e->getMessage()
        );
    }
}
