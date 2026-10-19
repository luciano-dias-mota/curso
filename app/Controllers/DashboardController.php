<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use PDO;

final class DashboardController extends Controller
{
    public function index(): void
    {
        if (Auth::isAdmin()) {
            $this->redirect('/admin');
        }

        $pdo = Database::connection();
        $userId = (int) Auth::id();
        $user = Auth::user() ?? [];

        $coursesStmt = $pdo->prepare(
            "SELECT c.id, c.title, c.slug, c.short_description, c.cover_image,
                    c.difficulty, c.xp_reward,
                    e.enrolled_at,
                    COALESCE(ucp.status, 'not_started') AS progress_status,
                    COALESCE(ucp.progress_pct, 0) AS progress_pct,
                    COALESCE(ucp.average_score, 0) AS average_score,
                    ucp.last_accessed_at,
                    (SELECT COUNT(*)
                       FROM modules m
                      WHERE m.course_id = c.id
                        AND m.status = 'published') AS module_count,
                    (SELECT COUNT(*)
                       FROM lessons l
                       INNER JOIN phases p ON p.id = l.phase_id AND p.status = 'published'
                       INNER JOIN modules m ON m.id = p.module_id AND m.status = 'published'
                      WHERE m.course_id = c.id
                        AND l.status = 'published') AS lesson_count
             FROM enrollments e
             INNER JOIN courses c
               ON c.id = e.course_id
              AND c.status = 'published'
             LEFT JOIN user_course_progress ucp
               ON ucp.user_id = e.user_id
              AND ucp.course_id = e.course_id
             WHERE e.user_id = :user_id
               AND e.status = 'active'
             ORDER BY
                CASE WHEN ucp.last_accessed_at IS NULL THEN 1 ELSE 0 END,
                ucp.last_accessed_at DESC,
                c.position,
                c.id"
        );
        $coursesStmt->execute(['user_id' => $userId]);
        $courses = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);

        $continueStmt = $pdo->prepare(
            "SELECT c.id AS course_id,
                    l.id AS lesson_id,
                    l.title AS lesson_title,
                    p.title AS phase_title,
                    m.title AS module_title,
                    ulp.status AS lesson_status,
                    COALESCE(ulp.progress_pct, 0) AS lesson_progress_pct,
                    COALESCE(ulp.last_accessed_at, ulp.started_at) AS activity_at
             FROM user_lesson_progress ulp
             INNER JOIN lessons l
               ON l.id = ulp.lesson_id
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
              AND e.user_id = :enrollment_user
              AND e.status = 'active'
             WHERE ulp.user_id = :progress_user
               AND ulp.status IN ('in_progress', 'available')
             ORDER BY c.id,
                      (ulp.status = 'in_progress') DESC,
                      COALESCE(ulp.last_accessed_at, ulp.started_at, '1970-01-01 00:00:00') DESC,
                      m.position, p.position, l.position"
        );
        $continueStmt->execute([
            'enrollment_user' => $userId,
            'progress_user' => $userId,
        ]);

        $continueByCourse = [];
        foreach ($continueStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $courseId = (int) $row['course_id'];
            if (!isset($continueByCourse[$courseId])) {
                $continueByCourse[$courseId] = $row;
            }
        }

        $totalProgress = 0.0;
        $completedCourses = 0;
        $totalModules = 0;

        foreach ($courses as &$course) {
            $courseId = (int) $course['id'];
            $course['continue_lesson'] = $continueByCourse[$courseId] ?? null;
            $totalProgress += (float) $course['progress_pct'];
            $totalModules += (int) $course['module_count'];

            if (($course['progress_status'] ?? '') === 'completed') {
                $completedCourses++;
            }
        }
        unset($course);

        $courseCount = count($courses);
        $averageProgress = $courseCount > 0
            ? round($totalProgress / $courseCount, 1)
            : 0.0;

        $levelStmt = $pdo->prepare(
            "SELECT title
             FROM levels
             WHERE level_number = :level
             LIMIT 1"
        );
        $levelStmt->execute(['level' => (int) ($user['current_level'] ?? 1)]);
        $levelTitle = (string) ($levelStmt->fetchColumn() ?: 'Estudante');

        $this->view(
            'student/dashboard',
            [
                'title' => 'Meus Cursos',
                'user' => $user,
                'courses' => $courses,
                'summary' => [
                    'courses' => $courseCount,
                    'completed_courses' => $completedCourses,
                    'average_progress' => $averageProgress,
                    'modules' => $totalModules,
                    'xp_total' => (int) ($user['xp_total'] ?? 0),
                    'level_title' => $levelTitle,
                ],
            ],
            'layouts/student'
        );
    }
}
