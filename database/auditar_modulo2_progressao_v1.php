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
$moduleSlug = 'modulo-2-administracao-publica-e-gestao-fundamentos-organizacao-atos-controle-modelos-e-materiais';

$courseStmt = $pdo->prepare(
    "SELECT id,title FROM courses
     WHERE slug=:slug AND status='published'
     LIMIT 1"
);
$courseStmt->execute(['slug'=>$courseSlug]);
$course = $courseStmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    fwrite(STDERR, "[FALHA] Curso publicado não encontrado.\n");
    exit(2);
}

$moduleStmt = $pdo->prepare(
    "SELECT id,title,position
     FROM modules
     WHERE course_id=:course_id
       AND slug=:slug
       AND status='published'
     LIMIT 1"
);
$moduleStmt->execute([
    'course_id'=>(int)$course['id'],
    'slug'=>$moduleSlug,
]);
$module = $moduleStmt->fetch(PDO::FETCH_ASSOC);

if (!$module) {
    fwrite(STDERR, "[FALHA] Módulo 2 publicado não encontrado.\n");
    exit(3);
}

echo "=============================================================\n";
echo " AUDITORIA MÓDULO 2 - CONTEÚDO E PROGRESSÃO V1\n";
echo "=============================================================\n";
printf("Banco:  %s\n", (string)$pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Curso:  #%d - %s\n", (int)$course['id'], $course['title']);
printf("Módulo: #%d - %s\n\n", (int)$module['id'], $module['title']);

$phasesStmt = $pdo->prepare(
    "SELECT p.id,p.title,p.slug,p.position,p.is_required,p.phase_type,
            p.required_score,
            COALESCE(upp.status,'sem_progresso') AS progress_status
     FROM phases p
     LEFT JOIN user_phase_progress upp
       ON 1=0
     WHERE p.module_id=:module_id
       AND p.status='published'
     ORDER BY p.position,p.id"
);
$phasesStmt->execute(['module_id'=>(int)$module['id']]);
$phases = $phasesStmt->fetchAll(PDO::FETCH_ASSOC);

$lessonStmt = $pdo->prepare(
    "SELECT l.id,l.title,l.slug,l.position,l.is_required,l.estimated_minutes,
            COUNT(lb.id) AS block_count,
            SUM(CASE WHEN lb.is_required=1 THEN 1 ELSE 0 END) AS required_blocks
     FROM lessons l
     LEFT JOIN lesson_blocks lb ON lb.lesson_id=l.id
     WHERE l.phase_id=:phase_id
       AND l.status='published'
     GROUP BY l.id,l.title,l.slug,l.position,l.is_required,l.estimated_minutes
     ORDER BY l.position,l.id"
);

$quizStmt = $pdo->prepare(
    "SELECT qz.id,qz.quiz_type,qz.title,qz.required_score,qz.question_limit,qz.active,
            COUNT(DISTINCT qq.question_id) AS question_count
     FROM quizzes qz
     LEFT JOIN quiz_questions qq ON qq.quiz_id=qz.id
     WHERE qz.active=1
       AND (
            (:lesson_id > 0 AND qz.lesson_id=:lesson_id2)
            OR (:phase_id > 0 AND qz.phase_id=:phase_id2 AND qz.lesson_id IS NULL)
       )
     GROUP BY qz.id,qz.quiz_type,qz.title,qz.required_score,qz.question_limit,qz.active
     ORDER BY qz.quiz_type,qz.id"
);

$problems = [];
$totalLessons = 0;
$missingLessonFixations = 0;
$missingPhaseExams = 0;
$thinLessons = 0;

foreach ($phases as $phase) {
    $phaseId = (int)$phase['id'];
    $required = (int)$phase['is_required'] === 1;

    echo "-------------------------------------------------------------\n";
    printf(
        "FASE %02d | #%d | %s | %s\n",
        (int)$phase['position'],
        $phaseId,
        $required ? 'OBRIGATÓRIA' : 'OPCIONAL',
        $phase['title']
    );

    $lessonStmt->execute(['phase_id'=>$phaseId]);
    $lessons = $lessonStmt->fetchAll(PDO::FETCH_ASSOC);

    printf("Aulas publicadas: %d\n", count($lessons));

    foreach ($lessons as $lesson) {
        $totalLessons++;
        $lessonId = (int)$lesson['id'];
        $lessonRequired = (int)$lesson['is_required'] === 1;
        $blocks = (int)$lesson['block_count'];
        $requiredBlocks = (int)$lesson['required_blocks'];

        if ($lessonRequired && $blocks <= 1) {
            $thinLessons++;
            $problems[] = "Aula #{$lessonId} '{$lesson['title']}' possui apenas {$blocks} bloco(s).";
        }

        $quizStmt->execute([
            'lesson_id'=>$lessonId,
            'lesson_id2'=>$lessonId,
            'phase_id'=>0,
            'phase_id2'=>0,
        ]);
        $lessonQuizzes = $quizStmt->fetchAll(PDO::FETCH_ASSOC);
        $fixation = null;
        foreach ($lessonQuizzes as $quiz) {
            if ($quiz['quiz_type'] === 'lesson_fixation') {
                $fixation = $quiz;
                break;
            }
        }

        $fixationText = 'SEM FIXAÇÃO';
        if ($fixation) {
            $fixationText = sprintf(
                'fixação #%d: %d/%d questões | nota %.0f%%',
                (int)$fixation['id'],
                (int)$fixation['question_count'],
                (int)$fixation['question_limit'],
                (float)$fixation['required_score']
            );

            if ((int)$fixation['question_limit'] <= 0
                || (int)$fixation['question_count'] !== (int)$fixation['question_limit']) {
                $problems[] = "Fixação incompleta da aula #{$lessonId}: {$fixationText}.";
            }
        } elseif ($required && $lessonRequired) {
            $missingLessonFixations++;
            $problems[] = "Aula obrigatória #{$lessonId} '{$lesson['title']}' não possui lesson_fixation.";
        }

        printf(
            "  Aula %02d | #%d | %s | blocos=%d (%d obrig.) | %s\n",
            (int)$lesson['position'],
            $lessonId,
            $lessonRequired ? 'OBR.' : 'OPC.',
            $blocks,
            $requiredBlocks,
            $fixationText
        );
    }

    $quizStmt->execute([
        'lesson_id'=>0,
        'lesson_id2'=>0,
        'phase_id'=>$phaseId,
        'phase_id2'=>$phaseId,
    ]);
    $phaseQuizzes = $quizStmt->fetchAll(PDO::FETCH_ASSOC);
    $exam = null;
    foreach ($phaseQuizzes as $quiz) {
        if ($quiz['quiz_type'] === 'phase_exam') {
            $exam = $quiz;
            break;
        }
    }

    if ($required) {
        if (!$exam) {
            $missingPhaseExams++;
            $problems[] = "Fase obrigatória #{$phaseId} '{$phase['title']}' não possui phase_exam.";
            echo "Checkpoint da fase: AUSENTE\n";
        } else {
            printf(
                "Checkpoint da fase: #%d | %d/%d questões | nota %.0f%%\n",
                (int)$exam['id'],
                (int)$exam['question_count'],
                (int)$exam['question_limit'],
                (float)$exam['required_score']
            );
            if ((int)$exam['question_limit'] <= 0
                || (int)$exam['question_count'] !== (int)$exam['question_limit']) {
                $problems[] = "Checkpoint incompleto da fase #{$phaseId}.";
            }
        }
    } else {
        echo "Checkpoint da fase: não obrigatório\n";
    }
}

$bossStmt = $pdo->prepare(
    "SELECT qz.id,qz.title,qz.required_score,qz.question_limit,
            COUNT(DISTINCT qq.question_id) AS question_count
     FROM quizzes qz
     LEFT JOIN quiz_questions qq ON qq.quiz_id=qz.id
     WHERE qz.module_id=:module_id
       AND qz.quiz_type='module_boss'
       AND qz.active=1
     GROUP BY qz.id,qz.title,qz.required_score,qz.question_limit
     ORDER BY qz.id
     LIMIT 1"
);
$bossStmt->execute(['module_id'=>(int)$module['id']]);
$boss = $bossStmt->fetch(PDO::FETCH_ASSOC);

echo "\n=============================================================\n";
echo " RESUMO\n";
echo "=============================================================\n";
printf("Fases publicadas:                    %d\n", count($phases));
printf("Aulas publicadas:                    %d\n", $totalLessons);
printf("Aulas obrigatórias sem fixação:      %d\n", $missingLessonFixations);
printf("Fases obrigatórias sem checkpoint:   %d\n", $missingPhaseExams);
printf("Aulas obrigatórias com <= 1 bloco:   %d\n", $thinLessons);

if ($boss) {
    printf(
        "Avaliação final do módulo:          #%d | %d/%d questões | nota %.0f%%\n",
        (int)$boss['id'],
        (int)$boss['question_count'],
        (int)$boss['question_limit'],
        (float)$boss['required_score']
    );
    if ((int)$boss['question_limit'] <= 0
        || (int)$boss['question_count'] !== (int)$boss['question_limit']) {
        $problems[] = 'Avaliação final do módulo está incompleta.';
    }
} else {
    echo "Avaliação final do módulo:          AUSENTE\n";
    $problems[] = 'Módulo 2 não possui avaliação final module_boss.';
}

if ($problems !== []) {
    echo "\nPROBLEMAS ENCONTRADOS:\n";
    foreach ($problems as $i=>$problem) {
        printf("%02d. %s\n", $i+1, $problem);
    }
    fwrite(STDERR, "\n[FALHA] Módulo 2 não está pronto para progressão obrigatória completa.\n");
    exit(4);
}

echo "\n[OK] Módulo 2 possui conteúdo, fixações, checkpoints e avaliação final completos.\n";
