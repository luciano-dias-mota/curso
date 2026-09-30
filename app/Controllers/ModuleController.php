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

        $stmt = $pdo->prepare(
            "SELECT m.*, c.title AS course_title
             FROM modules m
             INNER JOIN courses c ON c.id = m.course_id
             WHERE m.id = :id AND m.status = 'published'
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $module = $stmt->fetch();

        if (!$module) {
            http_response_code(404);
            $this->view('errors/404');
            return;
        }

        $phasesStmt = $pdo->prepare(
            "SELECT p.*,
                    COALESCE(upp.status, 'locked') AS user_status,
                    COALESCE(upp.progress_pct, 0) AS progress_pct,
                    COALESCE(upp.best_score, 0) AS best_score
             FROM phases p
             LEFT JOIN user_phase_progress upp
               ON upp.phase_id = p.id AND upp.user_id = :user_id
             WHERE p.module_id = :module_id
               AND p.status = 'published'
             ORDER BY p.position"
        );
        $phasesStmt->execute([
            'user_id' => Auth::id(),
            'module_id' => $id,
        ]);

        $this->view(
            'student/module',
            [
                'title' => $module['title'],
                'module' => $module,
                'phases' => $phasesStmt->fetchAll(),
            ],
            'layouts/student'
        );
    }
}
