<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Services\CourseSettingsService;
use PDO;
use RuntimeException;
use Throwable;

final class CourseController extends Controller
{
    private const STATUSES = ['draft', 'published', 'archived'];
    private const DIFFICULTIES = ['beginner', 'intermediate', 'advanced'];


    public function index(): void
    {
        $pdo = Database::connection();

        $courses = $pdo->query(
            "SELECT c.id, c.title, c.slug, c.short_description, c.status, c.difficulty,
                    c.required_score, c.position, c.published_at, c.updated_at,
                    (SELECT COUNT(*) FROM modules m WHERE m.course_id = c.id) AS modules_count,
                    (SELECT COUNT(*) FROM phases p INNER JOIN modules m2 ON m2.id = p.module_id WHERE m2.course_id = c.id) AS phases_count,
                    (SELECT COUNT(*) FROM lessons l INNER JOIN phases p2 ON p2.id = l.phase_id INNER JOIN modules m3 ON m3.id = p2.module_id WHERE m3.course_id = c.id) AS lessons_count,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id AND e.status = 'active') AS active_students
             FROM courses c
             ORDER BY FIELD(c.status, 'published', 'draft', 'archived'), c.position, c.id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $stats = [
            'total' => count($courses),
            'published' => count(array_filter($courses, static fn (array $course): bool => $course['status'] === 'published')),
            'draft' => count(array_filter($courses, static fn (array $course): bool => $course['status'] === 'draft')),
            'active_students' => array_sum(array_map(static fn (array $course): int => (int) $course['active_students'], $courses)),
        ];

        $this->view('admin/courses/index', [
            'title' => 'Cursos',
            'courses' => $courses,
            'stats' => $stats,
        ], 'layouts/admin');
    }

    public function create(): void
    {
        $pdo = Database::connection();
        $nextPosition = (int) $pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM courses')->fetchColumn();

        $this->view('admin/courses/form', [
            'title' => 'Novo curso',
            'course' => [
                'id' => null,
                'title' => '',
                'slug' => '',
                'short_description' => '',
                'description' => '',
                'cover_image' => '',
                'status' => 'draft',
                'difficulty' => 'beginner',
                'required_score' => '70.00',
                'xp_reward' => 0,
                'position' => max(1, $nextPosition),
            ],
            'settings' => CourseSettingsService::defaults(),
            'isEdit' => false,
        ], 'layouts/admin');
    }

    public function store(Request $request): void
    {
        $pdo = Database::connection();

        try {
            $data = $this->validatedCourseData($request, $pdo);
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/admin/cursos/novo');
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO courses
                    (title, slug, short_description, description, cover_image, status,
                     difficulty, required_score, xp_reward, position, published_at, created_by)
                 VALUES
                    (:title, :slug, :short_description, :description, :cover_image, :status,
                     :difficulty, :required_score, :xp_reward, :position,
                     CASE WHEN :publish_status = 'published' THEN NOW() ELSE NULL END,
                     :created_by)"
            );
            $stmt->execute([
                'title' => $data['title'],
                'slug' => $data['slug'],
                'short_description' => $data['short_description'],
                'description' => $data['description'],
                'cover_image' => $data['cover_image'],
                'status' => $data['status'],
                'difficulty' => $data['difficulty'],
                'required_score' => $data['required_score'],
                'xp_reward' => $data['xp_reward'],
                'position' => $data['position'],
                'publish_status' => $data['status'],
                'created_by' => Auth::id(),
            ]);

            $courseId = (int) $pdo->lastInsertId();
            (new CourseSettingsService())->save($courseId, $this->settingsFromRequest($request), $pdo);
            $this->audit($pdo, 'course_created', $courseId, 'Curso criado: ' . $data['title']);

            $pdo->commit();
            Session::flash('success', 'Curso criado. Agora monte módulos, fases e aulas na estrutura acadêmica.');
            $this->redirect('/admin/cursos/' . $courseId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('admin.course.store: ' . $e->getMessage());
            Session::flash('error', 'Não foi possível criar o curso.');
            $this->redirect('/admin/cursos/novo');
        }
    }

