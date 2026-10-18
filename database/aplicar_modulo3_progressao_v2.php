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

$sourceFile = BASE_PATH . '/database/sources/modulo3_progressao_v2.json';
if (!is_file($sourceFile)) {
    fwrite(STDERR, "[FALHA] Fonte não encontrada: {$sourceFile}\n");
    exit(2);
}

$data = json_decode((string) file_get_contents($sourceFile), true, 512, JSON_THROW_ON_ERROR);
$pdo = Database::connection();

$moduleSlug = (string) ($data['module_slug'] ?? '');
$sourceLabel = (string) ($data['source_label'] ?? 'M3V2');
$requiredSlugs = is_array($data['required_phase_slugs'] ?? null) ? $data['required_phase_slugs'] : [];
$optionalSlugs = is_array($data['optional_phase_slugs'] ?? null) ? $data['optional_phase_slugs'] : [];
$phasesData = is_array($data['phases'] ?? null) ? $data['phases'] : [];

$moduleStmt = $pdo->prepare(
    "SELECT m.id,m.course_id,m.title
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
    fwrite(STDERR, "[FALHA] Módulo 3 publicado não encontrado.\n");
    exit(3);
}

$moduleId = (int) $module['id'];
$courseId = (int) $module['course_id'];

if (count($requiredSlugs) !== 6 || count($optionalSlugs) !== 6) {
    fwrite(STDERR, "[FALHA] A fonte V2 deve definir 6 fases obrigatórias e 6 complementares.\n");
    exit(4);
}

$findPhase = $pdo->prepare(
    "SELECT id,title,slug,position
     FROM phases
     WHERE module_id=:module_id
       AND slug=:slug
       AND status='published'
     LIMIT 1"
);

$findLesson = $pdo->prepare(
    "SELECT id,title
     FROM lessons
     WHERE phase_id=:phase_id
       AND status='published'
     ORDER BY position,id
     LIMIT 1"
);

$phaseMap = [];
$lessonMap = [];
$missing = [];

foreach (array_merge($requiredSlugs, $optionalSlugs) as $slug) {
    $findPhase->execute(['module_id'=>$moduleId,'slug'=>$slug]);
    $phase = $findPhase->fetch(\PDO::FETCH_ASSOC);

    if (!$phase) {
        $missing[] = "Fase não encontrada: {$slug}";
        continue;
    }

    $phaseMap[$slug] = $phase;

    $findLesson->execute(['phase_id'=>(int)$phase['id']]);
    $lesson = $findLesson->fetch(\PDO::FETCH_ASSOC);
    if (!$lesson) {
        $missing[] = "Aula não encontrada na fase #{$phase['id']} ({$phase['title']})";
        continue;
    }

    $lessonMap[$slug] = $lesson;
}

if ($missing !== []) {
    foreach ($missing as $item) {
        echo "[FALHA] {$item}\n";
    }
    exit(5);
}

$existingCountStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM questions
     WHERE module_id=:module_id
       AND active=1
       AND source_label NOT LIKE 'M3V2%'"
);
$existingCountStmt->execute(['module_id'=>$moduleId]);
$existingQuestions = (int) $existingCountStmt->fetchColumn();

$newQuestionCount = 0;
foreach ($phasesData as $phaseData) {
    $newQuestionCount += count(is_array($phaseData['questions'] ?? null) ? $phaseData['questions'] : []);
}

