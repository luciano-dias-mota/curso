<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Database;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $pdo = Database::connection();

        $stats = [
            'students' => (int) $pdo->query(
                "SELECT COUNT(*) FROM users u
                 INNER JOIN roles r ON r.id = u.role_id
                 WHERE r.slug = 'student'"
            )->fetchColumn(),
            'courses' => (int) $pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn(),
            'modules' => (int) $pdo->query('SELECT COUNT(*) FROM modules')->fetchColumn(),
            'questions' => (int) $pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn(),
        ];

        $this->view(
            'admin/dashboard',
            [
                'title' => 'Administração',
                'stats' => $stats,
            ],
            'layouts/admin'
        );
    }
}