    public function show(int $id): void
    {
        $pdo = Database::connection();
        $course = $this->findCourse($pdo, $id);
        $settings = (new CourseSettingsService())->all($id, $pdo);

        $countsStmt = $pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM modules WHERE course_id = :c1) AS modules_count,
                (SELECT COUNT(*) FROM phases p INNER JOIN modules m ON m.id = p.module_id WHERE m.course_id = :c2) AS phases_count,
                (SELECT COUNT(*) FROM lessons l INNER JOIN phases p ON p.id = l.phase_id INNER JOIN modules m ON m.id = p.module_id WHERE m.course_id = :c3) AS lessons_count,
                (SELECT COUNT(*) FROM questions WHERE course_id = :c4) AS questions_count,
                (SELECT COUNT(*) FROM enrollments WHERE course_id = :c5 AND status = 'active') AS active_students,
                (SELECT COUNT(*) FROM enrollments WHERE course_id = :c6 AND status = 'completed') AS completed_students"
        );
        $countsStmt->execute([
            'c1' => $id, 'c2' => $id, 'c3' => $id,
            'c4' => $id, 'c5' => $id, 'c6' => $id,
        ]);
        $counts = $countsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $this->view('admin/courses/show', [
            'title' => $course['title'],
            'course' => $course,
            'settings' => $settings,
            'counts' => $counts,
        ], 'layouts/admin');
    }

    public function edit(int $id): void
    {
        $pdo = Database::connection();

        $this->view('admin/courses/form', [
            'title' => 'Editar curso',
            'course' => $this->findCourse($pdo, $id),
            'settings' => (new CourseSettingsService())->all($id, $pdo),
            'isEdit' => true,
        ], 'layouts/admin');
    }

    public function update(int $id, Request $request): void
    {
        $pdo = Database::connection();
        $current = $this->findCourse($pdo, $id);

        try {
            $data = $this->validatedCourseData($request, $pdo, $id);
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/admin/cursos/' . $id . '/editar');
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "UPDATE courses
                 SET title = :title,
                     slug = :slug,
                     short_description = :short_description,
                     description = :description,
                     cover_image = :cover_image,
                     status = :status,
                     difficulty = :difficulty,
                     required_score = :required_score,
                     xp_reward = :xp_reward,
                     position = :position,
                     published_at = CASE
                         WHEN :publish_status = 'published' THEN COALESCE(published_at, NOW())
                         ELSE published_at
                     END
                 WHERE id = :id"
            );
            $stmt->execute([
                'title' => $data['title'],
                'slug' => $data['slug'],
                'short_description' => $data['short_description'],
                'description' => $data['description'],
                'cover_image' => $data['cover_image'],
                'status' => $data['status'],
                'difficulty' => $data['difficulty'],
                'required_score' => $data['required_score'],
                'xp_reward' => $data['xp_reward'],
                'position' => $data['position'],
                'publish_status' => $data['status'],
                'id' => $id,
            ]);

            (new CourseSettingsService())->save($id, $this->settingsFromRequest($request), $pdo);
            $this->audit(
                $pdo,
                'course_updated',
                $id,
                sprintf('Curso atualizado: %s -> %s', $current['title'], $data['title'])
            );

            $pdo->commit();
            Session::flash('success', 'Curso atualizado.');
            $this->redirect('/admin/cursos/' . $id);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('admin.course.update: ' . $e->getMessage());
            Session::flash('error', 'Não foi possível atualizar o curso.');
            $this->redirect('/admin/cursos/' . $id . '/editar');
        }
    }

    public function setStatus(int $id, Request $request): void
    {
        $pdo = Database::connection();
        $course = $this->findCourse($pdo, $id);
        $status = trim((string) $request->input('status', ''));

        if (!in_array($status, self::STATUSES, true)) {
            Session::flash('error', 'Status de curso inválido.');
            $this->redirect('/admin/cursos/' . $id);
        }

        $stmt = $pdo->prepare(
            "UPDATE courses
             SET status = :status,
                 published_at = CASE
                     WHEN :publish_status = 'published' THEN COALESCE(published_at, NOW())
                     ELSE published_at
                 END
             WHERE id = :id"
        );
        $stmt->execute([
            'status' => $status,
            'publish_status' => $status,
            'id' => $id,
        ]);

        $this->audit($pdo, 'course_status_changed', $id, "Status de {$course['status']} para {$status}");
        Session::flash('success', 'Status do curso atualizado.');
        $this->redirect('/admin/cursos/' . $id);
    }

    private function validatedCourseData(Request $request, PDO $pdo, ?int $ignoreId = null): array
    {
        $title = trim((string) $request->input('title', ''));
        $slugInput = trim((string) $request->input('slug', ''));
        $slug = $this->slugify($slugInput !== '' ? $slugInput : $title);
        $shortDescription = trim((string) $request->input('short_description', ''));
        $description = trim((string) $request->input('description', ''));
        $coverImage = trim((string) $request->input('cover_image', ''));
        $status = trim((string) $request->input('status', 'draft'));
        $difficulty = trim((string) $request->input('difficulty', 'beginner'));
        $requiredScore = (float) $request->input('required_score', 70);
        $xpReward = max(0, (int) $request->input('xp_reward', 0));
        $position = max(1, (int) $request->input('position', 1));

        $errors = [];
        if (mb_strlen($title) < 3 || mb_strlen($title) > 180) {
            $errors[] = 'O título deve ter entre 3 e 180 caracteres.';
        }
        if ($slug === '' || mb_strlen($slug) > 190) {
            $errors[] = 'Slug inválido.';
        }
        if (mb_strlen($shortDescription) > 500) {
            $errors[] = 'A descrição curta deve ter no máximo 500 caracteres.';
        }
        if (!in_array($status, self::STATUSES, true)) {
            $errors[] = 'Status inválido.';
        }
        if (!in_array($difficulty, self::DIFFICULTIES, true)) {
            $errors[] = 'Dificuldade inválida.';
        }
        if ($requiredScore < 0 || $requiredScore > 100) {
            $errors[] = 'A nota mínima deve ficar entre 0 e 100.';
        }
        if ($coverImage !== '' && mb_strlen($coverImage) > 255) {
            $errors[] = 'O caminho da capa deve ter no máximo 255 caracteres.';
        }

        $slugSql = 'SELECT id FROM courses WHERE slug = :slug';
        $params = ['slug' => $slug];
        if ($ignoreId !== null) {
            $slugSql .= ' AND id <> :id';
            $params['id'] = $ignoreId;
        }
        $slugSql .= ' LIMIT 1';
        $slugStmt = $pdo->prepare($slugSql);
        $slugStmt->execute($params);
        if ($slugStmt->fetchColumn()) {
            $errors[] = 'Já existe um curso com este slug.';
        }

        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }

        return [
            'title' => $title,
            'slug' => $slug,
            'short_description' => $shortDescription !== '' ? $shortDescription : null,
            'description' => $description !== '' ? $description : null,
            'cover_image' => $coverImage !== '' ? $coverImage : null,
            'status' => $status,
            'difficulty' => $difficulty,
            'required_score' => round($requiredScore, 2),
            'xp_reward' => $xpReward,
            'position' => $position,
        ];
    }

    private function settingsFromRequest(Request $request): array
    {
        $moduleMode = (string) $request->input('module_access_mode', 'free');
        if (!in_array($moduleMode, ['free', 'sequential'], true)) {
            $moduleMode = 'free';
        }

        $lessonMode = (string) $request->input('lesson_access_mode', 'sequential');
        if (!in_array($lessonMode, ['sequential', 'free'], true)) {
            $lessonMode = 'sequential';
        }

        return [
            'module_access_mode' => $moduleMode,
            'lesson_access_mode' => $lessonMode,
            'exercises_enabled' => $request->input('exercises_enabled') ? '1' : '0',
            'simulations_enabled' => $request->input('simulations_enabled') ? '1' : '0',
            'notes_enabled' => $request->input('notes_enabled') ? '1' : '0',
            'certificate_enabled' => $request->input('certificate_enabled') ? '1' : '0',
            'lab_enabled' => $request->input('lab_enabled') ? '1' : '0',
        ];
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

    private function audit(PDO $pdo, string $action, int $courseId, string $description): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs
                (user_id, action, entity_type, entity_id, description, ip_address, user_agent)
             VALUES
                (:user_id, :action, 'course', :entity_id, :description, :ip_address, :user_agent)"
        );
        $stmt->execute([
            'user_id' => Auth::id(),
            'action' => $action,
            'entity_id' => $courseId,
            'description' => $description,
            'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
    }
}
