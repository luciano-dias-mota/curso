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
use PDO;
use Throwable;

App::boot(BASE_PATH);

$apply = in_array('--apply', $argv ?? [], true);
$forceProduction = in_array('--force-production', $argv ?? [], true);
$isProduction = strtolower((string) config('app.env', 'production')) === 'production';

if ($apply && $isProduction && !$forceProduction) {
    fwrite(
        STDERR,
        "ABORTADO: APP_ENV=production. Faça backup e use --apply --force-production somente após revisar o dry-run.\n"
    );
    exit(1);
}

$pdo = Database::connection();

function tableExistsLabV1(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
         LIMIT 1"
    );
    $stmt->execute(['table_name' => $table]);
    return (bool) $stmt->fetchColumn();
}

function columnExistsLabV1(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name
         LIMIT 1"
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);
    return (bool) $stmt->fetchColumn();
}

function columnDataTypeLabV1(PDO $pdo, string $table, string $column): string
{
    $stmt = $pdo->prepare(
        "SELECT DATA_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name
         LIMIT 1"
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);
    return strtolower((string) ($stmt->fetchColumn() ?: ''));
}

function indexExistsLabV1(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND INDEX_NAME = :index_name
         LIMIT 1"
    );
    $stmt->execute([
        'table_name' => $table,
        'index_name' => $index,
    ]);
    return (bool) $stmt->fetchColumn();
}

$checks = [
    'lessons.completion_mode' => columnExistsLabV1($pdo, 'lessons', 'completion_mode'),
    'lab_activities' => tableExistsLabV1($pdo, 'lab_activities'),
    'lab_activity_hints' => tableExistsLabV1($pdo, 'lab_activity_hints'),
    'lab_attempts' => tableExistsLabV1($pdo, 'lab_attempts'),
    'lab_activity_progress' => tableExistsLabV1($pdo, 'lab_activity_progress'),
    'lab_course_state' => tableExistsLabV1($pdo, 'lab_course_state'),
    'xp_events.event_type extensível' => columnDataTypeLabV1($pdo, 'xp_events', 'event_type') === 'varchar',
    'xp_events.uq_xp_event_once' => indexExistsLabV1($pdo, 'xp_events', 'uq_xp_event_once'),
];

echo "=================================================================\n";
echo " CURSO MI - MOTOR GENERICO DE LABORATORIOS V1\n";
echo "=================================================================\n";
echo 'Banco: ' . (string) $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo 'Modo:  ' . ($apply ? 'APLICAR' : 'DRY-RUN') . "\n\n";

foreach ($checks as $name => $exists) {
    printf("%-45s %s\n", $name, $exists ? 'OK' : 'CRIAR/AJUSTAR');
}

if (!$apply) {
    echo "\nNenhuma alteração foi realizada.\n";
    echo "Após backup, execute: php database/aplicar_motor_laboratorios_v1.php --apply\n";
    if ($isProduction) {
        echo "Em produção: acrescente --force-production após revisar o dry-run.\n";
    }
    exit(0);
}

