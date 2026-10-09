<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execução permitida apenas via CLI.');
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Database;
use App\Services\ProgressInitializer;

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

$loginTableExists = (bool) $pdo->query(
    "SELECT 1
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'login_attempts'
     LIMIT 1"
)->fetchColumn();

$uniqueXpExists = (bool) $pdo->query(
    "SELECT 1
     FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'xp_events'
       AND INDEX_NAME = 'uq_xp_event_once'
     LIMIT 1"
)->fetchColumn();

$sessionVersionExists = (bool) $pdo->query(
    "SELECT 1
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'users'
       AND COLUMN_NAME = 'session_version'
     LIMIT 1"
)->fetchColumn();

$duplicateGroups = $pdo->query(
    "SELECT user_id, event_type, reference_id, COUNT(*) AS qty
     FROM xp_events
     WHERE reference_id IS NOT NULL
     GROUP BY user_id, event_type, reference_id
     HAVING COUNT(*) > 1
     ORDER BY user_id, event_type, reference_id"
)->fetchAll(PDO::FETCH_ASSOC);

$activeEnrollments = (int) $pdo->query(
    "SELECT COUNT(*) FROM enrollments WHERE status = 'active'"
)->fetchColumn();

printf("=============================================================\n");
printf(" HARDENING / CORREÇÕES URGENTES - V6\n");
printf("=============================================================\n");
printf("Banco: %s\n", (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Modo:  %s\n\n", $apply ? 'APLICAR' : 'DRY-RUN');
printf("login_attempts: %s\n", $loginTableExists ? 'OK' : 'CRIAR');
printf("uq_xp_event_once: %s\n", $uniqueXpExists ? 'OK' : 'CRIAR');
printf("users.session_version: %s\n", $sessionVersionExists ? 'OK' : 'CRIAR');
printf("Grupos de XP duplicado: %d\n", count($duplicateGroups));
printf("Matrículas ativas a verificar: %d\n", $activeEnrollments);

if (!$apply) {
    echo "\nNenhuma alteração foi realizada.\n";
    echo "Após backup, execute: php database/aplicar_correcoes_seguranca_v6.php --apply\n";
    if ($isProduction) {
        echo "Em produção: acrescente --force-production após revisar o dry-run.\n";
    }
    exit(0);
}

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            email_hash CHAR(64) NOT NULL,
            ip_hash CHAR(64) NOT NULL,
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            window_started_at DATETIME NOT NULL,
            locked_until DATETIME NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_login_attempt_scope (email_hash, ip_hash),
            KEY idx_login_attempts_locked_until (locked_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    if (!$sessionVersionExists) {
        $pdo->exec(
            "ALTER TABLE users
             ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1
             AFTER remember_token"
        );
    }

    if ($duplicateGroups !== []) {
        $pdo->beginTransaction();

        $rowsStmt = $pdo->prepare(
            "SELECT id, xp_amount
             FROM xp_events
             WHERE user_id = :user_id
               AND event_type = :event_type
               AND reference_id = :reference_id
             ORDER BY id
             FOR UPDATE"
        );
        $adjustUser = $pdo->prepare(
            "UPDATE users
             SET xp_total = GREATEST(0, xp_total - :xp)
             WHERE id = :user_id"
        );

        foreach ($duplicateGroups as $group) {
            $rowsStmt->execute([
                'user_id' => (int) $group['user_id'],
                'event_type' => (string) $group['event_type'],
                'reference_id' => (int) $group['reference_id'],
            ]);
            $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($rows) <= 1) {
                continue;
            }

            array_shift($rows);
            $deleteIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
            $xpToRemove = array_sum(
                array_map(static fn (array $row): int => (int) $row['xp_amount'], $rows)
            );

            $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
            $pdo->prepare("DELETE FROM xp_events WHERE id IN ({$placeholders})")
                ->execute($deleteIds);

            if ($xpToRemove > 0) {
                $adjustUser->execute([
                    'xp' => $xpToRemove,
                    'user_id' => (int) $group['user_id'],
                ]);
            }
        }

        $pdo->commit();
    }

    if (!$uniqueXpExists) {
        $pdo->exec(
            "ALTER TABLE xp_events
             ADD UNIQUE KEY uq_xp_event_once (user_id, event_type, reference_id)"
        );
    }

    $initializer = new ProgressInitializer();
    $enrollments = $pdo->query(
        "SELECT DISTINCT user_id, course_id
         FROM enrollments
         WHERE status = 'active'
         ORDER BY user_id, course_id"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($enrollments as $enrollment) {
        $initializer->initializeEnrollment(
            (int) $enrollment['user_id'],
            (int) $enrollment['course_id'],
            $pdo
        );
    }

    // Recalcula current_level a partir do xp_total existente.
    $users = $pdo->query('SELECT id, xp_total FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $levelStmt = $pdo->prepare(
        "SELECT level_number
         FROM levels
         WHERE min_xp <= :xp
           AND (max_xp IS NULL OR max_xp >= :xp_max)
         ORDER BY level_number DESC
         LIMIT 1"
    );
    $updateLevel = $pdo->prepare(
        'UPDATE users SET current_level = :level WHERE id = :user_id'
    );

    foreach ($users as $user) {
        $xp = (int) $user['xp_total'];
        $levelStmt->execute(['xp' => $xp, 'xp_max' => $xp]);
        $level = (int) ($levelStmt->fetchColumn() ?: 1);
        $updateLevel->execute([
            'level' => $level,
            'user_id' => (int) $user['id'],
        ]);
    }

    echo "\n[OK] login_attempts pronta.\n";
    echo "[OK] users.session_version pronta.\n";
    echo "[OK] unicidade de XP aplicada.\n";
    echo '[OK] progressão verificada para ' . count($enrollments) . " matrícula(s) ativa(s).\n";
    echo '[OK] current_level recalculado para ' . count($users) . " usuário(s).\n";
    echo "\nHardening V6 concluído.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "ERRO: {$e->getMessage()}\n");
    exit(2);
}
