<?php

declare(strict_types=1);

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

function slugify(string $text): string
{
    $text = trim($text);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $ascii = $ascii !== false ? $ascii : $text;
    $ascii = strtolower($ascii);
    $ascii = preg_replace('/[^a-z0-9]+/', '-', $ascii) ?? '';
    $ascii = trim($ascii, '-');

    return $ascii !== '' ? $ascii : 'item';
}

function shortText(string $text, int $limit): string
{
    if (mb_strlen($text) <= $limit) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
}

function spreadPick(array $ids, int $limit): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $count = count($ids);

    if ($count <= $limit) {
        return $ids;
    }

    $picked = [];

    for ($i = 0; $i < $limit; $i++) {
        $index = (int) floor(($i * ($count - 1)) / max(1, $limit - 1));
        $picked[] = $ids[$index];
    }

    return array_values(array_unique($picked));
}

function insertQuizQuestions(\PDO $pdo, int $quizId, array $questionIds): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO quiz_questions (quiz_id, question_id, position, points)
         VALUES (:quiz_id, :question_id, :position, 1)"
    );

    foreach (array_values($questionIds) as $index => $questionId) {
        $stmt->execute([
            'quiz_id' => $quizId,
            'question_id' => (int) $questionId,
            'position' => $index + 1,
        ]);
    }
}

$dataFile = BASE_PATH . '/database/pop_pmmt_2023_course_data.json';

if (!is_file($dataFile)) {
    fwrite(STDERR, "Erro: database/pop_pmmt_2023_course_data.json não encontrado.\n");
    exit(1);
}

$data = json_decode(
    (string) file_get_contents($dataFile),
    true,
    512,
    JSON_THROW_ON_ERROR
);

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

$unlockFirst = in_array('--unlock-first', $argv ?? [], true);

$courseStmt = $pdo->prepare(
    "SELECT id, title
     FROM courses
     WHERE slug = :slug
     LIMIT 1"
);
$courseStmt->execute(['slug' => 'preparacao-pmmt-merito-intelectual-e-caoc']);
$course = $courseStmt->fetch();

if (!$course) {
    $course = $pdo->query(
        "SELECT id, title
         FROM courses
         WHERE status = 'published'
         ORDER BY position, id
         LIMIT 1"
    )->fetch();
}

if (!$course) {
    fwrite(STDERR, "Erro: nenhum curso publicado foi encontrado.\n");
    exit(1);
}

$courseId = (int) $course['id'];

$stats = [
    'modules' => 0,
    'phases' => 0,
    'lessons' => 0,
    'blocks' => 0,
    'questions' => 0,
    'lesson_quizzes' => 0,
    'phase_exams' => 0,
    'module_exams' => 0,
];

$pdo->beginTransaction();

