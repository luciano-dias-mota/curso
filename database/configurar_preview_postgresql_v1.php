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
use RuntimeException;
use Throwable;

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
$slug = 'postgresql-zero-intermediario';

$courseStmt = $pdo->prepare(
    "SELECT id, title, status, experience_key
       FROM courses
      WHERE slug = :slug
      LIMIT 1"
);
$courseStmt->execute(['slug' => $slug]);
$course = $courseStmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    fwrite(
        STDERR,
        "ABORTADO: curso PostgreSQL não encontrado. Execute primeiro database/criar_curso_postgresql_v1.php.\n"
    );
    exit(1);
}

if ((string) ($course['experience_key'] ?? '') !== 'postgresql') {
    fwrite(
        STDERR,
        "ABORTADO: o curso encontrado não está marcado com experience_key=postgresql.\n"
    );
    exit(1);
}

$courseId = (int) $course['id'];
$desired = [
    'catalog_preview_enabled' => ['1', 'boolean'],
    'catalog_preview_message' => [
        'Curso em construção. Estamos preparando aulas práticas e laboratórios. Em breve estará disponível.',
        'string',
    ],
];

$currentStmt = $pdo->prepare(
    "SELECT setting_key, setting_value, value_type
       FROM course_settings
      WHERE course_id = :course_id
        AND setting_key IN ('catalog_preview_enabled', 'catalog_preview_message')"
);
$currentStmt->execute(['course_id' => $courseId]);

$current = [];
foreach ($currentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $current[(string) $row['setting_key']] = [
        (string) ($row['setting_value'] ?? ''),
        (string) ($row['value_type'] ?? ''),
    ];
}

echo "=================================================================\n";
echo " LD ACADEMY - PREVIA DO CURSO POSTGRESQL V1\n";
echo "=================================================================\n";
echo 'Banco:  ' . (string) $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo 'Curso:  #' . $courseId . ' - ' . (string) $course['title'] . "\n";
echo 'Status: ' . (string) $course['status'] . "\n";
echo 'Modo:   ' . ($apply ? 'APLICAR' : 'DRY-RUN') . "\n\n";

foreach ($desired as $key => [$value, $type]) {
    $actual = $current[$key][0] ?? '(ausente)';
    $state = $actual === $value ? 'OK' : 'AJUSTAR';

    printf(
        "%-34s %-10s %s -> %s\n",
        $key,
        $state,
        $actual,
        $value
    );
}

if ((string) $course['status'] !== 'draft') {
    echo "\n[AVISO] O curso não está em draft. Este script NÃO altera o status.\n";
}

if (!$apply) {
    echo "\nNenhuma alteração foi realizada.\n";
    echo "A prévia exibirá apenas o card; o acesso ao curso continuará bloqueado.\n";
    echo "Após backup e revisão, execute:\n";
    echo "php database/configurar_preview_postgresql_v1.php --apply";
    if ($isProduction) {
        echo " --force-production";
    }
    echo "\n";
    exit(0);
}

$pdo->beginTransaction();

try {
    $upsert = $pdo->prepare(
        "INSERT INTO course_settings
            (course_id, setting_key, setting_value, value_type)
         VALUES
            (:course_id, :setting_key, :setting_value, :value_type)
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            value_type = VALUES(value_type)"
    );

    foreach ($desired as $key => [$value, $type]) {
        $upsert->execute([
            'course_id' => $courseId,
            'setting_key' => $key,
            'setting_value' => $value,
            'value_type' => $type,
        ]);
    }

    $verifyStmt = $pdo->prepare(
        "SELECT status
           FROM courses
          WHERE id = :course_id
          LIMIT 1"
    );
    $verifyStmt->execute(['course_id' => $courseId]);
    $status = (string) $verifyStmt->fetchColumn();

    if ($status !== (string) $course['status']) {
        throw new RuntimeException('O status do curso mudou inesperadamente durante a operação.');
    }

    $pdo->commit();

    echo "\n[OK] Prévia do card PostgreSQL ativada no catálogo.\n";
    echo "[OK] Mensagem 'Curso em construção' registrada.\n";
    echo "[OK] Status do curso preservado: {$status}.\n";
    echo "[OK] Nenhuma matrícula foi criada e nenhum conteúdo foi publicado.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "\nERRO: {$e->getMessage()}\n");
    exit(1);
}
