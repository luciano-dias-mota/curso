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
use PDO;

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
$course = $courseStmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    fwrite(STDERR, "[FALHA] Curso publicado não encontrado.\n");
    exit(2);
}

$modulesStmt = $pdo->prepare(
    "SELECT id,title,slug,position
     FROM modules
     WHERE course_id=:course_id
       AND status='published'
     ORDER BY position,id"
);
$modulesStmt->execute(['course_id'=>(int)$course['id']]);
$modules = $modulesStmt->fetchAll(PDO::FETCH_ASSOC);

$requiredPhasesStmt = $pdo->prepare(
    "SELECT id,title,slug,position
     FROM phases
     WHERE module_id=:module_id
       AND status='published'
       AND is_required=1
     ORDER BY position,id"
);

$allPhasesCountStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM phases
     WHERE module_id=:module_id
       AND status='published'"
);

$requiredLessonsStmt = $pdo->prepare(
    "SELECT l.id,l.title,l.slug,l.position,l.phase_id,
            COUNT(lb.id) AS block_count,
            SUM(CASE WHEN lb.is_required=1 THEN 1 ELSE 0 END) AS required_blocks
     FROM lessons l
     INNER JOIN phases p ON p.id=l.phase_id
     LEFT JOIN lesson_blocks lb ON lb.lesson_id=l.id
     WHERE p.module_id=:module_id
       AND p.status='published'
       AND p.is_required=1
       AND l.status='published'
       AND l.is_required=1
     GROUP BY l.id,l.title,l.slug,l.position,l.phase_id
     ORDER BY p.position,l.position,l.id"
);

$introStmt = $pdo->prepare(
    "SELECT p.id AS phase_id,p.title AS phase_title,p.is_required,
            l.id AS lesson_id,l.title AS lesson_title,
            COUNT(lb.id) AS block_count,
            GROUP_CONCAT(
                CONCAT(COALESCE(lb.title,''),'||',LEFT(COALESCE(lb.content,''),700))
                ORDER BY lb.position,lb.id
                SEPARATOR '\n---BLOCK---\n'
            ) AS blocks_preview
     FROM phases p
     LEFT JOIN lessons l
       ON l.phase_id=p.id
      AND l.status='published'
     LEFT JOIN lesson_blocks lb ON lb.lesson_id=l.id
     WHERE p.module_id=:module_id
       AND p.status='published'
       AND p.slug='introducao-e-visao-geral'
     GROUP BY p.id,p.title,p.is_required,l.id,l.title
     ORDER BY l.position,l.id
     LIMIT 1"
);

$lessonQuizStmt = $pdo->prepare(
    "SELECT qz.id,qz.required_score,qz.question_limit,
            COUNT(DISTINCT qq.question_id) AS question_count
     FROM quizzes qz
     LEFT JOIN quiz_questions qq ON qq.quiz_id=qz.id
     WHERE qz.lesson_id=:lesson_id
       AND qz.quiz_type='lesson_fixation'
       AND qz.active=1
     GROUP BY qz.id,qz.required_score,qz.question_limit
     ORDER BY qz.id
     LIMIT 1"
);

$phaseQuizStmt = $pdo->prepare(
    "SELECT qz.id,qz.required_score,qz.question_limit,
            COUNT(DISTINCT qq.question_id) AS question_count
     FROM quizzes qz
     LEFT JOIN quiz_questions qq ON qq.quiz_id=qz.id
     WHERE qz.phase_id=:phase_id
       AND qz.lesson_id IS NULL
       AND qz.quiz_type='phase_exam'
       AND qz.active=1
     GROUP BY qz.id,qz.required_score,qz.question_limit
     ORDER BY qz.id
     LIMIT 1"
);

$bossStmt = $pdo->prepare(
    "SELECT qz.id,qz.required_score,qz.question_limit,
            COUNT(DISTINCT qq.question_id) AS question_count
     FROM quizzes qz
     LEFT JOIN quiz_questions qq ON qq.quiz_id=qz.id
     WHERE qz.module_id=:module_id
       AND qz.quiz_type='module_boss'
       AND qz.active=1
     GROUP BY qz.id,qz.required_score,qz.question_limit
     ORDER BY qz.id
     LIMIT 1"
);

