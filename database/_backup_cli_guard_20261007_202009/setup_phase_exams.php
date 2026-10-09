<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

use Dotenv\Dotenv;

Dotenv::createImmutable(BASE_PATH)->safeLoad();

function envv(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }
    return is_string($value) ? trim($value, "\"'") : $value;
}

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
        \PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

try {
    $pdo->beginTransaction();

    /*
     * Módulo 0 é orientação/metodologia, não conteúdo examinável.
     * Ele continua disponível para leitura, mas não bloqueia a trilha.
     */
    $pdo->exec(
        "UPDATE phases p
         INNER JOIN modules m ON m.id = p.module_id
         SET p.is_required = 0
         WHERE m.title LIKE 'Módulo 0%'
            OR m.slug LIKE 'modulo-0-%'"
    );

    $pdo->exec(
        "UPDATE lessons l
         INNER JOIN phases p ON p.id = l.phase_id
         INNER JOIN modules m ON m.id = p.module_id
         SET l.is_required = 0
         WHERE m.title LIKE 'Módulo 0%'
            OR m.slug LIKE 'modulo-0-%'"
    );

    $phases = $pdo->query(
        "SELECT p.id, p.title, p.module_id, m.title AS module_title
         FROM phases p
         INNER JOIN modules m ON m.id = p.module_id
         WHERE p.status = 'published'
           AND p.is_required = 1
         ORDER BY m.position, p.position"
    )->fetchAll();

    $findQuiz = $pdo->prepare(
        "SELECT id
         FROM quizzes
         WHERE phase_id = :phase_id
           AND quiz_type = 'phase_exam'
         ORDER BY id
         LIMIT 1"
    );

    $insertQuiz = $pdo->prepare(
        "INSERT INTO quizzes
            (course_id, module_id, phase_id, lesson_id, title, description,
             quiz_type, required_score, question_limit, time_limit_minutes,
             shuffle_questions, shuffle_answers, show_feedback, max_attempts,
             xp_reward, active)
         SELECT m.course_id, p.module_id, p.id, NULL,
                CONCAT('Checkpoint - ', p.title),
                'Prova obrigatória da fase: 5 questões, aprovação com pelo menos 4 acertos.',
                'phase_exam', 80.00, 5, NULL,
                0, 1, 1, NULL,
                100, 1
         FROM phases p
         INNER JOIN modules m ON m.id = p.module_id
         WHERE p.id = :phase_id"
    );

    $updateQuiz = $pdo->prepare(
        "UPDATE quizzes
         SET title = :title,
             description = 'Prova obrigatória da fase: 5 questões, aprovação com pelo menos 4 acertos.',
             required_score = 80.00,
             question_limit = 5,
             shuffle_questions = 0,
             shuffle_answers = 1,
             show_feedback = 1,
             max_attempts = NULL,
             xp_reward = 100,
             active = 1
         WHERE id = :quiz_id"
    );

    $attachExisting = $pdo->prepare(
        "SELECT q.id
         FROM questions q
         WHERE q.phase_id = :phase_id
           AND q.active = 1
           AND q.id NOT IN (
               SELECT qq.question_id FROM quiz_questions qq WHERE qq.quiz_id = :quiz_id
           )
         ORDER BY q.id
         LIMIT 5"
    );

    $countQuestions = $pdo->prepare(
        "SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = :quiz_id"
    );

    $nextPosition = $pdo->prepare(
        "SELECT COALESCE(MAX(position), 0) + 1
         FROM quiz_questions
         WHERE quiz_id = :quiz_id"
    );

    $insertLink = $pdo->prepare(
        "INSERT IGNORE INTO quiz_questions (quiz_id, question_id, position, points)
         VALUES (:quiz_id, :question_id, :position, 1.00)"
    );

    $created = 0;
    $updated = 0;
    $ready = 0;
    $missing = [];

    foreach ($phases as $phase) {
        $findQuiz->execute(['phase_id' => $phase['id']]);
        $quizId = (int) ($findQuiz->fetchColumn() ?: 0);

        if ($quizId === 0) {
            $insertQuiz->execute(['phase_id' => $phase['id']]);
            $quizId = (int) $pdo->lastInsertId();
            $created++;
        } else {
            $updateQuiz->execute([
                'title' => 'Checkpoint - ' . $phase['title'],
                'quiz_id' => $quizId,
            ]);
            $updated++;
        }

        // Se já existirem questões vinculadas à fase, aproveita até completar 5.
        $countQuestions->execute(['quiz_id' => $quizId]);
        $currentCount = (int) $countQuestions->fetchColumn();

        if ($currentCount < 5) {
            $attachExisting->execute([
                'phase_id' => $phase['id'],
                'quiz_id' => $quizId,
            ]);

            foreach ($attachExisting->fetchAll(\PDO::FETCH_COLUMN) as $questionId) {
                $countQuestions->execute(['quiz_id' => $quizId]);
                if ((int) $countQuestions->fetchColumn() >= 5) {
                    break;
                }

                $nextPosition->execute(['quiz_id' => $quizId]);
                $position = (int) $nextPosition->fetchColumn();

                $insertLink->execute([
                    'quiz_id' => $quizId,
                    'question_id' => (int) $questionId,
                    'position' => $position,
                ]);
            }
        }

        $countQuestions->execute(['quiz_id' => $quizId]);
        $finalCount = (int) $countQuestions->fetchColumn();

        if ($finalCount === 5) {
            $ready++;
        } else {
            $missing[] = [
                'phase_id' => (int) $phase['id'],
                'module' => $phase['module_title'],
                'phase' => $phase['title'],
                'questions' => $finalCount,
            ];
        }

        $pdo->prepare('UPDATE phases SET required_score = 80.00 WHERE id = :id')
            ->execute(['id' => $phase['id']]);
    }

    /*
     * Regras antigas podiam ter marcado fases como concluídas apenas pela leitura.
     * Com a nova regra, uma fase obrigatória só permanece completed se houver
     * tentativa APROVADA no phase_exam.
     */
    $pdo->exec(
        "UPDATE user_phase_progress upp
         INNER JOIN phases p ON p.id = upp.phase_id
         SET upp.status = 'in_progress',
             upp.completed_at = NULL
         WHERE p.is_required = 1
           AND upp.status = 'completed'
           AND NOT EXISTS (
               SELECT 1
               FROM quizzes qz
               INNER JOIN quiz_attempts qa ON qa.quiz_id = qz.id
               WHERE qz.phase_id = p.id
                 AND qz.quiz_type = 'phase_exam'
                 AND qa.user_id = upp.user_id
                 AND qa.status = 'finished'
                 AND qa.passed = 1
           )"
    );

    /*
     * Reaplica o bloqueio sequencial por aluno. Progresso de leitura já feito não é apagado;
     * apenas o acesso a fases futuras é novamente controlado.
     */
    $students = $pdo->query(
        "SELECT u.id
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE r.slug = 'student' AND u.status = 'active'"
    )->fetchAll(\PDO::FETCH_COLUMN);

    foreach ($students as $studentIdRaw) {
        $studentId = (int) $studentIdRaw;

        $courses = $pdo->prepare(
            "SELECT c.id
             FROM courses c
             INNER JOIN enrollments e ON e.course_id = c.id
             WHERE e.user_id = :user_id
               AND e.status = 'active'
               AND c.status = 'published'
             ORDER BY c.position"
        );
        $courses->execute(['user_id' => $studentId]);

        foreach ($courses->fetchAll(\PDO::FETCH_COLUMN) as $courseIdRaw) {
            $courseId = (int) $courseIdRaw;
            $canEnterModule = true;

            $modulesStmt = $pdo->prepare(
                "SELECT id, title
                 FROM modules
                 WHERE course_id = :course_id AND status = 'published'
                 ORDER BY position"
            );
            $modulesStmt->execute(['course_id' => $courseId]);

            foreach ($modulesStmt->fetchAll() as $module) {
                $moduleId = (int) $module['id'];
                $isOrientation = str_starts_with($module['title'], 'Módulo 0');

                if ($isOrientation) {
                    continue;
                }

                $phaseStmt = $pdo->prepare(
                    "SELECT id
                     FROM phases
                     WHERE module_id = :module_id
                       AND status = 'published'
                       AND is_required = 1
                     ORDER BY position"
                );
                $phaseStmt->execute(['module_id' => $moduleId]);
                $phaseIds = array_map('intval', $phaseStmt->fetchAll(\PDO::FETCH_COLUMN));

                if (!$phaseIds) {
                    continue;
                }

                $allModulePhasesPassed = true;
                $passedPhaseCount = 0;
                $previousPassed = false;

                foreach ($phaseIds as $index => $phaseId) {
                    $passedStmt = $pdo->prepare(
                        "SELECT EXISTS(
                            SELECT 1
                            FROM quizzes qz
                            INNER JOIN quiz_attempts qa ON qa.quiz_id = qz.id
                            WHERE qz.phase_id = :phase_id
                              AND qz.quiz_type = 'phase_exam'
                              AND qa.user_id = :user_id
                              AND qa.status = 'finished'
                              AND qa.passed = 1
                        )"
                    );
                    $passedStmt->execute([
                        'phase_id' => $phaseId,
                        'user_id' => $studentId,
                    ]);
                    $passed = (bool) $passedStmt->fetchColumn();

                    $accessible = $canEnterModule && ($index === 0 || $previousPassed);

                    if ($passed) {
                        $passedPhaseCount++;
                        $pdo->prepare(
                            "INSERT INTO user_phase_progress
                                (user_id, phase_id, status, best_score, attempts_count, progress_pct, unlocked_at, completed_at)
                             VALUES
                                (:user_id, :phase_id, 'completed', 80, 1, 100, NOW(), NOW())
                             ON DUPLICATE KEY UPDATE
                                status = 'completed', progress_pct = 100,
                                unlocked_at = COALESCE(unlocked_at, NOW()),
                                completed_at = COALESCE(completed_at, NOW())"
                        )->execute([
                            'user_id' => $studentId,
                            'phase_id' => $phaseId,
                        ]);
                    } elseif ($accessible) {
                        $pdo->prepare(
                            "INSERT INTO user_phase_progress
                                (user_id, phase_id, status, best_score, attempts_count, progress_pct, unlocked_at)
                             VALUES
                                (:user_id, :phase_id, 'available', 0, 0, 0, NOW())
                             ON DUPLICATE KEY UPDATE
                                status = IF(status = 'in_progress', 'in_progress', 'available'),
                                unlocked_at = COALESCE(unlocked_at, NOW()),
                                completed_at = NULL"
                        )->execute([
                            'user_id' => $studentId,
                            'phase_id' => $phaseId,
                        ]);
                    } else {
                        $pdo->prepare(
                            "INSERT INTO user_phase_progress
                                (user_id, phase_id, status, best_score, attempts_count, progress_pct)
                             VALUES
                                (:user_id, :phase_id, 'locked', 0, 0, 0)
                             ON DUPLICATE KEY UPDATE
                                status = 'locked',
                                completed_at = NULL"
                        )->execute([
                            'user_id' => $studentId,
                            'phase_id' => $phaseId,
                        ]);
                    }

                    // Reconstroi também a liberação das aulas da fase para evitar
                    // fase disponível com todas as aulas ainda bloqueadas.
                    $phaseAccessible = $canEnterModule && ($passed || $accessible);

                    if ($phaseAccessible) {
                        $pdo->prepare(
                            "INSERT INTO user_lesson_progress
                                (user_id, lesson_id, status, current_block, blocks_viewed, progress_pct)
                             SELECT :optional_user_id, l.id, 'available', 1, 0, 0
                             FROM lessons l
                             WHERE l.phase_id = :optional_phase_id
                               AND l.status = 'published'
                               AND l.is_required = 0
                             ON DUPLICATE KEY UPDATE
                                status = IF(status IN ('completed','in_progress'), status, 'available')"
                        )->execute([
                            'optional_user_id' => $studentId,
                            'optional_phase_id' => $phaseId,
                        ]);
                    }

                    $requiredLessonsStmt = $pdo->prepare(
                        "SELECT l.id,
                                COALESCE(ulp.status, 'locked') AS user_status
                         FROM lessons l
                         LEFT JOIN user_lesson_progress ulp
                           ON ulp.lesson_id = l.id
                          AND ulp.user_id = :lesson_status_user_id
                         WHERE l.phase_id = :lesson_phase_id
                           AND l.status = 'published'
                           AND l.is_required = 1
                         ORDER BY l.position"
                    );
                    $requiredLessonsStmt->execute([
                        'lesson_status_user_id' => $studentId,
                        'lesson_phase_id' => $phaseId,
                    ]);

                    $canOpenLesson = $phaseAccessible;
                    foreach ($requiredLessonsStmt->fetchAll() as $lessonRow) {
                        $lessonId = (int) $lessonRow['id'];
                        $lessonStatus = (string) $lessonRow['user_status'];

                        if ($lessonStatus === 'completed') {
                            continue;
                        }

                        if ($canOpenLesson) {
                            $pdo->prepare(
                                "INSERT INTO user_lesson_progress
                                    (user_id, lesson_id, status, current_block, blocks_viewed, progress_pct)
                                 VALUES
                                    (:open_user_id, :open_lesson_id, 'available', 1, 0, 0)
                                 ON DUPLICATE KEY UPDATE
                                    status = IF(status = 'in_progress', 'in_progress', 'available')"
                            )->execute([
                                'open_user_id' => $studentId,
                                'open_lesson_id' => $lessonId,
                            ]);
                            $canOpenLesson = false;
                            continue;
                        }

                        $pdo->prepare(
                            "INSERT INTO user_lesson_progress
                                (user_id, lesson_id, status, current_block, blocks_viewed, progress_pct)
                             VALUES
                                (:locked_user_id, :locked_lesson_id, 'locked', 1, 0, 0)
                             ON DUPLICATE KEY UPDATE
                                status = IF(status = 'completed', 'completed', 'locked')"
                        )->execute([
                            'locked_user_id' => $studentId,
                            'locked_lesson_id' => $lessonId,
                        ]);
                    }

                    $previousPassed = $passed;
                    if (!$passed) {
                        $allModulePhasesPassed = false;
                    }
                }

                // Bibliotecas/fases opcionais ficam acessíveis sempre que o módulo está acessível,
                // mas não participam do bloqueio da progressão.
                if ($canEnterModule) {
                    $pdo->prepare(
                        "INSERT INTO user_phase_progress
                            (user_id, phase_id, status, best_score, attempts_count, progress_pct, unlocked_at)
                         SELECT :user_id, p.id, 'available', 0, 0, 0, NOW()
                         FROM phases p
                         WHERE p.module_id = :module_id
                           AND p.status = 'published'
                           AND p.is_required = 0
                         ON DUPLICATE KEY UPDATE
                            status = IF(status IN ('completed','in_progress'), status, 'available'),
                            unlocked_at = COALESCE(unlocked_at, NOW())"
                    )->execute([
                        'user_id' => $studentId,
                        'module_id' => $moduleId,
                    ]);

                    $pdo->prepare(
                        "INSERT INTO user_lesson_progress
                            (user_id, lesson_id, status, current_block, blocks_viewed, progress_pct)
                         SELECT :user_id, l.id, 'available', 1, 0, 0
                         FROM lessons l
                         INNER JOIN phases p ON p.id = l.phase_id
                         WHERE p.module_id = :module_id
                           AND p.status = 'published'
                           AND p.is_required = 0
                           AND l.status = 'published'
                         ON DUPLICATE KEY UPDATE
                            status = IF(status IN ('completed','in_progress'), status, 'available')"
                    )->execute([
                        'user_id' => $studentId,
                        'module_id' => $moduleId,
                    ]);
                }

                // O módulo só é considerado concluído após aprovação no module_boss.
                $bossStmt = $pdo->prepare(
                    "SELECT
                        COALESCE(MAX(CASE WHEN qa.status = 'finished' THEN qa.percentage ELSE 0 END), 0) AS best_score,
                        COALESCE(MAX(CASE WHEN qa.status = 'finished' AND qa.passed = 1 THEN 1 ELSE 0 END), 0) AS passed
                     FROM quizzes qz
                     LEFT JOIN quiz_attempts qa
                       ON qa.quiz_id = qz.id
                      AND qa.user_id = :user_id
                     WHERE qz.module_id = :module_id
                       AND qz.quiz_type = 'module_boss'
                       AND qz.active = 1"
                );
                $bossStmt->execute([
                    'user_id' => $studentId,
                    'module_id' => $moduleId,
                ]);
                $boss = $bossStmt->fetch();
                $bossPassed = (int) ($boss['passed'] ?? 0) === 1;
                $bestBossScore = (float) ($boss['best_score'] ?? 0);

                $phaseProgressPct = count($phaseIds) > 0
                    ? round(($passedPhaseCount / count($phaseIds)) * 100, 2)
                    : 0.0;

                $moduleCompleted = $allModulePhasesPassed && $bossPassed;

                $moduleStatus = 'locked';
                if ($canEnterModule) {
                    if ($moduleCompleted) {
                        $moduleStatus = 'completed';
                    } elseif ($allModulePhasesPassed) {
                        $moduleStatus = 'in_progress';
                    } else {
                        $moduleStatus = 'available';
                    }
                }

                $pdo->prepare(
                    "INSERT INTO user_module_progress
                        (user_id, module_id, status, best_score, progress_pct, unlocked_at, completed_at)
                     VALUES
                        (:user_id, :module_id, :status, :best_score, :pct, :unlocked_at, :completed_at)
                     ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        best_score = GREATEST(best_score, VALUES(best_score)),
                        progress_pct = VALUES(progress_pct),
                        unlocked_at = COALESCE(unlocked_at, VALUES(unlocked_at)),
                        completed_at = VALUES(completed_at)"
                )->execute([
                    'user_id' => $studentId,
                    'module_id' => $moduleId,
                    'status' => $moduleStatus,
                    'best_score' => $bestBossScore,
                    'pct' => $moduleCompleted ? 100 : $phaseProgressPct,
                    'unlocked_at' => $canEnterModule ? date('Y-m-d H:i:s') : null,
                    'completed_at' => $moduleCompleted ? date('Y-m-d H:i:s') : null,
                ]);

                // Somente a aprovação na avaliação final abre o módulo seguinte.
                $canEnterModule = $canEnterModule && $moduleCompleted;
            }
        }
    }

    $pdo->commit();

    echo "==============================================\n";
    echo " CHECKPOINTS DE FASE CONFIGURADOS\n";
    echo "==============================================\n";
    echo "Provas criadas: {$created}\n";
    echo "Provas atualizadas: {$updated}\n";
    echo "Provas prontas (5/5): {$ready}\n";
    echo "Fases ainda sem 5 questões: " . count($missing) . "\n\n";

    if ($missing) {
        echo "ATENÇÃO: a progressão dessas fases permanecerá bloqueada até completar 5 questões.\n";
        echo "Execute depois: php database\\auditar_provas.php\n\n";
    }
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "Erro: {$e->getMessage()}\n");
    exit(1);
}
