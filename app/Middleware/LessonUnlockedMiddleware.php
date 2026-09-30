<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;

final class LessonUnlockedMiddleware
{
    public function handle(array $params = []): void
    {
        if (!Auth::isStudent()) {
            return;
        }

        $lessonId = isset($params['id']) ? (int) $params['id'] : 0;

        if ($lessonId <= 0) {
            return;
        }

        $stmt = Database::connection()->prepare(
            "SELECT upp.status
             FROM lessons l
             INNER JOIN phases p ON p.id = l.phase_id
             LEFT JOIN user_phase_progress upp
               ON upp.phase_id = p.id AND upp.user_id = :user_id
             WHERE l.id = :lesson_id
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => Auth::id(),
            'lesson_id' => $lessonId,
        ]);

        $row = $stmt->fetch();

        if (!$row || ($row['status'] ?? 'locked') === 'locked') {
            http_response_code(403);
            View::render(
                'errors/locked',
                ['message' => 'Esta aula ainda está bloqueada.'],
                'layouts/app'
            );
            exit;
        }
    }
}
