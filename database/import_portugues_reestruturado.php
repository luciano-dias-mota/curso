<?php

declare(strict_types=1);


if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execução permitida apenas via CLI.');
}


define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

\Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

function envv(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }
    return is_string($value) ? trim($value, "\"'") : $value;
}

function argValue(string $name): ?string
{
    global $argv;
    foreach ($argv as $arg) {
        if (str_starts_with($arg, $name . '=')) {
            return substr($arg, strlen($name) + 1);
        }
    }
    return null;
}

function hasFlag(string $flag): bool
{
    global $argv;
    return in_array($flag, $argv, true);
}

function hasColumn(\PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name"
    );
    $stmt->execute(['table_name' => $table, 'column_name' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function rotateAlternatives(array $question): array
{
    $alts = $question['alternatives'];
    $count = count($alts);
    $shift = abs((int) crc32((string) $question['key'])) % max(1, $count);

    $rotated = array_merge(array_slice($alts, $shift), array_slice($alts, 0, $shift));
    $correctOriginal = (int) $question['correct'];
    $correctNew = ($correctOriginal - $shift) % $count;
    if ($correctNew < 0) {
        $correctNew += $count;
    }

    return [$rotated, $correctNew];
}

$apply = in_array('--apply', $argv ?? [], true);
if (!$apply) {
    fwrite(STDOUT, "MODO SEGURO: nenhuma alteração foi realizada. Use --apply para executar este script.\n");
    exit(0);
}

$isProduction = strtolower((string) envv('APP_ENV', 'production')) === 'production';
$forceProduction = in_array('--force-production', $argv ?? [], true);
if ($isProduction && !$forceProduction) {
    fwrite(STDERR, "ABORTADO: este script altera dados e APP_ENV=production. Use --force-production somente após backup e revisão.\n");
    exit(1);
}

$jsonPath = BASE_PATH . '/database/portugues_ufmt_reestruturado.json';
if (!is_file($jsonPath)) {
    fwrite(STDERR, "Erro: database/portugues_ufmt_reestruturado.json não encontrado.\n");
    exit(1);
}

$data = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
$moduleData = $data['module'];

$pdo = new \PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        envv('DB_HOST', '127.0.0.1'),
        envv('DB_PORT', '3306'),
        envv('DB_DATABASE', 'curso')
    ),
    (string) envv('DB_USERNAME', 'root'),
    (string) envv('DB_PASSWORD', ''),
    [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$courseIdArg = argValue('--course-id');
if ($courseIdArg !== null) {
    $stmt = $pdo->prepare("SELECT * FROM courses WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => (int) $courseIdArg]);
    $course = $stmt->fetch();
} else {
    $stmt = $pdo->prepare(
        "SELECT * FROM courses
         WHERE slug = 'preparacao-pmmt-merito-intelectual-e-caoc'
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute();
    $course = $stmt->fetch();

}

if (!$course) {
    fwrite(STDERR, "Erro: curso PMMT esperado não encontrado. Use --course-id=N somente se tiver certeza do destino.\n");
    exit(1);
}

$replace = hasFlag('--replace');
$resetProgression = hasFlag('--reset-progression');
$phaseHasRequired = hasColumn($pdo, 'phases', 'is_required');
$lessonHasRequired = hasColumn($pdo, 'lessons', 'is_required');

$pdo->beginTransaction();

try {
    // Remove uma versão anterior desta reestruturação quando solicitado.
    $stmt = $pdo->prepare(
        "SELECT id FROM modules WHERE course_id = :course_id AND slug = :slug LIMIT 1"
    );
    $stmt->execute([
        'course_id' => $course['id'],
        'slug' => $moduleData['slug'],
    ]);
    $existingNewId = (int) ($stmt->fetchColumn() ?: 0);

    if ($existingNewId > 0) {
        if (!$replace) {
            throw new RuntimeException(
                'O módulo reestruturado já existe. Execute novamente com --replace.'
            );
        }

        // Evita deixar questões órfãs porque as FKs de questions são SET NULL.
        $deleteQuestions = $pdo->prepare(
            "DELETE FROM questions WHERE module_id = :module_id"
        );
        $deleteQuestions->execute(['module_id' => $existingNewId]);

        $pdo->prepare("DELETE FROM modules WHERE id = :id")
            ->execute(['id' => $existingNewId]);
    }

    // Arquiva módulos antigos de Língua Portuguesa, preservando o conteúdo para consulta.
    $maxPosition = (int) $pdo->query(
        "SELECT COALESCE(MAX(position), 0) FROM modules WHERE course_id = " . (int) $course['id']
    )->fetchColumn();

    $oldStmt = $pdo->prepare(
        "SELECT id, title, position, slug
         FROM modules
         WHERE course_id = :course_id
           AND slug <> :new_slug
           AND (
                title LIKE '%Língua Portuguesa%'
                OR title LIKE '%Lingua Portuguesa%'
                OR slug LIKE '%lingua-portuguesa%'
           )
         ORDER BY id"
    );
    $oldStmt->execute([
        'course_id' => $course['id'],
        'new_slug' => $moduleData['slug'],
    ]);

    $archived = [];
    foreach ($oldStmt->fetchAll() as $idx => $old) {
        $newPosition = $maxPosition + 100 + $idx + 1;
        $newTitle = str_starts_with((string) $old['title'], '[ARQUIVO]')
            ? $old['title']
            : '[ARQUIVO] ' . $old['title'];
        $newTitle = mb_substr($newTitle, 0, 180);

        $pdo->prepare(
            "UPDATE modules
             SET title = :title,
                 position = :position,
                 status = 'archived'
             WHERE id = :id"
        )->execute([
            'title' => $newTitle,
            'position' => $newPosition,
            'id' => $old['id'],
        ]);

        $archived[] = $old['id'] . ' - ' . $old['title'];
    }

    // Garante que a posição 1 não esteja ocupada por outro módulo não-Português.
    $conflict = $pdo->prepare(
        "SELECT id, title FROM modules
         WHERE course_id = :course_id AND position = :position LIMIT 1"
    );
    $conflict->execute([
        'course_id' => $course['id'],
        'position' => $moduleData['position'],
    ]);
    $conflictRow = $conflict->fetch();

    if ($conflictRow) {
        $newPos = $maxPosition + 500;
        $pdo->prepare("UPDATE modules SET position = :p WHERE id = :id")
            ->execute(['p' => $newPos, 'id' => $conflictRow['id']]);
    }

    $moduleStmt = $pdo->prepare(
        "INSERT INTO modules
            (course_id, title, slug, description, icon, position,
             required_score, xp_reward, status)
         VALUES
            (:course_id, :title, :slug, :description, 'book-open', :position,
             :required_score, :xp_reward, 'published')"
    );
    $moduleStmt->execute([
        'course_id' => $course['id'],
        'title' => $moduleData['title'],
        'slug' => $moduleData['slug'],
        'description' => $moduleData['description'],
        'position' => $moduleData['position'],
        'required_score' => $moduleData['required_score'],
        'xp_reward' => $moduleData['xp_reward'],
    ]);
    $moduleId = (int) $pdo->lastInsertId();

    $questionMap = [];
    $firstPhaseId = null;
    $firstLessonId = null;
    $counts = ['phases'=>0,'lessons'=>0,'blocks'=>0,'questions'=>0,'lesson_quizzes'=>0,'phase_quizzes'=>0];

    foreach ($moduleData['phases'] as $phaseData) {
        if ($phaseHasRequired) {
            $phaseSql =
                "INSERT INTO phases
                    (module_id, title, slug, description, phase_type, is_required,
                     position, required_score, required_lessons_pct,
                     max_attempts, xp_reward, status)
                 VALUES
                    (:module_id, :title, :slug, :description, :phase_type, 1,
                     :position, :required_score, 100, NULL, :xp_reward, 'published')";
        } else {
            $phaseSql =
                "INSERT INTO phases
                    (module_id, title, slug, description, phase_type,
                     position, required_score, required_lessons_pct,
                     max_attempts, xp_reward, status)
                 VALUES
                    (:module_id, :title, :slug, :description, :phase_type,
                     :position, :required_score, 100, NULL, :xp_reward, 'published')";
        }

        $stmt = $pdo->prepare($phaseSql);
        $stmt->execute([
            'module_id' => $moduleId,
            'title' => $phaseData['title'],
            'slug' => $phaseData['slug'],
            'description' => $phaseData['description'] . ' (Edital: ' . $phaseData['edital'] . ')',
            'phase_type' => $phaseData['phase_type'],
            'position' => $phaseData['position'],
            'required_score' => $phaseData['required_score'],
            'xp_reward' => $phaseData['xp_reward'],
        ]);
        $phaseId = (int) $pdo->lastInsertId();
        $firstPhaseId ??= $phaseId;
        $counts['phases']++;

        $phaseQuestionKeys = [];

        foreach ($phaseData['lessons'] as $lessonData) {
            if ($lessonHasRequired) {
                $lessonSql =
                    "INSERT INTO lessons
                        (phase_id, title, slug, summary, is_required,
                         position, estimated_minutes, xp_reward, status)
                     VALUES
                        (:phase_id, :title, :slug, :summary, 1,
                         :position, :estimated_minutes, :xp_reward, 'published')";
            } else {
                $lessonSql =
                    "INSERT INTO lessons
                        (phase_id, title, slug, summary,
                         position, estimated_minutes, xp_reward, status)
                     VALUES
                        (:phase_id, :title, :slug, :summary,
                         :position, :estimated_minutes, :xp_reward, 'published')";
            }

            $stmt = $pdo->prepare($lessonSql);
            $stmt->execute([
                'phase_id' => $phaseId,
                'title' => $lessonData['title'],
                'slug' => $lessonData['slug'],
                'summary' => $lessonData['summary'],
                'position' => $lessonData['position'],
                'estimated_minutes' => $lessonData['estimated_minutes'],
                'xp_reward' => $lessonData['xp_reward'],
            ]);
            $lessonId = (int) $pdo->lastInsertId();
            $firstLessonId ??= $lessonId;
            $counts['lessons']++;

            $blockStmt = $pdo->prepare(
                "INSERT INTO lesson_blocks
                    (lesson_id, block_type, title, content, media_url,
                     position, is_required)
                 VALUES
                    (:lesson_id, :block_type, :title, :content, NULL,
                     :position, 1)"
            );

            foreach ($lessonData['blocks'] as $bi => $block) {
                $blockStmt->execute([
                    'lesson_id' => $lessonId,
                    'block_type' => $block['type'],
                    'title' => $block['title'],
                    'content' => $block['content'],
                    'position' => $bi + 1,
                ]);
                $counts['blocks']++;
            }

            $lessonQuestionIds = [];
            foreach ($lessonData['questions'] as $question) {
                $qStmt = $pdo->prepare(
                    "INSERT INTO questions
                        (course_id, module_id, phase_id, lesson_id,
                         question_type, statement, explanation,
                         difficulty, source_label, active)
                     VALUES
                        (:course_id, :module_id, :phase_id, :lesson_id,
                         'multiple_choice', :statement, :explanation,
                         :difficulty, :source_label, 1)"
                );
                $qStmt->execute([
                    'course_id' => $course['id'],
                    'module_id' => $moduleId,
                    'phase_id' => $phaseId,
                    'lesson_id' => $lessonId,
                    'statement' => $question['statement'],
                    'explanation' => $question['explanation'],
                    'difficulty' => $question['difficulty'],
                    'source_label' => mb_substr($question['source_label'], 0, 255),
                ]);
                $questionId = (int) $pdo->lastInsertId();
                $questionMap[$question['key']] = $questionId;
                $lessonQuestionIds[] = $questionId;
                $phaseQuestionKeys[] = $question['key'];
                $counts['questions']++;

                [$alts, $correctIdx] = rotateAlternatives($question);
                $altStmt = $pdo->prepare(
                    "INSERT INTO alternatives
                        (question_id, label, text, is_correct, explanation, position)
                     VALUES
                        (:question_id, :label, :text, :is_correct, NULL, :position)"
                );

                foreach ($alts as $ai => $altText) {
                    $altStmt->execute([
                        'question_id' => $questionId,
                        'label' => chr(65 + $ai),
                        'text' => $altText,
                        'is_correct' => $ai === $correctIdx ? 1 : 0,
                        'position' => $ai + 1,
                    ]);
                }
            }

            // Exercício da aula: 3/3 obrigatório.
            $quiz = $lessonData['quiz'];
            $qzStmt = $pdo->prepare(
                "INSERT INTO quizzes
                    (course_id, module_id, phase_id, lesson_id,
                     title, description, quiz_type, required_score,
                     question_limit, shuffle_questions, shuffle_answers,
                     show_feedback, max_attempts, xp_reward, active)
                 VALUES
                    (:course_id, :module_id, :phase_id, :lesson_id,
                     :title, :description, :quiz_type, :required_score,
                     :question_limit, 0, 0, 1, NULL, :xp_reward, 1)"
            );
            $qzStmt->execute([
                'course_id' => $course['id'],
                'module_id' => $moduleId,
                'phase_id' => $phaseId,
                'lesson_id' => $lessonId,
                'title' => $quiz['title'],
                'description' => 'Exercício obrigatório. Responda todas as questões e acerte 3 de 3 para liberar a próxima aula.',
                'quiz_type' => 'lesson_fixation',
                'required_score' => 100,
                'question_limit' => 3,
                'xp_reward' => $quiz['xp_reward'],
            ]);
            $lessonQuizId = (int) $pdo->lastInsertId();
            $counts['lesson_quizzes']++;

            $linkStmt = $pdo->prepare(
                "INSERT INTO quiz_questions (quiz_id, question_id, position, points)
                 VALUES (:quiz_id, :question_id, :position, 1)"
            );
            foreach ($lessonQuestionIds as $qi => $questionId) {
                $linkStmt->execute([
                    'quiz_id' => $lessonQuizId,
                    'question_id' => $questionId,
                    'position' => $qi + 1,
                ]);
            }
        }

        // Prova da fase: 5 questões / 4 acertos.
        $phaseQuiz = $phaseData['quiz'];
        $stmt = $pdo->prepare(
            "INSERT INTO quizzes
                (course_id, module_id, phase_id, lesson_id,
                 title, description, quiz_type, required_score,
                 question_limit, shuffle_questions, shuffle_answers,
                 show_feedback, max_attempts, xp_reward, active)
             VALUES
                (:course_id, :module_id, :phase_id, NULL,
                 :title, :description, 'phase_exam', 80,
                 5, 0, 0, 1, NULL, :xp_reward, 1)"
        );
        $stmt->execute([
            'course_id' => $course['id'],
            'module_id' => $moduleId,
            'phase_id' => $phaseId,
            'title' => $phaseQuiz['title'],
            'description' => 'Checkpoint da fase: 5 questões. Aprovação com pelo menos 4 acertos.',
            'xp_reward' => $phaseQuiz['xp_reward'],
        ]);
        $phaseQuizId = (int) $pdo->lastInsertId();
        $counts['phase_quizzes']++;

        $linkStmt = $pdo->prepare(
            "INSERT INTO quiz_questions (quiz_id, question_id, position, points)
             VALUES (:quiz_id, :question_id, :position, 1)"
        );
        foreach ($phaseQuiz['question_refs'] as $qi => $key) {
            if (!isset($questionMap[$key])) {
                throw new RuntimeException('Questão da fase não encontrada: ' . $key);
            }
            $linkStmt->execute([
                'quiz_id' => $phaseQuizId,
                'question_id' => $questionMap[$key],
                'position' => $qi + 1,
            ]);
        }
    }

    // Avaliação final do módulo: 10 questões / 8 acertos.
    $boss = $moduleData['boss_quiz'];
    $stmt = $pdo->prepare(
        "INSERT INTO quizzes
            (course_id, module_id, phase_id, lesson_id,
             title, description, quiz_type, required_score,
             question_limit, shuffle_questions, shuffle_answers,
             show_feedback, max_attempts, xp_reward, active)
         VALUES
            (:course_id, :module_id, NULL, NULL,
             :title, :description, 'module_boss', 80,
             10, 0, 0, 1, NULL, :xp_reward, 1)"
    );
    $stmt->execute([
        'course_id' => $course['id'],
        'module_id' => $moduleId,
        'title' => $boss['title'],
        'description' => 'Avaliação final de Língua Portuguesa: 10 questões. Aprovação com pelo menos 8 acertos.',
        'xp_reward' => $boss['xp_reward'],
    ]);
    $bossQuizId = (int) $pdo->lastInsertId();

    $linkStmt = $pdo->prepare(
        "INSERT INTO quiz_questions (quiz_id, question_id, position, points)
         VALUES (:quiz_id, :question_id, :position, 1)"
    );
    foreach ($boss['question_refs'] as $qi => $key) {
        if (!isset($questionMap[$key])) {
            throw new RuntimeException('Questão da avaliação final não encontrada: ' . $key);
        }
        $linkStmt->execute([
            'quiz_id' => $bossQuizId,
            'question_id' => $questionMap[$key],
            'position' => $qi + 1,
        ]);
    }

    // Inicializa progresso dos estudantes matriculados.
    $students = $pdo->prepare(
        "SELECT e.user_id
         FROM enrollments e
         INNER JOIN users u ON u.id = e.user_id
         WHERE e.course_id = :course_id
           AND e.status = 'active'
           AND u.status = 'active'"
    );
    $students->execute(['course_id' => $course['id']]);
    $userIds = array_map('intval', $students->fetchAll(\PDO::FETCH_COLUMN));

    $phaseIds = $pdo->prepare("SELECT id FROM phases WHERE module_id = :m ORDER BY position");
    $phaseIds->execute(['m' => $moduleId]);
    $allPhaseIds = array_map('intval', $phaseIds->fetchAll(\PDO::FETCH_COLUMN));

    $lessonIds = $pdo->prepare(
        "SELECT l.id FROM lessons l
         INNER JOIN phases p ON p.id = l.phase_id
         WHERE p.module_id = :m
         ORDER BY p.position, l.position"
    );
    $lessonIds->execute(['m' => $moduleId]);
    $allLessonIds = array_map('intval', $lessonIds->fetchAll(\PDO::FETCH_COLUMN));

    foreach ($userIds as $userId) {
        $pdo->prepare(
            "INSERT INTO user_module_progress
                (user_id, module_id, status, best_score, progress_pct, unlocked_at)
             VALUES
                (:user_id, :module_id, 'available', 0, 0, NOW())
             ON DUPLICATE KEY UPDATE status='available', progress_pct=0,
                 best_score=0, unlocked_at=COALESCE(unlocked_at,NOW()), completed_at=NULL"
        )->execute(['user_id'=>$userId,'module_id'=>$moduleId]);

        foreach ($allPhaseIds as $pid) {
            $status = $pid === $firstPhaseId ? 'available' : 'locked';
            $pdo->prepare(
                "INSERT INTO user_phase_progress
                    (user_id, phase_id, status, best_score, attempts_count,
                     progress_pct, unlocked_at)
                 VALUES
                    (:user_id, :phase_id, :status, 0, 0, 0,
                     CASE WHEN :status2='available' THEN NOW() ELSE NULL END)
                 ON DUPLICATE KEY UPDATE status=VALUES(status), best_score=0,
                     attempts_count=0, progress_pct=0,
                     unlocked_at=VALUES(unlocked_at), completed_at=NULL"
            )->execute([
                'user_id'=>$userId,'phase_id'=>$pid,
                'status'=>$status,'status2'=>$status,
            ]);
        }

        foreach ($allLessonIds as $lid) {
            $status = $lid === $firstLessonId ? 'available' : 'locked';
            $pdo->prepare(
                "INSERT INTO user_lesson_progress
                    (user_id, lesson_id, status, current_block, blocks_viewed,
                     progress_pct, started_at, completed_at, last_accessed_at)
                 VALUES
                    (:user_id, :lesson_id, :status, 1, 0, 0, NULL, NULL, NULL)
                 ON DUPLICATE KEY UPDATE status=VALUES(status), current_block=1,
                     blocks_viewed=0, progress_pct=0, started_at=NULL,
                     completed_at=NULL, last_accessed_at=NULL"
            )->execute(['user_id'=>$userId,'lesson_id'=>$lid,'status'=>$status]);
        }
    }

    if ($resetProgression) {
        // Em ambiente de desenvolvimento, bloqueia módulos seguintes ainda não concluídos.
        $pdo->prepare(
            "UPDATE user_module_progress ump
             INNER JOIN modules m ON m.id = ump.module_id
             SET ump.status='locked', ump.progress_pct=0, ump.best_score=0,
                 ump.unlocked_at=NULL, ump.completed_at=NULL
             WHERE m.course_id=:course_id
               AND m.position>1
               AND ump.status<>'completed'"
        )->execute(['course_id'=>$course['id']]);

        $pdo->prepare(
            "UPDATE user_phase_progress upp
             INNER JOIN phases p ON p.id=upp.phase_id
             INNER JOIN modules m ON m.id=p.module_id
             SET upp.status='locked', upp.progress_pct=0, upp.best_score=0,
                 upp.attempts_count=0, upp.unlocked_at=NULL, upp.completed_at=NULL
             WHERE m.course_id=:course_id
               AND m.position>1
               AND upp.status<>'completed'"
        )->execute(['course_id'=>$course['id']]);

        $pdo->prepare(
            "UPDATE user_lesson_progress ulp
             INNER JOIN lessons l ON l.id=ulp.lesson_id
             INNER JOIN phases p ON p.id=l.phase_id
             INNER JOIN modules m ON m.id=p.module_id
             SET ulp.status='locked', ulp.progress_pct=0, ulp.current_block=1,
                 ulp.blocks_viewed=0, ulp.started_at=NULL,
                 ulp.completed_at=NULL, ulp.last_accessed_at=NULL
             WHERE m.course_id=:course_id
               AND m.position>1
               AND ulp.status<>'completed'"
        )->execute(['course_id'=>$course['id']]);
    }

    $pdo->commit();

    echo "=========================================================\n";
    echo " LÍNGUA PORTUGUESA — REESTRUTURAÇÃO CONCLUÍDA\n";
    echo "=========================================================\n";
    echo "Curso: {$course['title']} (#{$course['id']})\n";
    echo "Novo módulo: #{$moduleId} | {$moduleData['title']}\n";
    echo "Fases: {$counts['phases']}\n";
    echo "Aulas: {$counts['lessons']}\n";
    echo "Telas didáticas: {$counts['blocks']}\n";
    echo "Questões originais: {$counts['questions']}\n";
    echo "Exercícios de aula: {$counts['lesson_quizzes']} (3/3 obrigatório)\n";
    echo "Provas de fase: {$counts['phase_quizzes']} (4/5)\n";
    echo "Avaliação do módulo: 1 (8/10)\n";
    echo "\nMódulos antigos arquivados: " . count($archived) . "\n";
    foreach ($archived as $item) {
        echo " - {$item}\n";
    }
    echo "\nBlocos de questão estática no novo módulo: 0\n";
    echo "O aluno só conclui uma aula após passar no quiz corrigido pelo backend.\n";

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Erro: {$e->getMessage()}\n");
    exit(1);
}
