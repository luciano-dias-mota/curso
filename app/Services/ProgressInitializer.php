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

        $settings = new CourseSettingsService();
        $moduleAccessMode = $settings->moduleAccessMode($courseId, $pdo);
        $lessonAccessMode = $settings->lessonAccessMode($courseId, $pdo);

        $modules = $pdo->prepare(
            "SELECT m.id,
                    COALESCE(ump.status, 'locked') AS user_status,
                    (
                        SELECT COUNT(*)
                        FROM phases p
                        WHERE p.module_id = m.id
                          AND p.status = 'published'
                          AND p.is_required = 1
                    ) AS required_phases
             FROM modules m
             LEFT JOIN user_module_progress ump
               ON ump.module_id = m.id
              AND ump.user_id = :user_id
             WHERE m.course_id = :course_id
               AND m.status = 'published'
             ORDER BY m.position, m.id"
        );
        $modules->execute([
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);

        $firstIncompleteModuleUnlocked = false;

        foreach ($modules->fetchAll(PDO::FETCH_ASSOC) as $module) {
            $moduleId = (int) $module['id'];
            $userStatus = (string) ($module['user_status'] ?? 'locked');

            if ($moduleAccessMode === 'free') {
                $this->unlockModule($pdo, $userId, $moduleId);
                $this->unlockModuleEntryPoints($pdo, $userId, $moduleId, $lessonAccessMode);
                continue;
            }

            // No modo sequencial, módulos já concluídos permanecem acessíveis e
            // apenas o primeiro módulo ainda não concluído é liberado.
            if ($userStatus === 'completed') {
                $this->unlockModule($pdo, $userId, $moduleId);
                continue;
            }

            if (!$firstIncompleteModuleUnlocked) {
                $this->unlockModule($pdo, $userId, $moduleId);
                $this->unlockModuleEntryPoints($pdo, $userId, $moduleId, $lessonAccessMode);
                $firstIncompleteModuleUnlocked = true;
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

    private function unlockModuleEntryPoints(PDO $pdo, int $userId, int $moduleId, string $lessonAccessMode): void
    {
        $stmt = $pdo->prepare(
            "SELECT p.id,
                    p.is_required,
                    COALESCE(upp.status, 'locked') AS user_status
             FROM phases p
             LEFT JOIN user_phase_progress upp
               ON upp.phase_id = p.id
              AND upp.user_id = :user_id
             WHERE p.module_id = :module_id
               AND p.status = 'published'
             ORDER BY p.position, p.id"
        );
        $stmt->execute([
            'user_id' => $userId,
            'module_id' => $moduleId,
        ]);
        $phases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $firstIncompleteRequiredUnlocked = false;

        foreach ($phases as $phase) {
            $phaseId = (int) $phase['id'];
            $required = (int) ($phase['is_required'] ?? 1) === 1;
            $userStatus = (string) ($phase['user_status'] ?? 'locked');

            if (!$required) {
                $this->unlockPhase($pdo, $userId, $phaseId);
                $this->unlockAllLessons($pdo, $userId, $phaseId);
                continue;
            }

            // Fases obrigatórias já concluídas não devem impedir que uma
            // matrícula antiga seja reconciliada com uma trilha atualizada.
            if ($userStatus === 'completed') {
                continue;
            }

            if (!$firstIncompleteRequiredUnlocked) {
                $this->unlockPhase($pdo, $userId, $phaseId);

                if ($lessonAccessMode === 'free') {
                    $this->unlockAllLessons($pdo, $userId, $phaseId);
                } else {
                    $this->unlockPhaseEntryLessons($pdo, $userId, $phaseId);
                }

                $firstIncompleteRequiredUnlocked = true;
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
