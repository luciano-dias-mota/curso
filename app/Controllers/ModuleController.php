<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;

final class ModuleController extends Controller
{
    public function show(int $id): void
    {
        $pdo = Database::connection();
        $userId = (int) Auth::id();

        $stmt = $pdo->prepare(
            "SELECT m.*, c.title AS course_title, c.id AS course_id,
                    ump.status AS user_status,
                    COALESCE(ump.progress_pct, 0) AS progress_pct,
                    COALESCE(ump.best_score, 0) AS best_score
             FROM modules m
             INNER JOIN courses c
               ON c.id = m.course_id
              AND c.status = 'published'
             INNER JOIN enrollments e
               ON e.course_id = c.id
              AND e.user_id = :enrollment_user_id
              AND e.status = 'active'
             INNER JOIN user_module_progress ump
               ON ump.module_id = m.id
              AND ump.user_id = :progress_user_id
              AND ump.status IN ('available', 'in_progress', 'completed')
             WHERE m.id = :id
               AND m.status = 'published'
             LIMIT 1"
        );
        $stmt->execute([
            'enrollment_user_id' => $userId,
            'progress_user_id' => $userId,
            'id' => $id,
        ]);
        $module = $stmt->fetch();

        if (!$module) {
            http_response_code(403);
            $this->view(
                'errors/locked',
                ['message' => 'Este módulo ainda não está disponível para sua matrícula.']
            );
            return;
        }

        $phasesStmt = $pdo->prepare(
            "SELECT p.*,
                    COALESCE(upp.status, 'locked') AS user_status,
                    COALESCE(upp.progress_pct, 0) AS progress_pct,
                    COALESCE(upp.best_score, 0) AS best_score,
                    (SELECT COUNT(*)
                     FROM lessons l
                     WHERE l.phase_id = p.id
                       AND l.status = 'published') AS lesson_count
             FROM phases p
             LEFT JOIN user_phase_progress upp
               ON upp.phase_id = p.id
              AND upp.user_id = :user_id
             WHERE p.module_id = :module_id
               AND p.status = 'published'
             ORDER BY p.position"
        );
        $phasesStmt->execute([
            'user_id' => $userId,
            'module_id' => $id,
        ]);
        $phases = $phasesStmt->fetchAll();

        $requiredPhases = array_values(
            array_filter(
                $phases,
                static fn (array $phase): bool => (int) ($phase['is_required'] ?? 1) === 1
            )
        );

        $allPhasesComplete = $requiredPhases !== [];
        foreach ($requiredPhases as $phase) {
            if (($phase['user_status'] ?? 'locked') !== 'completed') {
                $allPhasesComplete = false;
                break;
            }
        }

        $quizStmt = $pdo->prepare(
            "SELECT qz.*, COUNT(DISTINCT qq.question_id) AS question_count,
                    COALESCE(MAX(CASE WHEN qa.user_id = :user_id
                                         AND qa.status = 'finished'
                                     THEN qa.percentage ELSE 0 END), 0) AS best_percentage,
                    COALESCE(MAX(CASE WHEN qa.user_id = :user_id2
                                         AND qa.status = 'finished'
                                         AND qa.passed = 1
                                     THEN qa.percentage ELSE 0 END), 0) AS best_passed
             FROM quizzes qz
             LEFT JOIN quiz_questions qq ON qq.quiz_id = qz.id
             LEFT JOIN quiz_attempts qa ON qa.quiz_id = qz.id
             WHERE qz.module_id = :module_id
               AND qz.quiz_type = 'module_boss'
               AND qz.active = 1
             GROUP BY qz.id
             ORDER BY qz.id
             LIMIT 1"
        );
        $quizStmt->execute([
            'user_id' => $userId,
            'user_id2' => $userId,
            'module_id' => $id,
        ]);

        $this->view(
            'student/module',
            [
                'title' => $module['title'],
                'module' => $module,
                'phases' => $phases,
                'allPhasesComplete' => $allPhasesComplete,
                'moduleQuiz' => $quizStmt->fetch() ?: null,
            ],
            'layouts/student'
        );
    }
}
