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

$moduleSlug = 'modulo-2-administracao-publica-e-gestao-fundamentos-organizacao-atos-controle-modelos-e-materiais';

$moduleStmt = $pdo->prepare(
    "SELECT m.id,m.course_id,m.title,c.title AS course_title
     FROM modules m
     INNER JOIN courses c ON c.id=m.course_id
     WHERE m.slug=:slug
       AND m.status='published'
       AND c.status='published'
     LIMIT 1"
);
$moduleStmt->execute(['slug'=>$moduleSlug]);
$module = $moduleStmt->fetch(\PDO::FETCH_ASSOC);

if (!$module) {
    fwrite(STDERR, "[FALHA] Módulo 2 publicado não encontrado.\n");
    exit(2);
}

$moduleId = (int) $module['id'];

echo "=============================================================\n";
echo " AUDITORIA MÓDULO 2 - CONTEÚDO E PROGRESSÃO V2\n";
echo "=============================================================\n";
printf("Banco:  %s\n", (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Curso:  #%d - %s\n", (int)$module['course_id'], $module['course_title']);
printf("Módulo: #%d - %s\n\n", $moduleId, $module['title']);

$phasesStmt = $pdo->prepare(
    "SELECT id,title,slug,position,is_required,phase_type,required_score
     FROM phases
     WHERE module_id=:module_id
       AND status='published'
     ORDER BY position,id"
);
$phasesStmt->execute(['module_id'=>$moduleId]);
$phases = $phasesStmt->fetchAll(\PDO::FETCH_ASSOC);

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

$lessonQuizStmt = $pdo->prepare(
    "SELECT qz.id,qz.title,qz.required_score,qz.question_limit,
            COUNT(DISTINCT qq.question_id) AS question_count
     FROM quizzes qz
     LEFT JOIN quiz_questions qq ON qq.quiz_id=qz.id
     WHERE qz.lesson_id=:lesson_id
       AND qz.quiz_type='lesson_fixation'
       AND qz.active=1
     GROUP BY qz.id,qz.title,qz.required_score,qz.question_limit
     ORDER BY qz.id
     LIMIT 1"
);

$phaseQuizStmt = $pdo->prepare(
    "SELECT qz.id,qz.title,qz.required_score,qz.question_limit,
            COUNT(DISTINCT qq.question_id) AS question_count
     FROM quizzes qz
     LEFT JOIN quiz_questions qq ON qq.quiz_id=qz.id
     WHERE qz.phase_id=:phase_id
       AND qz.lesson_id IS NULL
       AND qz.quiz_type='phase_exam'
       AND qz.active=1
     GROUP BY qz.id,qz.title,qz.required_score,qz.question_limit
     ORDER BY qz.id
     LIMIT 1"
);

$problems = [];
$totalLessons = 0;
$requiredPhases = 0;
$optionalPhases = 0;
$missingLessonFixations = 0;
$missingPhaseExams = 0;
$thinRequiredLessons = 0;
$validFixations = 0;
$validPhaseExams = 0;

foreach ($phases as $phase) {
    $phaseId = (int) $phase['id'];
    $phaseRequired = (int) $phase['is_required'] === 1;

    if ($phaseRequired) {
        $requiredPhases++;
    } else {
        $optionalPhases++;
    }

    echo "-------------------------------------------------------------\n";
    printf(
        "FASE %02d | #%d | %s | %s\n",
        (int)$phase['position'],
        $phaseId,
        $phaseRequired ? 'OBRIGATÓRIA' : 'COMPLEMENTAR',
        $phase['title']
    );

    $lessonStmt->execute(['phase_id'=>$phaseId]);
    $lessons = $lessonStmt->fetchAll(\PDO::FETCH_ASSOC);
    printf("Aulas publicadas: %d\n", count($lessons));

    foreach ($lessons as $lesson) {
        $totalLessons++;
        $lessonId = (int) $lesson['id'];
        $lessonRequired = (int) $lesson['is_required'] === 1;
        $blocks = (int) $lesson['block_count'];
        $requiredBlocks = (int) $lesson['required_blocks'];

        if ($phaseRequired && $lessonRequired && $blocks <= 1) {
            $thinRequiredLessons++;
            $problems[] = "Aula curricular #{$lessonId} '{$lesson['title']}' possui apenas {$blocks} bloco(s).";
        }

        $lessonQuizStmt->execute(['lesson_id'=>$lessonId]);
        $fixation = $lessonQuizStmt->fetch(\PDO::FETCH_ASSOC);

        if ($phaseRequired && $lessonRequired) {
            if (!$fixation) {
                $missingLessonFixations++;
                $problems[] = "Aula obrigatória #{$lessonId} '{$lesson['title']}' não possui lesson_fixation.";
                $fixationText = 'SEM FIXAÇÃO';
            } else {
                $questionCount = (int)$fixation['question_count'];
                $limit = (int)$fixation['question_limit'];
                $score = (float)$fixation['required_score'];
                $fixationText = sprintf(
                    'fixação #%d: %d/%d questões | nota %.0f%%',
                    (int)$fixation['id'],
                    $questionCount,
                    $limit,
                    $score
                );

                if ($questionCount !== 3 || $limit !== 3 || abs($score - 100.0) > 0.01) {
                    $problems[] = "Fixação fora do padrão na aula #{$lessonId}: {$fixationText}.";
                } else {
                    $validFixations++;
                }
            }
        } else {
            $fixationText = $fixation
                ? sprintf(
                    'opcional com quiz #%d: %d/%d',
                    (int)$fixation['id'],
                    (int)$fixation['question_count'],
                    (int)$fixation['question_limit']
                )
                : 'sem fixação obrigatória';
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

    $phaseQuizStmt->execute(['phase_id'=>$phaseId]);
    $exam = $phaseQuizStmt->fetch(\PDO::FETCH_ASSOC);

    if ($phaseRequired) {
        if (!$exam) {
            $missingPhaseExams++;
            $problems[] = "Fase obrigatória #{$phaseId} '{$phase['title']}' não possui phase_exam.";
            echo "Checkpoint da fase: AUSENTE\n";
        } else {
            $questionCount = (int)$exam['question_count'];
            $limit = (int)$exam['question_limit'];
            $score = (float)$exam['required_score'];
            printf(
                "Checkpoint da fase: #%d | %d/%d questões | nota %.0f%%\n",
                (int)$exam['id'],
                $questionCount,
                $limit,
                $score
            );

            if ($questionCount !== 5 || $limit !== 5 || abs($score - 80.0) > 0.01) {
                $problems[] = "Checkpoint fora do padrão na fase #{$phaseId}.";
            } else {
                $validPhaseExams++;
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
$bossStmt->execute(['module_id'=>$moduleId]);
$boss = $bossStmt->fetch(\PDO::FETCH_ASSOC);

$managedQuestionsStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM questions
     WHERE module_id=:module_id
       AND active=1
       AND source_label LIKE 'M2V2%'"
);
$managedQuestionsStmt->execute(['module_id'=>$moduleId]);
$managedQuestions = (int) $managedQuestionsStmt->fetchColumn();

$invalidAlternativesStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM (
        SELECT q.id,
               COUNT(a.id) AS alternatives_count,
               SUM(CASE WHEN a.is_correct=1 THEN 1 ELSE 0 END) AS correct_count
        FROM questions q
        LEFT JOIN alternatives a ON a.question_id=q.id
        WHERE q.module_id=:module_id
          AND q.active=1
          AND q.source_label LIKE 'M2V2%'
        GROUP BY q.id
        HAVING COUNT(a.id)<>5
            OR SUM(CASE WHEN a.is_correct=1 THEN 1 ELSE 0 END)<>1
     ) x"
);
$invalidAlternativesStmt->execute(['module_id'=>$moduleId]);
$invalidAlternatives = (int) $invalidAlternativesStmt->fetchColumn();

echo "\n=============================================================\n";
echo " RESUMO\n";
echo "=============================================================\n";
printf("Fases publicadas:                    %d\n", count($phases));
printf("Fases curriculares obrigatórias:     %d / 11\n", $requiredPhases);
printf("Fases complementares opcionais:      %d / 5\n", $optionalPhases);
printf("Aulas publicadas:                    %d\n", $totalLessons);
printf("Aulas obrigatórias sem fixação:      %d\n", $missingLessonFixations);
printf("Fixações válidas 3/3 a 100%%:         %d / 11\n", $validFixations);
printf("Fases obrigatórias sem checkpoint:   %d\n", $missingPhaseExams);
printf("Checkpoints válidos 5/5 a 80%%:       %d / 11\n", $validPhaseExams);
printf("Aulas curriculares com <= 1 bloco:   %d\n", $thinRequiredLessons);
printf("Questões curadas M2V2:               %d / 55\n", $managedQuestions);
printf("Questões M2V2 com estrutura inválida:%d\n", $invalidAlternatives);

if ($boss) {
    printf(
        "Avaliação final do módulo:          #%d | %d/%d questões | nota %.0f%%\n",
        (int)$boss['id'],
        (int)$boss['question_count'],
        (int)$boss['question_limit'],
        (float)$boss['required_score']
    );

    if ((int)$boss['question_count'] !== 10
        || (int)$boss['question_limit'] !== 10
        || abs((float)$boss['required_score'] - 80.0) > 0.01) {
        $problems[] = 'Avaliação final do módulo está fora do padrão 10 questões / 80%.';
    }
} else {
    echo "Avaliação final do módulo:          AUSENTE\n";
    $problems[] = 'Módulo 2 não possui avaliação final module_boss.';
}

if ($requiredPhases !== 11) {
    $problems[] = "Quantidade de fases obrigatórias é {$requiredPhases}; esperado: 11.";
}
if ($optionalPhases !== 5) {
    $problems[] = "Quantidade de fases complementares é {$optionalPhases}; esperado: 5.";
}
if ($managedQuestions !== 55) {
    $problems[] = "Banco curado M2V2 possui {$managedQuestions}/55 questões.";
}
if ($invalidAlternatives > 0) {
    $problems[] = "{$invalidAlternatives} questão(ões) M2V2 com alternativas/gabarito inválidos.";
}

if ($problems !== []) {
    echo "\nPROBLEMAS ENCONTRADOS:\n";
    foreach ($problems as $i=>$problem) {
        printf("%02d. %s\n", $i+1, $problem);
    }
    fwrite(STDERR, "\n[FALHA] Módulo 2 ainda não está consistente.\n");
    exit(4);
}

echo "\n[OK] Módulo 2 consistente: conteúdo reforçado, 11 fixações, 11 checkpoints, 55 questões curadas e avaliação final válida.\n";
