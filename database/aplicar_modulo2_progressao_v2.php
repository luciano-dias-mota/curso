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
use App\Services\ProgressInitializer;

App::boot(BASE_PATH);

$apply = in_array('--apply', $argv ?? [], true);
$forceProduction = in_array('--force-production', $argv ?? [], true);
$isProduction = strtolower((string) config('app.env', 'production')) === 'production';

if ($apply && $isProduction && !$forceProduction) {
    fwrite(
        STDERR,
        "ABORTADO: APP_ENV=production. Faça backup e execute com --apply --force-production somente após revisar o dry-run.\n"
    );
    exit(1);
}

$sourceFile = BASE_PATH . '/database/sources/modulo2_progressao_v2.json';
if (!is_file($sourceFile)) {
    fwrite(STDERR, "[FALHA] Fonte não encontrada: {$sourceFile}\n");
    exit(2);
}

$data = json_decode((string) file_get_contents($sourceFile), true, 512, JSON_THROW_ON_ERROR);
$pdo = Database::connection();

$moduleSlug = (string) ($data['module_slug'] ?? '');
$sourceLabel = (string) ($data['source_label'] ?? 'M2V2');
$phasesData = is_array($data['phases'] ?? null) ? $data['phases'] : [];
$optionalSlugs = is_array($data['optional_phase_slugs'] ?? null) ? $data['optional_phase_slugs'] : [];

$moduleStmt = $pdo->prepare(
    "SELECT m.id,m.course_id,m.title
     FROM modules m
     INNER JOIN courses c ON c.id=m.course_id
     WHERE m.slug=:slug
       AND m.status='published'
       AND c.status='published'
     LIMIT 1"
);
$moduleStmt->execute(['slug' => $moduleSlug]);
$module = $moduleStmt->fetch(\PDO::FETCH_ASSOC);

if (!$module) {
    fwrite(STDERR, "[FALHA] Módulo 2 publicado não encontrado.\n");
    exit(3);
}

$courseId = (int) $module['course_id'];
$moduleId = (int) $module['id'];

if (count($phasesData) !== 11) {
    fwrite(STDERR, "[FALHA] Fonte V2 deve conter exatamente 11 fases curriculares obrigatórias.\n");
    exit(4);
}

$totalQuestions = 0;
foreach ($phasesData as $phaseData) {
    $questions = is_array($phaseData['questions'] ?? null) ? $phaseData['questions'] : [];
    if (count($questions) !== 5) {
        fwrite(STDERR, "[FALHA] Cada fase curricular deve possuir exatamente 5 questões curadas.\n");
        exit(5);
    }
    $totalQuestions += count($questions);
}

