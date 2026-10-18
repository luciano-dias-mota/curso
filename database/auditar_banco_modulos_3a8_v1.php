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

$courseSlug = 'preparacao-pmmt-merito-intelectual-e-caoc';

$courseStmt = $pdo->prepare(
    "SELECT id,title
     FROM courses
     WHERE slug=:slug
       AND status='published'
     LIMIT 1"
);
$courseStmt->execute(['slug'=>$courseSlug]);
$course = $courseStmt->fetch(\PDO::FETCH_ASSOC);

if (!$course) {
    fwrite(STDERR, "[FALHA] Curso publicado não encontrado.\n");
    exit(2);
}

$modulesStmt = $pdo->prepare(
    "SELECT id,title,slug,position
     FROM modules
     WHERE course_id=:course_id
       AND status='published'
       AND (
            title LIKE 'Módulo 3 -%'
         OR title LIKE 'Módulo 4 -%'
         OR title LIKE 'Módulo 5 -%'
         OR title LIKE 'Módulo 6 -%'
         OR title LIKE 'Módulo 7 -%'
         OR title LIKE 'Módulo 8 -%'
       )
     ORDER BY position,id"
);
$modulesStmt->execute(['course_id'=>(int)$course['id']]);
$modules=$modulesStmt->fetchAll(\PDO::FETCH_ASSOC);

$phasesStmt=$pdo->prepare(
    "SELECT id,title,slug,position,is_required
     FROM phases
     WHERE module_id=:module_id
       AND status='published'
     ORDER BY position,id"
);

$validQuestionCount=$pdo->prepare(
    "SELECT COUNT(DISTINCT q.id)
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
       AND quality.correct_count=1"
);

$phaseQuestionCount=$pdo->prepare(
    "SELECT COUNT(DISTINCT q.id)
     FROM questions q
     INNER JOIN (
        SELECT question_id,
               COUNT(*) AS alternatives_count,
               SUM(CASE WHEN is_correct=1 THEN 1 ELSE 0 END) AS correct_count
        FROM alternatives
        GROUP BY question_id
     ) quality ON quality.question_id=q.id
     WHERE q.module_id=:module_id
       AND q.phase_id=:phase_id
       AND q.active=1
       AND q.question_type IN ('multiple_choice','true_false')
       AND q.difficulty IN ('medium','hard')
       AND ((q.question_type='multiple_choice' AND quality.alternatives_count>=4)
            OR (q.question_type='true_false' AND quality.alternatives_count>=2))
       AND quality.correct_count=1"
);

$unassignedCount=$pdo->prepare(
    "SELECT COUNT(DISTINCT q.id)
     FROM questions q
     INNER JOIN (
        SELECT question_id,
               COUNT(*) AS alternatives_count,
               SUM(CASE WHEN is_correct=1 THEN 1 ELSE 0 END) AS correct_count
        FROM alternatives
        GROUP BY question_id
     ) quality ON quality.question_id=q.id
     WHERE q.module_id=:module_id
       AND q.phase_id IS NULL
       AND q.active=1
       AND q.question_type IN ('multiple_choice','true_false')
       AND q.difficulty IN ('medium','hard')
       AND ((q.question_type='multiple_choice' AND quality.alternatives_count>=4)
            OR (q.question_type='true_false' AND quality.alternatives_count>=2))
       AND quality.correct_count=1"
);

$sourceStats=$pdo->prepare(
    "SELECT COALESCE(NULLIF(TRIM(source_label),''),'[sem fonte]') AS source_label,
            COUNT(*) AS qty
     FROM questions
     WHERE module_id=:module_id
       AND active=1
     GROUP BY COALESCE(NULLIF(TRIM(source_label),''),'[sem fonte]')
     ORDER BY qty DESC, source_label
     LIMIT 12"
);

echo "=============================================================\n";
echo " AUDITORIA BANCO DE QUESTÕES - MÓDULOS 3 A 8 V1\n";
echo "=============================================================\n";
printf("Banco: %s\n", (string)$pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Curso: #%d - %s\n\n", (int)$course['id'], $course['title']);

foreach($modules as $module){
    $moduleId=(int)$module['id'];

    $validQuestionCount->execute(['module_id'=>$moduleId]);
    $valid=(int)$validQuestionCount->fetchColumn();

    $unassignedCount->execute(['module_id'=>$moduleId]);
    $unassigned=(int)$unassignedCount->fetchColumn();

    echo "-------------------------------------------------------------\n";
    printf("MÓDULO CURRICULAR | #%d | %s\n", $moduleId, $module['title']);
    printf("Questões válidas no módulo:          %d\n", $valid);
    printf("Questões válidas sem fase associada: %d\n", $unassigned);

    $phasesStmt->execute(['module_id'=>$moduleId]);
    $phases=$phasesStmt->fetchAll(\PDO::FETCH_ASSOC);

    $deficits=0;
    foreach($phases as $phase){
        $phaseQuestionCount->execute([
            'module_id'=>$moduleId,
            'phase_id'=>(int)$phase['id'],
        ]);
        $qty=(int)$phaseQuestionCount->fetchColumn();

        if((int)$phase['is_required']===1 && $qty < 5){
            $deficits++;
        }

        printf(
            "  %s FASE %02d | #%d | %2d questão(ões) | %s\n",
            (int)$phase['is_required']===1 ? '[OBR.]' : '[OPC.]',
            (int)$phase['position'],
            (int)$phase['id'],
            $qty,
            $phase['title']
        );
    }

    printf("Fases obrigatórias com menos de 5 questões: %d\n", $deficits);

    echo "Principais fontes/rótulos das questões:\n";
    $sourceStats->execute(['module_id'=>$moduleId]);
    foreach($sourceStats->fetchAll(\PDO::FETCH_ASSOC) as $row){
        printf("  - %3d | %s\n", (int)$row['qty'], $row['source_label']);
    }
}

echo "\n[OK] Auditoria concluída. Nenhuma alteração foi realizada.\n";
