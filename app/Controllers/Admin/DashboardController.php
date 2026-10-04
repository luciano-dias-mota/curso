<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Database;
use PDO;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $pdo = Database::connection();

        $stats = [
            'students' => (int) $pdo->query("SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE r.slug='student'")->fetchColumn(),
            'active_students' => (int) $pdo->query("SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE r.slug='student' AND u.status='active'")->fetchColumn(),
            'inactive_students' => (int) $pdo->query("SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE r.slug='student' AND u.status<>'active'")->fetchColumn(),
            'courses' => (int) $pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn(),
            'modules' => (int) $pdo->query('SELECT COUNT(*) FROM modules')->fetchColumn(),
            'questions' => (int) $pdo->query('SELECT COUNT(*) FROM questions WHERE active=1')->fetchColumn(),
            'simulations' => (int) $pdo->query("SELECT COUNT(*) FROM simulation_attempts WHERE status='finished'")->fetchColumn(),
        ];

        $recentStudents = $pdo->query(
            "SELECT u.id,u.name,u.email,u.status,u.xp_total,u.current_level,u.last_login_at,u.created_at
             FROM users u INNER JOIN roles r ON r.id=u.role_id
             WHERE r.slug='student'
             ORDER BY u.created_at DESC,u.id DESC LIMIT 6"
        )->fetchAll(PDO::FETCH_ASSOC);

        $recentAudit = $pdo->query(
            "SELECT al.action,al.description,al.created_at,u.name AS actor_name
             FROM audit_logs al
             LEFT JOIN users u ON u.id=al.user_id
             ORDER BY al.created_at DESC,al.id DESC LIMIT 8"
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->view('admin/dashboard', [
            'title' => 'Administração',
            'stats' => $stats,
            'recentStudents' => $recentStudents,
            'recentAudit' => $recentAudit,
        ], 'layouts/admin');
    }
}
