<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Services\EnrollmentService;
use PDO;
use RuntimeException;
use Throwable;

final class EnrollmentController extends Controller
{
    public function index(Request $request): void
    {
        $pdo = Database::connection();

        $studentId = max(0, (int) $request->input('student_id', 0));
        $courseId = max(0, (int) $request->input('course_id', 0));
        $status = trim((string) $request->input('status', ''));

        if (!in_array($status, ['', 'active', 'completed', 'cancelled'], true)) {
            $status = '';
        }

        $students = $pdo->query(
            "SELECT u.id, u.name, u.email, u.status
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE r.slug = 'student'
             ORDER BY u.name, u.id
             LIMIT 500"
        )->fetchAll(PDO::FETCH_ASSOC);

        $courses = $pdo->query(
            "SELECT id, title, status
             FROM courses
             WHERE status <> 'archived'
             ORDER BY position, id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $where = ['1=1'];
        $params = [];

        if ($studentId > 0) {
            $where[] = 'e.user_id = :student_id';
            $params['student_id'] = $studentId;
        }

        if ($courseId > 0) {
            $where[] = 'e.course_id = :course_id';
            $params['course_id'] = $courseId;
        }

        if ($status !== '') {
            $where[] = 'e.status = :status';
            $params['status'] = $status;
        }

        $stmt = $pdo->prepare(
            "SELECT e.id, e.user_id, e.course_id, e.status, e.enrolled_at, e.completed_at,
                    u.name AS student_name, u.email AS student_email,
                    c.title AS course_title, c.status AS course_status,
                    COALESCE(ucp.progress_pct, 0) AS progress_pct,
                    ucp.last_accessed_at
             FROM enrollments e
             INNER JOIN users u ON u.id = e.user_id
             INNER JOIN courses c ON c.id = e.course_id
             LEFT JOIN user_course_progress ucp
               ON ucp.user_id = e.user_id
              AND ucp.course_id = e.course_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY
                CASE e.status WHEN 'active' THEN 0 WHEN 'completed' THEN 1 ELSE 2 END,
                COALESCE(ucp.last_accessed_at, e.enrolled_at) DESC,
                u.name, c.position
             LIMIT 300"
        );
        $stmt->execute($params);
        $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stats = $pdo->query(
            "SELECT
                COUNT(*) AS total,
                SUM(status = 'active') AS active_count,
                SUM(status = 'completed') AS completed_count,
                SUM(status = 'cancelled') AS cancelled_count
             FROM enrollments"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $this->view('admin/enrollments/index', [
            'title' => 'Matrículas',
            'students' => $students,
            'courses' => $courses,
            'enrollments' => $enrollments,
            'stats' => $stats,
            'filters' => [
                'student_id' => $studentId,
                'course_id' => $courseId,
                'status' => $status,
            ],
        ], 'layouts/admin');
    }

    public function update(Request $request): void
    {
        $studentId = (int) $request->input('student_id', 0);
        $courseId = (int) $request->input('course_id', 0);
        $status = (string) $request->input('status', 'active');

        try {
            $result = (new EnrollmentService())->change(
                $studentId,
                $courseId,
                $status
            );

            Session::flash(
                'success',
                sprintf(
                    'Acesso de %s ao curso %s atualizado para %s.',
                    $result['student_name'],
                    $result['course_title'],
                    $this->statusLabel($result['status'])
                )
            );
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('admin.enrollment.update: ' . $e->getMessage());
            Session::flash('error', 'Não foi possível atualizar a matrícula.');
        }

        $this->redirect('/admin/matriculas');
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'ativa',
            'completed' => 'concluída',
            'cancelled' => 'cancelada',
            default => $status,
        };
    }
}
