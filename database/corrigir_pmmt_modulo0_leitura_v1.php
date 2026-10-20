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
        "ABORTADO: APP_ENV=production. Faça backup e use --apply --force-production após revisar o dry-run.\n"
    );
    exit(1);
}

$pdo = Database::connection();

$column = $pdo->query(
    "SELECT 1
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'lessons'
       AND COLUMN_NAME = 'completion_mode'
     LIMIT 1"
);

if (!$column->fetchColumn()) {
    fwrite(
        STDERR,
        "[FALHA] A coluna lessons.completion_mode ainda não existe neste banco. Nenhuma alteração realizada.\n"
    );
    exit(2);
}

$courseStmt = $pdo->prepare(
    "SELECT id, title, slug
     FROM courses
     WHERE status = 'published'
       AND (
            id = 2
            OR slug = 'preparacao-pmmt-merito-intelectual-e-caoc'
            OR title LIKE '%PMMT%'
       )
     ORDER BY (id = 2) DESC, id
     LIMIT 1"
);
$courseStmt->execute();
$course = $courseStmt->fetch(\PDO::FETCH_ASSOC);

if (!$course) {
    fwrite(STDERR, "[FALHA] Curso Preparatório PMMT publicado não encontrado.\n");
    exit(3);
}

$moduleStmt = $pdo->prepare(
    "SELECT id, title, slug, position
     FROM modules
     WHERE course_id = :course_id
       AND status = 'published'
       AND (
            position = 0
            OR title LIKE '%Orienta%'
            OR slug LIKE '%orient%'
       )
     ORDER BY (position = 0) DESC, position, id
     LIMIT 1"
);
$moduleStmt->execute(['course_id' => (int) $course['id']]);
$module = $moduleStmt->fetch(\PDO::FETCH_ASSOC);

if (!$module) {
    fwrite(
        STDERR,
        "[FALHA] Módulo 0 / Orientações não encontrado no curso PMMT. Nenhuma alteração realizada.\n"
    );
    exit(4);
}

$lessonsStmt = $pdo->prepare(
    "SELECT l.id,
            l.title,
            l.completion_mode,
            l.position,
            p.id AS phase_id,
            p.title AS phase_title,
            (
                SELECT COUNT(*)
                FROM quizzes qz
                WHERE qz.lesson_id = l.id
                  AND qz.quiz_type = 'lesson_fixation'
                  AND qz.active = 1
            ) AS fixation_quizzes
     FROM lessons l
     INNER JOIN phases p
       ON p.id = l.phase_id
      AND p.status = 'published'
     WHERE p.module_id = :module_id
       AND l.status = 'published'
     ORDER BY p.position, p.id, l.position, l.id"
);
$lessonsStmt->execute(['module_id' => (int) $module['id']]);
$lessons = $lessonsStmt->fetchAll(\PDO::FETCH_ASSOC);

if ($lessons === []) {
    fwrite(
        STDERR,
        "[FALHA] Nenhuma aula publicada encontrada no Módulo 0 / Orientações.\n"
    );
    exit(5);
}

echo "=================================================================\n";
echo " HOTFIX PMMT - MODULO 0 CONCLUSAO POR LEITURA V1\n";
echo "=================================================================\n";
printf("Banco:  %s\n", (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Curso:  #%d - %s\n", (int) $course['id'], (string) $course['title']);
printf(
    "Módulo: #%d - %s (posição %d)\n",
    (int) $module['id'],
    (string) $module['title'],
    (int) $module['position']
);
printf("Modo:   %s\n\n", $apply ? 'APLICAÇÃO' : 'DRY-RUN');

$alreadyReading = 0;

foreach ($lessons as $lesson) {
    $mode = trim((string) ($lesson['completion_mode'] ?? ''));
    if ($mode === 'reading') {
        $alreadyReading++;
    }

    printf(
        "Aula #%d | fase #%d | %-42s | modo=%-8s | quiz_fixacao=%d\n",
        (int) $lesson['id'],
        (int) $lesson['phase_id'],
        mb_strimwidth((string) $lesson['title'], 0, 42, '…'),
        $mode !== '' ? $mode : '(padrão)',
        (int) $lesson['fixation_quizzes']
    );
}

printf(
    "\nResumo: %d aula(s); %d já configurada(s) como reading; %d a ajustar.\n",
    count($lessons),
    $alreadyReading,
    count($lessons) - $alreadyReading
);

echo "\nO hotfix NÃO remove questões, quizzes, fases ou conteúdo.\n";
echo "Ele altera apenas lessons.completion_mode para 'reading' neste módulo.\n";

if (!$apply) {
    echo "\nDRY-RUN concluído. Nenhuma alteração foi gravada.\n";
    echo "Após backup, execute:\n";
    echo "php database/corrigir_pmmt_modulo0_leitura_v1.php --apply";
    if ($isProduction) {
        echo " --force-production";
    }
    echo "\n";
    exit(0);
}

$pdo->beginTransaction();

try {
    $update = $pdo->prepare(
        "UPDATE lessons l
         INNER JOIN phases p ON p.id = l.phase_id
         SET l.completion_mode = 'reading',
             l.updated_at = NOW()
         WHERE p.module_id = :module_id
           AND p.status = 'published'
           AND l.status = 'published'
           AND COALESCE(l.completion_mode, '') <> 'reading'"
    );
    $update->execute(['module_id' => (int) $module['id']]);

    $changed = $update->rowCount();

    $verify = $pdo->prepare(
        "SELECT COUNT(*)
         FROM lessons l
         INNER JOIN phases p ON p.id = l.phase_id
         WHERE p.module_id = :module_id
           AND p.status = 'published'
           AND l.status = 'published'
           AND l.completion_mode <> 'reading'"
    );
    $verify->execute(['module_id' => (int) $module['id']]);
    $remaining = (int) $verify->fetchColumn();

    if ($remaining !== 0) {
        throw new RuntimeException(
            "Verificação final falhou: {$remaining} aula(s) ainda não estão em modo reading."
        );
    }

    $pdo->commit();

    echo "\n[OK] Hotfix aplicado com sucesso.\n";
    printf("[OK] Aulas alteradas nesta execução: %d\n", $changed);
    printf("[OK] Total de aulas do módulo em modo reading: %d\n", count($lessons));
    echo "[OK] Quizzes e conteúdos existentes foram preservados.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "\nERRO: {$e->getMessage()}\n");
    exit(6);
}