try {
    // Remove somente conteúdo criado por ESTE importador.
    $pdo->exec(
        "DELETE FROM questions
         WHERE source_label LIKE 'Manual POP PMMT 2023|%'"
    );

    $oldModulesStmt = $pdo->prepare(
        "SELECT id
         FROM modules
         WHERE course_id = :course_id
           AND slug LIKE 'pop-pmmt-2023-%'"
    );
    $oldModulesStmt->execute(['course_id' => $courseId]);
    $oldIds = array_map('intval', $oldModulesStmt->fetchAll(\PDO::FETCH_COLUMN));

    if ($oldIds) {
        $placeholders = implode(',', array_fill(0, count($oldIds), '?'));
        $delete = $pdo->prepare("DELETE FROM modules WHERE id IN ({$placeholders})");
        $delete->execute($oldIds);
    }

    $positionStmt = $pdo->prepare(
        "SELECT COALESCE(MAX(position), 0)
         FROM modules
         WHERE course_id = :course_id"
    );
    $positionStmt->execute(['course_id' => $courseId]);
    $basePosition = (int) $positionStmt->fetchColumn();

    $insertModule = $pdo->prepare(
        "INSERT INTO modules
            (course_id, title, slug, description, icon, position,
             required_score, xp_reward, status)
         VALUES
            (:course_id, :title, :slug, :description, :icon, :position,
             80, 500, 'published')"
    );

    $insertPhase = $pdo->prepare(
        "INSERT INTO phases
            (module_id, title, slug, description, phase_type, position,
             required_score, required_lessons_pct, max_attempts, xp_reward, status)
         VALUES
            (:module_id, :title, :slug, :description, 'normal', :position,
             80, 100, NULL, 80, 'published')"
    );

    $insertLesson = $pdo->prepare(
        "INSERT INTO lessons
            (phase_id, title, slug, summary, position,
             estimated_minutes, xp_reward, status)
         VALUES
            (:phase_id, :title, :slug, :summary, :position,
             :estimated_minutes, 25, 'published')"
    );

    $insertBlock = $pdo->prepare(
        "INSERT INTO lesson_blocks
            (lesson_id, block_type, title, content, media_url, position, is_required)
         VALUES
            (:lesson_id, :block_type, :title, :content, :media_url, :position, :is_required)"
    );

    $insertQuestion = $pdo->prepare(
        "INSERT INTO questions
            (course_id, module_id, phase_id, lesson_id, question_type,
             statement, explanation, difficulty, source_label, active)
         VALUES
            (:course_id, :module_id, :phase_id, :lesson_id, 'multiple_choice',
             :statement, :explanation, :difficulty, :source_label, 1)"
    );

    $insertAlternative = $pdo->prepare(
        "INSERT INTO alternatives
            (question_id, label, text, is_correct, explanation, position)
         VALUES
            (:question_id, :label, :text, :is_correct, NULL, :position)"
    );

    $insertQuiz = $pdo->prepare(
        "INSERT INTO quizzes
            (course_id, module_id, phase_id, lesson_id, title, description,
             quiz_type, required_score, question_limit, time_limit_minutes,
             shuffle_questions, shuffle_answers, show_feedback, max_attempts,
             xp_reward, active)
         VALUES
            (:course_id, :module_id, :phase_id, :lesson_id, :title, :description,
             :quiz_type, :required_score, :question_limit, NULL,
             :shuffle_questions, 1, 1, NULL, :xp_reward, 1)"
    );

    $createdModuleIds = [];
    $createdPhaseIds = [];
    $createdLessonIds = [];
    $firstModuleId = null;
    $firstPhaseId = null;
    $firstLessonId = null;

    foreach ($data['modules'] as $moduleIndex => $moduleData) {
        $sourceNumber = (int) $moduleData['source_module'];
        $moduleTitle = 'POP PMMT 2023 • Módulo ' . $sourceNumber . ' - ' . $moduleData['title'];
        $moduleSlug = 'pop-pmmt-2023-modulo-' . $sourceNumber . '-' . slugify($moduleData['title']);

        $insertModule->execute([
            'course_id' => $courseId,
            'title' => shortText($moduleTitle, 180),
            'slug' => shortText($moduleSlug, 190),
            'description' => $moduleData['description']
                . ' Conteúdo estruturado a partir da 2ª edição revisada e atualizada do Manual POP PMMT, 2023.',
            'icon' => 'shield',
            'position' => $basePosition + $moduleIndex + 1,
        ]);

        $moduleId = (int) $pdo->lastInsertId();
        $createdModuleIds[] = $moduleId;
        $firstModuleId ??= $moduleId;
        $stats['modules']++;

        $moduleExamPool = [];

        foreach ($moduleData['phases'] as $phaseIndex => $phaseData) {
            $phaseTitle = 'Processo ' . $phaseData['code'] . ' - ' . $phaseData['title'];
            $phaseSlug = 'processo-' . $phaseData['code'] . '-' . slugify($phaseData['title']);
            $sourcePages = $phaseData['source_pages'];

            $insertPhase->execute([
                'module_id' => $moduleId,
                'title' => shortText($phaseTitle, 180),
                'slug' => shortText($phaseSlug, 190),
                'description' =>
                    'Processo ' . $phaseData['code']
                    . ' do Manual POP PMMT 2023. Fonte: páginas '
                    . $sourcePages[0] . '-' . $sourcePages[1] . '.',
                'position' => $phaseIndex + 1,
            ]);

            $phaseId = (int) $pdo->lastInsertId();
            $createdPhaseIds[] = $phaseId;
            $firstPhaseId ??= $phaseId;
            $stats['phases']++;

            $phaseQuestionPool = [];

            foreach ($phaseData['lessons'] as $lessonIndex => $lessonData) {
                $lessonTitle = 'POP ' . $lessonData['code'] . ' - ' . $lessonData['title'];
                $lessonSlug = 'pop-' . str_replace('.', '-', $lessonData['code'])
                    . '-' . slugify($lessonData['title']);

                $insertLesson->execute([
                    'phase_id' => $phaseId,
                    'title' => shortText($lessonTitle, 200),
                    'slug' => shortText($lessonSlug, 210),
                    'summary' => $lessonData['summary'],
                    'position' => $lessonIndex + 1,
                    'estimated_minutes' => (int) $lessonData['estimated_minutes'],
                ]);

                $lessonId = (int) $pdo->lastInsertId();
                $createdLessonIds[] = $lessonId;
                $firstLessonId ??= $lessonId;
                $stats['lessons']++;

                foreach ($lessonData['blocks'] as $blockIndex => $block) {
                    $insertBlock->execute([
                        'lesson_id' => $lessonId,
                        'block_type' => $block['type'],
                        'title' => shortText((string) $block['title'], 200),
                        'content' => (string) $block['content'],
                        'media_url' => $block['media_url'] ?? null,
                        'position' => $blockIndex + 1,
                        'is_required' => (int) ($block['required'] ?? 1),
                    ]);
                    $stats['blocks']++;
                }

                $lessonQuestionIds = [];

                foreach ($lessonData['questions'] as $questionData) {
                    $insertQuestion->execute([
                        'course_id' => $courseId,
                        'module_id' => $moduleId,
                        'phase_id' => $phaseId,
                        'lesson_id' => $lessonId,
                        'statement' => $questionData['statement'],
                        'explanation' => $questionData['explanation'],
                        'difficulty' => $questionData['difficulty'] ?? 'medium',
                        'source_label' => $questionData['source_label'],
                    ]);

                    $questionId = (int) $pdo->lastInsertId();
                    $lessonQuestionIds[] = $questionId;
                    $phaseQuestionPool[] = $questionId;
                    $stats['questions']++;

                    foreach ($questionData['alternatives'] as $altIndex => $alternative) {
                        $insertAlternative->execute([
                            'question_id' => $questionId,
                            'label' => chr(65 + $altIndex),
                            'text' => $alternative['text'],
                            'is_correct' => !empty($alternative['correct']) ? 1 : 0,
                            'position' => $altIndex + 1,
                        ]);
                    }
                }

                // Exercício ao fim de CADA aula: 3 questões, 2 acertos.
                $lessonQuizIds = array_slice($lessonQuestionIds, 0, 3);

                $insertQuiz->execute([
                    'course_id' => $courseId,
                    'module_id' => $moduleId,
                    'phase_id' => $phaseId,
                    'lesson_id' => $lessonId,
                    'title' => shortText('Fixação - POP ' . $lessonData['code'], 200),
                    'description' => 'Exercício obrigatório da aula: 3 questões, aprovação com pelo menos 2 acertos.',
                    'quiz_type' => 'lesson_fixation',
                    'required_score' => 66.67,
                    'question_limit' => 3,
                    'shuffle_questions' => 0,
                    'xp_reward' => 20,
                ]);

                $lessonQuizId = (int) $pdo->lastInsertId();
                insertQuizQuestions($pdo, $lessonQuizId, $lessonQuizIds);
                $stats['lesson_quizzes']++;
            }

            // Prova da fase/processo: 5 questões, 4 acertos.
            $phaseExamIds = spreadPick($phaseQuestionPool, 5);

            if (count($phaseExamIds) !== 5) {
                throw new \RuntimeException(
                    'Processo ' . $phaseData['code'] . ' não gerou 5 questões para a prova da fase.'
                );
            }

            $insertQuiz->execute([
                'course_id' => $courseId,
                'module_id' => $moduleId,
                'phase_id' => $phaseId,
                'lesson_id' => null,
                'title' => shortText(
                    'Checkpoint - Processo ' . $phaseData['code'],
                    200
                ),
                'description' => 'Prova obrigatória do processo: 5 questões, aprovação com pelo menos 4 acertos.',
                'quiz_type' => 'phase_exam',
                'required_score' => 80,
                'question_limit' => 5,
                'shuffle_questions' => 1,
                'xp_reward' => 80,
            ]);

            $phaseQuizId = (int) $pdo->lastInsertId();
            insertQuizQuestions($pdo, $phaseQuizId, $phaseExamIds);
            $stats['phase_exams']++;

            foreach ($phaseExamIds as $qid) {
                $moduleExamPool[] = $qid;
            }
        }

        // Avaliação do módulo do Manual: 10 questões, 8 acertos.
        $moduleExamIds = spreadPick($moduleExamPool, 10);

        if (count($moduleExamIds) !== 10) {
            throw new \RuntimeException(
                'Módulo POP ' . $sourceNumber . ' não gerou 10 questões para a avaliação final.'
            );
        }

        $insertQuiz->execute([
            'course_id' => $courseId,
            'module_id' => $moduleId,
            'phase_id' => null,
            'lesson_id' => null,
            'title' => shortText('Avaliação final - POP PMMT Módulo ' . $sourceNumber, 200),
            'description' => 'Avaliação final do módulo do Manual POP: 10 questões, aprovação com pelo menos 8 acertos.',
            'quiz_type' => 'module_boss',
            'required_score' => 80,
            'question_limit' => 10,
            'shuffle_questions' => 1,
            'xp_reward' => 250,
        ]);

        $moduleQuizId = (int) $pdo->lastInsertId();
        insertQuizQuestions($pdo, $moduleQuizId, $moduleExamIds);
        $stats['module_exams']++;
    }

    // Inicializa progresso para estudantes ativos.
    $students = $pdo->query(
        "SELECT u.id
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE r.slug = 'student'
           AND u.status = 'active'"
    )->fetchAll(\PDO::FETCH_COLUMN);

    $insModuleProgress = $pdo->prepare(
        "INSERT IGNORE INTO user_module_progress
            (user_id, module_id, status, best_score, progress_pct, unlocked_at)
         VALUES
            (:user_id, :module_id, 'locked', 0, 0, NULL)"
    );

    $insPhaseProgress = $pdo->prepare(
        "INSERT IGNORE INTO user_phase_progress
            (user_id, phase_id, status, best_score, attempts_count, progress_pct, unlocked_at)
         VALUES
            (:user_id, :phase_id, 'locked', 0, 0, 0, NULL)"
    );

    $insLessonProgress = $pdo->prepare(
        "INSERT IGNORE INTO user_lesson_progress
            (user_id, lesson_id, status, current_block, blocks_viewed, progress_pct)
         VALUES
            (:user_id, :lesson_id, 'locked', 1, 0, 0)"
    );

    foreach ($students as $studentIdRaw) {
        $studentId = (int) $studentIdRaw;

        foreach ($createdModuleIds as $moduleId) {
            $insModuleProgress->execute([
                'user_id' => $studentId,
                'module_id' => $moduleId,
            ]);
        }

        foreach ($createdPhaseIds as $phaseId) {
            $insPhaseProgress->execute([
                'user_id' => $studentId,
                'phase_id' => $phaseId,
            ]);
        }

        foreach ($createdLessonIds as $lessonId) {
            $insLessonProgress->execute([
                'user_id' => $studentId,
                'lesson_id' => $lessonId,
            ]);
        }

        if ($unlockFirst && $firstModuleId && $firstPhaseId && $firstLessonId) {
            $pdo->prepare(
                "UPDATE user_module_progress
                 SET status = 'available', unlocked_at = NOW()
                 WHERE user_id = :user_id AND module_id = :module_id"
            )->execute([
                'user_id' => $studentId,
                'module_id' => $firstModuleId,
            ]);

            $pdo->prepare(
                "UPDATE user_phase_progress
                 SET status = 'available', unlocked_at = NOW()
                 WHERE user_id = :user_id AND phase_id = :phase_id"
            )->execute([
                'user_id' => $studentId,
                'phase_id' => $firstPhaseId,
            ]);

            $pdo->prepare(
                "UPDATE user_lesson_progress
                 SET status = 'available'
                 WHERE user_id = :user_id AND lesson_id = :lesson_id"
            )->execute([
                'user_id' => $studentId,
                'lesson_id' => $firstLessonId,
            ]);
        }
    }

    $pdo->commit();

    echo "====================================================\n";
    echo " IMPORTAÇÃO POP PMMT 2023 CONCLUÍDA\n";
    echo "====================================================\n";
    echo "Curso: {$course['title']} (#{$courseId})\n";
    echo "Módulos POP criados: {$stats['modules']}\n";
    echo "Fases / Processos: {$stats['phases']}\n";
    echo "Aulas / POPs: {$stats['lessons']}\n";
    echo "Telas didáticas: {$stats['blocks']}\n";
    echo "Questões fonteadas: {$stats['questions']}\n";
    echo "Exercícios de aula: {$stats['lesson_quizzes']}\n";
    echo "Provas de fase: {$stats['phase_exams']}\n";
    echo "Avaliações de módulo: {$stats['module_exams']}\n";
    echo "\nRegra pedagógica:\n";
    echo " - Aula: 3 questões / mínimo 2 acertos\n";
    echo " - Fase: 5 questões / mínimo 4 acertos\n";
    echo " - Módulo: 10 questões / mínimo 8 acertos\n";

    if ($unlockFirst) {
        echo "\nModo de teste: primeiro módulo POP liberado para estudantes ativos.\n";
    } else {
        echo "\nOs módulos POP foram adicionados ao final da trilha e iniciam bloqueados.\n";
        echo "Use --unlock-first somente se quiser liberar o primeiro módulo POP para teste.\n";
    }

    $partialStmt = $pdo->prepare(
        "SELECT id, title, slug
         FROM modules
         WHERE course_id = :course_id
           AND title LIKE '%POP%'
           AND slug NOT LIKE 'pop-pmmt-2023-%'
         ORDER BY position"
    );
    $partialStmt->execute(['course_id' => $courseId]);
    $otherPop = $partialStmt->fetchAll();

    if ($otherPop) {
        echo "\nATENÇÃO: encontrei módulos antigos com 'POP' no título.\n";
        echo "Não foram apagados automaticamente para evitar perda acidental:\n";
        foreach ($otherPop as $row) {
            echo " - #{$row['id']} {$row['title']} ({$row['slug']})\n";
        }
        echo "Revise-os depois e arquive/remova apenas se forem realmente duplicados.\n";
    }

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "Erro: " . $e->getMessage() . "\n");
    exit(1);
}
