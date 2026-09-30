<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Services\ProgressService;

final class LessonController extends Controller
{
    public function show(Request $request, int $id): void
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT l.*, p.title AS phase_title, p.id AS phase_id,
                    p.module_id, m.title AS module_title
             FROM lessons l
             INNER JOIN phases p ON p.id = l.phase_id
             INNER JOIN modules m ON m.id = p.module_id
             WHERE l.id = :id
               AND l.status = 'published'
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $lesson = $stmt->fetch();

        if (!$lesson) {
            http_response_code(404);
            $this->view('errors/404');
            return;
        }

        $blocksStmt = $pdo->prepare(
            "SELECT *
             FROM lesson_blocks
             WHERE lesson_id = :lesson_id
             ORDER BY position"
        );
        $blocksStmt->execute(['lesson_id' => $id]);
        $blocks = $blocksStmt->fetchAll();

        $index = max(1, (int) $request->input('bloco', 1));
        $index = min($index, max(1, count($blocks)));
        $current = $blocks[$index - 1] ?? null;

        if ($current && Auth::id()) {
            (new ProgressService())->registerBlockView(
                (int) Auth::id(),
                $id,
                (int) $current['id'],
                $index
            );
        }

        $progressStmt = $pdo->prepare(
            "SELECT *
             FROM user_lesson_progress
             WHERE user_id = :user_id
               AND lesson_id = :lesson_id
             LIMIT 1"
        );
        $progressStmt->execute([
            'user_id' => Auth::id(),
            'lesson_id' => $id,
        ]);

        $quizStmt = $pdo->prepare(
            "SELECT qz.*, COUNT(qq.question_id) AS question_count
             FROM quizzes qz
             LEFT JOIN quiz_questions qq ON qq.quiz_id = qz.id
             WHERE qz.lesson_id = :lesson_id
               AND qz.quiz_type = 'lesson_fixation'
               AND qz.active = 1
             GROUP BY qz.id
             ORDER BY qz.id
             LIMIT 1"
        );
        $quizStmt->execute(['lesson_id' => $id]);

        $this->view(
            'student/lesson',
            [
                'title' => $lesson['title'],
                'lesson' => $lesson,
                'blocks' => $blocks,
                'currentBlock' => $current,
                'currentIndex' => $index,
                'totalBlocks' => count($blocks),
                'progress' => $progressStmt->fetch() ?: null,
                'lessonQuiz' => $quizStmt->fetch() ?: null,
                'user' => Auth::user(),
            ],
            'layouts/student'
        );
    }
}
