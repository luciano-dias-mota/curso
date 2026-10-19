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

final class CurriculumController extends Controller
{
    public function show(int $id): void
    {
        $pdo = Database::connection();
        $course = $this->course($pdo, $id);

        $stmt = $pdo->prepare(
            "SELECT m.id AS module_id, m.title AS module_title, m.slug AS module_slug,
                    m.position AS module_position, m.status AS module_status,
                    m.required_score AS module_required_score,
                    p.id AS phase_id, p.title AS phase_title, p.slug AS phase_slug,
                    p.position AS phase_position, p.status AS phase_status,
                    p.phase_type, p.required_score AS phase_required_score,
                    COALESCE(p.is_required, 1) AS phase_is_required,
                    l.id AS lesson_id, l.title AS lesson_title, l.slug AS lesson_slug,
                    l.position AS lesson_position, l.status AS lesson_status,
                    l.estimated_minutes, COALESCE(l.is_required, 1) AS lesson_is_required
             FROM modules m
             LEFT JOIN phases p ON p.module_id = m.id
             LEFT JOIN lessons l ON l.phase_id = p.id
             WHERE m.course_id = :course_id
             ORDER BY m.position, m.id, p.position, p.id, l.position, l.id"
        );
        $stmt->execute(['course_id' => $id]);

        $tree = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $moduleId = (int) $row['module_id'];
            if (!isset($tree[$moduleId])) {
                $tree[$moduleId] = [
                    'id' => $moduleId,
                    'title' => $row['module_title'],
                    'slug' => $row['module_slug'],
                    'position' => (int) $row['module_position'],
                    'status' => $row['module_status'],
                    'required_score' => (float) $row['module_required_score'],
                    'phases' => [],
                ];
            }

            if ($row['phase_id'] === null) {
                continue;
            }

            $phaseId = (int) $row['phase_id'];
            if (!isset($tree[$moduleId]['phases'][$phaseId])) {
                $tree[$moduleId]['phases'][$phaseId] = [
                    'id' => $phaseId,
                    'title' => $row['phase_title'],
                    'slug' => $row['phase_slug'],
                    'position' => (int) $row['phase_position'],
                    'status' => $row['phase_status'],
                    'phase_type' => $row['phase_type'],
                    'required_score' => (float) $row['phase_required_score'],
                    'is_required' => (int) $row['phase_is_required'],
                    'lessons' => [],
                ];
            }

            if ($row['lesson_id'] !== null) {
                $tree[$moduleId]['phases'][$phaseId]['lessons'][] = [
                    'id' => (int) $row['lesson_id'],
                    'title' => $row['lesson_title'],
                    'slug' => $row['lesson_slug'],
                    'position' => (int) $row['lesson_position'],
                    'status' => $row['lesson_status'],
                    'estimated_minutes' => (int) $row['estimated_minutes'],
                    'is_required' => (int) $row['lesson_is_required'],
                ];
            }
        }

