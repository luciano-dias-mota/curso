<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Session;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Monitor de acesso do estudante.
 *
 * Objetivos da V1:
 * - registrar login, horário, IP e dispositivo;
 * - manter última atividade da sessão sem gravar a cada requisição;
 * - detectar forte suspeita de uso simultâneo em dispositivos distintos;
 * - detectar uso de muitos dispositivos em curto período;
 * - fornecer histórico e alertas ao painel administrativo.
 *
 * O device_id é um identificador aleatório first-party. Não usa fingerprint
 * invasivo, geolocalização, câmera ou qualquer dado biométrico.
 */
final class UserAccessMonitor
{
    private const DEVICE_COOKIE = 'curso_device';
    private const DEVICE_COOKIE_DAYS = 365;
    private const TOUCH_INTERVAL_SECONDS = 60;
    private const ACTIVE_WINDOW_MINUTES = 10;
    private const MANY_DEVICES_WINDOW_HOURS = 24;
    private const MANY_DEVICES_THRESHOLD = 3;

    private static ?bool $storageAvailable = null;

    public function registerLogin(int $userId): void
    {
        if ($userId <= 0 || !$this->available()) {
            return;
        }

        try {
            $pdo = Database::connection();
            $device = $this->deviceContext();
            $sessionHash = $this->sessionHash();

            // Um novo login no mesmo navegador encerra sessões antigas desse
            // mesmo dispositivo, reduzindo falso positivo no painel.
            $close = $pdo->prepare(
                "UPDATE user_access_sessions
                 SET logged_out_at = COALESCE(logged_out_at, NOW()),
                     updated_at = NOW()
                 WHERE user_id = :user_id
                   AND device_hash = :device_hash
                   AND session_hash <> :session_hash
                   AND logged_out_at IS NULL"
            );
            $close->execute([
                'user_id' => $userId,
                'device_hash' => $device['device_hash'],
                'session_hash' => $sessionHash,
            ]);

            $stmt = $pdo->prepare(
                "INSERT INTO user_access_sessions
                    (user_id, session_hash, device_hash, device_type, platform,
                     browser, device_label, ip_address, user_agent,
                     login_at, last_activity_at, logged_out_at)
                 VALUES
                    (:user_id, :session_hash, :device_hash, :device_type, :platform,
                     :browser, :device_label, :ip_address, :user_agent,
                     NOW(), NOW(), NULL)
                 ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    device_hash = VALUES(device_hash),
                    device_type = VALUES(device_type),
                    platform = VALUES(platform),
                    browser = VALUES(browser),
                    device_label = VALUES(device_label),
                    ip_address = VALUES(ip_address),
                    user_agent = VALUES(user_agent),
                    last_activity_at = NOW(),
                    logged_out_at = NULL,
                    updated_at = NOW()"
            );
            $stmt->execute([
                'user_id' => $userId,
                'session_hash' => $sessionHash,
                'device_hash' => $device['device_hash'],
                'device_type' => $device['device_type'],
                'platform' => $device['platform'],
                'browser' => $device['browser'],
                'device_label' => $device['device_label'],
                'ip_address' => $device['ip_address'],
                'user_agent' => $device['user_agent'],
            ]);

            Session::put('_access_touch_at', time());
            $this->detectSuspiciousAccess($pdo, $userId);
        } catch (Throwable $e) {
            $this->logFailure('registerLogin', $e);
        }
    }

    public function touch(int $userId): void
    {
        if ($userId <= 0 || !$this->available()) {
            return;
        }

        $lastTouch = (int) Session::get('_access_touch_at', 0);
        if ($lastTouch > 0 && (time() - $lastTouch) < self::TOUCH_INTERVAL_SECONDS) {
            return;
        }

        try {
            $pdo = Database::connection();
            $sessionHash = $this->sessionHash();
            $device = $this->deviceContext();

            $stmt = $pdo->prepare(
                "UPDATE user_access_sessions
                 SET last_activity_at = NOW(),
                     ip_address = :ip_address,
                     user_agent = :user_agent,
                     device_type = :device_type,
                     platform = :platform,
                     browser = :browser,
                     device_label = :device_label,
                     updated_at = NOW()
                 WHERE user_id = :user_id
                   AND session_hash = :session_hash
                   AND logged_out_at IS NULL"
            );
            $stmt->execute([
                'ip_address' => $device['ip_address'],
                'user_agent' => $device['user_agent'],
                'device_type' => $device['device_type'],
                'platform' => $device['platform'],
                'browser' => $device['browser'],
                'device_label' => $device['device_label'],
                'user_id' => $userId,
                'session_hash' => $sessionHash,
            ]);

            if ($stmt->rowCount() === 0) {
                $this->registerLogin($userId);
                return;
            }

            Session::put('_access_touch_at', time());
            $this->detectSuspiciousAccess($pdo, $userId);
        } catch (Throwable $e) {
            $this->logFailure('touch', $e);
        }
    }

