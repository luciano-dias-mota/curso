<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;

final class PhaseController extends Controller
{
    public function show(int $id): void
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT
                p.*,
                m.title AS module_title,
                m.id AS module_id,
                m.course_id,
                m.position AS module_position,
                COALESCE(upp.status, 'locked') AS user_status,
                COALESCE(upp.progress_pct, 0) AS user_progress,
                COALESCE(upp.best_score, 0) AS best_score
             FROM phases p
             INNER JOIN modules m ON m.id = p.module_id
             LEFT JOIN user_phase_progress upp
                ON upp.phase_id = p.id
               AND upp.user_id = :user_id
             WHERE p.id = :id
               AND p.status = 'published'
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => Auth::id(),
            'id' => $id,
        ]);
        $phase = $stmt->fetch();

        if (!$phase) {
            http_response_code(404);
            $this->view('errors/404');
            return;
        }

        $lessonsStmt = $pdo->prepare(
            "SELECT
                l.*,
                COALESCE(ulp.status, 'locked') AS user_status,
                COALESCE(ulp.progress_pct, 0) AS progress_pct
             FROM lessons l
             LEFT JOIN user_lesson_progress ulp
                ON ulp.lesson_id = l.id
               AND ulp.user_id = :user_id
             WHERE l.phase_id = :phase_id
               AND l.status = 'published'
             ORDER BY l.position"
        );
        $lessonsStmt->execute([
            'user_id' => Auth::id(),
            'phase_id' => $id,
        ]);
        $lessons = $lessonsStmt->fetchAll();

        $quizStmt = $pdo->prepare(
            "SELECT *
             FROM quizzes
             WHERE phase_id = :phase_id
               AND active = 1
             ORDER BY id
             LIMIT 1"
        );
        $quizStmt->execute(['phase_id' => $id]);
        $quiz = $quizStmt->fetch() ?: null;

        $allLessonsCompleted = !empty($lessons);
        foreach ($lessons as $lesson) {
            if ($lesson['user_status'] !== 'completed') {
                $allLessonsCompleted = false;
                break;
            }
        }

        $next = null;

        if ($allLessonsCompleted && !$quiz) {
            $nextPhaseStmt = $pdo->prepare(
                "SELECT
                    p2.id AS phase_id,
                    l2.id AS lesson_id,
                    p2.title AS phase_title,
                    l2.title AS lesson_title
                 FROM phases p2
                 INNER JOIN lessons l2
                    ON l2.phase_id = p2.id
                   AND l2.status = 'published'
                 INNER JOIN user_phase_progress upp
                    ON upp.phase_id = p2.id
                   AND upp.user_id = :user_id
                   AND upp.status IN ('available', 'in_progress', 'completed')
                 WHERE p2.module_id = :module_id
                   AND p2.status = 'published'
                   AND p2.position > :phase_position
                 ORDER BY p2.position, l2.position
                 LIMIT 1"
            );
            $nextPhaseStmt->execute([
                'user_id' => Auth::id(),
                'module_id' => $phase['module_id'],
                'phase_position' => $phase['position'],
            ]);
            $next = $nextPhaseStmt->fetch() ?: null;

            // Se acabou o módulo, procura primeira fase/aula do próximo módulo já liberado.
            if (!$next) {
                $nextModuleStmt = $pdo->prepare(
                    "SELECT
                        m2.id AS module_id,
                        p2.id AS phase_id,
                        l2.id AS lesson_id,
                        p2.title AS phase_title,
                        l2.title AS lesson_title
                     FROM modules m2
                     INNER JOIN user_module_progress ump
                        ON ump.module_id = m2.id
                       AND ump.user_id = :user_id
                       AND ump.status IN ('available', 'in_progress', 'completed')
                     INNER JOIN phases p2
                        ON p2.module_id = m2.id
                       AND p2.status = 'published'
                     INNER JOIN user_phase_progress upp
                        ON upp.phase_id = p2.id
                       AND upp.user_id = :user_id2
                       AND upp.status IN ('available', 'in_progress', 'completed')
                     INNER JOIN lessons l2
                        ON l2.phase_id = p2.id
                       AND l2.status = 'published'
                     WHERE m2.course_id = :course_id
                       AND m2.status = 'published'
                       AND m2.position > :module_position
                     ORDER BY m2.position, p2.position, l2.position
                     LIMIT 1"
                );
                $nextModuleStmt->execute([
                    'user_id' => Auth::id(),
                    'user_id2' => Auth::id(),
                    'course_id' => $phase['course_id'],
                    'module_position' => $phase['module_position'],
                ]);
                $next = $nextModuleStmt->fetch() ?: null;
            }
        }

        $this->view(
            'student/phase',
            [
                'title' => $phase['title'],
                'phase' => $phase,
                'lessons' => $lessons,
                'quiz' => $quiz,
                'allLessonsCompleted' => $allLessonsCompleted,
                'nextContent' => $next,
            ],
            'layouts/student'
        );
    }
}
