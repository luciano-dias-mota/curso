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
            return;
        }

        $pdo->beginTransaction();

        try {
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
                "INSERT INTO user_lesson_progress
                    (user_id, lesson_id, status, current_block,
                     blocks_viewed, progress_pct, started_at, last_accessed_at)
                 VALUES
                    (:user_id, :lesson_id, 'in_progress', :current_block,
                     :blocks_viewed, :progress_pct, NOW(), NOW())
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

        $lessonStmt = $pdo->prepare(
            "SELECT l.id, l.phase_id
             FROM lessons l
             WHERE l.id = :lesson_id
               AND l.status = 'published'
             LIMIT 1"
        );
        $lessonStmt->execute(['lesson_id' => $lessonId]);
        $lesson = $lessonStmt->fetch();

        if (!$lesson) {
            throw new RuntimeException('Aula não encontrada.');
        }

        $statusStmt = $pdo->prepare(
            "SELECT status
             FROM user_lesson_progress
             WHERE user_id = :user_id
               AND lesson_id = :lesson_id
             LIMIT 1"
        );
        $statusStmt->execute([
            'user_id' => $userId,
            'lesson_id' => $lessonId,
        ]);
        $status = $statusStmt->fetchColumn();

        if (!$status || $status === 'locked') {
            throw new RuntimeException('Esta aula ainda está bloqueada.');
        }

        [$viewed, $required] = $this->blockCounts($pdo, $userId, $lessonId);

        if ($required > 0 && $viewed < $required) {
            throw new RuntimeException(
                "Você ainda não visualizou todas as telas obrigatórias ({$viewed}/{$required})."
            );
        }

        // A leitura chega a 100%, mas a aula continua in_progress.
        // Somente a aprovação no lesson_fixation muda para completed.
        $pdo->prepare(
            "UPDATE user_lesson_progress
             SET status = IF(status = 'completed', 'completed', 'in_progress'),
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
            throw new RuntimeException(
                'O exercício desta aula ainda não foi configurado.'
            );
        }

        if ((int) $quiz['question_count'] !== (int) $quiz['question_limit']) {
            throw new RuntimeException(
                'O exercício desta aula ainda está incompleto: '
                . (int) $quiz['question_count'] . '/'
                . (int) $quiz['question_limit'] . ' questões.'
            );
        }

        return [
            'phase_id' => (int) $lesson['phase_id'],
            'quiz_id' => (int) $quiz['id'],
        ];
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
