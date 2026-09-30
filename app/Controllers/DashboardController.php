<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;

final class DashboardController extends Controller
{
    public function index(): void
    {
        if (Auth::isAdmin()) {
            $this->redirect('/admin');
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT c.id, c.title, c.slug, c.short_description,
                    COALESCE(ucp.progress_pct, 0) AS progress_pct
             FROM enrollments e
             INNER JOIN courses c ON c.id = e.course_id
             LEFT JOIN user_course_progress ucp
               ON ucp.course_id = c.id AND ucp.user_id = e.user_id
             WHERE e.user_id = :user_id
               AND e.status = 'active'
               AND c.status = 'published'
             ORDER BY c.position"
        );
        $stmt->execute(['user_id' => Auth::id()]);

        $this->view(
            'student/dashboard',
            [
                'title' => 'Painel do Estudante',
                'user' => Auth::user(),
                'courses' => $stmt->fetchAll(),
            ],
            'layouts/student'
        );
    }
}