echo "=============================================================\n";
echo " CORREÇÃO MÓDULO 2 - CONTEÚDO E PROGRESSÃO V2\n";
echo "=============================================================\n";
printf("Banco:   %s\n", (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Módulo:  #%d - %s\n", $moduleId, $module['title']);
printf("Modo:    %s\n", $apply ? 'APLICAÇÃO' : 'DRY-RUN');
printf("Fases curriculares obrigatórias: %d\n", count($phasesData));
printf("Fases complementares opcionais:  %d\n", count($optionalSlugs));
printf("Questões curadas:                %d\n\n", $totalQuestions);

$findPhase = $pdo->prepare(
    "SELECT id,title,is_required
     FROM phases
     WHERE module_id=:module_id
       AND slug=:slug
       AND status='published'
     LIMIT 1"
);

$findLesson = $pdo->prepare(
    "SELECT id,title,is_required
     FROM lessons
     WHERE phase_id=:phase_id
       AND status='published'
     ORDER BY is_required DESC, position, id
     LIMIT 1"
);

$missing = [];
$planRows = [];

foreach ($phasesData as $position => $phaseData) {
    $slug = (string) ($phaseData['slug'] ?? '');
    $findPhase->execute(['module_id' => $moduleId, 'slug' => $slug]);
    $phase = $findPhase->fetch(\PDO::FETCH_ASSOC);

    if (!$phase) {
        $missing[] = "Fase não encontrada: {$slug}";
        continue;
    }

    $findLesson->execute(['phase_id' => (int) $phase['id']]);
    $lesson = $findLesson->fetch(\PDO::FETCH_ASSOC);

    if (!$lesson) {
        $missing[] = "Aula não encontrada na fase #{$phase['id']} ({$phase['title']})";
        continue;
    }

    $planRows[] = [
        'phase_id' => (int) $phase['id'],
        'phase_title' => (string) $phase['title'],
        'lesson_id' => (int) $lesson['id'],
        'lesson_title' => (string) $lesson['title'],
        'phase_data' => $phaseData,
    ];
}

foreach ($optionalSlugs as $slug) {
    $findPhase->execute(['module_id' => $moduleId, 'slug' => (string) $slug]);
    if (!$findPhase->fetch(\PDO::FETCH_ASSOC)) {
        $missing[] = "Fase complementar não encontrada: {$slug}";
    }
}

if ($missing !== []) {
    echo "INCONSISTÊNCIAS DE MAPEAMENTO:\n";
    foreach ($missing as $problem) {
        echo " - {$problem}\n";
    }
    fwrite(STDERR, "\n[FALHA] Nenhuma alteração realizada.\n");
    exit(6);
}

foreach ($planRows as $row) {
    printf(
        "FASE #%d %-58s -> aula #%d | 3 fixação + 5 checkpoint\n",
        $row['phase_id'],
        mb_strimwidth($row['phase_title'], 0, 58, '…'),
        $row['lesson_id']
    );
}

echo "\nMudanças curriculares planejadas:\n";
echo " - Introdução deixa de ser lista de fontes e passa a ensinar o mapa do módulo.\n";
echo " - Aulas curriculares superficiais recebem bloco extra de retenção/comparação.\n";
echo " - Cada uma das 11 fases curriculares terá exercício obrigatório (3/3 = 100%).\n";
echo " - Cada uma das 11 fases curriculares terá checkpoint (4/5 = 80%).\n";
echo " - O módulo terá avaliação final com 10 questões (8/10 = 80%).\n";
echo " - As 5 fases de revisão/aprofundamento/material antigo tornam-se complementares e não bloqueiam a trilha.\n";

if (!$apply) {
    echo "\nDRY-RUN concluído. Nenhuma alteração foi gravada.\n";
    echo "Após backup, execute: php database/aplicar_modulo2_progressao_v2.php --apply";
    if ($isProduction) {
        echo " --force-production";
    }
    echo "\n";
    exit(0);
}

$pdo->beginTransaction();

try {
    // 1) Material de apoio antigo deixa de bloquear a trilha curricular.
    if ($optionalSlugs !== []) {
        $placeholders = implode(',', array_fill(0, count($optionalSlugs), '?'));
        $params = array_merge([$moduleId], array_values($optionalSlugs));
        $stmt = $pdo->prepare(
            "UPDATE phases
             SET is_required=0,
                 description=CASE
                    WHEN description LIKE 'Material complementar%' THEN description
                    ELSE CONCAT('Material complementar • não bloqueia o avanço. ', COALESCE(description,''))
                 END,
                 updated_at=NOW()
             WHERE module_id=?
               AND slug IN ({$placeholders})
               AND status='published'"
        );
        $stmt->execute($params);
    }

    $phaseRequired = $pdo->prepare(
        "UPDATE phases
         SET is_required=1,
             required_score=80,
             updated_at=NOW()
         WHERE id=:id"
    );

    $lessonRequired = $pdo->prepare(
        "UPDATE lessons
         SET is_required=1,
             updated_at=NOW()
         WHERE id=:id"
    );

    $maxBlockPosition = $pdo->prepare(
        "SELECT COALESCE(MAX(position),0)
         FROM lesson_blocks
         WHERE lesson_id=:lesson_id"
    );

    $firstBlock = $pdo->prepare(
        "SELECT id
         FROM lesson_blocks
         WHERE lesson_id=:lesson_id
         ORDER BY position,id
         LIMIT 1"
    );

    $updateBlock = $pdo->prepare(
        "UPDATE lesson_blocks
         SET block_type=:block_type,
             title=:title,
             content=:content,
             is_required=1,
             updated_at=NOW()
         WHERE id=:id"
    );

    $findExtraBlock = $pdo->prepare(
        "SELECT id
         FROM lesson_blocks
         WHERE lesson_id=:lesson_id
           AND title=:title
         LIMIT 1"
    );

    $insertExtraBlock = $pdo->prepare(
        "INSERT INTO lesson_blocks
            (lesson_id,block_type,title,content,media_url,position,is_required)
         VALUES
            (:lesson_id,:block_type,:title,:content,NULL,:position,1)"
    );

    $updateExtraBlock = $pdo->prepare(
        "UPDATE lesson_blocks
         SET block_type=:block_type,
             content=:content,
             is_required=1,
             updated_at=NOW()
         WHERE id=:id"
    );

    $findQuestion = $pdo->prepare(
        "SELECT id
         FROM questions
         WHERE course_id=:course_id
           AND module_id=:module_id
           AND phase_id=:phase_id
           AND statement=:statement
         LIMIT 1"
    );

    $insertQuestion = $pdo->prepare(
        "INSERT INTO questions
            (course_id,module_id,phase_id,lesson_id,question_type,
             statement,explanation,difficulty,source_label,active)
         VALUES
            (:course_id,:module_id,:phase_id,:lesson_id,'multiple_choice',
             :statement,:explanation,:difficulty,:source_label,1)"
    );

    $updateQuestion = $pdo->prepare(
        "UPDATE questions
         SET lesson_id=:lesson_id,
             explanation=:explanation,
             difficulty=:difficulty,
             source_label=:source_label,
             active=1,
             updated_at=NOW()
         WHERE id=:id"
    );

    $countAlternatives = $pdo->prepare(
        "SELECT COUNT(*),
                SUM(CASE WHEN is_correct=1 THEN 1 ELSE 0 END)
         FROM alternatives
         WHERE question_id=:question_id"
    );

    $insertAlternative = $pdo->prepare(
        "INSERT INTO alternatives
            (question_id,label,text,is_correct,explanation,position)
         VALUES
            (:question_id,:label,:text,:is_correct,NULL,:position)"
    );

    $findQuiz = $pdo->prepare(
        "SELECT id
         FROM quizzes
         WHERE quiz_type=:quiz_type
           AND (
              (:lesson_id > 0 AND lesson_id=:lesson_id2)
              OR
              (:lesson_id = 0 AND lesson_id IS NULL AND phase_id=:phase_id)
           )
           AND module_id=:module_id
         ORDER BY id
         LIMIT 1"
    );

    $insertQuiz = $pdo->prepare(
        "INSERT INTO quizzes
            (course_id,module_id,phase_id,lesson_id,title,description,quiz_type,
             required_score,question_limit,time_limit_minutes,shuffle_questions,
             shuffle_answers,show_feedback,max_attempts,xp_reward,active)
         VALUES
            (:course_id,:module_id,:phase_id,:lesson_id,:title,:description,:quiz_type,
             :required_score,:question_limit,NULL,0,0,1,NULL,:xp_reward,1)"
    );

    $updateQuiz = $pdo->prepare(
        "UPDATE quizzes
         SET course_id=:course_id,
             module_id=:module_id,
             phase_id=:phase_id,
             lesson_id=:lesson_id,
             title=:title,
             description=:description,
             required_score=:required_score,
             question_limit=:question_limit,
             shuffle_questions=0,
             shuffle_answers=0,
             show_feedback=1,
             max_attempts=NULL,
             xp_reward=:xp_reward,
             active=1,
             updated_at=NOW()
         WHERE id=:id"
    );

    $linkQuestion = $pdo->prepare(
        "INSERT INTO quiz_questions (quiz_id,question_id,position,points)
         VALUES (:quiz_id,:question_id,:position,1.00)
         ON DUPLICATE KEY UPDATE
            position=VALUES(position),
            points=VALUES(points)"
    );

    $questionIdsByPhase = [];
    $createdQuestions = 0;
    $reusedQuestions = 0;
    $createdQuizzes = 0;
    $updatedQuizzes = 0;
    $blocksCreated = 0;
    $blocksUpdated = 0;

    $ensureQuiz = static function (
        string $type,
        int $phaseId,
        int $lessonId,
        string $title,
        string $description,
        float $requiredScore,
        int $limit,
        int $xp
    ) use (
        $findQuiz,$insertQuiz,$updateQuiz,$courseId,$moduleId,
        &$createdQuizzes,&$updatedQuizzes
    ): int {
        $findQuiz->execute([
            'quiz_type' => $type,
            'lesson_id' => $lessonId,
            'lesson_id2' => $lessonId,
            'phase_id' => $phaseId,
            'module_id' => $moduleId,
        ]);
        $id = (int) ($findQuiz->fetchColumn() ?: 0);

        $params = [
            'course_id' => $courseId,
            'module_id' => $moduleId,
            'phase_id' => $phaseId,
            'lesson_id' => $lessonId > 0 ? $lessonId : null,
            'title' => $title,
            'description' => $description,
            'required_score' => $requiredScore,
            'question_limit' => $limit,
            'xp_reward' => $xp,
        ];

        if ($id > 0) {
            $updateQuiz->execute($params + ['id' => $id]);
            $updatedQuizzes++;
            return $id;
        }

        $insertQuiz->execute($params + ['quiz_type' => $type]);
        $createdQuizzes++;
        return (int) Database::connection()->lastInsertId();
    };

    foreach ($planRows as $row) {
        $phaseId = (int) $row['phase_id'];
        $lessonId = (int) $row['lesson_id'];
        $phaseData = $row['phase_data'];

        $phaseRequired->execute(['id' => $phaseId]);
        $lessonRequired->execute(['id' => $lessonId]);

        if (is_array($phaseData['replace_first_block'] ?? null)) {
            $firstBlock->execute(['lesson_id' => $lessonId]);
            $blockId = (int) ($firstBlock->fetchColumn() ?: 0);
            $b = $phaseData['replace_first_block'];

            if ($blockId > 0) {
                $updateBlock->execute([
                    'block_type' => (string) ($b['type'] ?? 'theory'),
                    'title' => (string) ($b['title'] ?? 'Conteúdo'),
                    'content' => (string) ($b['content'] ?? ''),
                    'id' => $blockId,
                ]);
                $blocksUpdated++;
            }
        }

        $maxBlockPosition->execute(['lesson_id' => $lessonId]);
        $nextPosition = (int) $maxBlockPosition->fetchColumn();

        foreach (($phaseData['extra_blocks'] ?? []) as $extra) {
            $title = (string) ($extra['title'] ?? 'Reforço');
            $findExtraBlock->execute([
                'lesson_id' => $lessonId,
                'title' => $title,
            ]);
            $blockId = (int) ($findExtraBlock->fetchColumn() ?: 0);

            if ($blockId > 0) {
                $updateExtraBlock->execute([
                    'block_type' => (string) ($extra['type'] ?? 'comparison'),
                    'content' => (string) ($extra['content'] ?? ''),
                    'id' => $blockId,
                ]);
                $blocksUpdated++;
            } else {
                $nextPosition++;
                $insertExtraBlock->execute([
                    'lesson_id' => $lessonId,
                    'block_type' => (string) ($extra['type'] ?? 'comparison'),
                    'title' => $title,
                    'content' => (string) ($extra['content'] ?? ''),
                    'position' => $nextPosition,
                ]);
                $blocksCreated++;
            }
        }

        $questionIds = [];

        foreach ($phaseData['questions'] as $question) {
            $statement = trim((string) ($question['statement'] ?? ''));
            $findQuestion->execute([
                'course_id' => $courseId,
                'module_id' => $moduleId,
                'phase_id' => $phaseId,
                'statement' => $statement,
            ]);
            $questionId = (int) ($findQuestion->fetchColumn() ?: 0);

            if ($questionId <= 0) {
                $insertQuestion->execute([
                    'course_id' => $courseId,
                    'module_id' => $moduleId,
                    'phase_id' => $phaseId,
                    'lesson_id' => $lessonId,
                    'statement' => $statement,
                    'explanation' => (string) ($question['explanation'] ?? ''),
                    'difficulty' => (string) ($question['difficulty'] ?? 'medium'),
                    'source_label' => mb_strimwidth($sourceLabel, 0, 255, ''),
                ]);
                $questionId = (int) $pdo->lastInsertId();
                $createdQuestions++;
            } else {
                $updateQuestion->execute([
                    'lesson_id' => $lessonId,
                    'explanation' => (string) ($question['explanation'] ?? ''),
                    'difficulty' => (string) ($question['difficulty'] ?? 'medium'),
                    'source_label' => mb_strimwidth($sourceLabel, 0, 255, ''),
                    'id' => $questionId,
                ]);
                $reusedQuestions++;
            }

            $countAlternatives->execute(['question_id' => $questionId]);
            $altStats = $countAlternatives->fetch(\PDO::FETCH_NUM);
            $altCount = (int) ($altStats[0] ?? 0);
            $correctCount = (int) ($altStats[1] ?? 0);

            if ($altCount === 0) {
                foreach (($question['alternatives'] ?? []) as $idx => $alternative) {
                    $insertAlternative->execute([
                        'question_id' => $questionId,
                        'label' => (string) ($alternative['label'] ?? chr(65 + $idx)),
                        'text' => (string) ($alternative['text'] ?? ''),
                        'is_correct' => !empty($alternative['is_correct']) ? 1 : 0,
                        'position' => $idx + 1,
                    ]);
                }
            } elseif ($altCount !== 5 || $correctCount !== 1) {
                throw new RuntimeException(
                    "Questão #{$questionId} já existe, mas possui estrutura de alternativas incompatível ({$altCount} alternativas / {$correctCount} correta)."
                );
            }

            $questionIds[] = $questionId;
        }

        $questionIdsByPhase[$phaseId] = $questionIds;

        $fixQuizId = $ensureQuiz(
            'lesson_fixation',
            $phaseId,
            $lessonId,
            'Fixação — ' . (string) $row['phase_title'],
            'Exercício obrigatório: 3 questões. Acerte 3 de 3 para concluir a aula e liberar a avaliação da fase.',
            100.0,
            3,
            20
        );

        foreach (array_slice($questionIds, 0, 3) as $idx => $questionId) {
            $linkQuestion->execute([
                'quiz_id' => $fixQuizId,
                'question_id' => $questionId,
                'position' => $idx + 1,
            ]);
        }

        $phaseQuizId = $ensureQuiz(
            'phase_exam',
            $phaseId,
            0,
            'Checkpoint — ' . (string) $row['phase_title'],
            'Avaliação obrigatória da fase: 5 questões. Aprovação com pelo menos 4 acertos.',
            80.0,
            5,
            80
        );

        foreach ($questionIds as $idx => $questionId) {
            $linkQuestion->execute([
                'quiz_id' => $phaseQuizId,
                'question_id' => $questionId,
                'position' => $idx + 1,
            ]);
        }
    }

    // Avaliação final: uma questão de cada uma das dez fases temáticas
    // (fases 2 a 11), deixando a introdução como preparação e não como peso final.
    $bossQuestions = [];
    foreach (array_slice($planRows, 1) as $row) {
        $ids = $questionIdsByPhase[(int) $row['phase_id']] ?? [];
        if (isset($ids[1])) {
            $bossQuestions[] = $ids[1];
        }
    }

    if (count($bossQuestions) !== 10) {
        throw new RuntimeException(
            'Não foi possível montar as 10 questões da avaliação final do Módulo 2.'
        );
    }

    $bossFind = $pdo->prepare(
        "SELECT id
         FROM quizzes
         WHERE module_id=:module_id
           AND quiz_type='module_boss'
         ORDER BY id
         LIMIT 1"
    );
    $bossFind->execute(['module_id' => $moduleId]);
    $bossId = (int) ($bossFind->fetchColumn() ?: 0);

    if ($bossId > 0) {
        $updateQuiz->execute([
            'course_id' => $courseId,
            'module_id' => $moduleId,
            'phase_id' => null,
            'lesson_id' => null,
            'title' => 'Avaliação final — Administração Pública e Gestão',
            'description' => 'Avaliação final do Módulo 2: 10 questões. Aprovação com pelo menos 8 acertos.',
            'required_score' => 80.0,
            'question_limit' => 10,
            'xp_reward' => 300,
            'id' => $bossId,
        ]);
        $updatedQuizzes++;
    } else {
        $insertQuiz->execute([
            'course_id' => $courseId,
            'module_id' => $moduleId,
            'phase_id' => null,
            'lesson_id' => null,
            'title' => 'Avaliação final — Administração Pública e Gestão',
            'description' => 'Avaliação final do Módulo 2: 10 questões. Aprovação com pelo menos 8 acertos.',
            'quiz_type' => 'module_boss',
            'required_score' => 80.0,
            'question_limit' => 10,
            'xp_reward' => 300,
        ]);
        $bossId = (int) $pdo->lastInsertId();
        $createdQuizzes++;
    }

    foreach ($bossQuestions as $idx => $questionId) {
        $linkQuestion->execute([
            'quiz_id' => $bossId,
            'question_id' => $questionId,
            'position' => $idx + 1,
        ]);
    }

    // Corrige descrições da fase/aula inicial.
    $intro = $planRows[0] ?? null;
    if ($intro) {
        $pdo->prepare(
            "UPDATE phases
             SET description='Trilha obrigatória • visão geral didática + exercício + checkpoint.',
                 updated_at=NOW()
             WHERE id=:id"
        )->execute(['id' => (int) $intro['phase_id']]);

        $pdo->prepare(
            "UPDATE lessons
             SET summary='Visão geral do Módulo 2: conceitos-chave, diferenças de prova e mapa de estudo.',
                 estimated_minutes=10,
                 xp_reward=20,
                 updated_at=NOW()
             WHERE id=:id"
        )->execute(['id' => (int) $intro['lesson_id']]);
    }

    $pdo->commit();

    // Atualiza os pontos de entrada da trilha para todas as matrículas ativas.
    $usersStmt = $pdo->prepare(
        "SELECT DISTINCT user_id
         FROM enrollments
         WHERE course_id=:course_id
           AND status='active'"
    );
    $usersStmt->execute(['course_id' => $courseId]);

    $initializer = new ProgressInitializer();
    $initialized = 0;
    foreach ($usersStmt->fetchAll(\PDO::FETCH_COLUMN) as $userId) {
        $initializer->initializeEnrollment((int) $userId, $courseId, $pdo);
        $initialized++;
    }

    echo "\n[OK] Correção aplicada.\n";
    printf("Questões novas:             %d\n", $createdQuestions);
    printf("Questões reaproveitadas:    %d\n", $reusedQuestions);
    printf("Quizzes criados:            %d\n", $createdQuizzes);
    printf("Quizzes atualizados:        %d\n", $updatedQuizzes);
    printf("Blocos pedagógicos criados: %d\n", $blocksCreated);
    printf("Blocos pedagógicos atualiz.: %d\n", $blocksUpdated);
    printf("Matrículas reinicializadas: %d\n", $initialized);
    echo "\nExecute agora: php database/auditar_modulo2_progressao_v1.php\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "ERRO: {$e->getMessage()}\n");
    exit(7);
}
