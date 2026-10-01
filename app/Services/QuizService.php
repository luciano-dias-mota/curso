<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class QuizService
{
    public function getQuiz(int $quizId, int $userId): array
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT qz.*,
                    l.title AS lesson_title,
                    COALESCE(qz.phase_id, l.phase_id) AS resolved_phase_id,
                    p.title AS phase_title,
                    COALESCE(qz.module_id, p.module_id) AS resolved_module_id,
                    m.title AS module_title,
                    m.course_id
             FROM quizzes qz
             LEFT JOIN lessons l ON l.id = qz.lesson_id
             LEFT JOIN phases p ON p.id = COALESCE(qz.phase_id, l.phase_id)
             LEFT JOIN modules m ON m.id = COALESCE(qz.module_id, p.module_id)
             WHERE qz.id = :quiz_id
               AND qz.active = 1
             LIMIT 1"
        );
        $stmt->execute(['quiz_id' => $quizId]);
        $quiz = $stmt->fetch();

        if (!$quiz) {
            throw new RuntimeException('Avaliação não encontrada.');
        }

        $quiz['phase_id'] = $quiz['phase_id'] ?: $quiz['resolved_phase_id'];
        $quiz['module_id'] = $quiz['module_id'] ?: $quiz['resolved_module_id'];

        $this->assertEligible($pdo, $quiz, $userId);

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM quiz_questions
             WHERE quiz_id = :quiz_id"
        );
        $countStmt->execute(['quiz_id' => $quizId]);
        $quiz['question_count'] = (int) $countStmt->fetchColumn();

        $statsStmt = $pdo->prepare(
            "SELECT COUNT(*) AS attempts,
                    COALESCE(MAX(percentage), 0) AS best_percentage,
                    COALESCE(MAX(CASE WHEN passed = 1 THEN percentage ELSE 0 END), 0) AS best_passed
             FROM quiz_attempts
             WHERE quiz_id = :quiz_id
               AND user_id = :user_id
               AND status = 'finished'"
        );
        $statsStmt->execute([
            'quiz_id' => $quizId,
            'user_id' => $userId,
        ]);
        $stats = $statsStmt->fetch();

        $quiz['attempts'] = (int) ($stats['attempts'] ?? 0);
        $quiz['best_percentage'] = (float) ($stats['best_percentage'] ?? 0);
        $quiz['best_passed'] = (float) ($stats['best_passed'] ?? 0);
        $quiz['required_correct'] = $this->requiredCorrect($quiz);
        $quiz['type_label'] = $this->typeLabel((string) $quiz['quiz_type']);
        $quiz['context_url'] = $this->contextUrl($quiz);

        return $quiz;
    }

    public function startAttempt(int $quizId, int $userId): int
    {
        $pdo = Database::connection();
        $quiz = $this->getQuiz($quizId, $userId);

        $limit = (int) ($quiz['question_limit'] ?? 0);

        if ($limit <= 0 || (int) $quiz['question_count'] !== $limit) {
            throw new RuntimeException(
                'Esta avaliação ainda não possui a quantidade exigida de questões revisadas.'
            );
        }

        $this->validateStructure($pdo, $quizId, $limit);

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

        $insert = $pdo->prepare(
            "INSERT INTO quiz_attempts
                (quiz_id, user_id, attempt_number, started_at,
                 score, max_score, percentage, passed, xp_earned, status)
             VALUES
                (:quiz_id, :user_id, :attempt_number, NOW(),
                 0, :max_score, 0, 0, 0, 'in_progress')"
        );
        $insert->execute([
            'quiz_id' => $quizId,
            'user_id' => $userId,
            'attempt_number' => (int) $numberStmt->fetchColumn(),
            'max_score' => $limit,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function attemptData(int $attemptId, int $userId): array
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT qa.*,
                    qz.title AS quiz_title, qz.quiz_type, qz.required_score,
                    qz.question_limit, qz.xp_reward, qz.lesson_id,
                    l.phase_id AS lesson_phase_id,
                    COALESCE(qz.phase_id, l.phase_id) AS phase_id,
                    COALESCE(qz.module_id, p.module_id) AS module_id,
                    m.course_id
             FROM quiz_attempts qa
             INNER JOIN quizzes qz ON qz.id = qa.quiz_id
             LEFT JOIN lessons l ON l.id = qz.lesson_id
             LEFT JOIN phases p ON p.id = COALESCE(qz.phase_id, l.phase_id)
             LEFT JOIN modules m ON m.id = COALESCE(qz.module_id, p.module_id)
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
            throw new RuntimeException('Tentativa não encontrada.');
        }

        $questionsStmt = $pdo->prepare(
            "SELECT q.id, q.statement, q.explanation, q.difficulty,
                    qq.position, qq.points
             FROM quiz_questions qq
             INNER JOIN questions q ON q.id = qq.question_id
             WHERE qq.quiz_id = :quiz_id
               AND q.active = 1
             ORDER BY qq.position, q.id"
        );
        $questionsStmt->execute(['quiz_id' => $attempt['quiz_id']]);
        $questions = $questionsStmt->fetchAll();

        $expected = (int) $attempt['question_limit'];

        if (count($questions) !== $expected) {
            throw new RuntimeException(
                "Esta avaliação precisa ter exatamente {$expected} questões ativas."
            );
        }

        $ids = array_column($questions, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $altStmt = $pdo->prepare(
            "SELECT id, question_id, label, text, is_correct,
                    explanation, position
             FROM alternatives
             WHERE question_id IN ({$ph})
             ORDER BY question_id, position"
        );
        $altStmt->execute($ids);

        $byQuestion = [];
        foreach ($altStmt->fetchAll() as $alt) {
            $byQuestion[(int) $alt['question_id']][] = $alt;
        }

        foreach ($questions as &$q) {
            $q['alternatives'] = $byQuestion[(int) $q['id']] ?? [];
        }
        unset($q);

        $attempt['questions'] = $questions;
        $attempt['required_correct'] = $this->requiredCorrect($attempt);
        $attempt['type_label'] = $this->typeLabel((string) $attempt['quiz_type']);
        $attempt['context_url'] = $this->contextUrl($attempt);

        if ($attempt['status'] === 'finished') {
            $answerStmt = $pdo->prepare(
                "SELECT question_id, alternative_id, is_correct, points_awarded
                 FROM quiz_answers
                 WHERE attempt_id = :attempt_id"
            );
            $answerStmt->execute(['attempt_id' => $attemptId]);

            $answers = [];
            foreach ($answerStmt->fetchAll() as $a) {
                $answers[(int) $a['question_id']] = $a;
            }

            $attempt['answers'] = $answers;
            $attempt['next'] = $this->nextDestination($pdo, $attempt, $userId);
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
        $selected = [];

        foreach ($questionIds as $questionId) {
            $value = $answers[$questionId] ?? $answers[(string) $questionId] ?? null;

            if ($value === null || $value === '') {
                throw new RuntimeException('Responda todas as questões antes de finalizar.');
            }

            $selected[$questionId] = (int) $value;
        }

        $pdo->beginTransaction();

        try {
            $correctCount = 0;

            $altStmt = $pdo->prepare(
                "SELECT id, is_correct
                 FROM alternatives
                 WHERE id = :alternative_id
                   AND question_id = :question_id
                 LIMIT 1"
            );

            $saveStmt = $pdo->prepare(
                "INSERT INTO quiz_answers
                    (attempt_id, question_id, alternative_id,
                     is_correct, points_awarded, answered_at)
                 VALUES
                    (:attempt_id, :question_id, :alternative_id,
                     :is_correct, :points, NOW())"
            );

            foreach ($questionIds as $questionId) {
                $altStmt->execute([
                    'alternative_id' => $selected[$questionId],
                    'question_id' => $questionId,
                ]);

                $alt = $altStmt->fetch();

                if (!$alt) {
                    throw new RuntimeException('Resposta inválida recebida.');
                }

                $correct = (int) $alt['is_correct'] === 1;
                if ($correct) {
                    $correctCount++;
                }

                $saveStmt->execute([
                    'attempt_id' => $attemptId,
                    'question_id' => $questionId,
                    'alternative_id' => $selected[$questionId],
                    'is_correct' => $correct ? 1 : 0,
                    'points' => $correct ? 1 : 0,
                ]);
            }

            $total = count($questionIds);
            $percentage = $total > 0
                ? round(($correctCount / $total) * 100, 2)
                : 0.0;

            $passed = $percentage >= (float) $attempt['required_score'];

            $pdo->prepare(
                "UPDATE quiz_attempts
                 SET finished_at = NOW(),
                     score = :score,
                     max_score = :max_score,
                     percentage = :percentage,
                     passed = :passed,
                     status = 'finished'
                 WHERE id = :attempt_id"
            )->execute([
                'score' => $correctCount,
                'max_score' => $total,
                'percentage' => $percentage,
                'passed' => $passed ? 1 : 0,
                'attempt_id' => $attemptId,
            ]);

            if ($passed) {
                $this->applyPass($pdo, $attempt, $userId, $percentage);
            } else {
                $this->applyFailure($pdo, $attempt, $userId, $percentage);
            }

            $pdo->commit();

            return [
                'passed' => $passed,
                'correct' => $correctCount,
                'percentage' => $percentage,
            ];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function assertEligible(PDO $pdo, array $quiz, int $userId): void
    {
        switch ($quiz['quiz_type']) {
            case 'lesson_fixation':
                $stmt = $pdo->prepare(
                    "SELECT status, progress_pct
                     FROM user_lesson_progress
                     WHERE user_id = :user_id
                       AND lesson_id = :lesson_id
                     LIMIT 1"
                );
                $stmt->execute([
                    'user_id' => $userId,
                    'lesson_id' => $quiz['lesson_id'],
                ]);
                $p = $stmt->fetch();

                if (!$p || $p['status'] === 'locked' || (float) $p['progress_pct'] < 100) {
                    throw new RuntimeException(
                        'Conclua a leitura da aula antes de iniciar os exercícios.'
                    );
                }
                break;

            case 'phase_exam':
                if (!$this->allLessonsComplete($pdo, $userId, (int) $quiz['phase_id'])) {
                    throw new RuntimeException(
                        'Conclua todas as aulas e exercícios da fase antes da prova.'
                    );
                }
                break;

            case 'module_boss':
                if (!$this->allPhasesComplete($pdo, $userId, (int) $quiz['module_id'])) {
                    throw new RuntimeException(
                        'Conclua todas as fases do módulo antes da avaliação final.'
                    );
                }
                break;

            default:
                throw new RuntimeException('Tipo de avaliação não permitido.');
        }
    }

    private function applyPass(
        PDO $pdo,
        array $attempt,
        int $userId,
        float $percentage
    ): void {
        $quizId = (int) $attempt['quiz_id'];

        if ($attempt['quiz_type'] === 'lesson_fixation') {
            $lessonId = (int) $attempt['lesson_id'];

            $pdo->prepare(
                "UPDATE user_lesson_progress
                 SET status = 'completed',
                     progress_pct = 100,
                     completed_at = COALESCE(completed_at, NOW()),
                     last_accessed_at = NOW()
                 WHERE user_id = :user_id
                   AND lesson_id = :lesson_id"
            )->execute([
                'user_id' => $userId,
                'lesson_id' => $lessonId,
            ]);

            $xpStmt = $pdo->prepare(
                "SELECT xp_reward FROM lessons WHERE id = :id LIMIT 1"
            );
            $xpStmt->execute(['id' => $lessonId]);

            $this->awardOnce(
                $pdo,
                $userId,
                'lesson_completed',
                $lessonId,
                (int) ($xpStmt->fetchColumn() ?: 0),
                'Aula concluída após exercícios'
            );

            $this->awardOnce(
                $pdo,
                $userId,
                'quiz_passed',
                $quizId,
                (int) ($attempt['xp_reward'] ?? 0),
                'Exercício de fixação aprovado'
            );

            $this->unlockNextLesson($pdo, $userId, $lessonId, (int) $attempt['phase_id']);
            $this->refreshPhaseProgress($pdo, $userId, (int) $attempt['phase_id']);
            return;
        }

        if ($attempt['quiz_type'] === 'phase_exam') {
            $phaseId = (int) $attempt['phase_id'];

            $pdo->prepare(
                "UPDATE user_phase_progress
                 SET status = 'completed',
                     best_score = GREATEST(best_score, :score),
                     attempts_count = attempts_count + 1,
                     progress_pct = 100,
                     completed_at = COALESCE(completed_at, NOW())
                 WHERE user_id = :user_id
                   AND phase_id = :phase_id"
            )->execute([
                'score' => $percentage,
                'user_id' => $userId,
                'phase_id' => $phaseId,
            ]);

            $xpStmt = $pdo->prepare(
                "SELECT xp_reward FROM phases WHERE id = :id LIMIT 1"
            );
            $xpStmt->execute(['id' => $phaseId]);

            $this->awardOnce(
                $pdo,
                $userId,
                'phase_completed',
                $phaseId,
                (int) ($xpStmt->fetchColumn() ?: 0),
                'Fase aprovada'
            );

            $this->awardOnce(
                $pdo,
                $userId,
                'quiz_passed',
                $quizId,
                (int) ($attempt['xp_reward'] ?? 0),
                'Checkpoint da fase aprovado'
            );

            // Se houver próxima fase, libera. Se for a última, PARA no módulo.
            // O próximo módulo só abre após module_boss.
            $this->unlockNextPhaseOnly($pdo, $userId, $phaseId, (int) $attempt['module_id']);
            $this->refreshModuleProgress($pdo, $userId, (int) $attempt['module_id']);
            return;
        }

        if ($attempt['quiz_type'] === 'module_boss') {
            $moduleId = (int) $attempt['module_id'];

            $pdo->prepare(
                "UPDATE user_module_progress
                 SET status = 'completed',
                     best_score = GREATEST(best_score, :score),
                     progress_pct = 100,
                     completed_at = COALESCE(completed_at, NOW())
                 WHERE user_id = :user_id
                   AND module_id = :module_id"
            )->execute([
                'score' => $percentage,
                'user_id' => $userId,
                'module_id' => $moduleId,
            ]);

            $this->awardOnce(
                $pdo,
                $userId,
                'module_completed',
                $moduleId,
                (int) ($attempt['xp_reward'] ?? 0),
                'Módulo aprovado na avaliação final'
            );

            $this->unlockNextModule(
                $pdo,
                $userId,
                $moduleId,
                (int) $attempt['course_id']
            );
        }
    }

    private function applyFailure(
        PDO $pdo,
        array $attempt,
        int $userId,
        float $percentage
    ): void {
        if ($attempt['quiz_type'] === 'phase_exam') {
            $pdo->prepare(
                "UPDATE user_phase_progress
                 SET attempts_count = attempts_count + 1,
                     best_score = GREATEST(best_score, :score),
                     status = IF(status = 'completed', 'completed', 'in_progress')
                 WHERE user_id = :user_id
                   AND phase_id = :phase_id"
            )->execute([
                'score' => $percentage,
                'user_id' => $userId,
                'phase_id' => $attempt['phase_id'],
            ]);
        }

        if ($attempt['quiz_type'] === 'module_boss') {
            $pdo->prepare(
                "UPDATE user_module_progress
                 SET best_score = GREATEST(best_score, :score),
                     status = IF(status = 'completed', 'completed', 'in_progress')
                 WHERE user_id = :user_id
                   AND module_id = :module_id"
            )->execute([
                'score' => $percentage,
                'user_id' => $userId,
                'module_id' => $attempt['module_id'],
            ]);
        }
    }

    private function allLessonsComplete(PDO $pdo, int $userId, int $phaseId): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(l.id) AS total,
                    SUM(CASE WHEN ulp.status = 'completed' THEN 1 ELSE 0 END) AS completed
             FROM lessons l
             LEFT JOIN user_lesson_progress ulp
               ON ulp.lesson_id = l.id
              AND ulp.user_id = :user_id
             WHERE l.phase_id = :phase_id
               AND l.status = 'published'"
        );
        $stmt->execute([
            'user_id' => $userId,
            'phase_id' => $phaseId,
        ]);
        $r = $stmt->fetch();

        return (int) ($r['total'] ?? 0) > 0
            && (int) $r['total'] === (int) ($r['completed'] ?? 0);
    }

    private function allPhasesComplete(PDO $pdo, int $userId, int $moduleId): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(p.id) AS total,
                    SUM(CASE WHEN upp.status = 'completed' THEN 1 ELSE 0 END) AS completed
             FROM phases p
             LEFT JOIN user_phase_progress upp
               ON upp.phase_id = p.id
              AND upp.user_id = :user_id
             WHERE p.module_id = :module_id
               AND p.status = 'published'"
        );
        $stmt->execute([
            'user_id' => $userId,
            'module_id' => $moduleId,
        ]);
        $r = $stmt->fetch();

        return (int) ($r['total'] ?? 0) > 0
            && (int) $r['total'] === (int) ($r['completed'] ?? 0);
    }

    private function unlockNextLesson(PDO $pdo, int $userId, int $lessonId, int $phaseId): void
    {
        $stmt = $pdo->prepare("SELECT position FROM lessons WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $lessonId]);
        $position = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT id
             FROM lessons
             WHERE phase_id = :phase_id
               AND status = 'published'
               AND position > :position
             ORDER BY position
             LIMIT 1"
        );
        $stmt->execute([
            'phase_id' => $phaseId,
            'position' => $position,
        ]);
        $next = (int) ($stmt->fetchColumn() ?: 0);

        if ($next > 0) {
            $this->unlockLesson($pdo, $userId, $next);
        }
    }

    private function unlockNextPhaseOnly(PDO $pdo, int $userId, int $phaseId, int $moduleId): void
    {
        $stmt = $pdo->prepare("SELECT position FROM phases WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $phaseId]);
        $position = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT id
             FROM phases
             WHERE module_id = :module_id
               AND status = 'published'
               AND position > :position
             ORDER BY position
             LIMIT 1"
        );
        $stmt->execute([
            'module_id' => $moduleId,
            'position' => $position,
        ]);
        $nextPhase = (int) ($stmt->fetchColumn() ?: 0);

        if ($nextPhase <= 0) {
            return;
        }

        $this->unlockPhase($pdo, $userId, $nextPhase);

        $stmt = $pdo->prepare(
            "SELECT id
             FROM lessons
             WHERE phase_id = :phase_id
               AND status = 'published'
             ORDER BY position
             LIMIT 1"
        );
        $stmt->execute(['phase_id' => $nextPhase]);
        $lesson = (int) ($stmt->fetchColumn() ?: 0);

        if ($lesson > 0) {
            $this->unlockLesson($pdo, $userId, $lesson);
        }
    }

    private function unlockNextModule(PDO $pdo, int $userId, int $moduleId, int $courseId): void
    {
        $stmt = $pdo->prepare("SELECT position FROM modules WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $moduleId]);
        $position = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT id
             FROM modules
             WHERE course_id = :course_id
               AND status = 'published'
               AND position > :position
             ORDER BY position
             LIMIT 1"
        );
        $stmt->execute([
            'course_id' => $courseId,
            'position' => $position,
        ]);
        $nextModule = (int) ($stmt->fetchColumn() ?: 0);

        if ($nextModule <= 0) {
            $pdo->prepare(
                "UPDATE user_course_progress
                 SET status = 'completed',
                     progress_pct = 100,
                     completed_at = COALESCE(completed_at, NOW()),
                     last_accessed_at = NOW()
                 WHERE user_id = :user_id
                   AND course_id = :course_id"
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
            'module_id' => $nextModule,
        ]);

        $stmt = $pdo->prepare(
            "SELECT id
             FROM phases
             WHERE module_id = :module_id
               AND status = 'published'
             ORDER BY position
             LIMIT 1"
        );
        $stmt->execute(['module_id' => $nextModule]);
        $phase = (int) ($stmt->fetchColumn() ?: 0);

        if ($phase > 0) {
            $this->unlockPhase($pdo, $userId, $phase);

            $stmt = $pdo->prepare(
                "SELECT id
                 FROM lessons
                 WHERE phase_id = :phase_id
                   AND status = 'published'
                 ORDER BY position
                 LIMIT 1"
            );
            $stmt->execute(['phase_id' => $phase]);
            $lesson = (int) ($stmt->fetchColumn() ?: 0);

            if ($lesson > 0) {
                $this->unlockLesson($pdo, $userId, $lesson);
            }
        }
    }

    private function unlockLesson(PDO $pdo, int $userId, int $lessonId): void
    {
        $pdo->prepare(
            "INSERT INTO user_lesson_progress
                (user_id, lesson_id, status, current_block, blocks_viewed, progress_pct)
             VALUES
                (:user_id, :lesson_id, 'available', 1, 0, 0)
             ON DUPLICATE KEY UPDATE
                status = IF(status = 'completed', 'completed',
                         IF(status = 'in_progress', 'in_progress', 'available'))"
        )->execute([
            'user_id' => $userId,
            'lesson_id' => $lessonId,
        ]);
    }

    private function unlockPhase(PDO $pdo, int $userId, int $phaseId): void
    {
        $pdo->prepare(
            "INSERT INTO user_phase_progress
                (user_id, phase_id, status, best_score,
                 attempts_count, progress_pct, unlocked_at)
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

    private function refreshPhaseProgress(PDO $pdo, int $userId, int $phaseId): void
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(l.id) total,
                    SUM(CASE WHEN ulp.status='completed' THEN 1 ELSE 0 END) completed
             FROM lessons l
             LEFT JOIN user_lesson_progress ulp
               ON ulp.lesson_id=l.id AND ulp.user_id=:user_id
             WHERE l.phase_id=:phase_id AND l.status='published'"
        );
        $stmt->execute(['user_id'=>$userId,'phase_id'=>$phaseId]);
        $r=$stmt->fetch();
        $total=(int)($r['total']??0);
        $done=(int)($r['completed']??0);
        $pct=$total?round(($done/$total)*100,2):0;

        $pdo->prepare(
            "UPDATE user_phase_progress
             SET progress_pct=:pct,
                 status=IF(status='completed','completed','in_progress'),
                 started_at=COALESCE(started_at,NOW())
             WHERE user_id=:user_id AND phase_id=:phase_id"
        )->execute(['pct'=>$pct,'user_id'=>$userId,'phase_id'=>$phaseId]);
    }

    private function refreshModuleProgress(PDO $pdo, int $userId, int $moduleId): void
    {
        $stmt=$pdo->prepare(
            "SELECT COUNT(p.id) total,
                    SUM(CASE WHEN upp.status='completed' THEN 1 ELSE 0 END) completed
             FROM phases p
             LEFT JOIN user_phase_progress upp
               ON upp.phase_id=p.id AND upp.user_id=:user_id
             WHERE p.module_id=:module_id AND p.status='published'"
        );
        $stmt->execute(['user_id'=>$userId,'module_id'=>$moduleId]);
        $r=$stmt->fetch();
        $total=(int)($r['total']??0);
        $done=(int)($r['completed']??0);
        $pct=$total?round(($done/$total)*100,2):0;

        $pdo->prepare(
            "UPDATE user_module_progress
             SET progress_pct=:pct,
                 status=IF(status='completed','completed','in_progress'),
                 started_at=COALESCE(started_at,NOW())
             WHERE user_id=:user_id AND module_id=:module_id"
        )->execute(['pct'=>$pct,'user_id'=>$userId,'module_id'=>$moduleId]);
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

        $stmt=$pdo->prepare(
            "SELECT id FROM xp_events
             WHERE user_id=:user_id
               AND event_type=:event_type
               AND reference_id=:reference_id
             LIMIT 1"
        );
        $stmt->execute([
            'user_id'=>$userId,
            'event_type'=>$eventType,
            'reference_id'=>$referenceId,
        ]);

        if ($stmt->fetchColumn()) {
            return;
        }

        $pdo->prepare(
            "INSERT INTO xp_events
                (user_id,event_type,reference_id,xp_amount,description)
             VALUES
                (:user_id,:event_type,:reference_id,:xp,:description)"
        )->execute([
            'user_id'=>$userId,
            'event_type'=>$eventType,
            'reference_id'=>$referenceId,
            'xp'=>$xp,
            'description'=>$description,
        ]);

        $pdo->prepare(
            "UPDATE users SET xp_total=xp_total+:xp WHERE id=:user_id"
        )->execute(['xp'=>$xp,'user_id'=>$userId]);
    }

    private function validateStructure(PDO $pdo, int $quizId, int $expected): void
    {
        $stmt=$pdo->prepare(
            "SELECT q.id,
                    COUNT(a.id) alternatives_count,
                    SUM(CASE WHEN a.is_correct=1 THEN 1 ELSE 0 END) correct_count
             FROM quiz_questions qq
             INNER JOIN questions q ON q.id=qq.question_id AND q.active=1
             LEFT JOIN alternatives a ON a.question_id=q.id
             WHERE qq.quiz_id=:quiz_id
             GROUP BY q.id"
        );
        $stmt->execute(['quiz_id'=>$quizId]);
        $rows=$stmt->fetchAll();

        if (count($rows)!==$expected) {
            throw new RuntimeException('Quantidade inválida de questões.');
        }

        foreach($rows as $row){
            if((int)$row['alternatives_count']<2){
                throw new RuntimeException('Existe questão sem alternativas suficientes.');
            }
            if((int)$row['correct_count']!==1){
                throw new RuntimeException('Cada questão precisa ter exatamente uma resposta correta.');
            }
        }
    }

    private function requiredCorrect(array $quiz): int
    {
        $count=max(1,(int)($quiz['question_limit']??1));
        $score=(float)($quiz['required_score']??100);
        return (int) ceil((($score * $count) / 100) - 1e-9);
    }

    private function typeLabel(string $type): string
    {
        return match($type){
            'lesson_fixation'=>'EXERCÍCIO DA AULA',
            'phase_exam'=>'PROVA DA FASE',
            'module_boss'=>'AVALIAÇÃO DO MÓDULO',
            default=>'AVALIAÇÃO',
        };
    }

    private function contextUrl(array $quiz): string
    {
        return match((string)$quiz['quiz_type']){
            'lesson_fixation'=>'/aula/'.(int)$quiz['lesson_id'],
            'phase_exam'=>'/fase/'.(int)$quiz['phase_id'],
            'module_boss'=>'/modulo/'.(int)$quiz['module_id'],
            default=>'/dashboard',
        };
    }

    private function nextDestination(PDO $pdo, array $a, int $userId): array
    {
        if((int)$a['passed']!==1){
            return ['url'=>$a['context_url'],'label'=>'Revisar conteúdo'];
        }

        if($a['quiz_type']==='lesson_fixation'){
            $stmt=$pdo->prepare(
                "SELECT l2.id
                 FROM lessons l1
                 INNER JOIN lessons l2
                   ON l2.phase_id=l1.phase_id
                  AND l2.status='published'
                  AND l2.position>l1.position
                 WHERE l1.id=:lesson_id
                 ORDER BY l2.position
                 LIMIT 1"
            );
            $stmt->execute(['lesson_id'=>$a['lesson_id']]);
            $next=(int)($stmt->fetchColumn()?:0);
            if($next>0){
                return ['url'=>'/aula/'.$next,'label'=>'Próxima aula →'];
            }

            $stmt=$pdo->prepare(
                "SELECT id FROM quizzes
                 WHERE phase_id=:phase_id
                   AND quiz_type='phase_exam'
                   AND active=1
                 ORDER BY id LIMIT 1"
            );
            $stmt->execute(['phase_id'=>$a['phase_id']]);
            $quiz=(int)($stmt->fetchColumn()?:0);
            return $quiz>0
                ? ['url'=>'/prova/'.$quiz,'label'=>'Fazer prova da fase →']
                : ['url'=>'/fase/'.(int)$a['phase_id'],'label'=>'Voltar à fase'];
        }

        if($a['quiz_type']==='phase_exam'){
            $stmt=$pdo->prepare(
                "SELECT p2.id
                 FROM phases p1
                 INNER JOIN phases p2
                   ON p2.module_id=p1.module_id
                  AND p2.status='published'
                  AND p2.position>p1.position
                 WHERE p1.id=:phase_id
                 ORDER BY p2.position LIMIT 1"
            );
            $stmt->execute(['phase_id'=>$a['phase_id']]);
            $nextPhase=(int)($stmt->fetchColumn()?:0);

            if($nextPhase>0){
                $stmt=$pdo->prepare(
                    "SELECT id FROM lessons
                     WHERE phase_id=:phase_id AND status='published'
                     ORDER BY position LIMIT 1"
                );
                $stmt->execute(['phase_id'=>$nextPhase]);
                $lesson=(int)($stmt->fetchColumn()?:0);
                return $lesson>0
                    ? ['url'=>'/aula/'.$lesson,'label'=>'Próxima fase →']
                    : ['url'=>'/fase/'.$nextPhase,'label'=>'Próxima fase →'];
            }

            $stmt=$pdo->prepare(
                "SELECT id FROM quizzes
                 WHERE module_id=:module_id
                   AND quiz_type='module_boss'
                   AND active=1
                 ORDER BY id LIMIT 1"
            );
            $stmt->execute(['module_id'=>$a['module_id']]);
            $boss=(int)($stmt->fetchColumn()?:0);
            return $boss>0
                ? ['url'=>'/prova/'.$boss,'label'=>'Avaliação do módulo →']
                : ['url'=>'/modulo/'.(int)$a['module_id'],'label'=>'Voltar ao módulo'];
        }

        return [
            'url'=>'/curso/'.(int)$a['course_id'],
            'label'=>'Continuar no curso →'
        ];
    }
}
