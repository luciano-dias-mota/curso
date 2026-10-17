<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida apenas via CLI.\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Database;

App::boot(BASE_PATH);

$apply = in_array('--apply', $argv ?? [], true);
$forceProduction = in_array('--force-production', $argv ?? [], true);
$isProduction = strtolower((string) config('app.env', 'production')) === 'production';

if ($apply && $isProduction && !$forceProduction) {
    fwrite(
        STDERR,
        "ABORTADO: APP_ENV=production. Faça backup e execute com --apply --force-production somente após revisar o dry-run.\n"
    );
    exit(1);
}

$pdo = Database::connection();

$tableExists = static function (string $table) use ($pdo): bool {
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
         LIMIT 1"
    );
    $stmt->execute(['table' => $table]);
    return (bool) $stmt->fetchColumn();
};

$sessionsExists = $tableExists('user_access_sessions');
$alertsExists = $tableExists('user_access_alerts');

echo "=============================================================\n";
echo " MONITOR DE ACESSOS DO ESTUDANTE - V1\n";
echo "=============================================================\n";
printf("Banco: %s\n", (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Modo:  %s\n\n", $apply ? 'APLICAR' : 'DRY-RUN');
printf("user_access_sessions: %s\n", $sessionsExists ? 'OK' : 'CRIAR');
printf("user_access_alerts:   %s\n", $alertsExists ? 'OK' : 'CRIAR');

if (!$apply) {
    echo "\nNenhuma alteração foi realizada.\n";
    echo "Após backup, execute: php database/aplicar_monitor_acessos_v1.php --apply";
    if ($isProduction) {
        echo " --force-production";
    }
    echo "\n";
    exit(0);
}

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_access_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            session_hash CHAR(64) NOT NULL,
            device_hash CHAR(64) NOT NULL,
            device_type VARCHAR(30) NOT NULL DEFAULT 'Computador',
            platform VARCHAR(80) NOT NULL DEFAULT 'Plataforma desconhecida',
            browser VARCHAR(80) NOT NULL DEFAULT 'Navegador desconhecido',
            device_label VARCHAR(180) NOT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(500) NULL,
            login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            logged_out_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_user_access_sessions_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            UNIQUE KEY uq_user_access_session_hash (session_hash),
            KEY idx_user_access_user_activity (user_id, last_activity_at),
            KEY idx_user_access_user_device (user_id, device_hash, login_at),
            KEY idx_user_access_open (user_id, logged_out_at, last_activity_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_access_alerts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            alert_type VARCHAR(50) NOT NULL,
            severity ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
            title VARCHAR(180) NOT NULL,
            description TEXT NOT NULL,
            first_detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
            resolved_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_user_access_alerts_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            KEY idx_user_access_alert_user (user_id, resolved_at, last_detected_at),
            KEY idx_user_access_alert_open (resolved_at, severity, last_detected_at),
            KEY idx_user_access_alert_type (user_id, alert_type, last_detected_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    echo "\n[OK] user_access_sessions pronta.\n";
    echo "[OK] user_access_alerts pronta.\n";
    echo "[OK] Nenhum histórico acadêmico foi alterado.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERRO: {$e->getMessage()}\n");
    exit(2);
}
