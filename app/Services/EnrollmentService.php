<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class EnrollmentService
{
    private const STATUSES = ['active', 'completed', 'cancelled'];

    public function change(
        int $studentId,
        int $courseId,
        string $status,
        ?PDO $pdo = null
    ): array {
        if ($studentId <= 0 || $courseId <= 0 || !in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('Dados de matrícula inválidos.');
        }

        $pdo ??= Database::connection();

        $studentStmt = $pdo->prepare(
            "SELECT u.id, u.name, u.email
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id
               AND r.slug = 'student'
             LIMIT 1"
        );
        $studentStmt->execute(['id' => $studentId]);
        $student = $studentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            throw new RuntimeException('Aluno não encontrado.');
        }

        $courseStmt = $pdo->prepare(
            "SELECT id, title, status
             FROM courses
             WHERE id = :id
               AND status <> 'archived'
             LIMIT 1"
        );
        $courseStmt->execute(['id' => $courseId]);
        $course = $courseStmt->fetch(PDO::FETCH_ASSOC);

        if (!$course) {
            throw new RuntimeException('Curso não encontrado ou arquivado.');
        }

        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $existing = $pdo->prepare(
                "SELECT id, status
                 FROM enrollments
                 WHERE user_id = :user_id
                   AND course_id = :course_id
                 LIMIT 1
                 FOR UPDATE"
            );
            $existing->execute([
                'user_id' => $studentId,
                'course_id' => $courseId,
            ]);
            $enrollment = $existing->fetch(PDO::FETCH_ASSOC);

            if ($enrollment) {
                $stmt = $pdo->prepare(
                    "UPDATE enrollments
                     SET status = :status,
                         enrolled_at = CASE
                             WHEN :status_active = 'active' AND status <> 'active' THEN NOW()
                             ELSE enrolled_at
                         END,
                         completed_at = CASE
                             WHEN :status_completed = 'completed' THEN COALESCE(completed_at, NOW())
                             ELSE NULL
                         END
                     WHERE id = :id"
                );
                $stmt->execute([
                    'status' => $status,
                    'status_active' => $status,
                    'status_completed' => $status,
                    'id' => (int) $enrollment['id'],
                ]);
                $enrollmentId = (int) $enrollment['id'];
                $previousStatus = (string) $enrollment['status'];
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO enrollments
                        (user_id, course_id, status, enrolled_at, completed_at)
                     VALUES
                        (:user_id, :course_id, :status, NOW(),
                         CASE WHEN :status_completed = 'completed' THEN NOW() ELSE NULL END)"
                );
                $stmt->execute([
                    'user_id' => $studentId,
                    'course_id' => $courseId,
                    'status' => $status,
                    'status_completed' => $status,
                ]);
                $enrollmentId = (int) $pdo->lastInsertId();
                $previousStatus = 'none';
            }

            if ($status === 'active') {
                (new ProgressInitializer())->initializeEnrollment(
                    $studentId,
                    $courseId,
                    $pdo
                );
            }

            $this->audit(
                $pdo,
                $studentId,
                sprintf(
                    'Matrícula em %s alterada de %s para %s',
                    (string) $course['title'],
                    $previousStatus,
                    $status
                )
            );

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'enrollment_id' => $enrollmentId,
                'student_id' => $studentId,
                'student_name' => (string) $student['name'],
                'course_id' => $courseId,
                'course_title' => (string) $course['title'],
                'previous_status' => $previousStatus,
                'status' => $status,
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function audit(
        PDO $pdo,
        int $studentId,
        string $description
    ): void {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs
                (user_id, action, entity_type, entity_id, description, ip_address, user_agent)
             VALUES
                (:user_id, 'student_enrollment_changed', 'student', :entity_id,
                 :description, :ip_address, :user_agent)"
        );
        $stmt->execute([
            'user_id' => Auth::id(),
            'entity_id' => $studentId,
            'description' => $description,
            'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
    }
}