echo "=============================================================\n";
echo " CORREÇÃO MÓDULO 3 - PJM / CPM / CPPM / RDPM V2\n";
echo "=============================================================\n";
printf("Banco: %s\n", (string)$pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Módulo: #%d - %s\n", $moduleId, $module['title']);
printf("Modo: %s\n", $apply ? 'APLICAÇÃO' : 'DRY-RUN');
printf("Questões existentes a reaproveitar/classificar: %d\n", $existingQuestions);
printf("Questões novas curadas:                         %d\n", $newQuestionCount);
printf("Fases obrigatórias:                             %d\n", count($requiredSlugs));
printf("Fases complementares:                           %d\n\n", count($optionalSlugs));

foreach ($requiredSlugs as $slug) {
    printf(
        "[OBR.] #%d %s -> aula #%d\n",
        (int)$phaseMap[$slug]['id'],
        $phaseMap[$slug]['title'],
        (int)$lessonMap[$slug]['id']
    );
}
foreach ($optionalSlugs as $slug) {
    printf(
        "[OPC.] #%d %s\n",
        (int)$phaseMap[$slug]['id'],
        $phaseMap[$slug]['title']
    );
}

echo "\nPlano:\n";
echo " - 6 fases curriculares obrigatórias: Introdução, CPM, crimes, CPPM/PJM, estrutura RDPM e disciplina.\n";
echo " - 6 fases de aprofundamento/revisão passam a complementares e não bloqueiam o avanço.\n";
echo " - 50 questões existentes são classificadas por assunto e reaproveitadas.\n";
echo " - 15 questões novas cobrem introdução e os dois eixos do RDPM.\n";
echo " - Cada fase obrigatória terá fixação 3/3 (100%) e checkpoint 5 questões (80%).\n";
echo " - Avaliação final terá 10 questões (80%), duas de cada eixo temático principal.\n";

if (!$apply) {
    echo "\nDRY-RUN concluído. Nenhuma alteração foi gravada.\n";
    echo "Após backup, execute: php database/aplicar_modulo3_progressao_v2.php --apply";
    if ($isProduction) {
        echo " --force-production";
    }
    echo "\n";
    exit(0);
}

$pdo->beginTransaction();

try {
    $setPhaseRequired = $pdo->prepare(
        "UPDATE phases
         SET is_required=:required,
             required_score=80,
             description=:description,
             updated_at=NOW()
         WHERE id=:id"
    );

    $setLessonRequired = $pdo->prepare(
        "UPDATE lessons
         SET is_required=:required,
             updated_at=NOW()
         WHERE phase_id=:phase_id
           AND status='published'"
    );

    foreach ($requiredSlugs as $slug) {
        $phase = $phaseMap[$slug];
        $setPhaseRequired->execute([
            'required'=>1,
            'description'=>'Trilha curricular obrigatória • leitura + fixação + checkpoint.',
            'id'=>(int)$phase['id'],
        ]);
        $setLessonRequired->execute([
            'required'=>1,
            'phase_id'=>(int)$phase['id'],
        ]);
    }

    foreach ($optionalSlugs as $slug) {
        $phase = $phaseMap[$slug];
        $setPhaseRequired->execute([
            'required'=>0,
            'description'=>'Material complementar • disponível para revisão e aprofundamento, sem bloquear o avanço.',
            'id'=>(int)$phase['id'],
        ]);
        $setLessonRequired->execute([
            'required'=>0,
            'phase_id'=>(int)$phase['id'],
        ]);
    }

    // Conteúdo pedagógico reforçado.
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
    $maxPosition = $pdo->prepare(
        "SELECT COALESCE(MAX(position),0)
         FROM lesson_blocks
         WHERE lesson_id=:lesson_id"
    );
    $insertBlock = $pdo->prepare(
        "INSERT INTO lesson_blocks
            (lesson_id,block_type,title,content,media_url,position,is_required)
         VALUES
            (:lesson_id,:block_type,:title,:content,NULL,:position,1)"
    );
    $updateExtra = $pdo->prepare(
        "UPDATE lesson_blocks
         SET block_type=:block_type,
             content=:content,
             is_required=1,
             updated_at=NOW()
         WHERE id=:id"
    );

    foreach ($phasesData as $phaseData) {
        $slug = (string)($phaseData['slug'] ?? '');
        if (!isset($lessonMap[$slug])) {
            continue;
        }
        $lessonId = (int)$lessonMap[$slug]['id'];

        if (is_array($phaseData['replace_first_block'] ?? null)) {
            $firstBlock->execute(['lesson_id'=>$lessonId]);
            $blockId = (int)($firstBlock->fetchColumn() ?: 0);
            if ($blockId > 0) {
                $b = $phaseData['replace_first_block'];
                $updateBlock->execute([
                    'block_type'=>(string)($b['type'] ?? 'theory'),
                    'title'=>(string)($b['title'] ?? 'Conteúdo'),
                    'content'=>(string)($b['content'] ?? ''),
                    'id'=>$blockId,
                ]);
            }
        }

        $maxPosition->execute(['lesson_id'=>$lessonId]);
        $nextPosition = (int)$maxPosition->fetchColumn();

        foreach (($phaseData['extra_blocks'] ?? []) as $extra) {
            $title = (string)($extra['title'] ?? 'Reforço');
            $findExtraBlock->execute(['lesson_id'=>$lessonId,'title'=>$title]);
            $blockId = (int)($findExtraBlock->fetchColumn() ?: 0);

            if ($blockId > 0) {
                $updateExtra->execute([
                    'block_type'=>(string)($extra['type'] ?? 'comparison'),
                    'content'=>(string)($extra['content'] ?? ''),
                    'id'=>$blockId,
                ]);
            } else {
                $nextPosition++;
                $insertBlock->execute([
                    'lesson_id'=>$lessonId,
                    'block_type'=>(string)($extra['type'] ?? 'comparison'),
                    'title'=>$title,
                    'content'=>(string)($extra['content'] ?? ''),
                    'position'=>$nextPosition,
                ]);
            }
        }
    }

    // Mapa de fases obrigatórias.
    $phaseIds = [];
    $lessonIds = [];
    foreach ($requiredSlugs as $slug) {
        $phaseIds[$slug] = (int)$phaseMap[$slug]['id'];
        $lessonIds[$slug] = (int)$lessonMap[$slug]['id'];
    }

    // Reclassifica as 50 questões existentes por assunto.
    $existingStmt = $pdo->prepare(
        "SELECT id,source_label,statement
         FROM questions
         WHERE module_id=:module_id
           AND active=1
           AND source_label NOT LIKE 'M3V2%'
         ORDER BY id"
    );
    $existingStmt->execute(['module_id'=>$moduleId]);
    $existingRows = $existingStmt->fetchAll(\PDO::FETCH_ASSOC);

    $updateQuestionPhase = $pdo->prepare(
        "UPDATE questions
         SET phase_id=:phase_id,
             lesson_id=:lesson_id,
             updated_at=NOW()
         WHERE id=:id"
    );

    $normalize = static function (string $value): string {
        $value = mb_strtolower($value, 'UTF-8');
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return is_string($ascii) ? strtolower($ascii) : $value;
    };

    $containsAny = static function (string $text, array $needles): bool {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }
        return false;
    };

    foreach ($existingRows as $row) {
        $id = (int)$row['id'];
        $source = $normalize((string)($row['source_label'] ?? ''));
        $statement = $normalize((string)($row['statement'] ?? ''));
        $text = $source . ' ' . $statement;

        if (str_contains($source, 'questao integradora de literalidade')) {
            $rotation = $id % 3;
            $slug = $rotation === 0
                ? '1-codigo-penal-militar-recorte-do-edital'
                : ($rotation === 1
                    ? '2-crimes-militares-selecionados'
                    : '3-policia-judiciaria-militar-no-cppm');
        } elseif (
            $containsAny($text, [
                'rdpm','regulamento disciplinar','decreto no 1.329',
                'titulo v do rdpm','comportamento policial-militar'
            ])
        ) {
            $slug = $containsAny($text, [
                'transgress','punic','comportamento','disciplina militar',
                'recurso','cancelamento','recompensa'
            ])
                ? '5-transgressao-julgamento-punicao-e-comportamento'
                : '4-rdpm-mt-estrutura';
        } elseif (
            $containsAny($text, [
                'cppm','policia judiciaria militar','delegacao de pjm',
                'delegacao para instaurar ipm','finalidade do ipm','inicio do ipm',
                'escrivao','medidas preliminares','reproducao simulada',
                'infracao evidentemente nao militar','indiciado da reserva'
            ])
        ) {
            $slug = '3-policia-judiciaria-militar-no-cppm';
        } elseif (
            $containsAny($text, [
                'motim','revolta','violencia contra','desrespeito','recusa de obediencia',
                'desercao','abandono de posto','embriaguez em servico',
                'art. 149','art. 157','art. 160','art. 163','art. 175','art. 187','art. 195','art. 202'
            ])
        ) {
            $slug = '2-crimes-militares-selecionados';
        } else {
            $slug = '1-codigo-penal-militar-recorte-do-edital';
        }

        $updateQuestionPhase->execute([
            'phase_id'=>$phaseIds[$slug],
            'lesson_id'=>$lessonIds[$slug],
            'id'=>$id,
        ]);
    }

    // Insere/atualiza as 15 questões novas.
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

    foreach ($phasesData as $phaseData) {
        $slug = (string)($phaseData['slug'] ?? '');
        if (!isset($phaseIds[$slug])) {
            continue;
        }
        foreach (($phaseData['questions'] ?? []) as $question) {
            $statement = trim((string)($question['statement'] ?? ''));

            $findQuestion->execute([
                'module_id'=>$moduleId,
                'statement'=>$statement,
            ]);
            $questionId = (int)($findQuestion->fetchColumn() ?: 0);

            $params = [
                'phase_id'=>$phaseIds[$slug],
                'lesson_id'=>$lessonIds[$slug],
                'explanation'=>(string)($question['explanation'] ?? ''),
                'difficulty'=>(string)($question['difficulty'] ?? 'medium'),
                'source_label'=>mb_strimwidth(
                    $sourceLabel . ' | ' . (string)($phaseData['title'] ?? ''),
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
                foreach (($question['alternatives'] ?? []) as $idx=>$alt) {
                    $insertAlternative->execute([
                        'question_id'=>$questionId,
                        'label'=>(string)($alt['label'] ?? chr(65+$idx)),
                        'text'=>(string)($alt['text'] ?? ''),
                        'is_correct'=>!empty($alt['is_correct']) ? 1 : 0,
                        'position'=>$idx+1,
                    ]);
                }
            } elseif ($altCount !== 5 || $correctCount !== 1) {
                throw new RuntimeException(
                    "Questão #{$questionId} possui estrutura incompatível: {$altCount} alternativas / {$correctCount} correta."
                );
            }
        }
    }

    // Cria/atualiza quizzes com conjunto exato de questões.
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
    $clearQuiz = $pdo->prepare("DELETE FROM quiz_questions WHERE quiz_id=:quiz_id");
    $linkQuestion = $pdo->prepare(
        "INSERT INTO quiz_questions (quiz_id,question_id,position,points)
         VALUES (:quiz_id,:question_id,:position,1.00)"
    );

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
        $findQuiz,$insertQuiz,$updateQuiz,$courseId,$moduleId
    ): int {
        $findQuiz->execute([
            'module_id'=>$moduleId,
            'quiz_type'=>$type,
            'lesson_positive'=>$lessonId,
            'lesson_id'=>$lessonId,
            'lesson_zero'=>$lessonId,
            'phase_id'=>$phaseId,
        ]);
        $id = (int)($findQuiz->fetchColumn() ?: 0);

        $params = [
            'course_id'=>$courseId,
            'phase_id'=>$phaseId,
            'lesson_id'=>$lessonId > 0 ? $lessonId : null,
            'title'=>$title,
            'description'=>$description,
            'required_score'=>$requiredScore,
            'question_limit'=>$limit,
            'xp_reward'=>$xp,
        ];

        if ($id > 0) {
            $updateQuiz->execute($params + ['id'=>$id]);
            return $id;
        }

        $insertQuiz->execute($params + [
            'module_id'=>$moduleId,
            'quiz_type'=>$type,
        ]);
        return (int)Database::connection()->lastInsertId();
    };

    $validQuestionsByPhase = $pdo->prepare(
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

    $phaseQuestionSets = [];

    foreach ($requiredSlugs as $slug) {
        $phaseId = $phaseIds[$slug];
        $lessonId = $lessonIds[$slug];

        $validQuestionsByPhase->execute([
            'module_id'=>$moduleId,
            'phase_id'=>$phaseId,
        ]);
        $ids = array_map('intval', $validQuestionsByPhase->fetchAll(\PDO::FETCH_COLUMN));

        if (count($ids) < 5) {
            throw new RuntimeException(
                "Fase #{$phaseId} ({$phaseMap[$slug]['title']}) possui apenas " . count($ids) . " questões válidas."
            );
        }

        $phaseQuestionSets[$slug] = $ids;

        $fixId = $ensureQuiz(
            'lesson_fixation',
            $phaseId,
            $lessonId,
            'Fixação — ' . $phaseMap[$slug]['title'],
            'Exercício obrigatório: 3 questões. Acerte 3 de 3 para concluir a aula.',
            100.0,
            3,
            20
        );
        $clearQuiz->execute(['quiz_id'=>$fixId]);
        foreach (array_slice($ids,0,3) as $idx=>$questionId) {
            $linkQuestion->execute([
                'quiz_id'=>$fixId,
                'question_id'=>$questionId,
                'position'=>$idx+1,
            ]);
        }

        $phaseQuizId = $ensureQuiz(
            'phase_exam',
            $phaseId,
            0,
            'Checkpoint — ' . $phaseMap[$slug]['title'],
            'Avaliação obrigatória da fase: 5 questões. Aprovação com pelo menos 4 acertos.',
            80.0,
            5,
            80
        );
        $clearQuiz->execute(['quiz_id'=>$phaseQuizId]);
        foreach (array_slice($ids,0,5) as $idx=>$questionId) {
            $linkQuestion->execute([
                'quiz_id'=>$phaseQuizId,
                'question_id'=>$questionId,
                'position'=>$idx+1,
            ]);
        }
    }

    // Avaliação final: 2 questões de cada eixo temático principal, sem usar a introdução.
    $bossQuestions = [];
    foreach ([
        '1-codigo-penal-militar-recorte-do-edital',
        '2-crimes-militares-selecionados',
        '3-policia-judiciaria-militar-no-cppm',
        '4-rdpm-mt-estrutura',
        '5-transgressao-julgamento-punicao-e-comportamento'
    ] as $slug) {
        $bossQuestions = array_merge(
            $bossQuestions,
            array_slice($phaseQuestionSets[$slug] ?? [],0,2)
        );
    }

    if (count($bossQuestions) !== 10) {
        throw new RuntimeException('Não foi possível montar as 10 questões da avaliação final.');
    }

    $bossFind = $pdo->prepare(
        "SELECT id
         FROM quizzes
         WHERE module_id=:module_id
           AND quiz_type='module_boss'
         ORDER BY id
         LIMIT 1"
    );
    $bossFind->execute(['module_id'=>$moduleId]);
    $bossId = (int)($bossFind->fetchColumn() ?: 0);

    if ($bossId > 0) {
        $updateQuiz->execute([
            'course_id'=>$courseId,
            'phase_id'=>null,
            'lesson_id'=>null,
            'title'=>'Avaliação final — Polícia Judiciária Militar',
            'description'=>'Avaliação final do Módulo 3: CPM, crimes selecionados, CPPM/PJM e RDPM/MT. Aprovação com pelo menos 8 de 10.',
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
            'title'=>'Avaliação final — Polícia Judiciária Militar',
            'description'=>'Avaliação final do Módulo 3: CPM, crimes selecionados, CPPM/PJM e RDPM/MT. Aprovação com pelo menos 8 de 10.',
            'quiz_type'=>'module_boss',
            'required_score'=>80.0,
            'question_limit'=>10,
            'xp_reward'=>300,
        ]);
        $bossId = (int)$pdo->lastInsertId();
    }

    $clearQuiz->execute(['quiz_id'=>$bossId]);
    foreach ($bossQuestions as $idx=>$questionId) {
        $linkQuestion->execute([
            'quiz_id'=>$bossId,
            'question_id'=>$questionId,
            'position'=>$idx+1,
        ]);
    }

    $pdo->commit();

    // Libera fases complementares e garante o ponto de entrada correto para alunos ativos.
    $usersStmt = $pdo->prepare(
        "SELECT DISTINCT user_id
         FROM enrollments
         WHERE course_id=:course_id
           AND status='active'"
    );
    $usersStmt->execute(['course_id'=>$courseId]);

    $initializer = new ProgressInitializer();
    $initialized = 0;
    foreach ($usersStmt->fetchAll(\PDO::FETCH_COLUMN) as $userId) {
        $initializer->initializeEnrollment((int)$userId, $courseId, $pdo);
        $initialized++;
    }

    echo "\n[OK] Módulo 3 corrigido.\n";
    printf("Matrículas reinicializadas: %d\n", $initialized);
    echo "Execute agora: php database/auditar_modulo3_progressao_v1.php\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "ERRO: {$e->getMessage()}\n");
    exit(7);
}
