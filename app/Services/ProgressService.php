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
        $lesson = $this->lessonAccess($pdo, $userId, $lessonId);

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

        return [
            'phase_id' => (int) $lesson['phase_id'],
            'quiz_id' => (int) $quiz['id'],
            'optional' => false,
        ];
    }

    private function lessonAccess(PDO $pdo, int $userId, int $lessonId, bool $forUpdate = false): array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';

        $stmt = $pdo->prepare(
            "SELECT l.id,
                    l.phase_id,
                    l.is_required AS lesson_required,
                    p.is_required AS phase_required,
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
