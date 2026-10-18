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

$sourceFile = BASE_PATH . '/database/sources/complemento_edital_mi_2026_v1.json';
if (!is_file($sourceFile)) {
    fwrite(STDERR, "[FALHA] Fonte curricular não encontrada.\n");
    exit(2);
}

$data = json_decode((string) file_get_contents($sourceFile), true, 512, JSON_THROW_ON_ERROR);
$phasesData = is_array($data['phases'] ?? null) ? $data['phases'] : [];

$moduleStmt = $pdo->prepare(
    "SELECT id,title
     FROM modules
     WHERE slug=:slug
       AND status='published'
     LIMIT 1"
);

$phaseStmt = $pdo->prepare(
    "SELECT id,title,is_required,required_score
     FROM phases
     WHERE module_id=:module_id
       AND slug=:slug
       AND status='published'
     LIMIT 1"
);

$lessonStmt = $pdo->prepare(
    "SELECT l.id,l.title,l.is_required,
            COUNT(lb.id) AS block_count
     FROM lessons l
     LEFT JOIN lesson_blocks lb ON lb.lesson_id=l.id
     WHERE l.phase_id=:phase_id
       AND l.status='published'
     GROUP BY l.id,l.title,l.is_required
     ORDER BY l.position,l.id"
);

$fixStmt = $pdo->prepare(
    "SELECT q.id,q.required_score,q.question_limit,
            COUNT(qq.question_id) AS linked_questions
     FROM quizzes q
     LEFT JOIN quiz_questions qq ON qq.quiz_id=q.id
     WHERE q.lesson_id=:lesson_id
       AND q.quiz_type='lesson_fixation'
       AND q.active=1
     GROUP BY q.id,q.required_score,q.question_limit
     ORDER BY q.id
     LIMIT 1"
);

$checkpointStmt = $pdo->prepare(
    "SELECT q.id,q.required_score,q.question_limit,
            COUNT(qq.question_id) AS linked_questions
     FROM quizzes q
     LEFT JOIN quiz_questions qq ON qq.quiz_id=q.id
     WHERE q.phase_id=:phase_id
       AND q.lesson_id IS NULL
       AND q.quiz_type='phase_exam'
       AND q.active=1
     GROUP BY q.id,q.required_score,q.question_limit
     ORDER BY q.id
     LIMIT 1"
);

$keyStmt = $pdo->prepare(
    "SELECT q.id,q.module_id,q.phase_id,q.lesson_id,q.question_type,q.active,
            COUNT(a.id) AS alternative_count,
            SUM(CASE WHEN a.is_correct=1 THEN 1 ELSE 0 END) AS correct_count
     FROM question_import_keys k
     INNER JOIN questions q ON q.id=k.question_id
     LEFT JOIN alternatives a ON a.question_id=q.id
     WHERE k.source_key=:source_key
     GROUP BY q.id,q.module_id,q.phase_id,q.lesson_id,q.question_type,q.active"
);

echo "=============================================================\n";
echo " AUDITORIA - COMPLEMENTO DO EDITAL MI 2026 V1\n";
echo "=============================================================\n";
printf("Banco: %s\n\n", (string)$pdo->query('SELECT DATABASE()')->fetchColumn());

$problems = [];
$totalKeys = 0;
$validKeys = 0;
$totalLessons = 0;
$validFixations = 0;
$validCheckpoints = 0;

