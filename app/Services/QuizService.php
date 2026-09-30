<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class QuizService
{
    public const REQUIRED_QUESTIONS = 5;
    public const REQUIRED_CORRECT = 4;
    public const REQUIRED_PERCENTAGE = 80.0;

    public function quizForStudent(int $quizId, int $userId): array
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT qz.*, p.title AS phase_title, p.id AS phase_id, p.module_id,
                    m.title AS module_title, m.course_id,
                    COALESCE(upp.status, 'locked') AS phase_status
             FROM quizzes qz
             INNER JOIN phases p ON p.id = qz.phase_id
             INNER JOIN modules m ON m.id = p.module_id
             LEFT JOIN user_phase_progress upp
               ON upp.phase_id = p.id AND upp.user_id = :user_id
             WHERE qz.id = :quiz_id
               AND qz.active = 1
               AND qz.quiz_type = 'phase_exam'
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => $userId,
            'quiz_id' => $quizId,
        ]);

        $quiz = $stmt->fetch();
        if (!$quiz) {
            throw new RuntimeException('Prova não encontrada.');
        }

        if ($quiz['phase_status'] === 'locked') {
            throw new RuntimeException('Esta prova ainda está bloqueada.');
        }

        if (!$this->requiredLessonsComplete($pdo, $userId, (int) $quiz['phase_id'])) {
            throw new RuntimeException('Conclua todas as aulas obrigatórias da fase antes de iniciar a prova.');
        }

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = :quiz_id"
        );
        $countStmt->execute(['quiz_id' => $quizId]);
        $quiz['question_count'] = (int) $countStmt->fetchColumn();

        $attemptStmt = $pdo->prepare(
            "SELECT COUNT(*) AS attempts,
                    COALESCE(MAX(percentage), 0) AS best_percentage,
                    COALESCE(MAX(CASE WHEN passed = 1 THEN percentage ELSE 0 END), 0) AS best_passed
             FROM quiz_attempts
             WHERE quiz_id = :quiz_id
               AND user_id = :user_id
               AND status = 'finished'"
        );
        $attemptStmt->execute([
            'quiz_id' => $quizId,
            'user_id' => $userId,
        ]);
        $stats = $attemptStmt->fetch();
        $quiz['attempts'] = (int) ($stats['attempts'] ?? 0);
        $quiz['best_percentage'] = (float) ($stats['best_percentage'] ?? 0);
        $quiz['best_passed'] = (float) ($stats['best_passed'] ?? 0);

        return $quiz;
    }

    public function startAttempt(int $quizId, int $userId): int
    {
        $pdo = Database::connection();
        $quiz = $this->quizForStudent($quizId, $userId);

        if ((int) $quiz['question_count'] !== self::REQUIRED_QUESTIONS) {
            throw new RuntimeException(
                'Esta prova ainda não possui as 5 questões exigidas. O conteúdo precisa ser revisado pelo administrador.'
            );
        }

        $this->validateQuizStructure($pdo, $quizId);

        $existing = $pdo->prepare(
            "SELECT id
             FROM quiz_attempts
             WHERE quiz_id = :quiz_id
               AND user_id = :user_id
               AND status = 'in_progress'
             ORDER BY id DESC
             LIMIT 1"
        );
        $existing->execute([
            'quiz_id' => $quizId,
            'user_id' => $userId,
        ]);
        $attemptId = (int) ($existing->fetchColumn() ?: 0);

        if ($attemptId > 0) {
            return $attemptId;
        }

        $numberStmt = $pdo->prepare(
            "SELECT COALESCE(MAX(attempt_number), 0) + 1
             FROM quiz_attempts
             WHERE quiz_id = :quiz_id
               AND user_id = :user_id"
        );
        $numberStmt->execute([
            'quiz_id' => $quizId,
            'user_id' => $userId,
        ]);
        $attemptNumber = (int) $numberStmt->fetchColumn();

        $insert = $pdo->prepare(
            "INSERT INTO quiz_attempts
                (quiz_id, user_id, attempt_number, started_at, score, max_score, percentage, passed, xp_earned, status)
             VALUES
                (:quiz_id, :user_id, :attempt_number, NOW(), 0, 5, 0, 0, 0, 'in_progress')"
        );
        $insert->execute([
            'quiz_id' => $quizId,
            'user_id' => $userId,
            'attempt_number' => $attemptNumber,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function attemptData(int $attemptId, int $userId): array
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT qa.*, qz.title AS quiz_title, qz.required_score,
                    p.id AS phase_id, p.title AS phase_title, p.module_id,
                    m.title AS module_title, m.course_id
             FROM quiz_attempts qa
             INNER JOIN quizzes qz ON qz.id = qa.quiz_id
             INNER JOIN phases p ON p.id = qz.phase_id
             INNER JOIN modules m ON m.id = p.module_id
             WHERE qa.id = :attempt_id
               AND qa.user_id = :user_id
             LIMIT 1"
        );
        $stmt->execute([
            'attempt_id' => $attemptId,
            'user_id' => $userId,
        ]);
        $attempt = $stmt->fetch();

        if (!$attempt) {
            throw new RuntimeException('Tentativa de prova não encontrada.');
        }

        $questionsStmt = $pdo->prepare(
            "SELECT q.id, q.statement, q.explanation, q.difficulty, qq.position, qq.points
             FROM quiz_questions qq
             INNER JOIN questions q ON q.id = qq.question_id
             WHERE qq.quiz_id = :quiz_id
               AND q.active = 1
             ORDER BY qq.position, q.id"
        );
        $questionsStmt->execute(['quiz_id' => $attempt['quiz_id']]);
        $questions = $questionsStmt->fetchAll();

        if (count($questions) !== self::REQUIRED_QUESTIONS) {
            throw new RuntimeException('A prova precisa conter exatamente 5 questões ativas.');
        }

        $altStmt = $pdo->prepare(
            "SELECT id, question_id, label, text, is_correct, explanation, position
             FROM alternatives
             WHERE question_id IN (" . implode(',', array_fill(0, count($questions), '?')) . ")
             ORDER BY question_id, position"
        );
        $altStmt->execute(array_column($questions, 'id'));
        $alternatives = $altStmt->fetchAll();

        $byQuestion = [];
        foreach ($alternatives as $alt) {
            $byQuestion[(int) $alt['question_id']][] = $alt;
        }

        foreach ($questions as &$question) {
            $question['alternatives'] = $byQuestion[(int) $question['id']] ?? [];
        }
        unset($question);

        $attempt['questions'] = $questions;

        if ($attempt['status'] === 'finished') {
            $answersStmt = $pdo->prepare(
                "SELECT question_id, alternative_id, is_correct, points_awarded
                 FROM quiz_answers
                 WHERE attempt_id = :attempt_id"
            );
            $answersStmt->execute(['attempt_id' => $attemptId]);
            $answers = [];
            foreach ($answersStmt->fetchAll() as $answer) {
                $answers[(int) $answer['question_id']] = $answer;
            }
            $attempt['answers'] = $answers;
            $attempt['next_content'] = $this->nextAccessibleContent(
                $pdo,
                $userId,
                (int) $attempt['phase_id'],
                (int) $attempt['module_id'],
                (int) $attempt['course_id']
            );
        }

        return $attempt;
    }

    public function submitAttempt(int $attemptId, int $userId, array $answers): array
    {
        $pdo = Database::connection();
        $attempt = $this->attemptData($attemptId, $userId);

        if ($attempt['status'] !== 'in_progress') {
            throw new RuntimeException('Esta tentativa já foi finalizada.');
        }

        $questionIds = array_map('intval', array_column($attempt['questions'], 'id'));
        $normalized = [];

        foreach ($questionIds as $questionId) {
            $selected = $answers[$questionId] ?? $answers[(string) $questionId] ?? null;
            if ($selected === null || $selected === '') {
                throw new RuntimeException('Responda as 5 questões antes de finalizar a prova.');
            }
            $normalized[$questionId] = (int) $selected;
        }

        $pdo->beginTransaction();

        try {
            $correctCount = 0;

            $correctStmt = $pdo->prepare(
                "SELECT id, is_correct
                 FROM alternatives
                 WHERE id = :alternative_id
                   AND question_id = :question_id
                 LIMIT 1"
            );

            $answerStmt = $pdo->prepare(
                "INSERT INTO quiz_answers
                    (attempt_id, question_id, alternative_id, is_correct, points_awarded, answered_at)
                 VALUES
                    (:attempt_id, :question_id, :alternative_id, :is_correct, :points, NOW())"
            );

            foreach ($questionIds as $questionId) {
                $alternativeId = $normalized[$questionId];
                $correctStmt->execute([
                    'alternative_id' => $alternativeId,
                    'question_id' => $questionId,
                ]);
                $alt = $correctStmt->fetch();

                if (!$alt) {
                    throw new RuntimeException('Uma das respostas enviadas é inválida.');
                }

                $isCorrect = (int) $alt['is_correct'] === 1;
                if ($isCorrect) {
                    $correctCount++;
                }

                $answerStmt->execute([
                    'attempt_id' => $attemptId,
                    'question_id' => $questionId,
                    'alternative_id' => $alternativeId,
                    'is_correct' => $isCorrect ? 1 : 0,
                    'points' => $isCorrect ? 1 : 0,
                ]);
            }

            $percentage = ($correctCount / self::REQUIRED_QUESTIONS) * 100;
            $passed = $correctCount >= self::REQUIRED_CORRECT;

            $pdo->prepare(
                "UPDATE quiz_attempts
                 SET finished_at = NOW(),
                     score = :score,
                     max_score = 5,
                     percentage = :percentage,
                     passed = :passed,
                     status = 'finished'
                 WHERE id = :attempt_id"
            )->execute([
                'score' => $correctCount,
                'percentage' => $percentage,
                'passed' => $passed ? 1 : 0,
                'attempt_id' => $attemptId,
            ]);

            $phaseId = (int) $attempt['phase_id'];

            $pdo->prepare(
                "UPDATE user_phase_progress
                 SET attempts_count = attempts_count + 1,
                     best_score = GREATEST(best_score, :percentage),
                     progress_pct = CASE WHEN :passed = 1 THEN 100 ELSE progress_pct END,
                     status = CASE WHEN :passed = 1 THEN 'completed' ELSE 'in_progress' END,
                     completed_at = CASE WHEN :passed = 1 THEN COALESCE(completed_at, NOW()) ELSE completed_at END
                 WHERE user_id = :user_id
                   AND phase_id = :phase_id"
            )->execute([
                'percentage' => $percentage,
                'passed' => $passed ? 1 : 0,
                'user_id' => $userId,
                'phase_id' => $phaseId,
            ]);

            if ($passed) {
                $this->awardOnce(
                    $pdo,
                    $userId,
                    'quiz_passed',
                    (int) $attempt['quiz_id'],
                    100,
                    'Aprovação na prova da fase #' . $phaseId
                );

                $phaseXpStmt = $pdo->prepare('SELECT xp_reward FROM phases WHERE id = :id LIMIT 1');
                $phaseXpStmt->execute(['id' => $phaseId]);
                $phaseXp = (int) ($phaseXpStmt->fetchColumn() ?: 0);

                $this->awardOnce(
                    $pdo,
                    $userId,
                    'phase_completed',
                    $phaseId,
                    $phaseXp,
                    'Conclusão da fase #' . $phaseId
                );

                $this->unlockNextPhaseOrModule(
                    $pdo,
                    $userId,
                    $phaseId,
                    (int) $attempt['module_id'],
                    (int) $attempt['course_id']
                );

                $this->recalculateProgress(
                    $pdo,
                    $userId,
                    (int) $attempt['module_id'],
                    (int) $attempt['course_id']
                );
            }

            $pdo->commit();

            return [
                'passed' => $passed,
                'correct' => $correctCount,
                'percentage' => $percentage,
                'phase_id' => $phaseId,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function requiredLessonsComplete(PDO $pdo, int $userId, int $phaseId): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(l.id) AS total,
                    SUM(CASE WHEN ulp.status = 'completed' THEN 1 ELSE 0 END) AS completed
             FROM lessons l
             LEFT JOIN user_lesson_progress ulp
               ON ulp.lesson_id = l.id AND ulp.user_id = :user_id
             WHERE l.phase_id = :phase_id
               AND l.status = 'published'
               AND l.is_required = 1"
        );
        $stmt->execute([
            'user_id' => $userId,
            'phase_id' => $phaseId,
        ]);
        $row = $stmt->fetch();

        return (int) $row['total'] > 0 && (int) $row['total'] === (int) $row['completed'];
    }

    private function unlockNextPhaseOrModule(
        PDO $pdo,
        int $userId,
        int $phaseId,
        int $moduleId,
        int $courseId
    ): void {
        $phaseStmt = $pdo->prepare('SELECT position FROM phases WHERE id = :id LIMIT 1');
        $phaseStmt->execute(['id' => $phaseId]);
        $phasePosition = (int) $phaseStmt->fetchColumn();

        $nextPhaseStmt = $pdo->prepare(
            "SELECT id
             FROM phases
             WHERE module_id = :module_id
               AND status = 'published'
               AND is_required = 1
               AND position > :position
             ORDER BY position
             LIMIT 1"
        );
        $nextPhaseStmt->execute([
            'module_id' => $moduleId,
            'position' => $phasePosition,
        ]);
        $nextPhaseId = (int) ($nextPhaseStmt->fetchColumn() ?: 0);

        if ($nextPhaseId > 0) {
            $this->unlockPhaseAndFirstLesson($pdo, $userId, $nextPhaseId);
            return;
        }

        $pdo->prepare(
            "UPDATE user_module_progress
             SET status = 'completed', progress_pct = 100,
                 completed_at = COALESCE(completed_at, NOW())
             WHERE user_id = :user_id AND module_id = :module_id"
        )->execute([
            'user_id' => $userId,
            'module_id' => $moduleId,
        ]);

        $moduleStmt = $pdo->prepare('SELECT position FROM modules WHERE id = :id LIMIT 1');
        $moduleStmt->execute(['id' => $moduleId]);
        $modulePosition = (int) $moduleStmt->fetchColumn();

        $nextModuleStmt = $pdo->prepare(
            "SELECT id
             FROM modules
             WHERE course_id = :course_id
               AND status = 'published'
               AND position > :position
             ORDER BY position
             LIMIT 1"
        );
        $nextModuleStmt->execute([
            'course_id' => $courseId,
            'position' => $modulePosition,
        ]);
        $nextModuleId = (int) ($nextModuleStmt->fetchColumn() ?: 0);

        if ($nextModuleId === 0) {
            $pdo->prepare(
                "UPDATE user_course_progress
                 SET status = 'completed', progress_pct = 100,
                     completed_at = COALESCE(completed_at, NOW()),
                     last_accessed_at = NOW()
                 WHERE user_id = :user_id AND course_id = :course_id"
            )->execute([
                'user_id' => $userId,
                'course_id' => $courseId,
            ]);
            return;
        }

        $pdo->prepare(
            "INSERT INTO user_module_progress
                (user_id, module_id, status, best_score, progress_pct, unlocked_at)
             VALUES
                (:user_id, :module_id, 'available', 0, 0, NOW())
             ON DUPLICATE KEY UPDATE
                status = IF(status = 'completed', 'completed', 'available'),
                unlocked_at = COALESCE(unlocked_at, NOW())"
        )->execute([
            'user_id' => $userId,
            'module_id' => $nextModuleId,
        ]);

        // Bibliotecas/fases opcionais do novo módulo ficam disponíveis para consulta livre.
        $optionalStmt = $pdo->prepare(
            "SELECT id FROM phases
             WHERE module_id = :module_id
               AND status = 'published'
               AND is_required = 0"
        );
        $optionalStmt->execute(['module_id' => $nextModuleId]);
        foreach ($optionalStmt->fetchAll(PDO::FETCH_COLUMN) as $optionalPhaseId) {
            $optionalPhaseId = (int) $optionalPhaseId;
            $this->unlockPhaseOnly($pdo, $userId, $optionalPhaseId);

            $optionalLessons = $pdo->prepare(
                "SELECT id FROM lessons
                 WHERE phase_id = :phase_id AND status = 'published'"
            );
            $optionalLessons->execute(['phase_id' => $optionalPhaseId]);
            foreach ($optionalLessons->fetchAll(PDO::FETCH_COLUMN) as $optionalLessonId) {
                $this->unlockLessonOnly($pdo, $userId, (int) $optionalLessonId);
            }
        }

        $firstPhaseStmt = $pdo->prepare(
            "SELECT id
             FROM phases
             WHERE module_id = :module_id
               AND status = 'published'
               AND is_required = 1
             ORDER BY position
             LIMIT 1"
        );
        $firstPhaseStmt->execute(['module_id' => $nextModuleId]);
        $firstPhaseId = (int) ($firstPhaseStmt->fetchColumn() ?: 0);

        if ($firstPhaseId > 0) {
            $this->unlockPhaseAndFirstLesson($pdo, $userId, $firstPhaseId);
        }
    }

    private function unlockPhaseAndFirstLesson(PDO $pdo, int $userId, int $phaseId): void
    {
        $pdo->prepare(
            "INSERT INTO user_phase_progress
                (user_id, phase_id, status, best_score, attempts_count, progress_pct, unlocked_at)
             VALUES
                (:user_id, :phase_id, 'available', 0, 0, 0, NOW())
             ON DUPLICATE KEY UPDATE
                status = IF(status = 'completed', 'completed', 'available'),
                unlocked_at = COALESCE(unlocked_at, NOW())"
        )->execute([
            'user_id' => $userId,
            'phase_id' => $phaseId,
        ]);

        $lessonStmt = $pdo->prepare(
            "SELECT id
             FROM lessons
             WHERE phase_id = :phase_id
               AND status = 'published'
               AND is_required = 1
             ORDER BY position
             LIMIT 1"
        );
        $lessonStmt->execute(['phase_id' => $phaseId]);
        $lessonId = (int) ($lessonStmt->fetchColumn() ?: 0);

        if ($lessonId > 0) {
            $pdo->prepare(
                "INSERT INTO user_lesson_progress
                    (user_id, lesson_id, status, current_block, blocks_viewed, progress_pct)
                 VALUES
                    (:user_id, :lesson_id, 'available', 1, 0, 0)
                 ON DUPLICATE KEY UPDATE
                    status = IF(status = 'completed', 'completed', IF(status = 'in_progress', 'in_progress', 'available'))"
            )->execute([
                'user_id' => $userId,
                'lesson_id' => $lessonId,
            ]);
        }
    }

    private function unlockPhaseOnly(PDO $pdo, int $userId, int $phaseId): void
    {
        $pdo->prepare(
            "INSERT INTO user_phase_progress
                (user_id, phase_id, status, best_score, attempts_count, progress_pct, unlocked_at)
             VALUES
                (:user_id, :phase_id, 'available', 0, 0, 0, NOW())
             ON DUPLICATE KEY UPDATE
                status = IF(status = 'completed', 'completed', 'available'),
                unlocked_at = COALESCE(unlocked_at, NOW())"
        )->execute([
            'user_id' => $userId,
            'phase_id' => $phaseId,
        ]);
    }

    private function unlockLessonOnly(PDO $pdo, int $userId, int $lessonId): void
    {
        $pdo->prepare(
            "INSERT INTO user_lesson_progress
                (user_id, lesson_id, status, current_block, blocks_viewed, progress_pct)
             VALUES
                (:user_id, :lesson_id, 'available', 1, 0, 0)
             ON DUPLICATE KEY UPDATE
                status = IF(status = 'completed', 'completed', IF(status = 'in_progress', 'in_progress', 'available'))"
        )->execute([
            'user_id' => $userId,
            'lesson_id' => $lessonId,
        ]);
    }

    private function validateQuizStructure(PDO $pdo, int $quizId): void
    {
        $stmt = $pdo->prepare(
            "SELECT q.id,
                    COUNT(a.id) AS alternative_count,
                    SUM(CASE WHEN a.is_correct = 1 THEN 1 ELSE 0 END) AS correct_count
             FROM quiz_questions qq
             INNER JOIN questions q ON q.id = qq.question_id AND q.active = 1
             LEFT JOIN alternatives a ON a.question_id = q.id
             WHERE qq.quiz_id = :quiz_id
             GROUP BY q.id
             ORDER BY qq.position"
        );
        $stmt->execute(['quiz_id' => $quizId]);
        $rows = $stmt->fetchAll();

        if (count($rows) !== self::REQUIRED_QUESTIONS) {
            throw new RuntimeException('A prova precisa conter exatamente 5 questões ativas.');
        }

        foreach ($rows as $index => $row) {
            if ((int) $row['alternative_count'] < 2 || (int) $row['correct_count'] !== 1) {
                $number = $index + 1;
                throw new RuntimeException(
                    "A questão {$number} da prova está incompleta: ela precisa ter alternativas e exatamente uma resposta correta."
                );
            }
        }
    }

    private function awardOnce(
        PDO $pdo,
        int $userId,
        string $eventType,
        int $referenceId,
        int $xp,
        string $description
    ): void {
        if ($xp <= 0) {
            return;
        }

        $exists = $pdo->prepare(
            "SELECT id FROM xp_events
             WHERE user_id = :user_id
               AND event_type = :event_type
               AND reference_id = :reference_id
             LIMIT 1"
        );
        $exists->execute([
            'user_id' => $userId,
            'event_type' => $eventType,
            'reference_id' => $referenceId,
        ]);

        if ($exists->fetchColumn()) {
            return;
        }

        $pdo->prepare(
            "INSERT INTO xp_events
                (user_id, event_type, reference_id, xp_amount, description)
             VALUES
                (:user_id, :event_type, :reference_id, :xp, :description)"
        )->execute([
            'user_id' => $userId,
            'event_type' => $eventType,
            'reference_id' => $referenceId,
            'xp' => $xp,
            'description' => $description,
        ]);

        $pdo->prepare('UPDATE users SET xp_total = xp_total + :xp WHERE id = :user_id')
            ->execute(['xp' => $xp, 'user_id' => $userId]);
    }

    private function recalculateProgress(PDO $pdo, int $userId, int $moduleId, int $courseId): void
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(p.id) AS total,
                    SUM(CASE WHEN upp.status = 'completed' THEN 1 ELSE 0 END) AS completed
             FROM phases p
             LEFT JOIN user_phase_progress upp
               ON upp.phase_id = p.id AND upp.user_id = :user_id
             WHERE p.module_id = :module_id
               AND p.status = 'published'
               AND p.is_required = 1"
        );
        $stmt->execute(['user_id' => $userId, 'module_id' => $moduleId]);
        $row = $stmt->fetch();
        $modulePct = (int) $row['total'] > 0
            ? round(((int) $row['completed'] / (int) $row['total']) * 100, 2)
            : 0;

        $pdo->prepare(
            "UPDATE user_module_progress
             SET progress_pct = :pct
             WHERE user_id = :user_id AND module_id = :module_id"
        )->execute([
            'pct' => $modulePct,
            'user_id' => $userId,
            'module_id' => $moduleId,
        ]);

        $stmt = $pdo->prepare(
            "SELECT COUNT(p.id) AS total,
                    SUM(CASE WHEN upp.status = 'completed' THEN 1 ELSE 0 END) AS completed
             FROM phases p
             INNER JOIN modules m ON m.id = p.module_id
             LEFT JOIN user_phase_progress upp
               ON upp.phase_id = p.id AND upp.user_id = :user_id
             WHERE m.course_id = :course_id
               AND m.status = 'published'
               AND p.status = 'published'
               AND p.is_required = 1"
        );
        $stmt->execute(['user_id' => $userId, 'course_id' => $courseId]);
        $row = $stmt->fetch();
        $coursePct = (int) $row['total'] > 0
            ? round(((int) $row['completed'] / (int) $row['total']) * 100, 2)
            : 0;

        $pdo->prepare(
            "UPDATE user_course_progress
             SET progress_pct = :pct, last_accessed_at = NOW()
             WHERE user_id = :user_id AND course_id = :course_id"
        )->execute([
            'pct' => $coursePct,
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);
    }

    private function nextAccessibleContent(
        PDO $pdo,
        int $userId,
        int $phaseId,
        int $moduleId,
        int $courseId
    ): ?array {
        $phaseStmt = $pdo->prepare('SELECT position FROM phases WHERE id = :id LIMIT 1');
        $phaseStmt->execute(['id' => $phaseId]);
        $phasePosition = (int) $phaseStmt->fetchColumn();

        $nextStmt = $pdo->prepare(
            "SELECT p.id AS phase_id, p.title AS phase_title, l.id AS lesson_id, l.title AS lesson_title
             FROM phases p
             INNER JOIN user_phase_progress upp
               ON upp.phase_id = p.id AND upp.user_id = :user_id
             INNER JOIN lessons l
               ON l.phase_id = p.id AND l.status = 'published' AND l.is_required = 1
             WHERE p.module_id = :module_id
               AND p.status = 'published'
               AND p.is_required = 1
               AND p.position > :position
               AND upp.status IN ('available', 'in_progress', 'completed')
             ORDER BY p.position, l.position
             LIMIT 1"
        );
        $nextStmt->execute([
            'user_id' => $userId,
            'module_id' => $moduleId,
            'position' => $phasePosition,
        ]);
        $next = $nextStmt->fetch();
        if ($next) {
            return $next;
        }

        $moduleStmt = $pdo->prepare('SELECT position FROM modules WHERE id = :id LIMIT 1');
        $moduleStmt->execute(['id' => $moduleId]);
        $modulePosition = (int) $moduleStmt->fetchColumn();

        $nextModuleStmt = $pdo->prepare(
            "SELECT m.id AS module_id, p.id AS phase_id, p.title AS phase_title,
                    l.id AS lesson_id, l.title AS lesson_title
             FROM modules m
             INNER JOIN user_module_progress ump
               ON ump.module_id = m.id AND ump.user_id = :user_id
             INNER JOIN phases p
               ON p.module_id = m.id AND p.status = 'published' AND p.is_required = 1
             INNER JOIN user_phase_progress upp
               ON upp.phase_id = p.id AND upp.user_id = :user_id2
             INNER JOIN lessons l
               ON l.phase_id = p.id AND l.status = 'published' AND l.is_required = 1
             WHERE m.course_id = :course_id
               AND m.status = 'published'
               AND m.position > :module_position
               AND ump.status IN ('available', 'in_progress', 'completed')
               AND upp.status IN ('available', 'in_progress', 'completed')
             ORDER BY m.position, p.position, l.position
             LIMIT 1"
        );
        $nextModuleStmt->execute([
            'user_id' => $userId,
            'user_id2' => $userId,
            'course_id' => $courseId,
            'module_position' => $modulePosition,
        ]);

        return $nextModuleStmt->fetch() ?: null;
    }
}
