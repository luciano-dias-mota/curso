<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use PDO;

final class AdminOverviewController extends Controller
{
    public function content(): void
    {
        $pdo = Database::connection();
        $stats = [
            'courses' => (int) $pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn(),
            'modules' => (int) $pdo->query('SELECT COUNT(*) FROM modules')->fetchColumn(),
            'phases' => (int) $pdo->query('SELECT COUNT(*) FROM phases')->fetchColumn(),
            'lessons' => (int) $pdo->query('SELECT COUNT(*) FROM lessons')->fetchColumn(),
            'videos' => (int) $pdo->query("SELECT COUNT(*) FROM media_files WHERE status = 'active'")->fetchColumn(),
        ];

        $courses = $pdo->query(
            "SELECT c.id, c.title, c.status, c.difficulty, c.position,
                    (SELECT COUNT(*) FROM modules m WHERE m.course_id = c.id) AS modules_count,
                    (SELECT COUNT(*) FROM phases p INNER JOIN modules m2 ON m2.id = p.module_id WHERE m2.course_id = c.id) AS phases_count,
                    (SELECT COUNT(*) FROM lessons l INNER JOIN phases p2 ON p2.id = l.phase_id INNER JOIN modules m3 ON m3.id = p2.module_id WHERE m3.course_id = c.id) AS lessons_count
             FROM courses c
             ORDER BY c.position, c.id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->view('admin/content/index', [
            'title' => 'Cursos e conteúdo',
            'stats' => $stats,
            'courses' => $courses,
        ], 'layouts/admin');
    }

    public function questions(Request $request): void
    {
        $pdo = Database::connection();
        $search = trim((string) $request->input('q', ''));
        $difficulty = trim((string) $request->input('difficulty', ''));
        $moduleId = (int) $request->input('module_id', 0);
        $state = trim((string) $request->input('state', ''));

        $where = ['1=1'];
        $params = [];
        if ($search !== '') {
            $where[] = '(q.statement LIKE :search OR q.source_label LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        if (in_array($difficulty, ['easy', 'medium', 'hard'], true)) {
            $where[] = 'q.difficulty = :difficulty';
            $params['difficulty'] = $difficulty;
        } else {
            $difficulty = '';
        }
        if ($moduleId > 0) {
            $where[] = 'q.module_id = :module_id';
            $params['module_id'] = $moduleId;
        }
        if ($state === 'active' || $state === 'inactive') {
            $where[] = 'q.active = :active';
            $params['active'] = $state === 'active' ? 1 : 0;
        } else {
            $state = '';
        }

        $stmt = $pdo->prepare(
            "SELECT q.id, q.statement, q.difficulty, q.source_label, q.active,
                    q.updated_at, m.title AS module_title
             FROM questions q
             LEFT JOIN modules m ON m.id = q.module_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY q.id DESC
             LIMIT 150"
        );
        $stmt->execute($params);

        $stats = $pdo->query(
            "SELECT COUNT(*) AS total,
                    SUM(active = 1) AS active_count,
                    SUM(difficulty = 'medium' AND active = 1) AS medium_count,
                    SUM(difficulty = 'hard' AND active = 1) AS hard_count,
                    SUM(difficulty = 'easy' AND active = 1) AS easy_count
             FROM questions"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $modules = $pdo->query('SELECT id, title FROM modules ORDER BY position, id')->fetchAll(PDO::FETCH_ASSOC);

        $this->view('admin/questions/index', [
            'title' => 'Banco de questões',
            'questions' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'stats' => $stats,
            'modules' => $modules,
            'filters' => compact('search', 'difficulty', 'moduleId', 'state'),
        ], 'layouts/admin');
    }

    public function questionStatus(int $id, Request $request): void
    {
        $active = (int) $request->input('active', -1);
        if (!in_array($active, [0, 1], true)) {
            Session::flash('error', 'Status de questão inválido.');
            $this->redirect('/admin/questoes');
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE questions SET active = :active WHERE id = :id');
        $stmt->execute(['active' => $active, 'id' => $id]);

        $this->audit($pdo, $active ? 'question_activated' : 'question_deactivated', 'question', $id, 'Status da questão alterado pelo administrador.');
        Session::flash('success', $active ? 'Questão ativada.' : 'Questão desativada.');
        $this->redirect('/admin/questoes');
    }

    public function simulations(): void
    {
        $pdo = Database::connection();
        $stats = $pdo->query(
            "SELECT COUNT(*) AS total_attempts,
                    SUM(status = 'finished') AS finished_attempts,
                    SUM(status = 'finished' AND passed = 1) AS passed_attempts,
                    COALESCE(AVG(CASE WHEN status = 'finished' THEN percentage END), 0) AS average_percentage,
                    COUNT(DISTINCT user_id) AS students_count
             FROM simulation_attempts"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $recent = $pdo->query(
            "SELECT sa.id, sa.user_id, u.name AS student_name, u.email,
                    sa.attempt_number, sa.question_limit, sa.selection_mode,
                    sa.percentage, sa.passed, sa.status, sa.started_at, sa.finished_at,
                    m.title AS module_title, s.title AS simulation_title
             FROM simulation_attempts sa
             INNER JOIN users u ON u.id = sa.user_id
             INNER JOIN simulations s ON s.id = sa.simulation_id
             LEFT JOIN modules m ON m.id = sa.scope_module_id
             ORDER BY sa.started_at DESC, sa.id DESC
             LIMIT 100"
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->view('admin/simulations/index', [
            'title' => 'Simulados',
            'stats' => $stats,
            'attempts' => $recent,
        ], 'layouts/admin');
    }

    public function reports(): void
    {
        $rows = $this->studentReportRows(Database::connection());
        $this->view('admin/reports/index', [
            'title' => 'Relatórios',
            'rows' => $rows,
        ], 'layouts/admin');
    }

    public function exportReports(): never
    {
        $rows = $this->studentReportRows(Database::connection());
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="relatorio-alunos-' . date('Y-m-d-His') . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'wb');

        if ($out === false) {
            throw new \RuntimeException('Não foi possível iniciar a exportação CSV.');
        }

        // Ajuda o Excel a reconhecer o separador usado no arquivo.
        fwrite($out, "sep=;\r\n");

        fputcsv($out, ['ID', 'Nome', 'E-mail', 'Status', 'XP', 'Nível', 'Progresso médio', 'Quizzes', 'Média quizzes', 'Simulados', 'Média simulados', 'Último login'], ';');
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['id'],
                $this->csvSafe($row['name']),
                $this->csvSafe($row['email']),
                $this->csvSafe($row['status']),
                $row['xp_total'],
                $row['current_level'],
                $row['progress_pct'],
                $row['quiz_attempts'],
                $row['quiz_average'],
                $row['simulation_attempts'],
                $row['simulation_average'],
                $this->csvSafe($row['last_login_at']),
            ], ';');
        }
        fclose($out);
        exit;
    }

    private function csvSafe(mixed $value): string
    {
        $text = (string) $value;

        if ($text !== '' && preg_match('/^[=+\-@\t\r\n]/u', $text) === 1) {
            return "'" . $text;
        }

        return $text;
    }

    private function studentReportRows(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT u.id, u.name, u.email, u.status, u.xp_total, u.current_level, u.last_login_at,
                    COALESCE((SELECT AVG(ucp.progress_pct) FROM user_course_progress ucp WHERE ucp.user_id = u.id), 0) AS progress_pct,
                    (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.user_id = u.id AND qa.status = 'finished') AS quiz_attempts,
                    COALESCE((SELECT AVG(qa2.percentage) FROM quiz_attempts qa2 WHERE qa2.user_id = u.id AND qa2.status = 'finished'), 0) AS quiz_average,
                    (SELECT COUNT(*) FROM simulation_attempts sa WHERE sa.user_id = u.id AND sa.status = 'finished') AS simulation_attempts,
                    COALESCE((SELECT AVG(sa2.percentage) FROM simulation_attempts sa2 WHERE sa2.user_id = u.id AND sa2.status = 'finished'), 0) AS simulation_average
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE r.slug = 'student'
             ORDER BY u.name, u.id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function audit(PDO $pdo, string $action, string $entityType, int $entityId, string $description): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs
                (user_id, action, entity_type, entity_id, description, ip_address, user_agent)
             VALUES (:user_id, :action, :entity_type, :entity_id, :description, :ip_address, :user_agent)"
        );
        $stmt->execute([
            'user_id' => Auth::id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'description' => $description,
            'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
    }
}
