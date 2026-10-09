<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class ProgressInitializer
{
    public function initializeActiveEnrollments(int $userId): void
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT course_id
             FROM enrollments
             WHERE user_id = :user_id
               AND status = 'active'
             ORDER BY id"
        );
        $stmt->execute(['user_id' => $userId]);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $courseId) {
            $this->initializeEnrollment($userId, (int) $courseId, $pdo);
        }
    }

    public function initializeEnrollment(int $userId, int $courseId, ?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();

        $enrollment = $pdo->prepare(
            "SELECT 1
             FROM enrollments
             WHERE user_id = :user_id
               AND course_id = :course_id
               AND status = 'active'
             LIMIT 1"
        );
        $enrollment->execute([
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);

        if (!$enrollment->fetchColumn()) {
            return;
        }

        $pdo->prepare(
            "INSERT INTO user_course_progress
                (user_id, course_id, status, progress_pct, average_score, started_at, last_accessed_at)
             VALUES
                (:user_id, :course_id, 'not_started', 0, 0, NULL, NULL)
             ON DUPLICATE KEY UPDATE
                course_id = VALUES(course_id)"
        )->execute([
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);

        $modules = $pdo->prepare(
            "SELECT m.id,
                    (
                        SELECT COUNT(*)
                        FROM phases p
                        WHERE p.module_id = m.id
                          AND p.status = 'published'
                          AND p.is_required = 1
                    ) AS required_phases
             FROM modules m
             WHERE m.course_id = :course_id
               AND m.status = 'published'
             ORDER BY m.position, m.id"
        );
        $modules->execute(['course_id' => $courseId]);

        foreach ($modules->fetchAll(PDO::FETCH_ASSOC) as $module) {
            $moduleId = (int) $module['id'];
            $requiredPhases = (int) $module['required_phases'];

            $this->unlockModule($pdo, $userId, $moduleId);
            $this->unlockModuleEntryPoints($pdo, $userId, $moduleId);

            // Módulos formados apenas por conteúdo opcional/orientação não bloqueiam
            // a entrada no próximo módulo. O primeiro módulo com fase obrigatória
            // passa a ser o ponto real de entrada da trilha pedagógica.
            if ($requiredPhases > 0) {
                break;
            }
        }
    }

    private function unlockModule(PDO $pdo, int $userId, int $moduleId): void
    {
        $pdo->prepare(
            "INSERT INTO user_module_progress
                (user_id, module_id, status, best_score, progress_pct, unlocked_at)
             VALUES
                (:user_id, :module_id, 'available', 0, 0, NOW())
             ON DUPLICATE KEY UPDATE
                status = CASE
                    WHEN status = 'completed' THEN 'completed'
                    WHEN status = 'in_progress' THEN 'in_progress'
                    ELSE 'available'
                END,
                unlocked_at = COALESCE(unlocked_at, NOW())"
        )->execute([
            'user_id' => $userId,
            'module_id' => $moduleId,
        ]);
    }

    private function unlockModuleEntryPoints(PDO $pdo, int $userId, int $moduleId): void
    {
        $stmt = $pdo->prepare(
            "SELECT id, is_required
             FROM phases
             WHERE module_id = :module_id
               AND status = 'published'
             ORDER BY position, id"
        );
        $stmt->execute(['module_id' => $moduleId]);
        $phases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $firstRequiredUnlocked = false;

        foreach ($phases as $phase) {
            $phaseId = (int) $phase['id'];
            $required = (int) ($phase['is_required'] ?? 1) === 1;

            if (!$required) {
                $this->unlockPhase($pdo, $userId, $phaseId);
                $this->unlockAllLessons($pdo, $userId, $phaseId);
                continue;
            }

            if (!$firstRequiredUnlocked) {
                $this->unlockPhase($pdo, $userId, $phaseId);
                $this->unlockPhaseEntryLessons($pdo, $userId, $phaseId);
                $firstRequiredUnlocked = true;
            }
        }
    }

    private function unlockPhaseEntryLessons(PDO $pdo, int $userId, int $phaseId): void
    {
        $optional = $pdo->prepare(
            "SELECT id
             FROM lessons
             WHERE phase_id = :phase_id
               AND status = 'published'
               AND is_required = 0
             ORDER BY position, id"
        );
        $optional->execute(['phase_id' => $phaseId]);

        foreach ($optional->fetchAll(PDO::FETCH_COLUMN) as $lessonId) {
            $this->unlockLesson($pdo, $userId, (int) $lessonId);
        }

        $required = $pdo->prepare(
            "SELECT id
             FROM lessons
             WHERE phase_id = :phase_id
               AND status = 'published'
               AND is_required = 1
             ORDER BY position, id
             LIMIT 1"
        );
        $required->execute(['phase_id' => $phaseId]);
        $firstRequired = (int) ($required->fetchColumn() ?: 0);

        if ($firstRequired > 0) {
            $this->unlockLesson($pdo, $userId, $firstRequired);
        }
    }

    private function unlockAllLessons(PDO $pdo, int $userId, int $phaseId): void
    {
        $stmt = $pdo->prepare(
            "SELECT id
             FROM lessons
             WHERE phase_id = :phase_id
               AND status = 'published'
             ORDER BY position, id"
        );
        $stmt->execute(['phase_id' => $phaseId]);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $lessonId) {
            $this->unlockLesson($pdo, $userId, (int) $lessonId);
        }
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
                    WHEN status = 'in_progress' THEN 'in_progress'
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
}
