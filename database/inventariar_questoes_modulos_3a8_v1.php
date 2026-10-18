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
$pdo = Database::connection();

$moduleTitles = [
    'Módulo 3 -%',
    'Módulo 4 -%',
    'Módulo 5 -%',
    'Módulo 6 -%',
    'Módulo 8 -%',
];

$moduleStmt = $pdo->prepare(
    "SELECT id,title
     FROM modules
     WHERE course_id = (
        SELECT id FROM courses
        WHERE slug='preparacao-pmmt-merito-intelectual-e-caoc'
        LIMIT 1
     )
       AND status='published'
       AND title LIKE :pattern
     ORDER BY id
     LIMIT 1"
);

$qStmt = $pdo->prepare(
    "SELECT q.id,q.source_label,q.statement,q.difficulty
     FROM questions q
     INNER JOIN (
        SELECT question_id,
               COUNT(*) AS alternatives_count,
               SUM(CASE WHEN is_correct=1 THEN 1 ELSE 0 END) AS correct_count
        FROM alternatives
        GROUP BY question_id
     ) quality ON quality.question_id=q.id
     WHERE q.module_id=:module_id
       AND q.active=1
       AND q.question_type IN ('multiple_choice','true_false')
       AND q.difficulty IN ('medium','hard')
       AND ((q.question_type='multiple_choice' AND quality.alternatives_count>=4)
            OR (q.question_type='true_false' AND quality.alternatives_count>=2))
       AND quality.correct_count=1
     ORDER BY COALESCE(q.source_label,''), q.id"
);

echo "=============================================================\n";
echo " INVENTÁRIO DETALHADO DE QUESTÕES - MÓDULOS 3,4,5,6,8\n";
echo "=============================================================\n";

foreach ($moduleTitles as $pattern) {
    $moduleStmt->execute(['pattern'=>$pattern]);
    $module = $moduleStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$module) continue;

    echo "\n-------------------------------------------------------------\n";
    printf("MÓDULO #%d | %s\n", (int)$module['id'], $module['title']);

    $qStmt->execute(['module_id'=>(int)$module['id']]);
    foreach ($qStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        printf(
            "#%d | %s | %s\n%s\n\n",
            (int)$row['id'],
            strtoupper((string)$row['difficulty']),
            (string)($row['source_label'] ?: '[sem fonte]'),
            trim((string)$row['statement'])
        );
    }
}

echo "[OK] Inventário concluído. Nenhuma alteração foi realizada.\n";
