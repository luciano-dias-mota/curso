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
            "SELECT lb.id
             FROM lesson_blocks lb
             WHERE lb.id = :block_id
               AND lb.lesson_id = :lesson_id
             LIMIT 1"
        );
        $stmt->execute([
            'block_id' => $blockId,
            'lesson_id' => $lessonId,
        ]);

        if (!$stmt->fetch()) {
            return;
        }

        $pdo->beginTransaction();

        try {
            $view = $pdo->prepare(
                "INSERT INTO user_lesson_block_views
                    (user_id, lesson_block_id, first_viewed_at, last_viewed_at, view_count)
                 VALUES
                    (:user_id, :block_id, NOW(), NOW(), 1)
                 ON DUPLICATE KEY UPDATE
                    last_viewed_at = NOW(),
                    view_count = view_count + 1"
            );
            $view->execute([
                'user_id' => $userId,
                'block_id' => $blockId,
            ]);

            [$viewed, $required] = $this->blockCounts($pdo, $userId, $lessonId);

            $pct = $required > 0
                ? min(100, round(($viewed / $required) * 100, 2))
                : 100;

            $progress = $pdo->prepare(
                "INSERT INTO user_lesson_progress
                    (
                        user_id, lesson_id, status, current_block,
                        blocks_viewed, progress_pct, started_at, last_accessed_at
                    )
                 VALUES
                    (
                        :user_id, :lesson_id, 'in_progress', :current_block,
                        :blocks_viewed, :progress_pct, NOW(), NOW()
                    )
                 ON DUPLICATE KEY UPDATE
                    status = CASE
                        WHEN status = 'completed' THEN 'completed'
                        ELSE 'in_progress'
                    END,
                    current_block = VALUES(current_block),
                    blocks_viewed = VALUES(blocks_viewed),
                    progress_pct = CASE
                        WHEN status = 'completed' THEN 100
                        ELSE VALUES(progress_pct)
                    END,
                    started_at = COALESCE(started_at, NOW()),
                    last_accessed_at = NOW()"
            );
            $progress->execute([
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

    public function completeLesson(int $userId, int $lessonId): array
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT
                l.id,
                l.phase_id,
                l.position,
                l.xp_reward,
                p.module_id,
                p.position AS phase_position,
                p.xp_reward AS phase_xp,
                m.course_id,
                m.position AS module_position
             FROM lessons l
             INNER JOIN phases p ON p.id = l.phase_id
             INNER JOIN modules m ON m.id = p.module_id
             WHERE l.id = :lesson_id
               AND l.status = 'published'
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
             WHERE user_id = :user_id
               AND lesson_id = :lesson_id
             LIMIT 1"
        );
        $progressStmt->execute([
            'user_id' => $userId,
            'lesson_id' => $lessonId,
        ]);
        $current = $progressStmt->fetch();

        if (($current['status'] ?? 'locked') === 'locked') {
            throw new RuntimeException('Esta aula ainda está bloqueada.');
        }

        [$viewed, $required] = $this->blockCounts($pdo, $userId, $lessonId);

        if ($required > 0 && $viewed < $required) {
            throw new RuntimeException(
                "Você visualizou {$viewed} de {$required} telas obrigatórias. ".
                "Conclua a leitura antes de finalizar a aula."
            );
        }

        $alreadyCompleted = ($current['status'] ?? null) === 'completed';

        $pdo->beginTransaction();

        try {
            $pdo->prepare(
                "UPDATE user_lesson_progress
                 SET status = 'completed',
                     progress_pct = 100,
                     blocks_viewed = :blocks_viewed,
                     completed_at = COALESCE(completed_at, NOW()),
                     last_accessed_at = NOW()
                 WHERE user_id = :user_id
                   AND lesson_id = :lesson_id"
            )->execute([
                'blocks_viewed' => max($viewed, $required),
                'user_id' => $userId,
                'lesson_id' => $lessonId,
            ]);

            if (!$alreadyCompleted) {
                $this->awardXp(
                    $pdo,
                    $userId,
                    'lesson_completed',
                    $lessonId,
                    (int) $lesson['xp_reward'],
                    'Conclusão da aula #' . $lessonId
                );
            }

            $phaseId = (int) $lesson['phase_id'];

            $countsStmt = $pdo->prepare(
                "SELECT
                    COUNT(l.id) AS total,
                    SUM(CASE WHEN ulp.status = 'completed' THEN 1 ELSE 0 END) AS completed
                 FROM lessons l
                 LEFT JOIN user_lesson_progress ulp
                    ON ulp.lesson_id = l.id
                   AND ulp.user_id = :user_id
                 WHERE l.phase_id = :phase_id
                   AND l.status = 'published'"
            );
            $countsStmt->execute([
                'user_id' => $userId,
                'phase_id' => $phaseId,
            ]);
            $counts = $countsStmt->fetch();

            $totalLessons = (int) ($counts['total'] ?? 0);
            $completedLessons = (int) ($counts['completed'] ?? 0);
            $allLessonsCompleted = $totalLessons > 0 && $totalLessons === $completedLessons;

            $phasePct = $totalLessons > 0
                ? round(($completedLessons / $totalLessons) * 100, 2)
                : 0;

            $pdo->prepare(
                "UPDATE user_phase_progress
                 SET status = CASE
                        WHEN status = 'completed' THEN 'completed'
                        ELSE 'in_progress'
                     END,
                     progress_pct = :progress_pct,
                     started_at = COALESCE(started_at, NOW())
                 WHERE user_id = :user_id
                   AND phase_id = :phase_id"
            )->execute([
                'progress_pct' => $phasePct,
                'user_id' => $userId,
                'phase_id' => $phaseId,
            ]);

            // Próxima aula dentro da mesma fase.
            $nextLessonStmt = $pdo->prepare(
                "SELECT id
                 FROM lessons
                 WHERE phase_id = :phase_id
                   AND status = 'published'
                   AND position > :position
                 ORDER BY position
                 LIMIT 1"
            );
            $nextLessonStmt->execute([
                'phase_id' => $phaseId,
                'position' => (int) $lesson['position'],
            ]);
            $nextLessonId = (int) ($nextLessonStmt->fetchColumn() ?: 0);

            if ($nextLessonId > 0) {
                $this->unlockLesson($pdo, $userId, $nextLessonId);
            }

            $quizStmt = $pdo->prepare(
                "SELECT id
                 FROM quizzes
                 WHERE phase_id = :phase_id
                   AND active = 1
                   AND quiz_type IN ('phase_exam', 'module_boss', 'review')
                 ORDER BY id
                 LIMIT 1"
            );
            $quizStmt->execute(['phase_id' => $phaseId]);
            $quizId = (int) ($quizStmt->fetchColumn() ?: 0);

            $nextContent = null;

            /*
             * Enquanto ainda não existem provas interativas importadas:
             * - se a fase não possui quiz e toda a leitura foi concluída,
             *   a leitura vale como checkpoint provisório;
             * - a fase é concluída e a próxima fase/aula é liberada.
             *
             * Assim que os quizzes forem implantados, basta existir um quiz ativo
             * para que este atalho deixe de ocorrer automaticamente.
             */
            if ($allLessonsCompleted && $quizId === 0) {
                $phaseWasCompleted = $this->isPhaseCompleted($pdo, $userId, $phaseId);

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

                if (!$phaseWasCompleted) {
                    $this->awardXp(
                        $pdo,
                        $userId,
                        'phase_completed',
                        $phaseId,
                        (int) $lesson['phase_xp'],
                        'Conclusão provisória da fase #' . $phaseId . ' por leitura'
                    );
                }

                $nextContent = $this->unlockNextPhaseOrModule(
                    $pdo,
                    $userId,
                    (int) $lesson['course_id'],
                    (int) $lesson['module_id'],
                    (int) $lesson['module_position'],
                    (int) $lesson['phase_position']
                );
            }

            $this->recalculateCourseProgress(
                $pdo,
                $userId,
                (int) $lesson['course_id']
            );

            $pdo->commit();

            return [
                'phase_id' => $phaseId,
                'module_id' => (int) $lesson['module_id'],
                'course_id' => (int) $lesson['course_id'],
                'next_lesson_id' => $nextLessonId > 0 ? $nextLessonId : null,
                'all_lessons_completed' => $allLessonsCompleted,
                'quiz_id' => $quizId > 0 ? $quizId : null,
                'next_phase_id' => $nextContent['phase_id'] ?? null,
                'next_content_lesson_id' => $nextContent['lesson_id'] ?? null,
                'next_module_id' => $nextContent['module_id'] ?? null,
                'course_completed' => (bool) ($nextContent['course_completed'] ?? false),
                'already_completed' => $alreadyCompleted,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function unlockNextPhaseOrModule(
        PDO $pdo,
        int $userId,
        int $courseId,
        int $moduleId,
        int $modulePosition,
        int $phasePosition
    ): array {
        // Primeiro tenta a próxima fase do mesmo módulo.
        $stmt = $pdo->prepare(
            "SELECT p.id AS phase_id, p.module_id
             FROM phases p
             WHERE p.module_id = :module_id
               AND p.status = 'published'
               AND p.position > :phase_position
             ORDER BY p.position
             LIMIT 1"
        );
        $stmt->execute([
            'module_id' => $moduleId,
            'phase_position' => $phasePosition,
        ]);
        $nextPhase = $stmt->fetch();

        if ($nextPhase) {
            $phaseId = (int) $nextPhase['phase_id'];
            $this->unlockPhase($pdo, $userId, $phaseId);
            $lessonId = $this->firstLessonId($pdo, $phaseId);

            if ($lessonId) {
                $this->unlockLesson($pdo, $userId, $lessonId);
            }

            return [
                'phase_id' => $phaseId,
                'lesson_id' => $lessonId,
                'module_id' => $moduleId,
                'course_completed' => false,
            ];
        }

        // Não há próxima fase: fecha módulo e tenta o próximo módulo.
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

        $stmt = $pdo->prepare(
            "SELECT id
             FROM modules
             WHERE course_id = :course_id
               AND status = 'published'
               AND position > :module_position
             ORDER BY position
             LIMIT 1"
        );
        $stmt->execute([
            'course_id' => $courseId,
            'module_position' => $modulePosition,
        ]);
        $nextModuleId = (int) ($stmt->fetchColumn() ?: 0);

        if ($nextModuleId === 0) {
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

            return [
                'phase_id' => null,
                'lesson_id' => null,
                'module_id' => null,
                'course_completed' => true,
            ];
        }

        $pdo->prepare(
            "INSERT INTO user_module_progress
                (user_id, module_id, status, best_score, progress_pct, unlocked_at)
             VALUES
                (:user_id, :module_id, 'available', 0, 0, NOW())
             ON DUPLICATE KEY UPDATE
                status = CASE
                    WHEN status = 'completed' THEN 'completed'
                    ELSE 'available'
                END,
                unlocked_at = COALESCE(unlocked_at, NOW())"
        )->execute([
            'user_id' => $userId,
            'module_id' => $nextModuleId,
        ]);

        $stmt = $pdo->prepare(
            "SELECT id
             FROM phases
             WHERE module_id = :module_id
               AND status = 'published'
             ORDER BY position
             LIMIT 1"
        );
        $stmt->execute(['module_id' => $nextModuleId]);
        $nextPhaseId = (int) ($stmt->fetchColumn() ?: 0);

        if ($nextPhaseId === 0) {
            return [
                'phase_id' => null,
                'lesson_id' => null,
                'module_id' => $nextModuleId,
                'course_completed' => false,
            ];
        }

        $this->unlockPhase($pdo, $userId, $nextPhaseId);
        $firstLessonId = $this->firstLessonId($pdo, $nextPhaseId);

        if ($firstLessonId) {
            $this->unlockLesson($pdo, $userId, $firstLessonId);
        }

        return [
            'phase_id' => $nextPhaseId,
            'lesson_id' => $firstLessonId,
            'module_id' => $nextModuleId,
            'course_completed' => false,
        ];
    }

    private function unlockPhase(PDO $pdo, int $userId, int $phaseId): void
    {
        $pdo->prepare(
            "INSERT INTO user_phase_progress
                (user_id, phase_id, status, best_score, attempts_count, progress_pct, unlocked_at)
             VALUES
                (:user_id, :phase_id, 'available', 0, 0, 0, NOW())
             ON DUPLICATE KEY UPDATE
                status = CASE
                    WHEN status = 'completed' THEN 'completed'
                    ELSE 'available'
                END,
                unlocked_at = COALESCE(unlocked_at, NOW())"
        )->execute([
            'user_id' => $userId,
            'phase_id' => $phaseId,
        ]);
    }

    private function unlockLesson(PDO $pdo, int $userId, int $lessonId): void
    {
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
            'lesson_id' => $lessonId,
        ]);
    }

    private function firstLessonId(PDO $pdo, int $phaseId): ?int
    {
        $stmt = $pdo->prepare(
            "SELECT id
             FROM lessons
             WHERE phase_id = :phase_id
               AND status = 'published'
             ORDER BY position
             LIMIT 1"
        );
        $stmt->execute(['phase_id' => $phaseId]);

        $id = (int) ($stmt->fetchColumn() ?: 0);
        return $id > 0 ? $id : null;
    }

    private function isPhaseCompleted(PDO $pdo, int $userId, int $phaseId): bool
    {
        $stmt = $pdo->prepare(
            "SELECT status
             FROM user_phase_progress
             WHERE user_id = :user_id
               AND phase_id = :phase_id
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => $userId,
            'phase_id' => $phaseId,
        ]);

        return $stmt->fetchColumn() === 'completed';
    }

    private function awardXp(
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

        // Evita duplicidade de XP para o mesmo evento/referência.
        $check = $pdo->prepare(
            "SELECT id
             FROM xp_events
             WHERE user_id = :user_id
               AND event_type = :event_type
               AND reference_id = :reference_id
             LIMIT 1"
        );
        $check->execute([
            'user_id' => $userId,
            'event_type' => $eventType,
            'reference_id' => $referenceId,
        ]);

        if ($check->fetchColumn()) {
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

        $pdo->prepare(
            "UPDATE users
             SET xp_total = xp_total + :xp
             WHERE id = :user_id"
        )->execute([
            'xp' => $xp,
            'user_id' => $userId,
        ]);

        $xpStmt = $pdo->prepare(
            "SELECT xp_total FROM users WHERE id = :user_id LIMIT 1"
        );
        $xpStmt->execute(['user_id' => $userId]);
        $xpTotal = (int) $xpStmt->fetchColumn();

        $levelStmt = $pdo->prepare(
            "SELECT level_number
             FROM levels
             WHERE min_xp <= :xp
               AND (max_xp IS NULL OR max_xp >= :xp2)
             ORDER BY level_number DESC
             LIMIT 1"
        );
        $levelStmt->execute([
            'xp' => $xpTotal,
            'xp2' => $xpTotal,
        ]);

        $pdo->prepare(
            "UPDATE users
             SET current_level = :level
             WHERE id = :user_id"
        )->execute([
            'level' => (int) ($levelStmt->fetchColumn() ?: 1),
            'user_id' => $userId,
        ]);
    }

    private function recalculateCourseProgress(PDO $pdo, int $userId, int $courseId): void
    {
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(p.id) AS total,
                SUM(CASE WHEN upp.status = 'completed' THEN 1 ELSE 0 END) AS completed
             FROM phases p
             INNER JOIN modules m ON m.id = p.module_id
             LEFT JOIN user_phase_progress upp
                ON upp.phase_id = p.id
               AND upp.user_id = :user_id
             WHERE m.course_id = :course_id
               AND p.status = 'published'
               AND m.status = 'published'"
        );
        $stmt->execute([
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);
        $row = $stmt->fetch();

        $total = (int) ($row['total'] ?? 0);
        $completed = (int) ($row['completed'] ?? 0);
        $pct = $total > 0 ? round(($completed / $total) * 100, 2) : 0;

        $pdo->prepare(
            "UPDATE user_course_progress
             SET progress_pct = :pct,
                 last_accessed_at = NOW()
             WHERE user_id = :user_id
               AND course_id = :course_id"
        )->execute([
            'pct' => $pct,
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);
    }

    private function blockCounts(PDO $pdo, int $userId, int $lessonId): array
    {
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(lb.id) AS required_count,
                COUNT(ulbv.id) AS viewed_count
             FROM lesson_blocks lb
             LEFT JOIN user_lesson_block_views ulbv
                ON ulbv.lesson_block_id = lb.id
               AND ulbv.user_id = :user_id
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
