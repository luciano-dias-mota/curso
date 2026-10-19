<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Services\ProgressInitializer;

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

        // Política de estudo livre entre disciplinas: todo módulo publicado da
        // matrícula fica disponível. A sequência obrigatória é preservada
        // dentro de cada módulo.
        (new ProgressInitializer())->initializeEnrollment(
            (int) Auth::id(),
            $id,
            $pdo
        );

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
             ORDER BY
                CASE
                    WHEN m.title LIKE 'Módulo 0 -%' THEN 0
                    ELSE m.position
                END,
                m.position,
                m.id"
        );
        $modulesStmt->execute([
            'user_id' => Auth::id(),
            'course_id' => $id,
        ]);

        $modules = $modulesStmt->fetchAll();

        foreach ($modules as &$module) {
            $title = (string) ($module['title'] ?? '');

            if (preg_match('/^Módulo\s+(\d+)\b/ui', $title, $matches) === 1) {
                $module['display_number'] = (int) $matches[1];
                $module['display_label'] = 'MÓDULO ' . (int) $matches[1];
                $module['rail_label'] = (string) (int) $matches[1];
                continue;
            }

            $module['display_number'] = (int) ($module['position'] ?? 0) ?: null;

            if (stripos($title, 'Simulado Final') === 0) {
                $module['display_label'] = 'SIMULADO FINAL';
                $module['rail_label'] = 'S';
            } elseif (stripos($title, 'Fontes utilizadas') === 0 || stripos($title, 'Biblioteca') === 0) {
                $module['display_label'] = 'BIBLIOTECA';
                $module['rail_label'] = 'F';
            } else {
                $position = max(1, (int) ($module['position'] ?? 1));
                $module['display_label'] = 'MÓDULO ' . $position;
                $module['rail_label'] = (string) $position;
            }
        }
        unset($module);

        $this->view(
            'student/course',
            [
                'title' => $course['title'],
                'course' => $course,
                'modules' => $modules,
            ],
            'layouts/student'
        );
    }
}
