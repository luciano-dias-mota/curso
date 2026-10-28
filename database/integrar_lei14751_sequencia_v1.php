<?php

declare(strict_types=1);

/**
 * Integra 9 aulas já importadas da Lei nº 14.751/2023 ao fluxo obrigatório
 * da fase "Regime constitucional dos militares e Lei Orgânica Nacional".
 *
 * Diagnóstico (somente leitura):
 *   php database/integrar_lei14751_sequencia_v1.php
 * Aplicação (backup e homologação obrigatórios):
 *   php database/integrar_lei14751_sequencia_v1.php --apply --force-production
 * Auditoria (somente leitura):
 *   php database/integrar_lei14751_sequencia_v1.php --audit
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente CLI.\n");
}
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Database;

App::boot(BASE_PATH);

$apply = in_array('--apply', $argv ?? [], true);
$audit = in_array('--audit', $argv ?? [], true);
$force = in_array('--force-production', $argv ?? [], true);
$production = strtolower((string) config('app.env', 'production')) === 'production';

if ($apply && $audit) {
    throw new RuntimeException('Use --apply OU --audit.');
}
if ($apply && $production && !$force) {
    fwrite(STDERR, "[BLOQUEADO] Produção exige backup, homologação e --force-production.\n");
    exit(1);
}

$source = json_decode(
    (string) file_get_contents(BASE_PATH . '/database/sources/lei14751_ufmt_aulas_v1.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$lessons = $source['lessons'] ?? [];
if (($source['format_version'] ?? null) !== 1 || count($lessons) !== 9) {
    throw new RuntimeException('Fonte das nove aulas está ausente ou inválida.');
}

$pdo = Database::connection();
$column = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'lessons'
       AND COLUMN_NAME = 'completion_mode'"
);
if ((int) $column->fetchColumn() !== 1) {
    throw new RuntimeException('Coluna lessons.completion_mode não encontrada.');
}

$scope = $pdo->prepare(
    "SELECT c.id AS course_id, m.id AS module_id, p.id AS phase_id
     FROM courses c
     JOIN modules m ON m.course_id = c.id
     JOIN phases p ON p.module_id = m.id
     WHERE c.experience_key = 'pmmt'
       AND c.status = 'published'
       AND m.status = 'published'
       AND p.status = 'published'
       AND p.is_required = 1
       AND m.slug = 'modulo-6-direito-constitucional-e-legislacao-institucional-da-pmmt'
       AND p.slug = '4-regime-dos-militares-e-seguranca-publica'"
);
$scope->execute();
$scopes = $scope->fetchAll(PDO::FETCH_ASSOC);
if (count($scopes) !== 1) {
    throw new RuntimeException('Fase obrigatória do Módulo 6 PMMT ausente ou ambígua.');
}
$courseId = (int) $scopes[0]['course_id'];
$moduleId = (int) $scopes[0]['module_id'];
$phaseId = (int) $scopes[0]['phase_id'];

$allStmt = $pdo->prepare(
    "SELECT id, slug, title, summary, position, is_required, completion_mode, status
     FROM lessons WHERE phase_id = :phase ORDER BY position, id"
);
$allStmt->execute(['phase' => $phaseId]);
$all = $allStmt->fetchAll(PDO::FETCH_ASSOC);

$targets = [];
foreach ($lessons as $lesson) {
    if (($lesson['phase_slug'] ?? '') !== '4-regime-dos-militares-e-seguranca-publica') {
        throw new RuntimeException('Fonte com fase divergente.');
    }
    $slug = (string) $lesson['slug'];
    if (!str_starts_with($slug, 'reforco-lo-14751-ufmt-') || isset($targets[$slug])) {
        throw new RuntimeException("Slug de reforço inválido ou duplicado: {$slug}");
    }
    $targets[$slug] = null;
}
$originals = [];
foreach ($all as $row) {
    $slug = (string) $row['slug'];
    if (array_key_exists($slug, $targets)) {
        if ($targets[$slug] !== null || $row['status'] !== 'published') {
            throw new RuntimeException("Aula de reforço duplicada ou não publicada: {$slug}");
        }
        $targets[$slug] = $row;
    } else {
        $originals[] = $row;
    }
}
if (count($originals) < 1 || in_array(null, array_values($targets), true)) {
    throw new RuntimeException('São necessárias as nove aulas já importadas e aulas originais na fase.');
}

$maxOriginal = max(array_map(static fn (array $row): int => (int) $row['position'], $originals));
$ordered = [];
$expected = [];
foreach ($lessons as $i => $lesson) {
    $row = $targets[$lesson['slug']];
    $row['target_position'] = $maxOriginal + $i + 1;
    if (!in_array((int) $row['is_required'], [0, 1], true)) {
        throw new RuntimeException("is_required divergente: {$row['slug']}");
    }
    $ordered[] = $row;
    $expected[(int) $row['id']] = [];
}

$questionsStmt = $pdo->prepare(
    "SELECT q.id,
            (SELECT COUNT(*) FROM alternatives a WHERE a.question_id = q.id) AS alt_count,
            (SELECT COALESCE(SUM(a.is_correct),0) FROM alternatives a WHERE a.question_id = q.id) AS correct_count
     FROM questions q
     INNER JOIN question_import_keys k ON k.question_id = q.id
     WHERE q.lesson_id = :lesson
       AND q.phase_id = :phase
       AND q.module_id = :module
       AND q.course_id = :course
       AND q.active = 1
       AND q.difficulty = 'medium'
       AND k.source_key LIKE 'LO14751-UFMT-V1-%'
     ORDER BY q.id"
);
$quizStmt = $pdo->prepare(
    "SELECT id, active, question_limit, required_score, quiz_type
     FROM quizzes
     WHERE lesson_id = :lesson AND quiz_type = 'lesson_fixation'"
);
$linkedStmt = $pdo->prepare(
    "SELECT question_id FROM quiz_questions WHERE quiz_id = :quiz ORDER BY position, question_id"
);
$existingQuizzes = [];
$pendingQuizzes = 0;
$needsChange = false;

foreach ($ordered as $row) {
    $id = (int) $row['id'];
    $questionsStmt->execute([
        'lesson' => $id, 'phase' => $phaseId,
        'module' => $moduleId, 'course' => $courseId
    ]);
    $items = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($items) !== 10) {
        throw new RuntimeException("Aula #{$id} exige exatamente 10 questões Medium vinculadas.");
    }
    foreach ($items as $item) {
        if ((int) $item['alt_count'] !== 5 || (int) $item['correct_count'] !== 1) {
            throw new RuntimeException("Questão #{$item['id']} com alternativas/gabarito inválidos.");
        }
    }
    $expected[$id] = array_map(static fn (array $item): int => (int) $item['id'], $items);
    $quizStmt->execute(['lesson' => $id]);
    $quizzes = $quizStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($quizzes) > 1) {
        throw new RuntimeException("Mais de um exercício de fixação na aula #{$id}.");
    }
    if (!$quizzes) {
        ++$pendingQuizzes;
        $existingQuizzes[$id] = null;
    } else {
        $quiz = $quizzes[0];
        if ((int) $quiz['active'] !== 1
            || (int) $quiz['question_limit'] !== 10
            || (float) $quiz['required_score'] !== 80.0) {
            throw new RuntimeException("Exercício existente divergente na aula #{$id}.");
        }
        $linkedStmt->execute(['quiz' => (int) $quiz['id']]);
        $linked = array_map('intval', $linkedStmt->fetchAll(PDO::FETCH_COLUMN));
        if ($linked !== $expected[$id]) {
            throw new RuntimeException("Questões vinculadas divergentes no exercício da aula #{$id}.");
        }
        $existingQuizzes[$id] = (int) $quiz['id'];
    }
    if ((int) $row['position'] !== (int) $row['target_position']
        || (int) $row['is_required'] !== 1
        || $row['completion_mode'] !== 'quiz') {
        $needsChange = true;
    }
}
$needsChange = $needsChange || $pendingQuizzes > 0;

echo "===========================================================\n";
echo " LEI 14.751 — INSERÇÃO NA SEQUÊNCIA NORMAL DO MÓDULO 6\n";
echo "===========================================================\n";
printf("Banco: %s | Curso #%d | Módulo #%d | Fase #%d\n",
    (string) $pdo->query('SELECT DATABASE()')->fetchColumn(), $courseId, $moduleId, $phaseId);
printf("Aulas originais mantidas: %d\n", count($originals));
printf("Aulas integradas: %d | novos quizzes: %d | 10 questões cada\n",
    count($ordered), $pendingQuizzes);
echo "Regra: avançar após exercício de fixação (mínimo 8/10).\n";
foreach ($ordered as $row) {
    printf("  Aula #%d | posição %d -> %d | %s\n",
        $row['id'], $row['position'], $row['target_position'], $row['title']);
}
printf("Modo: %s | ajustes pendentes: %s\n",
    $audit ? 'AUDITORIA' : ($apply ? 'APLICAÇÃO' : 'DRY-RUN'),
    $needsChange ? 'SIM' : 'NÃO');

if ($audit) {
    if ($needsChange) {
        fwrite(STDERR, "[PENDENTE] Integração ainda não está completa.\n");
        exit(6);
    }
    echo "[OK] Ordem, obrigatoriedade e 9 quizzes de fixação validados.\n";
    exit(0);
}
if (!$apply) {
    echo "[DRY-RUN] Nenhum dado alterado.\n";
    exit(0);
}
if (!$needsChange) {
    echo "[OK] Já integrado. Nenhum dado alterado.\n";
    exit(0);
}

$phaseProgress = $pdo->prepare(
    "SELECT COUNT(*) FROM user_phase_progress
     WHERE phase_id = :phase AND status IN ('in_progress', 'completed')"
);
$phaseProgress->execute(['phase' => $phaseId]);
if ((int) $phaseProgress->fetchColumn() > 0) {
    throw new RuntimeException('Há alunos com fase em andamento/concluída. Migração interrompida para preservar progresso.');
}
$students = $pdo->prepare(
    "SELECT COUNT(*) FROM user_lesson_progress
     WHERE lesson_id = :lesson
       AND (status IN ('in_progress','completed') OR blocks_viewed > 0)"
);
foreach ($ordered as $row) {
    $students->execute(['lesson' => (int) $row['id']]);
    if ((int) $students->fetchColumn() > 0) {
        throw new RuntimeException('Há alunos que já iniciaram uma das aulas de reforço. Migração interrompida.');
    }
}

$move = $pdo->prepare(
    "UPDATE lessons SET position = :position,
        is_required = 1, completion_mode = 'quiz',
        summary = 'Aula integrada à sequência do Módulo 6, com fixação obrigatória.'
     WHERE id = :lesson AND phase_id = :phase AND status = 'published'"
);
$insertQuiz = $pdo->prepare(
    "INSERT INTO quizzes
       (course_id,module_id,phase_id,lesson_id,title,description,quiz_type,
        required_score,question_limit,time_limit_minutes,shuffle_questions,
        shuffle_answers,show_feedback,max_attempts,xp_reward,active)
     VALUES
       (:course,:module,:phase,:lesson,:title,:description,'lesson_fixation',
        80.00,10,NULL,1,1,1,NULL,0,1)"
);
$insertLink = $pdo->prepare(
    "INSERT INTO quiz_questions (quiz_id,question_id,position,points)
     VALUES (:quiz,:question,:position,1.00)"
);
$created = 0;
$pdo->beginTransaction();
try {
    // Deslocamento temporário evita colisões caso exista índice único de posição.
    $highest = max(array_map(static fn (array $row): int => (int) $row['position'], $all));
    $tempBase = $highest + 100;
    foreach ($ordered as $i => $row) {
        $pdo->prepare('UPDATE lessons SET position=:position WHERE id=:id AND phase_id=:phase')
            ->execute([
                'position' => $tempBase + $i + 1,
                'id' => (int) $row['id'],
                'phase' => $phaseId
            ]);
    }
    foreach ($ordered as $row) {
        $id = (int) $row['id'];
        $move->execute([
            'position' => (int) $row['target_position'],
            'lesson' => $id, 'phase' => $phaseId
        ]);
        if ($existingQuizzes[$id] !== null) {
            continue;
        }
        $insertQuiz->execute([
            'course' => $courseId,
            'module' => $moduleId,
            'phase' => $phaseId,
            'lesson' => $id,
            'title' => mb_substr('Fixação — ' . $row['title'], 0, 200),
            'description' => 'Exercício obrigatório da aula: 10 questões Medium; mínimo 8 acertos.'
        ]);
        $quizId = (int) $pdo->lastInsertId();
        foreach ($expected[$id] as $index => $questionId) {
            $insertLink->execute([
                'quiz' => $quizId,
                'question' => $questionId,
                'position' => $index + 1
            ]);
        }
        ++$created;
    }
    $pdo->commit();
    printf("[OK] 9 aulas integradas e %d exercícios criados. Progresso preservado.\n", $created);
    echo "[PRÓXIMO] Execute --audit e teste a progressão como aluno.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "[ERRO] Transação revertida: {$e->getMessage()}\n");
    exit(7);
}
