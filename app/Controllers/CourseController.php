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
                    COALESCE(ucp.progress_pct, 0) AS progress_pct
             FROM courses c
             INNER JOIN enrollments e
               ON e.course_id = c.id AND e.user_id = :user_id AND e.status = 'active'
             LEFT JOIN user_course_progress ucp
               ON ucp.course_id = c.id AND ucp.user_id = :user_id2
             WHERE c.id = :id AND c.status = 'published'
             LIMIT 1"
        );
        $courseStmt->execute([
            'user_id' => Auth::id(),
            'user_id2' => Auth::id(),
            'id' => $id,
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
                    COALESCE(ump.progress_pct, 0) AS progress_pct
             FROM modules m
             LEFT JOIN user_module_progress ump
               ON ump.module_id = m.id AND ump.user_id = :user_id
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