foreach ($phasesData as $phaseData) {
    $moduleStmt->execute(['slug'=>(string)$phaseData['module_slug']]);
    $module = $moduleStmt->fetch(PDO::FETCH_ASSOC);

    echo "-------------------------------------------------------------\n";
    printf("%s\n", (string)$phaseData['title']);

    if (!$module) {
        $problems[] = 'Módulo ausente: ' . (string)$phaseData['module_slug'];
        echo "Módulo: AUSENTE\n";
        continue;
    }

    $moduleId = (int)$module['id'];
    $phaseStmt->execute([
        'module_id'=>$moduleId,
        'slug'=>(string)$phaseData['slug'],
    ]);
    $phase = $phaseStmt->fetch(PDO::FETCH_ASSOC);

    if (!$phase) {
        $problems[] = 'Fase ausente: ' . (string)$phaseData['slug'];
        echo "Fase: AUSENTE\n";
        continue;
    }

    $phaseId = (int)$phase['id'];
    printf(
        "Módulo #%d | Fase #%d | obrigatória=%s | nota=%.0f%%\n",
        $moduleId,
        $phaseId,
        (int)$phase['is_required']===1 ? 'SIM' : 'NÃO',
        (float)$phase['required_score']
    );

    if ((int)$phase['is_required'] !== 1) {
        $problems[] = "Fase #{$phaseId} não está obrigatória.";
    }
    if (abs((float)$phase['required_score'] - 80.0) >= 0.01) {
        $problems[] = "Fase #{$phaseId} com nota mínima diferente de 80%.";
    }

    $lessonStmt->execute(['phase_id'=>$phaseId]);
    $lessons = $lessonStmt->fetchAll(PDO::FETCH_ASSOC);
    $expectedLessons = count($phaseData['lessons'] ?? []);

    printf("Aulas: %d / %d\n", count($lessons), $expectedLessons);

    if (count($lessons) < $expectedLessons) {
        $problems[] =
            "Fase #{$phaseId} possui " . count($lessons)
            . " aula(s); esperado ao menos {$expectedLessons}.";
    }

    foreach ($lessons as $lesson) {
        $lessonId = (int)$lesson['id'];
        $totalLessons++;

        if ((int)$lesson['is_required'] !== 1) {
            $problems[] = "Aula #{$lessonId} não está obrigatória.";
        }

        if ((int)$lesson['block_count'] < 2) {
            $problems[] =
                "Aula #{$lessonId} '{$lesson['title']}' possui somente "
                . (int)$lesson['block_count'] . " bloco(s).";
        }

        $fixStmt->execute(['lesson_id'=>$lessonId]);
        $fix = $fixStmt->fetch(PDO::FETCH_ASSOC);

        if (!$fix) {
            $problems[] = "Aula #{$lessonId} sem fixação.";
        } elseif (
            (int)$fix['question_limit'] === 3
            && (int)$fix['linked_questions'] === 3
            && abs((float)$fix['required_score'] - 100.0) < 0.01
        ) {
            $validFixations++;
        } else {
            $problems[] = "Fixação da aula #{$lessonId} fora do padrão 3/3 a 100%.";
        }
    }

    $checkpointStmt->execute(['phase_id'=>$phaseId]);
    $checkpoint = $checkpointStmt->fetch(PDO::FETCH_ASSOC);

    if (!$checkpoint) {
        $problems[] = "Fase #{$phaseId} sem checkpoint.";
    } elseif (
        (int)$checkpoint['question_limit'] === 5
        && (int)$checkpoint['linked_questions'] === 5
        && abs((float)$checkpoint['required_score'] - 80.0) < 0.01
    ) {
        $validCheckpoints++;
    } else {
        $problems[] = "Checkpoint da fase #{$phaseId} fora do padrão 5/5 a 80%.";
    }

    $phaseKeys = 0;
    foreach (($phaseData['questions'] ?? []) as $question) {
        $sourceKey = (string)$question['source_key'];
        $totalKeys++;

        $keyStmt->execute(['source_key'=>$sourceKey]);
        $row = $keyStmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $problems[] = "Chave ausente: {$sourceKey}.";
            continue;
        }

        $phaseKeys++;
        $validKeys++;

        if ((int)$row['module_id'] !== $moduleId
            || (int)$row['phase_id'] !== $phaseId
            || (int)($row['lesson_id'] ?? 0) <= 0) {
            $problems[] = "{$sourceKey} está fora da fase/aula esperada.";
        }

        if ((int)$row['active'] !== 1
            || (string)$row['question_type'] !== 'multiple_choice'
            || (int)$row['alternative_count'] !== 5
            || (int)$row['correct_count'] !== 1) {
            $problems[] = "{$sourceKey} possui estrutura inválida.";
        }
    }

    printf(
        "Questões curadas com chave: %d / %d\n",
        $phaseKeys,
        count($phaseData['questions'] ?? [])
    );
}

echo "\n=============================================================\n";
echo " RESUMO\n";
echo "=============================================================\n";
printf("Fases novas auditadas:             %d\n", count($phasesData));
printf("Aulas auditadas:                   %d\n", $totalLessons);
printf("Fixações válidas 3/3 a 100%%:       %d / %d\n", $validFixations, $totalLessons);
printf("Checkpoints válidos 5/5 a 80%%:     %d / %d\n", $validCheckpoints, count($phasesData));
printf("Questões/chaves válidas:           %d / %d\n", $validKeys, $totalKeys);
echo "Portaria nº 025/QCG/2025:          PENDENTE DE FONTE OFICIAL\n";

if ($problems !== []) {
    echo "\nPROBLEMAS ENCONTRADOS: " . count($problems) . "\n";
    foreach ($problems as $i=>$problem) {
        printf("%02d. %s\n", $i+1, $problem);
    }

    fwrite(STDERR, "\n[FALHA] O complemento do edital ainda possui inconsistências.\n");
    exit(4);
}

printf("\n[OK] As %d matérias adicionadas estão íntegras, obrigatórias e com exercícios/checkpoints válidos.\n", count($phasesData));
echo "[PENDENTE] A cobertura só será declarada 100% após integrar a Portaria nº 025/QCG/2025 a partir de fonte oficial.\n";
