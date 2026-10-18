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

$sourceFile = BASE_PATH . '/database/sources/modulos_4a8_progressao_v2.json';
if (!is_file($sourceFile)) {
    fwrite(STDERR, "[FALHA] Fonte não encontrada: {$sourceFile}\n");
    exit(2);
}

$data = json_decode((string) file_get_contents($sourceFile), true, 512, JSON_THROW_ON_ERROR);
$modulesConfig = is_array($data['modules'] ?? null) ? $data['modules'] : [];

if ($modulesConfig === []) {
    fwrite(STDERR, "[FALHA] Nenhum módulo configurado na fonte.\n");
    exit(3);
}

$pdo = Database::connection();

$normalize = static function (string $value): string {
    $value = mb_strtolower($value, 'UTF-8');
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    return is_string($ascii) ? strtolower($ascii) : $value;
};

$selectorMatches = static function (array $phase, array $selector) use ($normalize): bool {
    if (isset($selector['slug'])) {
        return (string) $phase['slug'] === (string) $selector['slug'];
    }

    if (isset($selector['title_prefix'])) {
        $title = $normalize((string) $phase['title']);
        $prefix = $normalize((string) $selector['title_prefix']);
        return str_starts_with($title, $prefix);
    }

    if (isset($selector['title_contains'])) {
        $title = $normalize((string) $phase['title']);
        $needle = $normalize((string) $selector['title_contains']);
        return str_contains($title, $needle);
    }

    return false;
};

$moduleStmt = $pdo->prepare(
    "SELECT m.id,m.course_id,m.title,m.slug
     FROM modules m
     INNER JOIN courses c ON c.id=m.course_id
     WHERE m.slug=:slug
       AND m.status='published'
       AND c.status='published'
     LIMIT 1"
);

$phasesStmt = $pdo->prepare(
    "SELECT id,title,slug,position,is_required
     FROM phases
     WHERE module_id=:module_id
       AND status='published'
     ORDER BY position,id"
);

$lessonsStmt = $pdo->prepare(
    "SELECT id,title,slug,position,is_required
     FROM lessons
     WHERE phase_id=:phase_id
       AND status='published'
     ORDER BY position,id"
);

$resolvedModules = [];
$validationErrors = [];

foreach ($modulesConfig as $moduleConfig) {
    $moduleSlug = (string) ($moduleConfig['module_slug'] ?? '');
    $moduleStmt->execute(['slug'=>$moduleSlug]);
    $module = $moduleStmt->fetch(\PDO::FETCH_ASSOC);

    if (!$module) {
        $validationErrors[] = "Módulo não encontrado: {$moduleSlug}";
        continue;
    }

    $moduleId = (int) $module['id'];
    $phasesStmt->execute(['module_id'=>$moduleId]);
    $allPhases = $phasesStmt->fetchAll(\PDO::FETCH_ASSOC);

    $requiredResolved = [];
    $usedIds = [];

    foreach (($moduleConfig['required_phases'] ?? []) as $phaseConfig) {
        $selector = is_array($phaseConfig['selector'] ?? null) ? $phaseConfig['selector'] : [];
        $found = null;

        foreach ($allPhases as $phase) {
            if (isset($usedIds[(int)$phase['id']])) {
                continue;
            }

            if ($selectorMatches($phase, $selector)) {
                $found = $phase;
                break;
            }
        }

        if (!$found) {
            $validationErrors[] =
                "Módulo #{$moduleId}: fase obrigatória não encontrada para seletor "
                . json_encode($selector, JSON_UNESCAPED_UNICODE);
            continue;
        }

        $phaseId = (int) $found['id'];
        $usedIds[$phaseId] = true;

        $lessonsStmt->execute(['phase_id'=>$phaseId]);
        $lessons = $lessonsStmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($lessons === []) {
            $validationErrors[] = "Módulo #{$moduleId}: fase #{$phaseId} sem aula publicada.";
            continue;
        }

        $requiredResolved[] = [
            'phase'=>$found,
            'config'=>$phaseConfig,
            'lessons'=>$lessons,
        ];
    }

    $optional = [];
    foreach ($allPhases as $phase) {
        if (!isset($usedIds[(int)$phase['id']])) {
            $optional[] = $phase;
        }
    }

    $resolvedModules[] = [
        'module'=>$module,
        'config'=>$moduleConfig,
        'required'=>$requiredResolved,
        'optional'=>$optional,
    ];
}

