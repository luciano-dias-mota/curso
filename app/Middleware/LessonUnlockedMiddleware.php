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

        $lessonId = (int) ($params['id'] ?? 0);

        if ($lessonId <= 0) {
            $this->deny('Aula inválida.');
        }

        $stmt = Database::connection()->prepare(
            "SELECT ulp.status
             FROM lessons l
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
              AND e.user_id = :user_id
              AND e.status = 'active'
             INNER JOIN user_module_progress ump
               ON ump.module_id = m.id
              AND ump.user_id = :module_user_id
              AND ump.status <> 'locked'
             INNER JOIN user_phase_progress upp
               ON upp.phase_id = p.id
              AND upp.user_id = :phase_user_id
              AND upp.status <> 'locked'
             INNER JOIN user_lesson_progress ulp
               ON ulp.lesson_id = l.id
              AND ulp.user_id = :lesson_user_id
             WHERE l.id = :lesson_id
               AND l.status = 'published'
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => Auth::id(),
            'module_user_id' => Auth::id(),
            'phase_user_id' => Auth::id(),
            'lesson_user_id' => Auth::id(),
            'lesson_id' => $lessonId,
        ]);

        $status = $stmt->fetchColumn();

        if (!$status || $status === 'locked') {
            $this->deny('Conclua a aula anterior para desbloquear esta etapa.');
        }
    }

    private function deny(string $message): never
    {
        http_response_code(403);
        View::render('errors/locked', ['message' => $message], 'layouts/app');
        exit;
    }
}
