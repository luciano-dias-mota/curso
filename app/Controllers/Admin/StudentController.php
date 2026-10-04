<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use PDO;
use RuntimeException;
use Throwable;

final class StudentController extends Controller
{
    public function index(Request $request): void
    {
        $pdo = Database::connection();
        $search = trim((string) $request->input('q', ''));
        $status = trim((string) $request->input('status', ''));
        $allowedStatuses = ['active', 'inactive', 'blocked'];

        $where = ["r.slug = 'student'"];
        $params = [];

        if ($search !== '') {
            $where[] = '(u.name LIKE :search OR u.email LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        if (in_array($status, $allowedStatuses, true)) {
            $where[] = 'u.status = :status';
            $params['status'] = $status;
        } else {
            $status = '';
        }

        $sql = "SELECT u.id, u.name, u.email, u.status, u.xp_total, u.current_level,
                       u.last_study_date, u.last_login_at, u.created_at,
                       COUNT(DISTINCT CASE WHEN e.status = 'active' THEN e.course_id END) AS active_courses,
                       COALESCE(MAX(ucp.progress_pct), 0) AS max_progress
                FROM users u
                INNER JOIN roles r ON r.id = u.role_id
                LEFT JOIN enrollments e ON e.user_id = u.id
                LEFT JOIN user_course_progress ucp ON ucp.user_id = u.id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY u.id, u.name, u.email, u.status, u.xp_total, u.current_level,
                         u.last_study_date, u.last_login_at, u.created_at
                ORDER BY u.name, u.id
                LIMIT 200";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $stats = $pdo->query(
            "SELECT
                SUM(CASE WHEN u.status = 'active' THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN u.status = 'inactive' THEN 1 ELSE 0 END) AS inactive_count,
                SUM(CASE WHEN u.status = 'blocked' THEN 1 ELSE 0 END) AS blocked_count,
                COUNT(*) AS total_count
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE r.slug = 'student'"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $this->view('admin/students/index', [
            'title' => 'Gestão de alunos',
            'students' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'stats' => $stats,
            'filters' => ['q' => $search, 'status' => $status],
        ], 'layouts/admin');
    }

    public function create(): void
    {
        $pdo = Database::connection();
        $courses = $pdo->query(
            "SELECT id, title, status
             FROM courses
             WHERE status <> 'archived'
             ORDER BY position, id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->view('admin/students/create', [
            'title' => 'Novo aluno',
            'courses' => $courses,
        ], 'layouts/admin');
    }

    public function store(Request $request): void
    {
        $name = trim((string) $request->input('name', ''));
        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');
        $confirmation = (string) $request->input('password_confirmation', '');
        $courseIds = $request->input('course_ids', []);
        $courseIds = is_array($courseIds) ? array_values(array_unique(array_map('intval', $courseIds))) : [];

        $errors = [];
        if (mb_strlen($name) < 3 || mb_strlen($name) > 150) {
            $errors[] = 'Informe um nome entre 3 e 150 caracteres.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $errors[] = 'Informe um e-mail válido.';
        }
        if (mb_strlen($password) < 8) {
            $errors[] = 'A senha inicial deve possuir pelo menos 8 caracteres.';
        }
        if ($password !== $confirmation) {
            $errors[] = 'A confirmação da senha não confere.';
        }

        if ($errors !== []) {
            Session::flash('error', implode(' ', $errors));
            Session::flash('old_student', ['name' => $name, 'email' => $email, 'course_ids' => $courseIds]);
            $this->redirect('/admin/usuarios/novo');
        }

        $pdo = Database::connection();

        $exists = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $exists->execute(['email' => $email]);
        if ($exists->fetchColumn()) {
            Session::flash('error', 'Já existe um usuário cadastrado com este e-mail.');
            Session::flash('old_student', ['name' => $name, 'email' => $email, 'course_ids' => $courseIds]);
            $this->redirect('/admin/usuarios/novo');
        }

        $roleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'student' LIMIT 1")->fetchColumn();
        if ($roleId <= 0) {
            throw new RuntimeException('Perfil student não encontrado na tabela roles.');
        }

        try {
            $pdo->beginTransaction();

            $insert = $pdo->prepare(
                "INSERT INTO users
                    (role_id, name, email, password_hash, status, theme,
                     xp_total, current_level, current_streak, best_streak)
                 VALUES
                    (:role_id, :name, :email, :password_hash, 'active', 'dark', 0, 1, 0, 0)"
            );
            $insert->execute([
                'role_id' => $roleId,
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $studentId = (int) $pdo->lastInsertId();

            if ($courseIds !== []) {
                $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
                $validStmt = $pdo->prepare(
                    "SELECT id FROM courses WHERE id IN ({$placeholders}) AND status <> 'archived'"
                );
                $validStmt->execute($courseIds);
                $validCourseIds = array_map('intval', $validStmt->fetchAll(PDO::FETCH_COLUMN));

                $enroll = $pdo->prepare(
                    "INSERT INTO enrollments (user_id, course_id, status, enrolled_at)
                     VALUES (:user_id, :course_id, 'active', NOW())"
                );
                foreach ($validCourseIds as $courseId) {
                    $enroll->execute(['user_id' => $studentId, 'course_id' => $courseId]);
                }
            }

            $this->audit($pdo, 'student_created', $studentId, "Aluno criado: {$name} <{$email}>");
            $pdo->commit();

            Session::flash('success', 'Aluno cadastrado com sucesso.');
            $this->redirect('/admin/usuarios/' . $studentId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Erro ao cadastrar aluno: ' . $e->getMessage());
            Session::flash('error', 'Não foi possível cadastrar o aluno. Verifique os dados e tente novamente.');
            $this->redirect('/admin/usuarios/novo');
        }
    }

    public function show(int $id): void
    {
        $pdo = Database::connection();
        $student = $this->findStudent($pdo, $id);

        $enrollmentsStmt = $pdo->prepare(
            "SELECT e.id, e.course_id, e.status, e.enrolled_at, e.completed_at,
                    c.title AS course_title,
                    COALESCE(ucp.progress_pct, 0) AS progress_pct,
                    COALESCE(ucp.average_score, 0) AS average_score,
                    ucp.last_accessed_at
             FROM enrollments e
             INNER JOIN courses c ON c.id = e.course_id
             LEFT JOIN user_course_progress ucp
               ON ucp.user_id = e.user_id AND ucp.course_id = e.course_id
             WHERE e.user_id = :user_id
             ORDER BY c.position, c.id"
        );
        $enrollmentsStmt->execute(['user_id' => $id]);
        $enrollments = $enrollmentsStmt->fetchAll(PDO::FETCH_ASSOC);

        $courses = $pdo->query(
            "SELECT id, title, status FROM courses WHERE status <> 'archived' ORDER BY position, id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $summaryStmt = $pdo->prepare(
            "SELECT
               (SELECT COUNT(*) FROM user_lesson_progress WHERE user_id = :u1 AND status = 'completed') AS lessons_completed,
               (SELECT COUNT(*) FROM user_phase_progress WHERE user_id = :u2 AND status = 'completed') AS phases_completed,
               (SELECT COUNT(*) FROM user_module_progress WHERE user_id = :u3 AND status = 'completed') AS modules_completed,
               (SELECT COUNT(*) FROM quiz_attempts WHERE user_id = :u4 AND status = 'finished') AS quiz_attempts,
               (SELECT COALESCE(AVG(percentage), 0) FROM quiz_attempts WHERE user_id = :u5 AND status = 'finished') AS quiz_average,
               (SELECT COUNT(*) FROM simulation_attempts WHERE user_id = :u6 AND status = 'finished') AS simulation_attempts,
               (SELECT COALESCE(AVG(percentage), 0) FROM simulation_attempts WHERE user_id = :u7 AND status = 'finished') AS simulation_average"
        );
        $summaryStmt->execute([
            'u1' => $id, 'u2' => $id, 'u3' => $id, 'u4' => $id,
            'u5' => $id, 'u6' => $id, 'u7' => $id,
        ]);
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $xpStmt = $pdo->prepare(
            "SELECT event_type, reference_id, xp_amount, description, created_at
             FROM xp_events WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC LIMIT 60"
        );
        $xpStmt->execute(['user_id' => $id]);

        $quizStmt = $pdo->prepare(
            "SELECT qa.id, qa.attempt_number, qa.percentage, qa.passed, qa.status,
                    qa.started_at, qa.finished_at, q.title AS quiz_title, q.quiz_type
             FROM quiz_attempts qa
             INNER JOIN quizzes q ON q.id = qa.quiz_id
             WHERE qa.user_id = :user_id
             ORDER BY qa.started_at DESC, qa.id DESC LIMIT 30"
        );
        $quizStmt->execute(['user_id' => $id]);

        $simulationStmt = $pdo->prepare(
            "SELECT sa.id, sa.attempt_number, sa.question_limit, sa.time_limit_minutes,
                    sa.selection_mode, sa.percentage, sa.passed, sa.status,
                    sa.started_at, sa.finished_at,
                    s.title AS simulation_title, m.title AS module_title
             FROM simulation_attempts sa
             INNER JOIN simulations s ON s.id = sa.simulation_id
             LEFT JOIN modules m ON m.id = sa.scope_module_id
             WHERE sa.user_id = :user_id
             ORDER BY sa.started_at DESC, sa.id DESC LIMIT 30"
        );
        $simulationStmt->execute(['user_id' => $id]);

        $auditStmt = $pdo->prepare(
            "SELECT al.action, al.description, al.created_at,
                    actor.name AS actor_name
             FROM audit_logs al
             LEFT JOIN users actor ON actor.id = al.user_id
             WHERE al.entity_type = 'student' AND al.entity_id = :student_id
             ORDER BY al.created_at DESC, al.id DESC LIMIT 60"
        );
        $auditStmt->execute(['student_id' => $id]);

        $this->view('admin/students/show', [
            'title' => 'Aluno • ' . $student['name'],
            'student' => $student,
            'enrollments' => $enrollments,
            'courses' => $courses,
            'summary' => $summary,
            'xpEvents' => $xpStmt->fetchAll(PDO::FETCH_ASSOC),
            'quizAttempts' => $quizStmt->fetchAll(PDO::FETCH_ASSOC),
            'simulationAttempts' => $simulationStmt->fetchAll(PDO::FETCH_ASSOC),
            'auditEvents' => $auditStmt->fetchAll(PDO::FETCH_ASSOC),
        ], 'layouts/admin');
    }

    public function update(int $id, Request $request): void
    {
        $pdo = Database::connection();
        $student = $this->findStudent($pdo, $id);
        $name = trim((string) $request->input('name', ''));
        $email = mb_strtolower(trim((string) $request->input('email', '')));

        if (mb_strlen($name) < 3 || mb_strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::flash('error', 'Nome ou e-mail inválido.');
            $this->redirect('/admin/usuarios/' . $id);
        }

        $dup = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
        $dup->execute(['email' => $email, 'id' => $id]);
        if ($dup->fetchColumn()) {
            Session::flash('error', 'Este e-mail já está sendo usado por outro usuário.');
            $this->redirect('/admin/usuarios/' . $id);
        }

        $stmt = $pdo->prepare('UPDATE users SET name = :name, email = :email WHERE id = :id');
        $stmt->execute(['name' => $name, 'email' => $email, 'id' => $id]);
        $this->audit($pdo, 'student_updated', $id, "Dados alterados de {$student['email']} para {$email}");

        Session::flash('success', 'Dados do aluno atualizados.');
        $this->redirect('/admin/usuarios/' . $id);
    }

    public function setStatus(int $id, Request $request): void
    {
        $pdo = Database::connection();
        $student = $this->findStudent($pdo, $id);
        $status = (string) $request->input('status', '');

        if (!in_array($status, ['active', 'inactive', 'blocked'], true)) {
            Session::flash('error', 'Status de aluno inválido.');
            $this->redirect('/admin/usuarios/' . $id);
        }

        $stmt = $pdo->prepare('UPDATE users SET status = :status, remember_token = NULL WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
        $this->audit($pdo, 'student_status_changed', $id, "Status alterado de {$student['status']} para {$status}");

        Session::flash('success', 'Status do aluno atualizado.');
        $this->redirect('/admin/usuarios/' . $id);
    }

    public function resetPassword(int $id, Request $request): void
    {
        $pdo = Database::connection();
        $this->findStudent($pdo, $id);
        $password = (string) $request->input('password', '');
        $confirmation = (string) $request->input('password_confirmation', '');

        if (mb_strlen($password) < 8) {
            Session::flash('error', 'A nova senha deve possuir pelo menos 8 caracteres.');
            $this->redirect('/admin/usuarios/' . $id);
        }
        if ($password !== $confirmation) {
            Session::flash('error', 'A confirmação da nova senha não confere.');
            $this->redirect('/admin/usuarios/' . $id);
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'UPDATE users SET password_hash = :hash, remember_token = NULL WHERE id = :id'
            );
            $stmt->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $id]);
            $deleteTokens = $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :id');
            $deleteTokens->execute(['id' => $id]);
            $this->audit($pdo, 'student_password_reset', $id, 'Senha do aluno redefinida pelo administrador.');
            $pdo->commit();

            Session::flash('success', 'Senha redefinida com sucesso. Informe a nova senha ao aluno por um canal seguro.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Erro no reset de senha do aluno #' . $id . ': ' . $e->getMessage());
            Session::flash('error', 'Não foi possível redefinir a senha.');
        }

        $this->redirect('/admin/usuarios/' . $id);
    }

    public function updateEnrollment(int $id, Request $request): void
    {
        $pdo = Database::connection();
        $this->findStudent($pdo, $id);
        $courseId = (int) $request->input('course_id', 0);
        $status = (string) $request->input('status', 'active');

        if ($courseId <= 0 || !in_array($status, ['active', 'completed', 'cancelled'], true)) {
            Session::flash('error', 'Dados de matrícula inválidos.');
            $this->redirect('/admin/usuarios/' . $id);
        }

        $course = $pdo->prepare("SELECT id, title FROM courses WHERE id = :id AND status <> 'archived' LIMIT 1");
        $course->execute(['id' => $courseId]);
        $courseRow = $course->fetch(PDO::FETCH_ASSOC);
        if (!$courseRow) {
            Session::flash('error', 'Curso não encontrado ou arquivado.');
            $this->redirect('/admin/usuarios/' . $id);
        }

        $existing = $pdo->prepare('SELECT id FROM enrollments WHERE user_id = :user_id AND course_id = :course_id LIMIT 1');
        $existing->execute(['user_id' => $id, 'course_id' => $courseId]);
        $enrollmentId = (int) ($existing->fetchColumn() ?: 0);

        if ($enrollmentId > 0) {
            $stmt = $pdo->prepare(
                "UPDATE enrollments
                 SET status = :status,
                     completed_at = CASE WHEN :status_completed = 'completed' THEN NOW() ELSE NULL END
                 WHERE id = :id"
            );
            $stmt->execute(['status' => $status, 'status_completed' => $status, 'id' => $enrollmentId]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO enrollments (user_id, course_id, status, enrolled_at, completed_at)
                 VALUES (:user_id, :course_id, :status, NOW(),
                         CASE WHEN :status_completed = 'completed' THEN NOW() ELSE NULL END)"
            );
            $stmt->execute([
                'user_id' => $id,
                'course_id' => $courseId,
                'status' => $status,
                'status_completed' => $status,
            ]);
        }

        $this->audit($pdo, 'student_enrollment_changed', $id, "Matrícula em {$courseRow['title']} alterada para {$status}");
        Session::flash('success', 'Matrícula atualizada.');
        $this->redirect('/admin/usuarios/' . $id);
    }

    public function destroy(int $id, Request $request): void
    {
        $pdo = Database::connection();
        $student = $this->findStudent($pdo, $id);
        $confirmation = mb_strtolower(trim((string) $request->input('confirm_email', '')));

        if ($confirmation !== mb_strtolower((string) $student['email'])) {
            Session::flash('error', 'Para excluir definitivamente, digite exatamente o e-mail do aluno.');
            $this->redirect('/admin/usuarios/' . $id);
        }

        try {
            $pdo->beginTransaction();
            $this->audit(
                $pdo,
                'student_deleted',
                $id,
                "Aluno excluído definitivamente: {$student['name']} <{$student['email']}>"
            );
            $delete = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $delete->execute(['id' => $id]);
            $pdo->commit();

            Session::flash('success', 'Aluno e dados vinculados excluídos definitivamente. O registro administrativo da ação foi preservado.');
            $this->redirect('/admin/usuarios');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Erro ao excluir aluno #' . $id . ': ' . $e->getMessage());
            Session::flash('error', 'A exclusão não pôde ser concluída. Prefira desativar o aluno e verifique vínculos existentes.');
            $this->redirect('/admin/usuarios/' . $id);
        }
    }

    private function findStudent(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare(
            "SELECT u.*
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND r.slug = 'student'
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            http_response_code(404);
            Session::flash('error', 'Aluno não encontrado.');
            $this->redirect('/admin/usuarios');
        }

        return $student;
    }

    private function audit(PDO $pdo, string $action, int $studentId, string $description): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs
                (user_id, action, entity_type, entity_id, description, ip_address, user_agent)
             VALUES
                (:user_id, :action, 'student', :entity_id, :description, :ip_address, :user_agent)"
        );
        $stmt->execute([
            'user_id' => Auth::id(),
            'action' => $action,
            'entity_id' => $studentId,
            'description' => $description,
            'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
    }
}