if ($validationErrors !== []) {
    echo "INCONSISTÊNCIAS DE MAPEAMENTO:\n";
    foreach ($validationErrors as $error) {
        echo " - {$error}\n";
    }
    fwrite(STDERR, "\n[FALHA] Nenhuma alteração realizada.\n");
    exit(4);
}

echo "=============================================================\n";
echo " CORREÇÃO GLOBAL - MÓDULOS 4, 5, 6, 7 E 8 V2\n";
echo "=============================================================\n";
printf("Banco: %s\n", (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Modo:  %s\n\n", $apply ? 'APLICAÇÃO' : 'DRY-RUN');

$totalNewQuestions = 0;

foreach ($resolvedModules as $resolved) {
    $module = $resolved['module'];
    $moduleConfig = $resolved['config'];

    $newQuestions = 0;
    foreach ($resolved['required'] as $item) {
        $newQuestions += count(
            is_array($item['config']['new_questions'] ?? null)
                ? $item['config']['new_questions']
                : []
        );
    }
    $totalNewQuestions += $newQuestions;

    echo "-------------------------------------------------------------\n";
    printf("#%d | %s\n", (int)$module['id'], $module['title']);
    printf("Fases obrigatórias após correção: %d\n", count($resolved['required']));
    printf("Fases complementares:            %d\n", count($resolved['optional']));
    printf("Questões novas curadas:           %d\n", $newQuestions);

    foreach ($resolved['required'] as $idx=>$item) {
        printf(
            "  [OBR. %02d] fase #%d | %s | %d aula(s)\n",
            $idx + 1,
            (int)$item['phase']['id'],
            (string)($item['config']['title'] ?? $item['phase']['title']),
            count($item['lessons'])
        );
    }
}

echo "\nMudanças comuns:\n";
echo " - introduções deixam de ser listas de fontes e passam a ensinar o mapa da matéria;\n";
echo " - cada aula obrigatória recebe exercício de fixação 3/3 a 100%;\n";
echo " - cada fase obrigatória recebe checkpoint 5 questões a 80%;\n";
echo " - cada módulo recebe avaliação final de 10 questões a 80%;\n";
echo " - aprofundamentos, mapas, revisões e mini-simulados antigos deixam de bloquear a trilha;\n";
echo " - as questões já existentes são reaproveitadas e associadas aos temas corretos;\n";
echo " - no POP, as quatro fases atualizadas passam a formar a trilha obrigatória.\n";
printf("Questões novas curadas no total: %d\n", $totalNewQuestions);

if (!$apply) {
    echo "\nDRY-RUN concluído. Nenhuma alteração foi gravada.\n";
    echo "Após backup, execute: php database/aplicar_modulos_4a8_progressao_v2.php --apply";
    if ($isProduction) {
        echo " --force-production";
    }
    echo "\n";
    exit(0);
}

$pdo->beginTransaction();

try {
    $updateModule = $pdo->prepare(
        "UPDATE modules
         SET required_score=80,
             updated_at=NOW()
         WHERE id=:id"
    );

    $bumpPositions = $pdo->prepare(
        "UPDATE phases
         SET position=position+1000,
             updated_at=NOW()
         WHERE module_id=:module_id
           AND status='published'"
    );

    $updatePhase = $pdo->prepare(
        "UPDATE phases
         SET title=:title,
             position=:position,
             is_required=:is_required,
             required_score=80,
             description=:description,
             updated_at=NOW()
         WHERE id=:id"
    );

    $updateLessonsRequired = $pdo->prepare(
        "UPDATE lessons
         SET is_required=:is_required,
             updated_at=NOW()
         WHERE phase_id=:phase_id
           AND status='published'"
    );

    $firstBlockStmt = $pdo->prepare(
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

    $maxBlockPosition = $pdo->prepare(
        "SELECT COALESCE(MAX(position),0)
         FROM lesson_blocks
         WHERE lesson_id=:lesson_id"
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
         WHERE module_id=:module_id
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
         SET phase_id=:phase_id,
             lesson_id=:lesson_id,
             explanation=:explanation,
             difficulty=:difficulty,
             source_label=:source_label,
             active=1,
             updated_at=NOW()
         WHERE id=:id"
    );

    $moveQuestion = $pdo->prepare(
        "UPDATE questions
         SET phase_id=:phase_id,
             lesson_id=:lesson_id,
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
         WHERE module_id=:module_id
           AND quiz_type=:quiz_type
           AND (
               (:lesson_positive > 0 AND lesson_id=:lesson_id)
               OR
               (:lesson_zero = 0 AND lesson_id IS NULL AND phase_id=:phase_id)
           )
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

    $clearQuizQuestions = $pdo->prepare(
        "DELETE FROM quiz_questions WHERE quiz_id=:quiz_id"
    );

    $linkQuestion = $pdo->prepare(
        "INSERT INTO quiz_questions
            (quiz_id,question_id,position,points)
         VALUES
            (:quiz_id,:question_id,:position,1.00)"
    );

    $ensureQuiz = static function (
        int $courseId,
        int $moduleId,
        string $type,
        int $phaseId,
        int $lessonId,
        string $title,
        string $description,
        float $requiredScore,
        int $limit,
        int $xp
    ) use ($findQuiz,$insertQuiz,$updateQuiz): int {
        $findQuiz->execute([
            'module_id'=>$moduleId,
            'quiz_type'=>$type,
            'lesson_positive'=>$lessonId,
            'lesson_id'=>$lessonId,
            'lesson_zero'=>$lessonId,
            'phase_id'=>$phaseId,
        ]);
        $quizId = (int)($findQuiz->fetchColumn() ?: 0);

        $params = [
            'course_id'=>$courseId,
            'phase_id'=>$phaseId > 0 ? $phaseId : null,
            'lesson_id'=>$lessonId > 0 ? $lessonId : null,
            'title'=>$title,
            'description'=>$description,
            'required_score'=>$requiredScore,
            'question_limit'=>$limit,
            'xp_reward'=>$xp,
        ];

        if ($quizId > 0) {
            $updateQuiz->execute($params + ['id'=>$quizId]);
            return $quizId;
        }

        $insertQuiz->execute($params + [
            'module_id'=>$moduleId,
            'quiz_type'=>$type,
        ]);

        return (int)Database::connection()->lastInsertId();
    };

    $validPhaseQuestions = $pdo->prepare(
        "SELECT q.id
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
           AND q.question_type='multiple_choice'
           AND quality.alternatives_count>=4
           AND quality.correct_count=1
         ORDER BY CASE q.difficulty WHEN 'medium' THEN 1 WHEN 'hard' THEN 2 ELSE 3 END,
                  q.id"
    );

    $unmappedQuestionsStmt = $pdo->prepare(
        "SELECT id,source_label,statement
         FROM questions
         WHERE module_id=:module_id
           AND active=1
           AND phase_id IS NULL
         ORDER BY id"
    );

    $bossFind = $pdo->prepare(
        "SELECT id
         FROM quizzes
         WHERE module_id=:module_id
           AND quiz_type='module_boss'
         ORDER BY id
         LIMIT 1"
    );

    foreach ($resolvedModules as $resolved) {
        $module = $resolved['module'];
        $moduleConfig = $resolved['config'];
        $moduleId = (int)$module['id'];
        $courseId = (int)$module['course_id'];
        $sourceLabel = (string)($moduleConfig['source_label'] ?? 'CURRICULO-V2');

        $updateModule->execute(['id'=>$moduleId]);

        // Remove conflitos de posição antes de reordenar.
        $bumpPositions->execute(['module_id'=>$moduleId]);

        $requiredById = [];
        $requiredPhaseIds = [];
        $requiredLessonIds = [];
        $position = 1;

        foreach ($resolved['required'] as $item) {
            $phaseId = (int)$item['phase']['id'];
            $phaseConfig = $item['config'];
            $phaseTitle = (string)($phaseConfig['title'] ?? $item['phase']['title']);

            $requiredById[$phaseId] = $item;
            $requiredPhaseIds[] = $phaseId;

            $updatePhase->execute([
                'title'=>$phaseTitle,
                'position'=>$position++,
                'is_required'=>1,
                'description'=>'Trilha curricular obrigatória • leitura + fixação + checkpoint.',
                'id'=>$phaseId,
            ]);

            $updateLessonsRequired->execute([
                'is_required'=>1,
                'phase_id'=>$phaseId,
            ]);

            foreach ($item['lessons'] as $lesson) {
                $requiredLessonIds[$phaseId][] = (int)$lesson['id'];
            }

            $firstLessonId = (int)$item['lessons'][0]['id'];

            if (is_array($phaseConfig['replace_first_block'] ?? null)) {
                $firstBlockStmt->execute(['lesson_id'=>$firstLessonId]);
                $blockId = (int)($firstBlockStmt->fetchColumn() ?: 0);

                if ($blockId > 0) {
                    $block = $phaseConfig['replace_first_block'];
                    $updateBlock->execute([
                        'block_type'=>(string)($block['type'] ?? 'theory'),
                        'title'=>(string)($block['title'] ?? 'Conteúdo'),
                        'content'=>(string)($block['content'] ?? ''),
                        'id'=>$blockId,
                    ]);
                }
            }

            $maxBlockPosition->execute(['lesson_id'=>$firstLessonId]);
            $nextBlockPosition = (int)$maxBlockPosition->fetchColumn();

            foreach (($phaseConfig['extra_blocks'] ?? []) as $extra) {
                $extraTitle = (string)($extra['title'] ?? 'Reforço');
                $findExtraBlock->execute([
                    'lesson_id'=>$firstLessonId,
                    'title'=>$extraTitle,
                ]);
                $existingBlockId = (int)($findExtraBlock->fetchColumn() ?: 0);

                if ($existingBlockId > 0) {
                    $updateExtraBlock->execute([
                        'block_type'=>(string)($extra['type'] ?? 'comparison'),
                        'content'=>(string)($extra['content'] ?? ''),
                        'id'=>$existingBlockId,
                    ]);
                } else {
                    $nextBlockPosition++;
                    $insertExtraBlock->execute([
                        'lesson_id'=>$firstLessonId,
                        'block_type'=>(string)($extra['type'] ?? 'comparison'),
                        'title'=>$extraTitle,
                        'content'=>(string)($extra['content'] ?? ''),
                        'position'=>$nextBlockPosition,
                    ]);
                }
            }
        }

        foreach ($resolved['optional'] as $phase) {
            $phaseId = (int)$phase['id'];

            $updatePhase->execute([
                'title'=>(string)$phase['title'],
                'position'=>$position++,
                'is_required'=>0,
                'description'=>'Material complementar • disponível para revisão e aprofundamento, sem bloquear o avanço.',
                'id'=>$phaseId,
            ]);

            $updateLessonsRequired->execute([
                'is_required'=>0,
                'phase_id'=>$phaseId,
            ]);
        }

        // Associa questões antigas que ainda não possuem fase.
        if (empty($moduleConfig['use_existing_phase_links'])) {
            $thematic = array_values(array_filter(
                $resolved['required'],
                static fn(array $item): bool =>
                    (string)($item['phase']['slug'] ?? '') !== 'introducao-e-visao-geral'
            ));

            if ($thematic === []) {
                throw new RuntimeException("Módulo #{$moduleId} sem fases temáticas obrigatórias.");
            }

            $unmappedQuestionsStmt->execute(['module_id'=>$moduleId]);
            $unmapped = $unmappedQuestionsStmt->fetchAll(\PDO::FETCH_ASSOC);
            $fallbackIndex = 0;

            foreach ($unmapped as $question) {
                $text = $normalize(
                    (string)($question['source_label'] ?? '')
                    . ' '
                    . (string)($question['statement'] ?? '')
                );

                $chosen = null;
                $bestScore = 0;

                foreach ($thematic as $item) {
                    $score = 0;
                    foreach (($item['config']['keywords'] ?? []) as $keyword) {
                        $needle = $normalize((string)$keyword);
                        if ($needle !== '' && str_contains($text, $needle)) {
                            $score++;
                        }
                    }

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $chosen = $item;
                    }
                }

                if (!$chosen) {
                    $chosen = $thematic[$fallbackIndex % count($thematic)];
                    $fallbackIndex++;
                }

                $phaseId = (int)$chosen['phase']['id'];
                $lessonId = (int)$chosen['lessons'][0]['id'];

                $moveQuestion->execute([
                    'phase_id'=>$phaseId,
                    'lesson_id'=>$lessonId,
                    'id'=>(int)$question['id'],
                ]);
            }
        }

        // Questões novas curadas.
        foreach ($resolved['required'] as $item) {
            $phaseId = (int)$item['phase']['id'];
            $lessonId = (int)$item['lessons'][0]['id'];
            $phaseConfig = $item['config'];

            foreach (($phaseConfig['new_questions'] ?? []) as $question) {
                $statement = trim((string)($question['statement'] ?? ''));

                $findQuestion->execute([
                    'module_id'=>$moduleId,
                    'statement'=>$statement,
                ]);
                $questionId = (int)($findQuestion->fetchColumn() ?: 0);

                $params = [
                    'phase_id'=>$phaseId,
                    'lesson_id'=>$lessonId,
                    'explanation'=>(string)($question['explanation'] ?? ''),
                    'difficulty'=>(string)($question['difficulty'] ?? 'medium'),
                    'source_label'=>mb_strimwidth(
                        $sourceLabel . ' | ' . (string)($phaseConfig['title'] ?? $item['phase']['title']),
                        0,
                        255,
                        ''
                    ),
                ];

                if ($questionId > 0) {
                    $updateQuestion->execute($params + ['id'=>$questionId]);
                } else {
                    $insertQuestion->execute($params + [
                        'course_id'=>$courseId,
                        'module_id'=>$moduleId,
                        'statement'=>$statement,
                    ]);
                    $questionId = (int)$pdo->lastInsertId();
                }

                $countAlternatives->execute(['question_id'=>$questionId]);
                $stats = $countAlternatives->fetch(\PDO::FETCH_NUM);
                $altCount = (int)($stats[0] ?? 0);
                $correctCount = (int)($stats[1] ?? 0);

                if ($altCount === 0) {
                    foreach (($question['alternatives'] ?? []) as $idx=>$alternative) {
                        $insertAlternative->execute([
                            'question_id'=>$questionId,
                            'label'=>(string)($alternative['label'] ?? chr(65+$idx)),
                            'text'=>(string)($alternative['text'] ?? ''),
                            'is_correct'=>!empty($alternative['is_correct']) ? 1 : 0,
                            'position'=>$idx+1,
                        ]);
                    }
                } elseif ($altCount !== 5 || $correctCount !== 1) {
                    throw new RuntimeException(
                        "Questão #{$questionId} possui {$altCount} alternativas e {$correctCount} correta(s)."
                    );
                }
            }
        }

        // Fixações de TODAS as aulas obrigatórias e checkpoint de cada fase.
        $phasePools = [];

        foreach ($resolved['required'] as $item) {
            $phaseId = (int)$item['phase']['id'];
            $phaseTitle = (string)($item['config']['title'] ?? $item['phase']['title']);

            $validPhaseQuestions->execute([
                'module_id'=>$moduleId,
                'phase_id'=>$phaseId,
            ]);
            $pool = array_map('intval', $validPhaseQuestions->fetchAll(\PDO::FETCH_COLUMN));

            if (count($pool) < 5) {
                throw new RuntimeException(
                    "Módulo #{$moduleId}, fase #{$phaseId} '{$phaseTitle}' possui somente "
                    . count($pool)
                    . " questões válidas; mínimo 5."
                );
            }

            $phasePools[$phaseId] = $pool;

            foreach ($item['lessons'] as $lessonIndex=>$lesson) {
                $lessonId = (int)$lesson['id'];
                $lessonTitle = (string)$lesson['title'];
                $fixId = $ensureQuiz(
                    $courseId,
                    $moduleId,
                    'lesson_fixation',
                    $phaseId,
                    $lessonId,
                    'Fixação — ' . $lessonTitle,
                    'Exercício obrigatório: 3 questões. Acerte 3 de 3 para concluir a aula.',
                    100.0,
                    3,
                    20
                );

                $clearQuizQuestions->execute(['quiz_id'=>$fixId]);

                $countPool = count($pool);
                for ($i=0; $i<3; $i++) {
                    $offset = (($lessonIndex * 3) + $i) % $countPool;
                    $linkQuestion->execute([
                        'quiz_id'=>$fixId,
                        'question_id'=>$pool[$offset],
                        'position'=>$i+1,
                    ]);
                }
            }

            $phaseQuizId = $ensureQuiz(
                $courseId,
                $moduleId,
                'phase_exam',
                $phaseId,
                0,
                'Checkpoint — ' . $phaseTitle,
                'Avaliação obrigatória da fase: 5 questões. Aprovação com pelo menos 4 acertos.',
                80.0,
                5,
                80
            );

            $clearQuizQuestions->execute(['quiz_id'=>$phaseQuizId]);

            foreach (array_slice($pool,0,5) as $idx=>$questionId) {
                $linkQuestion->execute([
                    'quiz_id'=>$phaseQuizId,
                    'question_id'=>$questionId,
                    'position'=>$idx+1,
                ]);
            }
        }

        // Avaliação final: distribui 10 questões entre as fases temáticas.
        $thematicPhaseIds = [];
        foreach ($resolved['required'] as $item) {
            if ((string)$item['phase']['slug'] !== 'introducao-e-visao-geral') {
                $thematicPhaseIds[] = (int)$item['phase']['id'];
            }
        }

        if ($thematicPhaseIds === []) {
            throw new RuntimeException("Módulo #{$moduleId} sem fase temática para avaliação final.");
        }

        $bossQuestions = [];
        $cursor = 0;

        while (count($bossQuestions) < 10) {
            $addedThisRound = false;

            foreach ($thematicPhaseIds as $phaseId) {
                $pool = $phasePools[$phaseId] ?? [];
                if (isset($pool[$cursor]) && !in_array($pool[$cursor], $bossQuestions, true)) {
                    $bossQuestions[] = $pool[$cursor];
                    $addedThisRound = true;

                    if (count($bossQuestions) === 10) {
                        break;
                    }
                }
            }

            if (!$addedThisRound) {
                break;
            }

            $cursor++;
        }

        if (count($bossQuestions) !== 10) {
            throw new RuntimeException(
                "Módulo #{$moduleId}: não foi possível compor 10 questões distintas para a avaliação final."
            );
        }

        $bossFind->execute(['module_id'=>$moduleId]);
        $bossId = (int)($bossFind->fetchColumn() ?: 0);

        $bossTitle = 'Avaliação final — ' . preg_replace('/^Módulo\s+\d+\s*-\s*/ui', '', (string)$module['title']);
        $bossDescription = 'Avaliação final obrigatória: 10 questões. Aprovação com pelo menos 8 acertos.';

        if ($bossId > 0) {
            $updateQuiz->execute([
                'course_id'=>$courseId,
                'phase_id'=>null,
                'lesson_id'=>null,
                'title'=>$bossTitle,
                'description'=>$bossDescription,
                'required_score'=>80.0,
                'question_limit'=>10,
                'xp_reward'=>300,
                'id'=>$bossId,
            ]);
        } else {
            $insertQuiz->execute([
                'course_id'=>$courseId,
                'module_id'=>$moduleId,
                'phase_id'=>null,
                'lesson_id'=>null,
                'title'=>$bossTitle,
                'description'=>$bossDescription,
                'quiz_type'=>'module_boss',
                'required_score'=>80.0,
                'question_limit'=>10,
                'xp_reward'=>300,
            ]);
            $bossId = (int)$pdo->lastInsertId();
        }

        $clearQuizQuestions->execute(['quiz_id'=>$bossId]);

        foreach ($bossQuestions as $idx=>$questionId) {
            $linkQuestion->execute([
                'quiz_id'=>$bossId,
                'question_id'=>$questionId,
                'position'=>$idx+1,
            ]);
        }
    }

    $pdo->commit();

    // Reconciliar matrículas após a mudança curricular.
    $initializer = new ProgressInitializer();
    $enrollmentsStmt = $pdo->prepare(
        "SELECT DISTINCT user_id,course_id
         FROM enrollments
         WHERE status='active'
         ORDER BY user_id,course_id"
    );
    $enrollmentsStmt->execute();

    $reconciled = 0;
    foreach ($enrollmentsStmt->fetchAll(\PDO::FETCH_ASSOC) as $enrollment) {
        $initializer->initializeEnrollment(
            (int)$enrollment['user_id'],
            (int)$enrollment['course_id'],
            $pdo
        );
        $reconciled++;
    }

    echo "\n[OK] Módulos 4, 5, 6, 7 e 8 corrigidos.\n";
    printf("Matrículas reconciliadas: %d\n", $reconciled);
    echo "Execute agora: php database/auditar_modulos_4a8_progressao_v2.php\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "ERRO: {$e->getMessage()}\n");
    exit(7);
}