echo "=============================================================\n";
echo " AUDITORIA GLOBAL - CONTEÚDO E PROGRESSÃO DOS MÓDULOS V1\n";
echo "=============================================================\n";
printf("Banco: %s\n", (string)$pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Curso: #%d - %s\n", (int)$course['id'], $course['title']);
printf("Módulos publicados: %d\n\n", count($modules));

$summary = [];
$totalProblems = 0;

foreach ($modules as $module) {
    $moduleId = (int)$module['id'];

    $allPhasesCountStmt->execute(['module_id'=>$moduleId]);
    $allPhasesCount = (int)$allPhasesCountStmt->fetchColumn();

    $requiredPhasesStmt->execute(['module_id'=>$moduleId]);
    $requiredPhases = $requiredPhasesStmt->fetchAll(PDO::FETCH_ASSOC);

    $requiredLessonsStmt->execute(['module_id'=>$moduleId]);
    $requiredLessons = $requiredLessonsStmt->fetchAll(PDO::FETCH_ASSOC);

    $introStmt->execute(['module_id'=>$moduleId]);
    $intro = $introStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $missingFixations = [];
    $invalidFixations = [];
    $thinLessons = [];

    foreach ($requiredLessons as $lesson) {
        $lessonId = (int)$lesson['id'];
        $blocks = (int)$lesson['block_count'];

        if ($blocks <= 1) {
            $thinLessons[] = [
                'id'=>$lessonId,
                'title'=>(string)$lesson['title'],
                'blocks'=>$blocks,
            ];
        }

        $lessonQuizStmt->execute(['lesson_id'=>$lessonId]);
        $fix = $lessonQuizStmt->fetch(PDO::FETCH_ASSOC);

        if (!$fix) {
            $missingFixations[] = [
                'id'=>$lessonId,
                'title'=>(string)$lesson['title'],
            ];
            continue;
        }

        $questionCount = (int)$fix['question_count'];
        $limit = (int)$fix['question_limit'];

        if ($limit <= 0 || $questionCount !== $limit) {
            $invalidFixations[] = [
                'lesson_id'=>$lessonId,
                'quiz_id'=>(int)$fix['id'],
                'question_count'=>$questionCount,
                'question_limit'=>$limit,
            ];
        }
    }

    $missingPhaseExams = [];
    $invalidPhaseExams = [];

    foreach ($requiredPhases as $phase) {
        $phaseId = (int)$phase['id'];
        $phaseQuizStmt->execute(['phase_id'=>$phaseId]);
        $exam = $phaseQuizStmt->fetch(PDO::FETCH_ASSOC);

        if (!$exam) {
            $missingPhaseExams[] = [
                'id'=>$phaseId,
                'title'=>(string)$phase['title'],
            ];
            continue;
        }

        $questionCount = (int)$exam['question_count'];
        $limit = (int)$exam['question_limit'];

        if ($limit <= 0 || $questionCount !== $limit) {
            $invalidPhaseExams[] = [
                'phase_id'=>$phaseId,
                'quiz_id'=>(int)$exam['id'],
                'question_count'=>$questionCount,
                'question_limit'=>$limit,
            ];
        }
    }

    $bossStmt->execute(['module_id'=>$moduleId]);
    $boss = $bossStmt->fetch(PDO::FETCH_ASSOC);

    $bossProblem = null;
    if (!$boss) {
        $bossProblem = 'AUSENTE';
    } elseif ((int)$boss['question_limit'] <= 0
        || (int)$boss['question_count'] !== (int)$boss['question_limit']) {
        $bossProblem = sprintf(
            'INCOMPLETA #%d %d/%d',
            (int)$boss['id'],
            (int)$boss['question_count'],
            (int)$boss['question_limit']
        );
    }

    $introProblems = [];
    $introPreview = '';
    if (!$intro) {
        $introProblems[] = 'introdução ausente';
    } else {
        $introPreview = trim((string)($intro['blocks_preview'] ?? ''));
        $blockCount = (int)($intro['block_count'] ?? 0);

        if ($blockCount <= 1) {
            $introProblems[] = 'introdução com apenas 1 bloco';
        }

        $normalizedPreview = mb_strtolower($introPreview, 'UTF-8');
        if (str_contains($normalizedPreview, 'fontes-base:')
            || (str_contains($normalizedPreview, 'quadro comparativo / tabela')
                && !str_contains($normalizedPreview, 'conceito'))) {
            $introProblems[] = 'introdução aparenta ser só lista de fontes/referências';
        }

        if (mb_strlen(preg_replace('/\s+/u',' ', $introPreview) ?? $introPreview, 'UTF-8') < 350) {
            $introProblems[] = 'introdução muito curta/superficial';
        }
    }

    $problemCount =
        count($missingFixations)
        + count($invalidFixations)
        + count($missingPhaseExams)
        + count($invalidPhaseExams)
        + count($thinLessons)
        + count($introProblems)
        + ($bossProblem !== null ? 1 : 0);

    $totalProblems += $problemCount;

    $status = $problemCount === 0
        ? 'OK'
        : ((count($missingFixations) > 0 || count($missingPhaseExams) > 0 || $bossProblem !== null) ? 'CRÍTICO' : 'ATENÇÃO');

    echo "-------------------------------------------------------------\n";
    printf(
        "MÓDULO %d | #%d | %s\n",
        (int)$module['position'],
        $moduleId,
        $module['title']
    );
    printf("Status:                         %s\n", $status);
    printf("Fases publicadas / obrigatórias: %d / %d\n", $allPhasesCount, count($requiredPhases));
    printf("Aulas obrigatórias:             %d\n", count($requiredLessons));
    printf("Aulas obrig. sem fixação:       %d\n", count($missingFixations));
    printf("Fixações incompletas:            %d\n", count($invalidFixations));
    printf("Fases obrig. sem checkpoint:     %d\n", count($missingPhaseExams));
    printf("Checkpoints incompletos:         %d\n", count($invalidPhaseExams));
    printf("Aulas obrig. com <= 1 bloco:     %d\n", count($thinLessons));
    printf("Problemas na introdução:         %d\n", count($introProblems));
    printf(
        "Avaliação final do módulo:       %s\n",
        $bossProblem === null
            ? sprintf('#%d %d/%d', (int)$boss['id'], (int)$boss['question_count'], (int)$boss['question_limit'])
            : $bossProblem
    );

    if ($introProblems !== []) {
        echo "  Introdução:\n";
        foreach ($introProblems as $p) {
            echo "    - {$p}\n";
        }
    }

    if ($missingFixations !== []) {
        echo "  Aulas sem fixação (primeiras 12):\n";
        foreach (array_slice($missingFixations,0,12) as $item) {
            printf("    - aula #%d: %s\n", $item['id'], $item['title']);
        }
        if (count($missingFixations) > 12) {
            printf("    ... e mais %d\n", count($missingFixations)-12);
        }
    }

    if ($missingPhaseExams !== []) {
        echo "  Fases sem checkpoint (primeiras 12):\n";
        foreach (array_slice($missingPhaseExams,0,12) as $item) {
            printf("    - fase #%d: %s\n", $item['id'], $item['title']);
        }
        if (count($missingPhaseExams) > 12) {
            printf("    ... e mais %d\n", count($missingPhaseExams)-12);
        }
    }

    if ($thinLessons !== []) {
        echo "  Aulas superficiais (<=1 bloco; primeiras 12):\n";
        foreach (array_slice($thinLessons,0,12) as $item) {
            printf("    - aula #%d: %s (%d bloco)\n", $item['id'], $item['title'], $item['blocks']);
        }
        if (count($thinLessons) > 12) {
            printf("    ... e mais %d\n", count($thinLessons)-12);
        }
    }

    $summary[] = [
        'position'=>(int)$module['position'],
        'title'=>(string)$module['title'],
        'status'=>$status,
        'missing_fixations'=>count($missingFixations),
        'missing_phase_exams'=>count($missingPhaseExams),
        'thin_lessons'=>count($thinLessons),
        'intro_problems'=>count($introProblems),
        'boss_problem'=>$bossProblem !== null ? 1 : 0,
        'problems'=>$problemCount,
    ];
}

echo "\n=============================================================\n";
echo " RESUMO GERAL\n";
echo "=============================================================\n";
foreach ($summary as $row) {
    printf(
        "Módulo %d | %-8s | fixação ausente=%d | checkpoint ausente=%d | superficiais=%d | intro=%d | final=%d | problemas=%d\n",
        $row['position'],
        $row['status'],
        $row['missing_fixations'],
        $row['missing_phase_exams'],
        $row['thin_lessons'],
        $row['intro_problems'],
        $row['boss_problem'],
        $row['problems']
    );
}
printf("\nProblemas totais detectados: %d\n", $totalProblems);

if ($totalProblems > 0) {
    fwrite(
        STDERR,
        "\n[ATENÇÃO] Há módulos que não estão prontos para uma progressão pedagógica obrigatória completa.\n"
    );
    exit(3);
}

echo "\n[OK] Todos os módulos publicados possuem conteúdo e progressão completos.\n";
