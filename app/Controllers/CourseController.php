<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;

final class CourseController extends Controller
{
    public function show(int $id): void
    {
        $pdo = Database::connection();

        $courseStmt = $pdo->prepare(
            "SELECT c.*,
                    COALESCE(p.progress_pct, 0) AS progress_pct,
                    COALESCE(p.average_score, 0) AS average_score
             FROM courses c
             INNER JOIN enrollments e
               ON e.course_id = c.id
              AND e.user_id = :user_id
              AND e.status = 'active'
             LEFT JOIN user_course_progress p
               ON p.course_id = c.id
              AND p.user_id = :progress_user_id
             WHERE c.id = :course_id
               AND c.status = 'published'
             LIMIT 1"
        );
        $courseStmt->execute([
            'user_id' => Auth::id(),
            'progress_user_id' => Auth::id(),
            'course_id' => $id,
        ]);

        $course = $courseStmt->fetch();

        if (!$course) {
            http_response_code(404);
            $this->view('errors/404');
            return;
        }

        $modulesStmt = $pdo->prepare(
            "SELECT m.*,
                    COALESCE(ump.status, 'locked') AS user_status,
                    COALESCE(ump.progress_pct, 0) AS progress_pct,
                    (
                        SELECT COUNT(*)
                        FROM phases p
                        WHERE p.module_id = m.id
                          AND p.status = 'published'
                          AND p.is_required = 1
                    ) AS required_phase_count,
                    (
                        SELECT COUNT(*)
                        FROM phases p
                        WHERE p.module_id = m.id
                          AND p.status = 'published'
                          AND p.is_required = 0
                    ) AS library_phase_count
             FROM modules m
             LEFT JOIN user_module_progress ump
               ON ump.module_id = m.id
              AND ump.user_id = :user_id
             WHERE m.course_id = :course_id
               AND m.status = 'published'
             ORDER BY m.position"
        );
        $modulesStmt->execute([
            'user_id' => Auth::id(),
            'course_id' => $id,
        ]);

        $this->view(
            'student/course',
            [
                'title' => $course['title'],
                'course' => $course,
                'modules' => $modulesStmt->fetchAll(),
            ],
            'layouts/student'
        );
    }
}
