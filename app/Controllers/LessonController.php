<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;

final class LessonController extends Controller
{
    public function show(Request $request, int $id): void
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT l.*, p.title AS phase_title, p.id AS phase_id
             FROM lessons l
             INNER JOIN phases p ON p.id = l.phase_id
             WHERE l.id = :id AND l.status = 'published'
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

        $this->view(
            'student/lesson',
            [
                'title' => $lesson['title'],
                'lesson' => $lesson,
                'blocks' => $blocks,
                'currentBlock' => $current,
                'currentIndex' => $index,
                'totalBlocks' => count($blocks),
                'user' => Auth::user(),
            ],
            'layouts/student'
        );
    }
}