try {
    if (!columnExistsLabV1($pdo, 'lessons', 'completion_mode')) {
        $pdo->exec(
            "ALTER TABLE lessons
             ADD COLUMN completion_mode VARCHAR(30) NULL AFTER is_required"
        );
    }

    // Event types deixam de ser ENUM para que novos recursos da plataforma
    // (laboratórios e futuros motores) não exijam alterar o schema a cada evento.
    if (columnDataTypeLabV1($pdo, 'xp_events', 'event_type') !== 'varchar') {
        $pdo->exec(
            "ALTER TABLE xp_events
             MODIFY COLUMN event_type VARCHAR(50) NOT NULL"
        );
    }

    if (!indexExistsLabV1($pdo, 'xp_events', 'uq_xp_event_once')) {
        $pdo->exec(
            "ALTER TABLE xp_events
             ADD UNIQUE KEY uq_xp_event_once (user_id, event_type, reference_id)"
        );
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS lab_activities (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            lesson_id BIGINT UNSIGNED NOT NULL,
            engine_key VARCHAR(80) NOT NULL,
            activity_type VARCHAR(50) NOT NULL DEFAULT 'code',
            title VARCHAR(200) NOT NULL,
            instructions LONGTEXT NOT NULL,
            starter_code LONGTEXT NULL,
            validator_type VARCHAR(80) NOT NULL DEFAULT 'custom',
            validator_config LONGTEXT NULL,
            bootstrap_config LONGTEXT NULL,
            unlock_rule ENUM('lesson_available','reading_complete') NOT NULL DEFAULT 'reading_complete',
            state_version INT UNSIGNED NOT NULL DEFAULT 0,
            xp_reward INT UNSIGNED NOT NULL DEFAULT 0,
            is_required TINYINT(1) NOT NULL DEFAULT 1,
            max_attempts INT UNSIGNED NULL,
            position INT UNSIGNED NOT NULL DEFAULT 1,
            status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_lab_activities_lesson
                FOREIGN KEY (lesson_id) REFERENCES lessons(id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            UNIQUE KEY uq_lab_activity_position (lesson_id, position),
            INDEX idx_lab_activity_engine_status (engine_key, status),
            INDEX idx_lab_activity_lesson_required (lesson_id, is_required, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS lab_activity_hints (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            activity_id BIGINT UNSIGNED NOT NULL,
            position SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            title VARCHAR(180) NULL,
            content LONGTEXT NOT NULL,
            unlock_after_attempts INT UNSIGNED NOT NULL DEFAULT 1,
            xp_penalty INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_lab_hints_activity
                FOREIGN KEY (activity_id) REFERENCES lab_activities(id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            UNIQUE KEY uq_lab_hint_position (activity_id, position)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS lab_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            activity_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            attempt_number INT UNSIGNED NOT NULL,
            submission_payload LONGTEXT NOT NULL,
            result_payload LONGTEXT NULL,
            passed TINYINT(1) NOT NULL DEFAULT 0,
            score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            verification_source ENUM('server','client_evidence','hybrid') NOT NULL DEFAULT 'server',
            execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
            hints_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            xp_earned INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_lab_attempt_activity
                FOREIGN KEY (activity_id) REFERENCES lab_activities(id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_lab_attempt_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            UNIQUE KEY uq_lab_attempt_number (activity_id, user_id, attempt_number),
            INDEX idx_lab_attempt_user_date (user_id, created_at),
            INDEX idx_lab_attempt_activity_passed (activity_id, passed)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS lab_activity_progress (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            activity_id BIGINT UNSIGNED NOT NULL,
            status ENUM('available','in_progress','completed') NOT NULL DEFAULT 'available',
            attempts_count INT UNSIGNED NOT NULL DEFAULT 0,
            hints_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            best_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            completed_at DATETIME NULL,
            last_attempt_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_lab_progress_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_lab_progress_activity
                FOREIGN KEY (activity_id) REFERENCES lab_activities(id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            UNIQUE KEY uq_lab_progress_user_activity (user_id, activity_id),
            INDEX idx_lab_progress_user_status (user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS lab_course_state (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            course_id BIGINT UNSIGNED NOT NULL,
            engine_key VARCHAR(80) NOT NULL,
            state_version INT UNSIGNED NOT NULL DEFAULT 0,
            last_activity_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_lab_state_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_lab_state_course
                FOREIGN KEY (course_id) REFERENCES courses(id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_lab_state_activity
                FOREIGN KEY (last_activity_id) REFERENCES lab_activities(id)
                ON UPDATE CASCADE ON DELETE SET NULL,
            UNIQUE KEY uq_lab_state_user_course_engine (user_id, course_id, engine_key),
            INDEX idx_lab_state_course_engine (course_id, engine_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (tableExistsLabV1($pdo, 'course_settings')) {
        $insertSetting = $pdo->prepare(
            "INSERT INTO course_settings
                (course_id, setting_key, setting_value, value_type)
             SELECT c.id, 'lesson_completion_mode', 'quiz', 'string'
             FROM courses c
             ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key)"
        );
        $insertSetting->execute();
    }

    echo "\n[OK] Motor genérico de laboratórios instalado.\n";
    echo "[OK] Nenhum provider tecnológico foi registrado automaticamente.\n";
    echo "[OK] Cursos existentes continuam usando quiz como conclusão padrão.\n";
    echo "[OK] xp_events.event_type agora aceita eventos extensíveis da plataforma.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nERRO: {$e->getMessage()}\n");
    exit(1);
}
