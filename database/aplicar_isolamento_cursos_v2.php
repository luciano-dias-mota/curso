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

App::boot(BASE_PATH);

$apply = in_array('--apply', $argv ?? [], true);
$forceProduction = in_array('--force-production', $argv ?? [], true);
$isProduction = strtolower((string) config('app.env', 'production')) === 'production';

if ($apply && $isProduction && !$forceProduction) {
    fwrite(
        STDERR,
        "ABORTADO: APP_ENV=production. Revise o dry-run, faça backup e use --apply --force-production.\n"
    );
    exit(1);
}

$pdo = Database::connection();

function columnExistsIsolationV2(\PDO $pdo, string $table, string $column): bool
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

function indexExistsIsolationV2(\PDO $pdo, string $table, string $index): bool
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

$hasExperience = columnExistsIsolationV2($pdo, 'courses', 'experience_key');
$hasIndex = indexExistsIsolationV2($pdo, 'courses', 'idx_courses_experience_status');

echo "=================================================================\n";
echo " CURSO MI - ISOLAMENTO DE EXPERIENCIAS V2\n";
echo "=================================================================\n";
echo 'Banco: ' . (string) $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo 'Modo:  ' . ($apply ? 'APLICAR' : 'DRY-RUN') . "\n\n";

printf(
    "%-45s %s\n",
    'courses.experience_key',
    $hasExperience ? 'OK' : 'CRIAR/AJUSTAR'
);
printf(
    "%-45s %s\n",
    'courses.idx_courses_experience_status',
    $hasIndex ? 'OK' : 'CRIAR/AJUSTAR'
);

if ($hasExperience) {
    $pmmt = (int) $pdo->query(
        "SELECT COUNT(*)
           FROM courses
          WHERE experience_key = 'pmmt'"
    )->fetchColumn();
    $postgres = (int) $pdo->query(
        "SELECT COUNT(*)
           FROM courses
          WHERE experience_key = 'postgresql'"
    )->fetchColumn();

    echo "Cursos experience_key=pmmt: {$pmmt}\n";
    echo "Cursos experience_key=postgresql: {$postgres}\n";
} else {
    echo "Backfill previsto: cursos PMMT -> pmmt; curso PostgreSQL -> postgresql.\n";
}

if (!$apply) {
    echo "\nNenhuma alteração foi realizada.\n";
    echo "Após backup e revisão, execute:\n";
    echo "php database/aplicar_isolamento_cursos_v2.php --apply";
    if ($isProduction) {
        echo " --force-production";
    }
    echo "\n";
    exit(0);
}

try {
    if (!$hasExperience) {
        $pdo->exec(
            "ALTER TABLE courses
             ADD COLUMN experience_key VARCHAR(80) NOT NULL DEFAULT 'generic'
             AFTER slug"
        );
    }

    if (!$hasIndex) {
        $pdo->exec(
            "ALTER TABLE courses
             ADD INDEX idx_courses_experience_status
             (experience_key, status, position)"
        );
    }

    $pdo->exec(
        "UPDATE courses
            SET experience_key = 'pmmt'
          WHERE experience_key = 'generic'
            AND (
                LOWER(slug) LIKE '%pmmt%'
                OR UPPER(title) LIKE '%PMMT%'
            )"
    );

    $pdo->exec(
        "UPDATE courses
            SET experience_key = 'postgresql'
          WHERE experience_key = 'generic'
            AND (
                slug = 'postgresql-zero-intermediario'
                OR LOWER(title) LIKE 'postgresql%'
            )"
    );

    echo "\n[OK] experience_key disponível por curso.\n";
    echo "[OK] Índice de experiência criado/verificado.\n";
    echo "[OK] PMMT e PostgreSQL reconciliados por backfill idempotente.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "\nERRO: {$e->getMessage()}\n");
    exit(1);
}
