<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class ProgressService
{
    public function registerBlockView(int $userId, int $lessonId, int $blockId, int $currentBlock): void
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT id
             FROM lesson_blocks
             WHERE id = :block_id AND lesson_id = :lesson_id
             LIMIT 1"
        );
        $stmt->execute([
            'block_id' => $blockId,
            'lesson_id' => $lessonId,
        ]);

        if (!$stmt->fetchColumn()) {
            return;
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
        $pct = $required > 0 ? min(100, round(($viewed / $required) * 100, 2)) : 100;

        $pdo->prepare(
            "INSERT INTO user_lesson_progress
                (user_id, lesson_id, status, current_block, blocks_viewed, progress_pct, started_at, last_accessed_at)
             VALUES
                (:user_id, :lesson_id, 'in_progress', :current_block, :blocks_viewed, :progress_pct, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                status = IF(status = 'completed', 'completed', 'in_progress'),
                current_block = VALUES(current_block),
                blocks_viewed = VALUES(blocks_viewed),
                progress_pct = IF(status = 'completed', 100, VALUES(progress_pct)),
                started_at = COALESCE(started_at, NOW()),
                last_accessed_at = NOW()"
        )->execute([
            'user_id' => $userId,
            'lesson_id' => $lessonId,
            'current_block' => $currentBlock,
            'blocks_viewed' => $viewed,
            'progress_pct' => $pct,
        ]);
    }

    public function completeLesson(int $userId, int $lessonId): array
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT l.id, l.phase_id, l.position, l.is_required AS lesson_required,
                    l.xp_reward, p.module_id, p.is_required AS phase_required
             FROM lessons l
             INNER JOIN phases p ON p.id = l.phase_id
             WHERE l.id = :lesson_id
             LIMIT 1"
        );
        $stmt->execute(['lesson_id' => $lessonId]);
        $lesson = $stmt->fetch();

        if (!$lesson) {
            throw new RuntimeException('Aula não encontrada.');
        }

        $progressStmt = $pdo->prepare(
            "SELECT status
             FROM user_lesson_progress
             WHERE user_id = :user_id AND lesson_id = :lesson_id
             LIMIT 1"
        );
        $progressStmt->execute([
            'user_id' => $userId,
            'lesson_id' => $lessonId,
        ]);
        $oldStatus = $progressStmt->fetchColumn();

        if (!$oldStatus || $oldStatus === 'locked') {
            throw new RuntimeException('Esta aula ainda está bloqueada.');
        }

        [$viewed, $required] = $this->blockCounts($pdo, $userId, $lessonId);

        if ($required > 0 && $viewed < $required) {
            throw new RuntimeException("Conclua as {$required} telas obrigatórias antes de finalizar.");
        }

        $pdo->beginTransaction();

        try {
            $pdo->prepare(
                "UPDATE user_lesson_progress
                 SET status = 'completed', progress_pct = 100,
                     blocks_viewed = :viewed,
                     completed_at = COALESCE(completed_at, NOW()),
                     last_accessed_at = NOW()
                 WHERE user_id = :user_id AND lesson_id = :lesson_id"
            )->execute([
                'viewed' => max($viewed, $required),
                'user_id' => $userId,
                'lesson_id' => $lessonId,
            ]);

            $optional = (int) $lesson['phase_required'] === 0 || (int) $lesson['lesson_required'] === 0;

            if ($optional) {
                $pdo->commit();
                return [
                    'optional' => true,
                    'phase_id' => (int) $lesson['phase_id'],
                ];
            }

            if ($oldStatus !== 'completed' && (int) $lesson['xp_reward'] > 0) {
                $this->awardLessonXpOnce($pdo, $userId, $lessonId, (int) $lesson['xp_reward']);
            }

            $nextStmt = $pdo->prepare(
                "SELECT id
                 FROM lessons
                 WHERE phase_id = :phase_id
                   AND status = 'published'
                   AND is_required = 1
                   AND position > :position
                 ORDER BY position
                 LIMIT 1"
            );
            $nextStmt->execute([
                'phase_id' => $lesson['phase_id'],
                'position' => $lesson['position'],
            ]);
            $nextLessonId = (int) ($nextStmt->fetchColumn() ?: 0);

            if ($nextLessonId > 0) {
                $this->unlockLesson($pdo, $userId, $nextLessonId);
                $this->updatePhaseReadingPct($pdo, $userId, (int) $lesson['phase_id']);
                $pdo->commit();

                return [
                    'optional' => false,
                    'next_lesson_id' => $nextLessonId,
                    'phase_id' => (int) $lesson['phase_id'],
                ];
            }

            $this->updatePhaseReadingPct($pdo, $userId, (int) $lesson['phase_id']);

            if (!$this->requiredLessonsComplete($pdo, $userId, (int) $lesson['phase_id'])) {
                $pdo->commit();
                return [
                    'optional' => false,
                    'phase_id' => (int) $lesson['phase_id'],
                ];
            }

            $quizStmt = $pdo->prepare(
                "SELECT id
                 FROM quizzes
                 WHERE phase_id = :phase_id
                   AND active = 1
                   AND quiz_type = 'phase_exam'
                 ORDER BY id
                 LIMIT 1"
            );
            $quizStmt->execute(['phase_id' => $lesson['phase_id']]);
            $quizId = (int) ($quizStmt->fetchColumn() ?: 0);

            /*
             * REGRA PEDAGÓGICA:
             * terminar a leitura NÃO conclui a fase.
             * A fase só é concluída pelo QuizService após 4/5 acertos.
             */
            $pdo->commit();

            if ($quizId > 0) {
                return [
                    'optional' => false,
                    'phase_id' => (int) $lesson['phase_id'],
                    'quiz_id' => $quizId,
                ];
            }

            return [
                'optional' => false,
                'phase_id' => (int) $lesson['phase_id'],
                'quiz_missing' => true,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function awardLessonXpOnce(PDO $pdo, int $userId, int $lessonId, int $xp): void
    {
        $exists = $pdo->prepare(
            "SELECT id
             FROM xp_events
             WHERE user_id = :user_id
               AND event_type = 'lesson_completed'
               AND reference_id = :lesson_id
             LIMIT 1"
        );
        $exists->execute([
            'user_id' => $userId,
            'lesson_id' => $lessonId,
        ]);

        if ($exists->fetchColumn()) {
            return;
        }

        $pdo->prepare(
            "INSERT INTO xp_events
                (user_id, event_type, reference_id, xp_amount, description)
             VALUES
                (:user_id, 'lesson_completed', :lesson_id, :xp, :description)"
        )->execute([
            'user_id' => $userId,
            'lesson_id' => $lessonId,
            'xp' => $xp,
            'description' => 'Conclusão da aula #' . $lessonId,
        ]);

        $pdo->prepare('UPDATE users SET xp_total = xp_total + :xp WHERE id = :user_id')
            ->execute(['xp' => $xp, 'user_id' => $userId]);
    }

    private function unlockLesson(PDO $pdo, int $userId, int $lessonId): void
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

    private function updatePhaseReadingPct(PDO $pdo, int $userId, int $phaseId): void
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

        $pct = (int) $row['total'] > 0
            ? round(((int) $row['completed'] / (int) $row['total']) * 100, 2)
            : 0;

        /*
         * 100% aqui significa leitura concluída, não fase aprovada.
         * O status permanece in_progress até aprovação no checkpoint.
         */
        $pdo->prepare(
            "UPDATE user_phase_progress
             SET status = IF(status = 'completed', 'completed', 'in_progress'),
                 progress_pct = :pct,
                 started_at = COALESCE(started_at, NOW())
             WHERE user_id = :user_id AND phase_id = :phase_id"
        )->execute([
            'pct' => $pct,
            'user_id' => $userId,
            'phase_id' => $phaseId,
        ]);
    }

    private function blockCounts(PDO $pdo, int $userId, int $lessonId): array
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(lb.id) AS required_count,
                    COUNT(v.id) AS viewed_count
             FROM lesson_blocks lb
             LEFT JOIN user_lesson_block_views v
               ON v.lesson_block_id = lb.id AND v.user_id = :user_id
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
