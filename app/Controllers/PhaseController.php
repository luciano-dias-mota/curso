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
            "SELECT p.*, m.title AS module_title, m.id AS module_id, m.course_id,
                    COALESCE(upp.status, 'locked') AS user_status,
                    COALESCE(upp.progress_pct, 0) AS user_progress,
                    COALESCE(upp.best_score, 0) AS best_score,
                    COALESCE(upp.attempts_count, 0) AS attempts_count
             FROM phases p
             INNER JOIN modules m ON m.id = p.module_id
             LEFT JOIN user_phase_progress upp
               ON upp.phase_id = p.id AND upp.user_id = :user_id
             WHERE p.id = :phase_id
               AND p.status = 'published'
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => Auth::id(),
            'phase_id' => $id,
        ]);
        $phase = $stmt->fetch();

        if (!$phase) {
            http_response_code(404);
            $this->view('errors/404');
            return;
        }

        $lessonStmt = $pdo->prepare(
            "SELECT l.*,
                    COALESCE(ulp.status, 'locked') AS user_status,
                    COALESCE(ulp.progress_pct, 0) AS progress_pct
             FROM lessons l
             LEFT JOIN user_lesson_progress ulp
               ON ulp.lesson_id = l.id AND ulp.user_id = :user_id
             WHERE l.phase_id = :phase_id
               AND l.status = 'published'
             ORDER BY l.position"
        );
        $lessonStmt->execute([
            'user_id' => Auth::id(),
            'phase_id' => $id,
        ]);
        $lessons = $lessonStmt->fetchAll();

        $quizStmt = $pdo->prepare(
            "SELECT qz.*,
                    COUNT(DISTINCT qq.question_id) AS question_count,
                    COALESCE(MAX(qa.percentage), 0) AS best_percentage,
                    COUNT(DISTINCT CASE WHEN qa.status = 'finished' THEN qa.id END) AS finished_attempts
             FROM quizzes qz
             LEFT JOIN quiz_questions qq ON qq.quiz_id = qz.id
             LEFT JOIN quiz_attempts qa
               ON qa.quiz_id = qz.id AND qa.user_id = :user_id
             WHERE qz.phase_id = :phase_id
               AND qz.active = 1
               AND qz.quiz_type = 'phase_exam'
             GROUP BY qz.id
             ORDER BY qz.id
             LIMIT 1"
        );
        $quizStmt->execute([
            'user_id' => Auth::id(),
            'phase_id' => $id,
        ]);
        $quiz = $quizStmt->fetch() ?: null;

        $allRequiredCompleted = true;
        $requiredCount = 0;

        foreach ($lessons as $lesson) {
            if ((int) $lesson['is_required'] !== 1) {
                continue;
            }

            $requiredCount++;
            if ($lesson['user_status'] !== 'completed') {
                $allRequiredCompleted = false;
            }
        }

        if ($requiredCount === 0) {
            $allRequiredCompleted = false;
        }

        $this->view(
            'student/phase',
            [
                'title' => $phase['title'],
                'phase' => $phase,
                'lessons' => $lessons,
                'quiz' => $quiz,
                'allRequiredCompleted' => $allRequiredCompleted,
            ],
            'layouts/student'
        );
    }
}
