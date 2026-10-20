<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;
use RuntimeException;

final class LessonCompletionService
{
    public function complete(
        PDO $pdo,
        int $userId,
        int $lessonId,
        string $description = 'Aula concluída'
    ): array {
        $stmt = $pdo->prepare(
            "SELECT l.id, l.phase_id, l.position, l.xp_reward,
                    p.module_id, p.position AS phase_position, p.xp_reward AS phase_xp_reward,
                    m.course_id, m.position AS module_position, m.xp_reward AS module_xp_reward
             FROM lessons l
             INNER JOIN phases p ON p.id = l.phase_id
             INNER JOIN modules m ON m.id = p.module_id
             INNER JOIN courses c ON c.id = m.course_id
             INNER JOIN enrollments e
               ON e.course_id = c.id
              AND e.user_id = :user_id
              AND e.status = 'active'
             WHERE l.id = :lesson_id
               AND l.status = 'published'
               AND p.status = 'published'
               AND m.status = 'published'
               AND c.status = 'published'
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => $userId,
            'lesson_id' => $lessonId,
        ]);
        $lesson = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lesson) {
            throw new RuntimeException('Aula indisponível para conclusão.');
        }

        $progress = $pdo->prepare(
            "SELECT status, progress_pct
             FROM user_lesson_progress
             WHERE user_id = :user_id
               AND lesson_id = :lesson_id
             LIMIT 1
             FOR UPDATE"
        );
        $progress->execute([
            'user_id' => $userId,
            'lesson_id' => $lessonId,
        ]);
        $row = $progress->fetch(PDO::FETCH_ASSOC);

        if (!$row || ($row['status'] ?? '') === 'locked') {
            throw new RuntimeException('Esta aula ainda está bloqueada.');
        }

        if ((float) ($row['progress_pct'] ?? 0) < 100) {
            throw new RuntimeException(
                'Conclua a leitura da aula antes de finalizar a atividade obrigatória.'
            );
        }

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

        $this->awardOnce(
            $pdo,
            $userId,
            'lesson_completed',
            $lessonId,
            (int) ($lesson['xp_reward'] ?? 0),
            $description
        );

        $courseId = (int) $lesson['course_id'];
        $phaseId = (int) $lesson['phase_id'];
        $moduleId = (int) $lesson['module_id'];
        $settings = new CourseSettingsService();

        if ($settings->lessonAccessMode($courseId, $pdo) === 'sequential') {
            $this->unlockNextLesson(
                $pdo,
                $userId,
                $phaseId,
                (int) $lesson['position']
            );
        }

        $phaseStats = $this->refreshPhaseProgress($pdo, $userId, $phaseId);

        if (
            $settings->phaseCompletionMode($courseId, $pdo) === 'lessons'
            && $phaseStats['total'] > 0
            && $phaseStats['completed'] === $phaseStats['total']
        ) {
            $this->completePhase(
                $pdo,
                $userId,
                $phaseId,
                (int) ($lesson['phase_xp_reward'] ?? 0)
            );

            $moduleStats = $this->refreshModuleProgress($pdo, $userId, $moduleId);

            if (
                $settings->moduleCompletionMode($courseId, $pdo) === 'phases'
                && $moduleStats['total'] > 0
                && $moduleStats['completed'] === $moduleStats['total']
            ) {
                $this->completeModule(
                    $pdo,
                    $userId,
                    $moduleId,
                    (int) ($lesson['module_xp_reward'] ?? 0)
                );
                $this->refreshCourseProgress($pdo, $userId, $courseId);
            }

            // Reaplica as regras de navegação do curso. Isso libera a próxima
            // fase e, quando o módulo foi concluído, o próximo módulo sequencial.
            (new ProgressInitializer())->initializeEnrollment(
                $userId,
                $courseId,
                $pdo
            );
        }

        return [
            'lesson_id' => $lessonId,
            'phase_id' => $phaseId,
            'module_id' => $moduleId,
            'course_id' => $courseId,
        ];
    }

    private function unlockNextLesson(
        PDO $pdo,
        int $userId,
        int $phaseId,
        int $position
    ): void {
        $next = $pdo->prepare(
            "SELECT id
             FROM lessons
             WHERE phase_id = :phase_id
               AND status = 'published'
               AND is_required = 1
               AND position > :position
             ORDER BY position, id
             LIMIT 1"
        );
        $next->execute([
            'phase_id' => $phaseId,
            'position' => $position,
        ]);

        $nextLessonId = (int) ($next->fetchColumn() ?: 0);

        if ($nextLessonId > 0) {
            $this->unlockLesson($pdo, $userId, $nextLessonId);
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

    /**
     * @return array{total:int,completed:int}
     */
    private function refreshPhaseProgress(PDO $pdo, int $userId, int $phaseId): array
    {
        $stmt = $pdo->prepare(
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
        $stmt->execute([
            'user_id' => $userId,
            'phase_id' => $phaseId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $total = (int) ($row['total'] ?? 0);
        $completed = (int) ($row['completed'] ?? 0);
        $pct = $total > 0 ? round(($completed / $total) * 100, 2) : 0;

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
            'progress_pct' => $pct,
            'completed' => $completed,
            'completed2' => $completed,
            'user_id' => $userId,
            'phase_id' => $phaseId,
        ]);

        return ['total' => $total, 'completed' => $completed];
    }

    private function completePhase(
        PDO $pdo,
        int $userId,
        int $phaseId,
        int $xpReward
    ): void {
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

        $this->awardOnce(
            $pdo,
            $userId,
            'phase_completed',
            $phaseId,
            $xpReward,
            'Fase concluída pelas atividades obrigatórias'
        );
    }

    /**
     * @return array{total:int,completed:int}
     */
    private function refreshModuleProgress(PDO $pdo, int $userId, int $moduleId): array
    {
        $stmt = $pdo->prepare(
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
        $stmt->execute([
            'user_id' => $userId,
            'module_id' => $moduleId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $total = (int) ($row['total'] ?? 0);
        $completed = (int) ($row['completed'] ?? 0);
        $pct = $total > 0 ? round(($completed / $total) * 100, 2) : 0;

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
            'progress_pct' => $pct,
            'completed' => $completed,
            'completed2' => $completed,
            'user_id' => $userId,
            'module_id' => $moduleId,
        ]);

        return ['total' => $total, 'completed' => $completed];
    }

    private function completeModule(
        PDO $pdo,
        int $userId,
        int $moduleId,
        int $xpReward
    ): void {
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

        $this->awardOnce(
            $pdo,
            $userId,
            'module_completed',
            $moduleId,
            $xpReward,
            'Módulo concluído pelas fases obrigatórias'
        );
    }

    private function refreshCourseProgress(PDO $pdo, int $userId, int $courseId): void
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(m.id) AS total,
                    SUM(CASE WHEN ump.status = 'completed' THEN 1 ELSE 0 END) AS completed
             FROM modules m
             LEFT JOIN user_module_progress ump
               ON ump.module_id = m.id
              AND ump.user_id = :user_id
             WHERE m.course_id = :course_id
               AND m.status = 'published'"
        );
        $stmt->execute([
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $total = (int) ($row['total'] ?? 0);
        $completed = (int) ($row['completed'] ?? 0);
        $pct = $total > 0 ? round(($completed / $total) * 100, 2) : 0;
        $allCompleted = $total > 0 && $completed === $total;

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
            'progress_pct' => $pct,
            'all_completed' => $allCompleted ? 1 : 0,
            'completed' => $completed,
            'completed2' => $completed,
            'all_completed2' => $allCompleted ? 1 : 0,
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);
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

        try {
            $insert = $pdo->prepare(
                "INSERT INTO xp_events
                    (user_id, event_type, reference_id, xp_amount, description)
                 VALUES
                    (:user_id, :event_type, :reference_id, :xp, :description)"
            );
            $insert->execute([
                'user_id' => $userId,
                'event_type' => $eventType,
                'reference_id' => $referenceId,
                'xp' => $xp,
                'description' => $description,
            ]);
        } catch (PDOException $e) {
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            if ($e->getCode() === '23000' && $driverCode === 1062) {
                return;
            }
            throw $e;
        }

        $pdo->prepare(
            'UPDATE users SET xp_total = xp_total + :xp WHERE id = :user_id'
        )->execute([
            'xp' => $xp,
            'user_id' => $userId,
        ]);

        $xpStmt = $pdo->prepare('SELECT xp_total FROM users WHERE id = :user_id LIMIT 1');
        $xpStmt->execute(['user_id' => $userId]);
        $totalXp = (int) ($xpStmt->fetchColumn() ?: 0);

        $levelStmt = $pdo->prepare(
            "SELECT level_number
             FROM levels
             WHERE min_xp <= :xp
               AND (max_xp IS NULL OR max_xp >= :xp_max)
             ORDER BY level_number DESC
             LIMIT 1"
        );
        $levelStmt->execute([
            'xp' => $totalXp,
            'xp_max' => $totalXp,
        ]);
        $level = (int) ($levelStmt->fetchColumn() ?: 0);

        if ($level > 0) {
            $pdo->prepare(
                'UPDATE users SET current_level = :level WHERE id = :user_id'
            )->execute([
                'level' => $level,
                'user_id' => $userId,
            ]);
        }
    }
}
