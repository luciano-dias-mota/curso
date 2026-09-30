<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;

final class PhaseController extends Controller
{
    public function show(int $id): void
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT p.*, m.title AS module_title
             FROM phases p
             INNER JOIN modules m ON m.id = p.module_id
             WHERE p.id = :id AND p.status = 'published'
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $phase = $stmt->fetch();

        if (!$phase) {
            http_response_code(404);
            $this->view('errors/404');
            return;
        }

        $lessonsStmt = $pdo->prepare(
            "SELECT l.*
             FROM lessons l
             WHERE l.phase_id = :phase_id
               AND l.status = 'published'
             ORDER BY l.position"
        );
        $lessonsStmt->execute(['phase_id' => $id]);

        $this->view(
            'student/phase',
            [
                'title' => $phase['title'],
                'phase' => $phase,
                'lessons' => $lessonsStmt->fetchAll(),
            ],
            'layouts/student'
        );
    }
}