    public function logout(int $userId): void
    {
        if ($userId <= 0 || !$this->available()) {
            return;
        }

        try {
            $stmt = Database::connection()->prepare(
                "UPDATE user_access_sessions
                 SET logged_out_at = COALESCE(logged_out_at, NOW()),
                     last_activity_at = NOW(),
                     updated_at = NOW()
                 WHERE user_id = :user_id
                   AND session_hash = :session_hash
                   AND logged_out_at IS NULL"
            );
            $stmt->execute([
                'user_id' => $userId,
                'session_hash' => $this->sessionHash(),
            ]);
        } catch (Throwable $e) {
            $this->logFailure('logout', $e);
        }
    }

    public function available(): bool
    {
        if (self::$storageAvailable !== null) {
            return self::$storageAvailable;
        }

        try {
            $pdo = Database::connection();
            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME IN ('user_access_sessions','user_access_alerts')"
            );
            self::$storageAvailable = (int) $stmt->fetchColumn() === 2;
        } catch (Throwable) {
            self::$storageAvailable = false;
        }

        return self::$storageAvailable;
    }

    public function history(int $userId, int $limit = 80): array
    {
        if (!$this->available()) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $stmt = Database::connection()->prepare(
            "SELECT id, device_type, platform, browser, device_label,
                    ip_address, login_at, last_activity_at, logged_out_at
             FROM user_access_sessions
             WHERE user_id = :user_id
             ORDER BY login_at DESC, id DESC
             LIMIT {$limit}"
        );
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $activeCutoff = time() - (self::ACTIVE_WINDOW_MINUTES * 60);
        foreach ($rows as &$row) {
            $lastActivity = strtotime((string) ($row['last_activity_at'] ?? '')) ?: 0;
            if (!empty($row['logged_out_at'])) {
                $row['access_status'] = 'ended';
                $row['access_status_label'] = 'Encerrada';
            } elseif ($lastActivity >= $activeCutoff) {
                $row['access_status'] = 'active';
                $row['access_status_label'] = 'Ativa agora';
            } else {
                $row['access_status'] = 'idle';
                $row['access_status_label'] = 'Inativa';
            }
        }
        unset($row);

        return $rows;
    }

    public function alertsForUser(int $userId, int $limit = 40): array
    {
        if (!$this->available()) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $stmt = Database::connection()->prepare(
            "SELECT id, alert_type, severity, title, description,
                    first_detected_at, last_detected_at, occurrence_count, resolved_at
             FROM user_access_alerts
             WHERE user_id = :user_id
             ORDER BY (resolved_at IS NULL) DESC, last_detected_at DESC, id DESC
             LIMIT {$limit}"
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function summary(int $userId): array
    {
        if (!$this->available()) {
            return [
                'available' => false,
                'sessions' => 0,
                'devices' => 0,
                'active_sessions' => 0,
                'active_devices' => 0,
                'open_alerts' => 0,
            ];
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS sessions,
                    COUNT(DISTINCT device_hash) AS devices,
                    SUM(
                        logged_out_at IS NULL
                        AND last_activity_at >= DATE_SUB(NOW(), INTERVAL " . self::ACTIVE_WINDOW_MINUTES . " MINUTE)
                    ) AS active_sessions,
                    COUNT(DISTINCT CASE
                        WHEN logged_out_at IS NULL
                         AND last_activity_at >= DATE_SUB(NOW(), INTERVAL " . self::ACTIVE_WINDOW_MINUTES . " MINUTE)
                        THEN device_hash END
                    ) AS active_devices
             FROM user_access_sessions
             WHERE user_id = :user_id"
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $alertStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM user_access_alerts
             WHERE user_id = :user_id
               AND resolved_at IS NULL"
        );
        $alertStmt->execute(['user_id' => $userId]);

        return [
            'available' => true,
            'sessions' => (int) ($row['sessions'] ?? 0),
            'devices' => (int) ($row['devices'] ?? 0),
            'active_sessions' => (int) ($row['active_sessions'] ?? 0),
            'active_devices' => (int) ($row['active_devices'] ?? 0),
            'open_alerts' => (int) $alertStmt->fetchColumn(),
        ];
    }

    public function activeAlerts(int $limit = 8): array
    {
        if (!$this->available()) {
            return [];
        }

        $limit = max(1, min(30, $limit));
        $stmt = Database::connection()->query(
            "SELECT a.id, a.user_id, a.alert_type, a.severity, a.title,
                    a.description, a.last_detected_at, a.occurrence_count,
                    u.name, u.email
             FROM user_access_alerts a
             INNER JOIN users u ON u.id = a.user_id
             WHERE a.resolved_at IS NULL
               AND a.last_detected_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
               AND u.status = 'active'
             ORDER BY
                CASE a.severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END,
                a.last_detected_at DESC
             LIMIT {$limit}"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function detectSuspiciousAccess(PDO $pdo, int $userId): void
    {
        // Alertas sem nova ocorrência por 24h deixam de ficar "abertos".
        // O histórico continua preservado para consulta administrativa.
        $closeOld = $pdo->prepare(
            "UPDATE user_access_alerts
             SET resolved_at = NOW(),
                 updated_at = NOW()
             WHERE user_id = :user_id
               AND resolved_at IS NULL
               AND last_detected_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $closeOld->execute(['user_id' => $userId]);

        $stmt = $pdo->prepare(
            "SELECT device_hash, device_label, ip_address, last_activity_at
             FROM user_access_sessions
             WHERE user_id = :user_id
               AND logged_out_at IS NULL
               AND last_activity_at >= DATE_SUB(NOW(), INTERVAL " . self::ACTIVE_WINDOW_MINUTES . " MINUTE)
             ORDER BY last_activity_at DESC"
        );
        $stmt->execute(['user_id' => $userId]);

        $devices = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $hash = (string) $row['device_hash'];
            if (!isset($devices[$hash])) {
                $devices[$hash] = $row;
            }
        }

        if (count($devices) >= 2) {
            $labels = array_map(
                static fn (array $row): string =>
                    (string) $row['device_label']
                    . (!empty($row['ip_address']) ? ' (' . $row['ip_address'] . ')' : ''),
                array_slice(array_values($devices), 0, 4)
            );

            $this->upsertAlert(
                $pdo,
                $userId,
                'concurrent_devices',
                'high',
                'Possível acesso simultâneo em dispositivos diferentes',
                'Foram detectados ' . count($devices)
                . ' dispositivos distintos com atividade nos últimos '
                . self::ACTIVE_WINDOW_MINUTES . ' minutos: '
                . implode(' • ', $labels) . '.'
            );
        }

        $manyStmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT device_hash)
             FROM user_access_sessions
             WHERE user_id = :user_id
               AND login_at >= DATE_SUB(NOW(), INTERVAL " . self::MANY_DEVICES_WINDOW_HOURS . " HOUR)"
        );
        $manyStmt->execute(['user_id' => $userId]);
        $deviceCount = (int) $manyStmt->fetchColumn();

        if ($deviceCount >= self::MANY_DEVICES_THRESHOLD) {
            $this->upsertAlert(
                $pdo,
                $userId,
                'many_devices',
                'medium',
                'Muitos dispositivos utilizados em curto período',
                'A conta registrou ' . $deviceCount . ' dispositivos distintos nas últimas '
                . self::MANY_DEVICES_WINDOW_HOURS . ' horas. Isso não prova compartilhamento, '
                . 'mas merece conferência administrativa.'
            );
        }
    }

    private function upsertAlert(
        PDO $pdo,
        int $userId,
        string $type,
        string $severity,
        string $title,
        string $description
    ): void {
        $existing = $pdo->prepare(
            "SELECT id
             FROM user_access_alerts
             WHERE user_id = :user_id
               AND alert_type = :alert_type
               AND resolved_at IS NULL
               AND last_detected_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)
             ORDER BY id DESC
             LIMIT 1"
        );
        $existing->execute([
            'user_id' => $userId,
            'alert_type' => $type,
        ]);
        $id = (int) ($existing->fetchColumn() ?: 0);

        if ($id > 0) {
            $update = $pdo->prepare(
                "UPDATE user_access_alerts
                 SET severity = :severity,
                     title = :title,
                     description = :description,
                     last_detected_at = NOW(),
                     occurrence_count = occurrence_count + 1,
                     updated_at = NOW()
                 WHERE id = :id"
            );
            $update->execute([
                'severity' => $severity,
                'title' => $title,
                'description' => $description,
                'id' => $id,
            ]);
            return;
        }

        $insert = $pdo->prepare(
            "INSERT INTO user_access_alerts
                (user_id, alert_type, severity, title, description,
                 first_detected_at, last_detected_at, occurrence_count)
             VALUES
                (:user_id, :alert_type, :severity, :title, :description,
                 NOW(), NOW(), 1)"
        );
        $insert->execute([
            'user_id' => $userId,
            'alert_type' => $type,
            'severity' => $severity,
            'title' => $title,
            'description' => $description,
        ]);
    }

    private function deviceContext(): array
    {
        $token = $this->deviceToken();
        $userAgent = substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 500);
        $platform = $this->platform($userAgent);
        $browser = $this->browser($userAgent);
        $deviceType = $this->deviceType($userAgent);

        return [
            'device_hash' => hash('sha256', $token),
            'device_type' => $deviceType,
            'platform' => $platform,
            'browser' => $browser,
            'device_label' => $platform . ' · ' . $browser . ' · ' . $deviceType,
            'ip_address' => substr(trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45),
            'user_agent' => $userAgent,
        ];
    }

    private function deviceToken(): string
    {
        $existing = trim((string) ($_COOKIE[self::DEVICE_COOKIE] ?? ''));
        if (preg_match('/^[a-f0-9]{64}$/', $existing) === 1) {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

        setcookie(self::DEVICE_COOKIE, $token, [
            'expires' => time() + (self::DEVICE_COOKIE_DAYS * 86400),
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::DEVICE_COOKIE] = $token;

        return $token;
    }

    private function sessionHash(): string
    {
        $id = session_id();
        if ($id === '') {
            $id = 'no-session-' . bin2hex(random_bytes(16));
        }

        return hash('sha256', $id);
    }

    private function platform(string $ua): string
    {
        $u = strtolower($ua);
        return match (true) {
            str_contains($u, 'android') => 'Android',
            str_contains($u, 'iphone') => 'iPhone',
            str_contains($u, 'ipad') => 'iPad',
            str_contains($u, 'windows nt') => 'Windows',
            str_contains($u, 'macintosh'), str_contains($u, 'mac os x') => 'macOS',
            str_contains($u, 'linux') => 'Linux',
            default => 'Plataforma desconhecida',
        };
    }

    private function browser(string $ua): string
    {
        $u = strtolower($ua);
        return match (true) {
            str_contains($u, 'edg/') => 'Edge',
            str_contains($u, 'opr/'), str_contains($u, 'opera') => 'Opera',
            str_contains($u, 'firefox/') => 'Firefox',
            str_contains($u, 'crios/'), str_contains($u, 'chrome/') => 'Chrome',
            str_contains($u, 'safari/') => 'Safari',
            default => 'Navegador desconhecido',
        };
    }

    private function deviceType(string $ua): string
    {
        $u = strtolower($ua);
        return match (true) {
            str_contains($u, 'ipad'), str_contains($u, 'tablet') => 'Tablet',
            str_contains($u, 'mobile'), str_contains($u, 'android'), str_contains($u, 'iphone') => 'Celular',
            default => 'Computador',
        };
    }

    private function logFailure(string $context, Throwable $e): void
    {
        error_log(
            '[UserAccessMonitor:' . $context . '] '
            . $e::class . ': ' . $e->getMessage()
        );
    }
}