        $this->view('admin/courses/structure', [
            'title' => 'Estrutura • ' . $course['title'],
            'course' => $course,
            'modules' => array_values($tree),
        ], 'layouts/admin');
    }

    public function storeModule(int $id, Request $request): void
    {
        $pdo = Database::connection();
        $course = $this->course($pdo, $id);

        try {
            $title = $this->requiredTitle($request, 'title', 180);
            $position = $this->requestedPosition($request);
            if ($position === null) {
                $position = $this->nextPosition($pdo, 'modules', 'course_id', $id);
            }
            $slug = $this->uniqueSlug($pdo, 'modules', 'course_id', $id, $title, (string) $request->input('slug', ''));

            $stmt = $pdo->prepare(
                "INSERT INTO modules
                    (course_id, title, slug, description, icon, position, required_score, xp_reward, status)
                 VALUES
                    (:course_id, :title, :slug, :description, :icon, :position, :required_score, :xp_reward, :status)"
            );
            $stmt->execute([
                'course_id' => $id,
                'title' => $title,
                'slug' => $slug,
                'description' => $this->nullableText($request->input('description')),
                'icon' => $this->nullableLimited($request->input('icon'), 100),
                'position' => $position,
                'required_score' => $this->score($request->input('required_score', $course['required_score'] ?? 70)),
                'xp_reward' => max(0, (int) $request->input('xp_reward', 0)),
                'status' => $this->status($request->input('status', 'draft')),
            ]);

            $this->audit($pdo, 'module_created', (int) $pdo->lastInsertId(), 'Módulo criado em ' . $course['title'] . ': ' . $title);
            Session::flash('success', 'Módulo adicionado.');
        } catch (Throwable $e) {
            error_log('admin.curriculum.module: ' . $e->getMessage());
            Session::flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível adicionar o módulo.');
        }

        $this->redirect('/admin/cursos/' . $id . '/estrutura');
    }

    public function storePhase(int $id, int $moduleId, Request $request): void
    {
        $pdo = Database::connection();
        $this->course($pdo, $id);
        $module = $this->module($pdo, $id, $moduleId);

        try {
            $title = $this->requiredTitle($request, 'title', 180);
            $position = $this->requestedPosition($request);
            if ($position === null) {
                $position = $this->nextPosition($pdo, 'phases', 'module_id', $moduleId);
            }
            $slug = $this->uniqueSlug($pdo, 'phases', 'module_id', $moduleId, $title, (string) $request->input('slug', ''));
            $phaseType = (string) $request->input('phase_type', 'normal');
            if (!in_array($phaseType, ['normal', 'checkpoint', 'boss', 'review'], true)) {
                throw new RuntimeException('Tipo de fase inválido.');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO phases
                    (module_id, title, slug, description, phase_type, position,
                     required_score, required_lessons_pct, max_attempts, xp_reward, is_required, status)
                 VALUES
                    (:module_id, :title, :slug, :description, :phase_type, :position,
                     :required_score, 100, NULL, :xp_reward, :is_required, :status)"
            );
            $stmt->execute([
                'module_id' => $moduleId,
                'title' => $title,
                'slug' => $slug,
                'description' => $this->nullableText($request->input('description')),
                'phase_type' => $phaseType,
                'position' => $position,
                'required_score' => $this->score($request->input('required_score', $module['required_score'] ?? 70)),
                'xp_reward' => max(0, (int) $request->input('xp_reward', 50)),
                'is_required' => $request->input('is_required') ? 1 : 0,
                'status' => $this->status($request->input('status', 'draft')),
            ]);

            $this->audit($pdo, 'phase_created', (int) $pdo->lastInsertId(), 'Fase criada no módulo ' . $module['title'] . ': ' . $title);
            Session::flash('success', 'Fase adicionada.');
        } catch (Throwable $e) {
            error_log('admin.curriculum.phase: ' . $e->getMessage());
            Session::flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível adicionar a fase.');
        }

        $this->redirect('/admin/cursos/' . $id . '/estrutura');
    }

    public function storeLesson(int $id, int $phaseId, Request $request): void
    {
        $pdo = Database::connection();
        $this->course($pdo, $id);
        $phase = $this->phase($pdo, $id, $phaseId);

        try {
            $title = $this->requiredTitle($request, 'title', 200);
            $position = $this->requestedPosition($request);
            if ($position === null) {
                $position = $this->nextPosition($pdo, 'lessons', 'phase_id', $phaseId);
            }
            $slug = $this->uniqueSlug($pdo, 'lessons', 'phase_id', $phaseId, $title, (string) $request->input('slug', ''));

            $stmt = $pdo->prepare(
                "INSERT INTO lessons
                    (phase_id, title, slug, summary, position, estimated_minutes,
                     xp_reward, is_required, status)
                 VALUES
                    (:phase_id, :title, :slug, :summary, :position, :estimated_minutes,
                     :xp_reward, :is_required, :status)"
            );
            $stmt->execute([
                'phase_id' => $phaseId,
                'title' => $title,
                'slug' => $slug,
                'summary' => $this->nullableText($request->input('summary')),
                'position' => $position,
                'estimated_minutes' => max(1, min(1440, (int) $request->input('estimated_minutes', 10))),
                'xp_reward' => max(0, (int) $request->input('xp_reward', 10)),
                'is_required' => $request->input('is_required') ? 1 : 0,
                'status' => $this->status($request->input('status', 'draft')),
            ]);

            $lessonId = (int) $pdo->lastInsertId();
            $this->audit($pdo, 'lesson_created', $lessonId, 'Aula criada na fase ' . $phase['title'] . ': ' . $title);
            Session::flash('success', 'Aula adicionada. Você já pode editar o conteúdo dela.');
        } catch (Throwable $e) {
            error_log('admin.curriculum.lesson: ' . $e->getMessage());
            Session::flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível adicionar a aula.');
        }

        $this->redirect('/admin/cursos/' . $id . '/estrutura');
    }

    private function course(PDO $pdo, int $courseId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM courses WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $courseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            $this->view('errors/404');
            exit;
        }

        return $row;
    }

    private function module(PDO $pdo, int $courseId, int $moduleId): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM modules WHERE id = :id AND course_id = :course_id LIMIT 1'
        );
        $stmt->execute(['id' => $moduleId, 'course_id' => $courseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Módulo inválido para este curso.');
        }

        return $row;
    }

    private function phase(PDO $pdo, int $courseId, int $phaseId): array
    {
        $stmt = $pdo->prepare(
            "SELECT p.*
             FROM phases p
             INNER JOIN modules m ON m.id = p.module_id
             WHERE p.id = :id
               AND m.course_id = :course_id
             LIMIT 1"
        );
        $stmt->execute(['id' => $phaseId, 'course_id' => $courseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Fase inválida para este curso.');
        }

        return $row;
    }

    private function requiredTitle(Request $request, string $field, int $max): string
    {
        $value = trim((string) $request->input($field, ''));
        if (mb_strlen($value) < 2 || mb_strlen($value) > $max) {
            throw new RuntimeException("Informe um título entre 2 e {$max} caracteres.");
        }
        return $value;
    }

    private function requestedPosition(Request $request): ?int
    {
        $raw = trim((string) $request->input('position', ''));
        if ($raw === '') {
            return null;
        }

        $position = (int) $raw;
        if ($position <= 0) {
            throw new RuntimeException('A posição deve ser maior que zero.');
        }

        return $position;
    }

    private function nextPosition(PDO $pdo, string $table, string $parentColumn, int $parentId): int
    {
        $allowed = [
            'modules' => 'course_id',
            'phases' => 'module_id',
            'lessons' => 'phase_id',
        ];
        if (($allowed[$table] ?? null) !== $parentColumn) {
            throw new RuntimeException('Estrutura acadêmica inválida.');
        }

        $stmt = $pdo->prepare(
            "SELECT COALESCE(MAX(position), 0) + 1 FROM {$table} WHERE {$parentColumn} = :parent_id"
        );
        $stmt->execute(['parent_id' => $parentId]);
        return max(1, (int) $stmt->fetchColumn());
    }

    private function uniqueSlug(
        PDO $pdo,
        string $table,
        string $parentColumn,
        int $parentId,
        string $title,
        string $requestedSlug
    ): string {
        $base = $this->slugify($requestedSlug !== '' ? $requestedSlug : $title);
        if ($base === '') {
            $base = 'item';
        }

        $candidate = $base;
        $suffix = 2;
        while (true) {
            $stmt = $pdo->prepare(
                "SELECT id FROM {$table}
                 WHERE {$parentColumn} = :parent_id
                   AND slug = :slug
                 LIMIT 1"
            );
            $stmt->execute(['parent_id' => $parentId, 'slug' => $candidate]);
            if (!$stmt->fetchColumn()) {
                return $candidate;
            }
            $candidate = $base . '-' . $suffix++;
        }
    }

    private function slugify(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii !== false) {
            $value = $ascii;
        }

        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
        return trim(strtolower($value), '-');
    }

    private function score(mixed $value): float
    {
        $score = (float) $value;
        if ($score < 0 || $score > 100) {
            throw new RuntimeException('A nota mínima deve ficar entre 0 e 100.');
        }
        return round($score, 2);
    }

    private function status(mixed $value): string
    {
        $status = (string) $value;
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            throw new RuntimeException('Status inválido.');
        }
        return $status;
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    private function nullableLimited(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new RuntimeException("O campo deve ter no máximo {$max} caracteres.");
        }
        return $value;
    }

    private function audit(PDO $pdo, string $action, int $entityId, string $description): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs
                (user_id, action, entity_type, entity_id, description, ip_address, user_agent)
             VALUES
                (:user_id, :action, 'curriculum', :entity_id, :description, :ip_address, :user_agent)"
        );
        $stmt->execute([
            'user_id' => Auth::id(),
            'action' => $action,
            'entity_id' => $entityId,
            'description' => $description,
            'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
    }
}
