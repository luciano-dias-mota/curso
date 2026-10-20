<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Services\CourseExperienceRegistry;
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

        /*
         * O dashboard do estudante é o PORTAL da plataforma, não o dashboard
         * interno de nenhum curso. Todos os cursos publicados aparecem aqui.
         * A matrícula ativa apenas decide se o card entra ou permanece bloqueado.
         */
        $coursesStmt = $pdo->prepare(
            "SELECT c.id, c.title, c.slug, c.short_description, c.cover_image,
                    c.difficulty, c.position,
                    e.status AS enrollment_status,
                    e.enrolled_at,
                    CASE WHEN e.status = 'active' THEN 1 ELSE 0 END AS has_access,
                    CASE
                        WHEN e.status = 'active' THEN COALESCE(ucp.status, 'not_started')
                        ELSE 'not_started'
                    END AS progress_status,
                    CASE
                        WHEN e.status = 'active' THEN COALESCE(ucp.progress_pct, 0)
                        ELSE 0
                    END AS progress_pct,
                    CASE
                        WHEN e.status = 'active' THEN COALESCE(ucp.average_score, 0)
                        ELSE 0
                    END AS average_score,
                    CASE
                        WHEN e.status = 'active' THEN ucp.last_accessed_at
                        ELSE NULL
                    END AS last_accessed_at,
                    (SELECT COUNT(*)
                       FROM modules m
                      WHERE m.course_id = c.id
                        AND m.status = 'published') AS module_count,
                    (SELECT COUNT(*)
                       FROM lessons l
                       INNER JOIN phases p
                         ON p.id = l.phase_id
                        AND p.status = 'published'
                       INNER JOIN modules m
                         ON m.id = p.module_id
                        AND m.status = 'published'
                      WHERE m.course_id = c.id
                        AND l.status = 'published') AS lesson_count
             FROM courses c
             LEFT JOIN enrollments e
               ON e.course_id = c.id
              AND e.user_id = :user_id
             LEFT JOIN user_course_progress ucp
               ON ucp.course_id = c.id
              AND ucp.user_id = :progress_user
             WHERE c.status = 'published'
             ORDER BY
                CASE WHEN e.status = 'active' THEN 0 ELSE 1 END,
                CASE WHEN e.status = 'active' AND ucp.last_accessed_at IS NOT NULL THEN 0 ELSE 1 END,
                ucp.last_accessed_at DESC,
                c.position,
                c.id"
        );
        $coursesStmt->execute([
            'user_id' => $userId,
            'progress_user' => $userId,
        ]);
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

        $myCourses = [];
        $otherCourses = [];
        $completedCourses = 0;
        $experienceRegistry = new CourseExperienceRegistry();

        foreach ($courses as $course) {
            $courseId = (int) $course['id'];
            $course['continue_lesson'] = $continueByCourse[$courseId] ?? null;
            $course['has_access'] = (int) ($course['has_access'] ?? 0) === 1;
            $course['entry_path'] = $experienceRegistry->entryPath($course);

            if ($course['has_access']) {
                if (($course['progress_status'] ?? '') === 'completed') {
                    $completedCourses++;
                }

                $myCourses[] = $course;
                continue;
            }

            $otherCourses[] = $course;
        }

        $this->view(
            'student/dashboard',
            [
                'title' => 'Portal de Cursos',
                'user' => $user,
                'myCourses' => $myCourses,
                'otherCourses' => $otherCourses,
                'summary' => [
                    'published_courses' => count($courses),
                    'accessible_courses' => count($myCourses),
                    'locked_courses' => count($otherCourses),
                    'completed_courses' => $completedCourses,
                ],
            ],
            'layouts/portal'
        );
    }
}
