<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class ProgressService
{
    public function registerBlockView(
        int $userId,
        int $lessonId,
        int $blockId,
        int $currentBlock
    ): void {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $lesson = $this->lessonAccess($pdo, $userId, $lessonId, true);

            if ($lesson['status'] === 'locked') {
                throw new RuntimeException('Esta aula ainda está bloqueada.');
            }

            $stmt = $pdo->prepare(
                "SELECT id
                 FROM lesson_blocks
                 WHERE id = :block_id
                   AND lesson_id = :lesson_id
                 LIMIT 1"
            );
            $stmt->execute([
                'block_id' => $blockId,
                'lesson_id' => $lessonId,
            ]);

            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('Bloco de conteúdo inválido para esta aula.');
            }

            $pdo->prepare(
                "INSERT INTO user_lesson_block_views
                    (user_id, lesson_block_id, first_viewed_at, last_viewed_at, view_count)
                 VALUES
                    (:user_id, :block_id, NOW(), NOW(), 1)
                 ON DUPLICATE KEY UPDATE
                    last_viewed_at = NOW(),
                    view_count = view_count + 1"
            )->execute([
                'user_id' => $userId,
                'block_id' => $blockId,
            ]);

            [$viewed, $required] = $this->blockCounts($pdo, $userId, $lessonId);
            $pct = $required > 0
                ? min(100, round(($viewed / $required) * 100, 2))
                : 100;

            $pdo->prepare(
                "UPDATE user_lesson_progress
                 SET status = CASE
                        WHEN status = 'completed' THEN 'completed'
                        WHEN status IN ('available', 'in_progress') THEN 'in_progress'
                        ELSE status
                     END,
                     current_block = :current_block,
                     blocks_viewed = :blocks_viewed,
                     progress_pct = CASE
                        WHEN status = 'completed' THEN 100
                        ELSE :progress_pct
                     END,
                     started_at = COALESCE(started_at, NOW()),
                     last_accessed_at = NOW()
                 WHERE user_id = :user_id
                   AND lesson_id = :lesson_id"
            )->execute([
                'current_block' => $currentBlock,
                'blocks_viewed' => $viewed,
                'progress_pct' => $pct,
                'user_id' => $userId,
                'lesson_id' => $lessonId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function finishReading(int $userId, int $lessonId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $lesson = $this->lessonAccess($pdo, $userId, $lessonId, true);

            if ($lesson['status'] === 'locked') {
                throw new RuntimeException('Esta aula ainda está bloqueada.');
            }

            [$viewed, $required] = $this->blockCounts($pdo, $userId, $lessonId);

            if ($required > 0 && $viewed < $required) {
                throw new RuntimeException(
                    "Você ainda não visualizou todas as telas obrigatórias ({$viewed}/{$required})."
                );
            }

            $isOptional = (int) $lesson['lesson_required'] === 0
                || (int) $lesson['phase_required'] === 0;

            if ($isOptional) {
                $pdo->prepare(
                    "UPDATE user_lesson_progress
                     SET status = 'completed',
                         progress_pct = 100,
                         blocks_viewed = GREATEST(blocks_viewed, :blocks_viewed),
                         completed_at = COALESCE(completed_at, NOW()),
                         last_accessed_at = NOW()
                     WHERE user_id = :user_id
                       AND lesson_id = :lesson_id
                       AND status <> 'locked'"
                )->execute([
                    'blocks_viewed' => max($viewed, $required),
                    'user_id' => $userId,
                    'lesson_id' => $lessonId,
                ]);

                $pdo->commit();

                return [
                    'phase_id' => (int) $lesson['phase_id'],
                    'quiz_id' => null,
                    'optional' => true,
                ];
            }

            // A leitura obrigatória chega a 100%, mas a aula só vira completed
            // após aprovação no exercício lesson_fixation.
            $pdo->prepare(
                "UPDATE user_lesson_progress
                 SET status = CASE
                        WHEN status = 'completed' THEN 'completed'
                        WHEN status IN ('available', 'in_progress') THEN 'in_progress'
                        ELSE status
                     END,
                     progress_pct = 100,
                     blocks_viewed = :blocks_viewed,
                     last_accessed_at = NOW()
                 WHERE user_id = :user_id
                   AND lesson_id = :lesson_id"
            )->execute([
                'blocks_viewed' => max($viewed, $required),
                'user_id' => $userId,
                'lesson_id' => $lessonId,
            ]);

            $completionMode = $this->completionMode($pdo, $lessonId);

            if ($completionMode === 'reading') {
                $nextLessonId = $this->completeByReading(
                    $pdo,
                    $userId,
                    $lessonId,
                    (int) $lesson['phase_id'],
                    (int) $lesson['module_id'],
                    (int) $lesson['course_id']
                );

                $pdo->commit();

                return [
                    'phase_id' => (int) $lesson['phase_id'],
                    'quiz_id' => null,
                    'optional' => false,
                    'next_step' => 'completed',
                    'next_lesson_id' => $nextLessonId,
                ];
            }

            $quizStmt = $pdo->prepare(
                "SELECT qz.id, qz.question_limit, COUNT(qq.question_id) AS question_count
                 FROM quizzes qz
                 LEFT JOIN quiz_questions qq ON qq.quiz_id = qz.id
                 WHERE qz.lesson_id = :lesson_id
                   AND qz.quiz_type = 'lesson_fixation'
                   AND qz.active = 1
                 GROUP BY qz.id
                 ORDER BY qz.id
                 LIMIT 1"
            );
            $quizStmt->execute(['lesson_id' => $lessonId]);
            $quiz = $quizStmt->fetch();

            if (!$quiz) {
                throw new RuntimeException('O exercício desta aula ainda não foi configurado.');
            }

            if ((int) $quiz['question_count'] !== (int) $quiz['question_limit']) {
                throw new RuntimeException(
                    'O exercício desta aula ainda está incompleto: '
                    . (int) $quiz['question_count'] . '/'
                    . (int) $quiz['question_limit'] . ' questões.'
                );
            }

            $result = [
                'phase_id' => (int) $lesson['phase_id'],
                'quiz_id' => (int) $quiz['id'],
                'optional' => false,
            ];

            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    private function lessonAccess(PDO $pdo, int $userId, int $lessonId, bool $forUpdate = false): array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';

        $stmt = $pdo->prepare(
            "SELECT l.id,
                    l.phase_id,
                    l.is_required AS lesson_required,
                    p.is_required AS phase_required,
                    m.id AS module_id,
                    m.course_id,
                    ulp.status
             FROM lessons l
             INNER JOIN phases p
               ON p.id = l.phase_id
              AND p.status = 'published'
             INNER JOIN modules m
               ON m.id = p.module_id
              AND m.status = 'published'
             INNER JOIN courses c
               ON c.id = m.course_id
              AND c.status = 'published'
             INNER JOIN enrollments e
               ON e.course_id = c.id
              AND e.user_id = :enrollment_user_id
              AND e.status = 'active'
             INNER JOIN user_module_progress ump
               ON ump.module_id = m.id
              AND ump.user_id = :module_user_id
              AND ump.status <> 'locked'
             INNER JOIN user_phase_progress upp
               ON upp.phase_id = p.id
              AND upp.user_id = :phase_user_id
              AND upp.status <> 'locked'
             INNER JOIN user_lesson_progress ulp
               ON ulp.lesson_id = l.id
              AND ulp.user_id = :lesson_user_id
             WHERE l.id = :lesson_id
               AND l.status = 'published'
             LIMIT 1{$lock}"
        );
        $stmt->execute([
            'enrollment_user_id' => $userId,
            'module_user_id' => $userId,
            'phase_user_id' => $userId,
            'lesson_user_id' => $userId,
            'lesson_id' => $lessonId,
        ]);

        $lesson = $stmt->fetch();

        if (!$lesson) {
            throw new RuntimeException('Aula indisponível para sua matrícula.');
        }

        return $lesson;
    }

    private function completionMode(PDO $pdo, int $lessonId): string
    {
        $column = $pdo->prepare(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'lessons'
               AND COLUMN_NAME = 'completion_mode'
             LIMIT 1"
        );
        $column->execute();

        if (!$column->fetchColumn()) {
            return 'quiz';
        }

        $stmt = $pdo->prepare(
            "SELECT completion_mode
             FROM lessons
             WHERE id = :lesson_id
             LIMIT 1"
        );
        $stmt->execute(['lesson_id' => $lessonId]);

        $mode = trim((string) ($stmt->fetchColumn() ?: 'quiz'));

        return in_array($mode, ['reading', 'quiz'], true)
            ? $mode
            : 'quiz';
    }

    private function completeByReading(
        PDO $pdo,
        int $userId,
        int $lessonId,
        int $phaseId,
        int $moduleId,
        int $courseId
    ): ?int {
        $pdo->prepare(
            "UPDATE user_lesson_progress
             SET status = 'completed',
                 progress_pct = 100,
                 completed_at = COALESCE(completed_at, NOW()),
                 last_accessed_at = NOW()
             WHERE user_id = :user_id
               AND lesson_id = :lesson_id
               AND status <> 'locked'"
        )->execute([
            'user_id' => $userId,
            'lesson_id' => $lessonId,
        ]);

        $next = $pdo->prepare(
            "SELECT l2.id
             FROM lessons l1
             INNER JOIN lessons l2
               ON l2.phase_id = l1.phase_id
              AND l2.status = 'published'
              AND l2.is_required = 1
              AND l2.position > l1.position
             WHERE l1.id = :lesson_id
             ORDER BY l2.position, l2.id
             LIMIT 1"
        );
        $next->execute(['lesson_id' => $lessonId]);
        $nextLessonId = (int) ($next->fetchColumn() ?: 0);

        if ($nextLessonId > 0) {
            $pdo->prepare(
                "INSERT INTO user_lesson_progress
                    (user_id, lesson_id, status, current_block, blocks_viewed, progress_pct)
                 VALUES
                    (:user_id, :lesson_id, 'available', 1, 0, 0)
                 ON DUPLICATE KEY UPDATE
                    status = CASE
                        WHEN status = 'completed' THEN 'completed'
                        WHEN status = 'in_progress' THEN 'in_progress'
                        ELSE 'available'
                    END"
            )->execute([
                'user_id' => $userId,
                'lesson_id' => $nextLessonId,
            ]);
        }

        $phaseStats = $pdo->prepare(
            "SELECT COUNT(l.id) AS total,
                    SUM(CASE WHEN ulp.status = 'completed' THEN 1 ELSE 0 END) AS completed
             FROM lessons l
             LEFT JOIN user_lesson_progress ulp
               ON ulp.lesson_id = l.id
              AND ulp.user_id = :user_id
             WHERE l.phase_id = :phase_id
               AND l.status = 'published'
               AND l.is_required = 1"
        );
        $phaseStats->execute([
            'user_id' => $userId,
            'phase_id' => $phaseId,
        ]);
        $phaseRow = $phaseStats->fetch() ?: [];
        $phaseTotal = (int) ($phaseRow['total'] ?? 0);
        $phaseCompleted = (int) ($phaseRow['completed'] ?? 0);
        $phasePct = $phaseTotal > 0
            ? round(($phaseCompleted / $phaseTotal) * 100, 2)
            : 0;

        $pdo->prepare(
            "UPDATE user_phase_progress
             SET progress_pct = :progress_pct,
                 status = CASE
                    WHEN status = 'completed' THEN 'completed'
                    WHEN :completed > 0 THEN 'in_progress'
                    ELSE status
                 END,
                 started_at = CASE
                    WHEN :completed2 > 0 THEN COALESCE(started_at, NOW())
                    ELSE started_at
                 END
             WHERE user_id = :user_id
               AND phase_id = :phase_id"
        )->execute([
            'progress_pct' => $phasePct,
            'completed' => $phaseCompleted,
            'completed2' => $phaseCompleted,
            'user_id' => $userId,
            'phase_id' => $phaseId,
        ]);

        $phaseExam = $pdo->prepare(
            "SELECT 1
             FROM quizzes
             WHERE phase_id = :phase_id
               AND quiz_type = 'phase_exam'
               AND active = 1
             LIMIT 1"
        );
        $phaseExam->execute(['phase_id' => $phaseId]);

        if (
            $phaseTotal > 0
            && $phaseCompleted === $phaseTotal
            && !$phaseExam->fetchColumn()
        ) {
            $pdo->prepare(
                "UPDATE user_phase_progress
                 SET status = 'completed',
                     progress_pct = 100,
                     completed_at = COALESCE(completed_at, NOW())
                 WHERE user_id = :user_id
                   AND phase_id = :phase_id"
            )->execute([
                'user_id' => $userId,
                'phase_id' => $phaseId,
            ]);
        }

        $moduleStats = $pdo->prepare(
            "SELECT COUNT(p.id) AS total,
                    SUM(CASE WHEN upp.status = 'completed' THEN 1 ELSE 0 END) AS completed
             FROM phases p
             LEFT JOIN user_phase_progress upp
               ON upp.phase_id = p.id
              AND upp.user_id = :user_id
             WHERE p.module_id = :module_id
               AND p.status = 'published'
               AND p.is_required = 1"
        );
        $moduleStats->execute([
            'user_id' => $userId,
            'module_id' => $moduleId,
        ]);
        $moduleRow = $moduleStats->fetch() ?: [];
        $moduleTotal = (int) ($moduleRow['total'] ?? 0);
        $moduleCompleted = (int) ($moduleRow['completed'] ?? 0);
        $modulePct = $moduleTotal > 0
            ? round(($moduleCompleted / $moduleTotal) * 100, 2)
            : 0;

        $pdo->prepare(
            "UPDATE user_module_progress
             SET progress_pct = :progress_pct,
                 status = CASE
                    WHEN status = 'completed' THEN 'completed'
                    WHEN :completed > 0 THEN 'in_progress'
                    ELSE status
                 END,
                 started_at = CASE
                    WHEN :completed2 > 0 THEN COALESCE(started_at, NOW())
                    ELSE started_at
                 END
             WHERE user_id = :user_id
               AND module_id = :module_id"
        )->execute([
            'progress_pct' => $modulePct,
            'completed' => $moduleCompleted,
            'completed2' => $moduleCompleted,
            'user_id' => $userId,
            'module_id' => $moduleId,
        ]);

        $moduleBoss = $pdo->prepare(
            "SELECT 1
             FROM quizzes
             WHERE module_id = :module_id
               AND quiz_type = 'module_boss'
               AND active = 1
             LIMIT 1"
        );
        $moduleBoss->execute(['module_id' => $moduleId]);

        if (
            $moduleTotal > 0
            && $moduleCompleted === $moduleTotal
            && !$moduleBoss->fetchColumn()
        ) {
            $pdo->prepare(
                "UPDATE user_module_progress
                 SET status = 'completed',
                     progress_pct = 100,
                     completed_at = COALESCE(completed_at, NOW())
                 WHERE user_id = :user_id
                   AND module_id = :module_id"
            )->execute([
                'user_id' => $userId,
                'module_id' => $moduleId,
            ]);
        }

        (new ProgressInitializer())->initializeEnrollment(
            $userId,
            $courseId,
            $pdo
        );

        $courseStats = $pdo->prepare(
            "SELECT COUNT(m.id) AS total,
                    SUM(CASE WHEN ump.status = 'completed' THEN 1 ELSE 0 END) AS completed
             FROM modules m
             LEFT JOIN user_module_progress ump
               ON ump.module_id = m.id
              AND ump.user_id = :user_id
             WHERE m.course_id = :course_id
               AND m.status = 'published'"
        );
        $courseStats->execute([
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);
        $courseRow = $courseStats->fetch() ?: [];
        $courseTotal = (int) ($courseRow['total'] ?? 0);
        $courseCompleted = (int) ($courseRow['completed'] ?? 0);
        $coursePct = $courseTotal > 0
            ? round(($courseCompleted / $courseTotal) * 100, 2)
            : 0;

        $pdo->prepare(
            "UPDATE user_course_progress
             SET progress_pct = :progress_pct,
                 status = CASE
                    WHEN :all_completed = 1 THEN 'completed'
                    WHEN :completed > 0 THEN 'in_progress'
                    ELSE status
                 END,
                 started_at = CASE
                    WHEN :completed2 > 0 THEN COALESCE(started_at, NOW())
                    ELSE started_at
                 END,
                 completed_at = CASE
                    WHEN :all_completed2 = 1 THEN COALESCE(completed_at, NOW())
                    ELSE completed_at
                 END,
                 last_accessed_at = NOW()
             WHERE user_id = :user_id
               AND course_id = :course_id"
        )->execute([
            'progress_pct' => $coursePct,
            'all_completed' => $courseTotal > 0 && $courseCompleted === $courseTotal ? 1 : 0,
            'completed' => $courseCompleted,
            'completed2' => $courseCompleted,
            'all_completed2' => $courseTotal > 0 && $courseCompleted === $courseTotal ? 1 : 0,
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);

        return $this->nextPublishedLessonId(
            $pdo,
            $userId,
            $courseId,
            $lessonId
        );
    }

    private function nextPublishedLessonId(
        PDO $pdo,
        int $userId,
        int $courseId,
        int $currentLessonId
    ): ?int {
        $stmt = $pdo->prepare(
            "SELECT l.id
             FROM lessons l
             INNER JOIN phases p
               ON p.id = l.phase_id
              AND p.status = 'published'
             INNER JOIN modules m
               ON m.id = p.module_id
              AND m.status = 'published'
             INNER JOIN user_lesson_progress ulp
               ON ulp.lesson_id = l.id
              AND ulp.user_id = :user_id
              AND ulp.status <> 'locked'
             INNER JOIN lessons current_l
               ON current_l.id = :current_lesson_id
             INNER JOIN phases current_p
               ON current_p.id = current_l.phase_id
             INNER JOIN modules current_m
               ON current_m.id = current_p.module_id
             WHERE m.course_id = :course_id
               AND l.status = 'published'
               AND (
                    m.position > current_m.position
                    OR (
                        m.position = current_m.position
                        AND p.position > current_p.position
                    )
                    OR (
                        m.position = current_m.position
                        AND p.position = current_p.position
                        AND l.position > current_l.position
                    )
               )
             ORDER BY m.position, p.position, l.position, l.id
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => $userId,
            'current_lesson_id' => $currentLessonId,
            'course_id' => $courseId,
        ]);

        $nextLessonId = (int) ($stmt->fetchColumn() ?: 0);

        return $nextLessonId > 0 ? $nextLessonId : null;
    }

    private function blockCounts(PDO $pdo, int $userId, int $lessonId): array
    {
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(lb.id) AS required_count,
                COUNT(v.id) AS viewed_count
             FROM lesson_blocks lb
             LEFT JOIN user_lesson_block_views v
               ON v.lesson_block_id = lb.id
              AND v.user_id = :user_id
             WHERE lb.lesson_id = :lesson_id
               AND lb.is_required = 1"
        );
        $stmt->execute([
            'user_id' => $userId,
            'lesson_id' => $lessonId,
        ]);

        $row = $stmt->fetch();

        return [
            (int) ($row['viewed_count'] ?? 0),
            (int) ($row['required_count'] ?? 0),
        ];
    }
}
