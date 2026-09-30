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

                $allModulePassed = true;

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

                    $previousPassed = $passed;
                    if (!$passed) {
                        $allModulePassed = false;
                    }
                }

                $moduleStatus = $canEnterModule
                    ? ($allModulePassed ? 'completed' : 'available')
                    : 'locked';

                $pdo->prepare(
                    "INSERT INTO user_module_progress
                        (user_id, module_id, status, best_score, progress_pct, unlocked_at)
                     VALUES
                        (:user_id, :module_id, :status, 0, :pct, :unlocked_at)
                     ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        progress_pct = VALUES(progress_pct),
                        unlocked_at = COALESCE(unlocked_at, VALUES(unlocked_at))"
                )->execute([
                    'user_id' => $studentId,
                    'module_id' => $moduleId,
                    'status' => $moduleStatus,
                    'pct' => $allModulePassed ? 100 : 0,
                    'unlocked_at' => $canEnterModule ? date('Y-m-d H:i:s') : null,
                ]);

                $canEnterModule = $canEnterModule && $allModulePassed;
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
