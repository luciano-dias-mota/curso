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

function tableExists(PDO $pdo, string $table): bool
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

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name
         LIMIT 1"
    );
    $stmt->execute(['table_name' => $table, 'column_name' => $column]);
    return (bool) $stmt->fetchColumn();
}

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND INDEX_NAME = :index_name
         LIMIT 1"
    );
    $stmt->execute(['table_name' => $table, 'index_name' => $index]);
    return (bool) $stmt->fetchColumn();
}

function constraintExists(PDO $pdo, string $table, string $constraint): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND CONSTRAINT_NAME = :constraint_name
         LIMIT 1"
    );
    $stmt->execute(['table_name' => $table, 'constraint_name' => $constraint]);
    return (bool) $stmt->fetchColumn();
}

$checks = [
    'phases.is_required' => columnExists($pdo, 'phases', 'is_required'),
    'lessons.is_required' => columnExists($pdo, 'lessons', 'is_required'),
    'course_settings' => tableExists($pdo, 'course_settings'),
    'achievements.course_id' => columnExists($pdo, 'achievements', 'course_id'),
    'enrollments.idx_enrollments_user_status' => indexExists($pdo, 'enrollments', 'idx_enrollments_user_status'),
];

echo "=================================================================\n";
echo " PLATAFORMA MULTI-CURSO - FUNDAÇÃO V1\n";
echo "=================================================================\n";
echo 'Banco: ' . (string) $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo 'Modo:  ' . ($apply ? 'APLICAR' : 'DRY-RUN') . "\n\n";

foreach ($checks as $name => $exists) {
    printf("%-45s %s\n", $name, $exists ? 'OK' : 'CRIAR/AJUSTAR');
}

if (!$apply) {
    echo "\nNenhuma alteração foi realizada.\n";
    echo "Após backup, execute: php database/aplicar_plataforma_multicursos_v1.php --apply\n";
    if ($isProduction) {
        echo "Em produção: acrescente --force-production após revisar o dry-run.\n";
    }
    exit(0);
}

try {
    if (!columnExists($pdo, 'phases', 'is_required')) {
        $pdo->exec(
            "ALTER TABLE phases
             ADD COLUMN is_required TINYINT(1) NOT NULL DEFAULT 1 AFTER xp_reward"
        );
    }

    if (!columnExists($pdo, 'lessons', 'is_required')) {
        $pdo->exec(
            "ALTER TABLE lessons
             ADD COLUMN is_required TINYINT(1) NOT NULL DEFAULT 1 AFTER xp_reward"
        );
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS course_settings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            course_id BIGINT UNSIGNED NOT NULL,
            setting_key VARCHAR(120) NOT NULL,
            setting_value LONGTEXT NULL,
            value_type ENUM('string','integer','decimal','boolean','json') NOT NULL DEFAULT 'string',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_course_settings_course
                FOREIGN KEY (course_id) REFERENCES courses(id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            UNIQUE KEY uq_course_setting (course_id, setting_key),
            INDEX idx_course_settings_course (course_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!indexExists($pdo, 'enrollments', 'idx_enrollments_user_status')) {
        $pdo->exec(
            "ALTER TABLE enrollments
             ADD INDEX idx_enrollments_user_status (user_id, status, course_id)"
        );
    }

    if (!columnExists($pdo, 'achievements', 'course_id')) {
        $pdo->exec(
            "ALTER TABLE achievements
             ADD COLUMN course_id BIGINT UNSIGNED NULL AFTER id"
        );
    }

    if (!indexExists($pdo, 'achievements', 'idx_achievements_course_active')) {
        $pdo->exec(
            "ALTER TABLE achievements
             ADD INDEX idx_achievements_course_active (course_id, active)"
        );
    }

    if (!constraintExists($pdo, 'achievements', 'fk_achievements_course')) {
        $pdo->exec(
            "ALTER TABLE achievements
             ADD CONSTRAINT fk_achievements_course
             FOREIGN KEY (course_id) REFERENCES courses(id)
             ON UPDATE CASCADE ON DELETE CASCADE"
        );
    }

    $defaults = [
        ['module_access_mode', 'free', 'string'],
        ['lesson_access_mode', 'sequential', 'string'],
        ['exercises_enabled', '1', 'boolean'],
        ['simulations_enabled', '1', 'boolean'],
        ['notes_enabled', '1', 'boolean'],
        ['certificate_enabled', '0', 'boolean'],
        ['lab_enabled', '0', 'boolean'],
    ];

    $insertSetting = $pdo->prepare(
        "INSERT INTO course_settings (course_id, setting_key, setting_value, value_type)
         SELECT c.id, :setting_key, :setting_value, :value_type
         FROM courses c
         ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key)"
    );

    foreach ($defaults as [$key, $value, $type]) {
        $insertSetting->execute([
            'setting_key' => $key,
            'setting_value' => $value,
            'value_type' => $type,
        ]);
    }

    $platformName = $pdo->prepare(
        "UPDATE system_settings
         SET setting_value = 'Curso MI'
         WHERE setting_key = 'platform_name'
           AND setting_value = 'PMMT Academy'"
    );
    $platformName->execute();

    echo "\n[OK] Estrutura multi-curso aplicada.\n";
    echo "[OK] Colunas de conteúdo obrigatório sincronizadas.\n";
    echo "[OK] Configurações por curso disponíveis.\n";
    echo "[OK] Conquistas podem ser globais ou vinculadas a um curso.\n";
    echo "[OK] Índice de matrículas otimizado para múltiplos cursos.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nERRO: {$e->getMessage()}\n");
    exit(1);
}
