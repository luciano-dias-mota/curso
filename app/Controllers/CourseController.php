<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Services\CourseContextService;
use App\Services\CourseLayoutResolver;
use App\Services\CourseSettingsService;
use App\Services\ProgressInitializer;
use RuntimeException;

final class CourseController extends Controller
{
    public function show(int $id): void
    {
        $pdo = Database::connection();

        try {
            $context = (new CourseContextService())->activate(
                (int) Auth::id(),
                $id,
                $pdo
            );
            $course = $context['course'];
            $experience = (string) $context['experience_key'];
        } catch (RuntimeException) {
            http_response_code(404);
            $this->view('errors/404');
            return;
        }

        $progressStmt = $pdo->prepare(
            "SELECT COALESCE(progress_pct, 0) AS progress_pct,
                    COALESCE(average_score, 0) AS average_score
               FROM user_course_progress
              WHERE course_id = :course_id
                AND user_id = :user_id
              LIMIT 1"
        );
        $progressStmt->execute([
            'course_id' => $id,
            'user_id' => Auth::id(),
        ]);
        $course = array_merge(
            $course,
            $progressStmt->fetch() ?: [
                'progress_pct' => 0,
                'average_score' => 0,
            ]
        );

        // Reconcilia a matrícula com a política configurada para este curso.
        (new ProgressInitializer())->initializeEnrollment(
            (int) Auth::id(),
            $id,
            $pdo
        );

        $settings = (new CourseSettingsService())->all($id, $pdo);
        $showConstructionModules = $experience === 'postgresql'
            && (string) ($settings['catalog_preview_enabled'] ?? '0') === '1';

        $moduleStatusSql = $showConstructionModules
            ? "m.status <> 'archived'"
            : "m.status = 'published'";

        $phaseStatusSql = $showConstructionModules
            ? "p.status <> 'archived'"
            : "p.status = 'published'";

        $modulesStmt = $pdo->prepare(
            "SELECT m.*,
                    COALESCE(ump.status, 'locked') AS user_status,
                    COALESCE(ump.progress_pct, 0) AS progress_pct,
                    (
                        SELECT COUNT(*)
                        FROM phases p
                        WHERE p.module_id = m.id
                          AND {$phaseStatusSql}
                          AND p.is_required = 1
                    ) AS required_phase_count,
                    (
                        SELECT COUNT(*)
                        FROM phases p
                        WHERE p.module_id = m.id
                          AND {$phaseStatusSql}
                          AND p.is_required = 0
                    ) AS library_phase_count,
                    (
                        SELECT COUNT(*)
                        FROM lessons l
                        INNER JOIN phases p2 ON p2.id = l.phase_id
                        WHERE p2.module_id = m.id
                          AND l.status <> 'archived'
                    ) AS lesson_count,
                    (
                        SELECT COUNT(*)
                        FROM lab_activities la
                        INNER JOIN lessons l2 ON l2.id = la.lesson_id
                        INNER JOIN phases p3 ON p3.id = l2.phase_id
                        WHERE p3.module_id = m.id
                          AND la.status <> 'archived'
                    ) AS lab_count
             FROM modules m
             LEFT JOIN user_module_progress ump
               ON ump.module_id = m.id
              AND ump.user_id = :user_id
             WHERE m.course_id = :course_id
               AND {$moduleStatusSql}
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

        $courseStatsStmt = $pdo->prepare(
            "SELECT
                (SELECT COUNT(*)
                   FROM modules m2
                  WHERE m2.course_id = :c1
                    AND m2.status <> 'archived') AS modules_total,
                (SELECT COUNT(*)
                   FROM modules m3
                  WHERE m3.course_id = :c2
                    AND m3.status = 'published') AS modules_published,
                (SELECT COUNT(*)
                   FROM lessons l2
                   INNER JOIN phases p2 ON p2.id = l2.phase_id
                   INNER JOIN modules m4 ON m4.id = p2.module_id
                  WHERE m4.course_id = :c3
                    AND l2.status <> 'archived') AS lessons_total,
                (SELECT COUNT(*)
                   FROM lessons l3
                   INNER JOIN phases p3 ON p3.id = l3.phase_id
                   INNER JOIN modules m5 ON m5.id = p3.module_id
                  WHERE m5.course_id = :c4
                    AND l3.status = 'published') AS lessons_published,
                (SELECT COUNT(*)
                   FROM lab_activities la
                   INNER JOIN lessons l4 ON l4.id = la.lesson_id
                   INNER JOIN phases p4 ON p4.id = l4.phase_id
                   INNER JOIN modules m6 ON m6.id = p4.module_id
                  WHERE m6.course_id = :c5
                    AND la.status <> 'archived') AS labs_total,
                (SELECT COUNT(*)
                   FROM lab_activities la2
                   INNER JOIN lessons l5 ON l5.id = la2.lesson_id
                   INNER JOIN phases p5 ON p5.id = l5.phase_id
                   INNER JOIN modules m7 ON m7.id = p5.module_id
                  WHERE m7.course_id = :c6
                    AND la2.status = 'published') AS labs_published"
        );
        $courseStatsStmt->execute([
            'c1' => $id,
            'c2' => $id,
            'c3' => $id,
            'c4' => $id,
            'c5' => $id,
            'c6' => $id,
        ]);
        $courseStats = $courseStatsStmt->fetch() ?: [];

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
                'settings' => $settings,
                'showConstructionModules' => $showConstructionModules,
                'courseStats' => $courseStats,
            ],
            (new CourseLayoutResolver())->layoutForKey($experience)
        );
    }
}
