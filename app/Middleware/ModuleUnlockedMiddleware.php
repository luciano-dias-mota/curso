<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;

final class ModuleUnlockedMiddleware
{
    public function handle(array $params = []): void
    {
        if (!Auth::isStudent()) {
            return;
        }

        $moduleId = (int) ($params['id'] ?? 0);

        if ($moduleId <= 0) {
            $this->deny('Módulo inválido.');
        }

        $stmt = Database::connection()->prepare(
            "SELECT ump.status
             FROM modules m
             INNER JOIN courses c
               ON c.id = m.course_id
              AND c.status = 'published'
             INNER JOIN enrollments e
               ON e.course_id = c.id
              AND e.user_id = :user_id
              AND e.status = 'active'
             INNER JOIN user_module_progress ump
               ON ump.module_id = m.id
              AND ump.user_id = :user_id_progress
             WHERE m.id = :module_id
               AND m.status = 'published'
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => Auth::id(),
            'user_id_progress' => Auth::id(),
            'module_id' => $moduleId,
        ]);

        $status = $stmt->fetchColumn();

        if (!$status || $status === 'locked') {
            $this->deny('Conclua o módulo anterior para desbloquear esta etapa.');
        }
    }

    private function deny(string $message): never
    {
        http_response_code(403);
        View::render('errors/locked', ['message' => $message], 'layouts/app');
        exit;
    }
}
