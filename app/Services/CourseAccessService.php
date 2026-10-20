<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

final class CourseAccessService
{
    public function requireCourseEnrollment(
        int $userId,
        int $courseId,
        ?PDO $pdo = null
    ): array {
        $pdo ??= Database::connection();

        $stmt = $pdo->prepare(
            "SELECT c.*, e.id AS enrollment_id, e.status AS enrollment_status
             FROM courses c
             INNER JOIN enrollments e
               ON e.course_id = c.id
              AND e.user_id = :user_id
              AND e.status = 'active'
             WHERE c.id = :course_id
               AND c.status = 'published'
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Curso indisponível para esta matrícula.');
        }

        return $row;
    }

    public function requireModule(
        int $userId,
        int $moduleId,
        ?PDO $pdo = null
    ): array {
        $pdo ??= Database::connection();

        $stmt = $pdo->prepare(
            "SELECT m.*, c.id AS course_id, c.title AS course_title,
                    c.experience_key
             FROM modules m
             INNER JOIN courses c
               ON c.id = m.course_id
              AND c.status = 'published'
             INNER JOIN enrollments e
               ON e.course_id = c.id
              AND e.user_id = :user_id
              AND e.status = 'active'
             WHERE m.id = :module_id
               AND m.status = 'published'
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => $userId,
            'module_id' => $moduleId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Módulo indisponível para esta matrícula.');
        }

        return $row;
    }

    public function requirePhase(
        int $userId,
        int $phaseId,
        ?PDO $pdo = null
    ): array {
        $pdo ??= Database::connection();

        $stmt = $pdo->prepare(
            "SELECT p.*, m.id AS module_id, m.title AS module_title,
                    c.id AS course_id, c.title AS course_title,
                    c.experience_key
             FROM phases p
             INNER JOIN modules m
               ON m.id = p.module_id
              AND m.status = 'published'
             INNER JOIN courses c
               ON c.id = m.course_id
              AND c.status = 'published'
             INNER JOIN enrollments e
               ON e.course_id = c.id
              AND e.user_id = :user_id
              AND e.status = 'active'
             WHERE p.id = :phase_id
               AND p.status = 'published'
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => $userId,
            'phase_id' => $phaseId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Fase indisponível para esta matrícula.');
        }

        return $row;
    }

    public function requireLesson(
        int $userId,
        int $lessonId,
        ?PDO $pdo = null
    ): array {
        $pdo ??= Database::connection();

        $stmt = $pdo->prepare(
            "SELECT l.*, p.id AS phase_id, p.title AS phase_title,
                    m.id AS module_id, m.title AS module_title,
                    c.id AS course_id, c.title AS course_title,
                    c.experience_key
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
              AND e.user_id = :user_id
              AND e.status = 'active'
             WHERE l.id = :lesson_id
               AND l.status = 'published'
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => $userId,
            'lesson_id' => $lessonId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Aula indisponível para esta matrícula.');
        }

        return $row;
    }

    public function requireLabActivity(
        int $userId,
        int $activityId,
        ?PDO $pdo = null
    ): array {
        $pdo ??= Database::connection();

        $stmt = $pdo->prepare(
            "SELECT la.id AS activity_id, la.lesson_id,
                    p.id AS phase_id, m.id AS module_id,
                    c.id AS course_id, c.experience_key
             FROM lab_activities la
             INNER JOIN lessons l
               ON l.id = la.lesson_id
              AND l.status = 'published'
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
              AND e.user_id = :user_id
              AND e.status = 'active'
             WHERE la.id = :activity_id
               AND la.status = 'published'
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => $userId,
            'activity_id' => $activityId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Atividade prática indisponível para esta matrícula.');
        }

        return $row;
    }
}
