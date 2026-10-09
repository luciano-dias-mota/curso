<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

final class StudyNoteService
{
    private const TITLE_MAX = 160;
    private const CONTENT_MAX = 20000;

    public function dashboard(
        int $userId,
        string $search = '',
        int $courseId = 0,
        int $moduleId = 0
    ): array {
        $pdo = Database::connection();

        return [
            'notes' => $this->notes($pdo, $userId, $search, $courseId, $moduleId),
            'options' => $this->scopeOptions($pdo, $userId),
            'stats' => $this->stats($pdo, $userId),
            'filters' => [
                'search' => $search,
                'courseId' => $courseId,
                'moduleId' => $moduleId,
            ],
        ];
    }

    public function find(int $userId, int $noteId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT n.*, c.title AS course_title, m.title AS module_title, p.title AS phase_title
             FROM study_notes n
             LEFT JOIN courses c ON c.id = n.course_id
             LEFT JOIN modules m ON m.id = n.module_id
             LEFT JOIN phases p ON p.id = n.phase_id
             WHERE n.id = :id
               AND n.user_id = :user_id
             LIMIT 1"
        );
        $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$note) {
            throw new RuntimeException('Anotação não encontrada.');
        }

        return $note;
    }

    public function editData(int $userId, int $noteId): array
    {
        $pdo = Database::connection();
        return [
            'note' => $this->find($userId, $noteId),
            'options' => $this->scopeOptions($pdo, $userId),
        ];
    }

    public function create(
        int $userId,
        string $title,
        string $content,
        ?int $courseId,
        ?int $moduleId,
        ?int $phaseId
    ): int {
        [$title, $content] = $this->validateText($title, $content);
        $pdo = Database::connection();
        [$courseId, $moduleId, $phaseId] = $this->validateScope(
            $pdo,
            $userId,
            $courseId,
            $moduleId,
            $phaseId
        );

        $stmt = $pdo->prepare(
            "INSERT INTO study_notes
                (user_id, course_id, module_id, phase_id, title, content, pinned, created_at, updated_at)
             VALUES
                (:user_id, :course_id, :module_id, :phase_id, :title, :content, 0, NOW(), NOW())"
        );
        $stmt->execute([
            'user_id' => $userId,
            'course_id' => $courseId,
            'module_id' => $moduleId,
            'phase_id' => $phaseId,
            'title' => $title,
            'content' => $content,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function update(
        int $userId,
        int $noteId,
        string $title,
        string $content,
        ?int $courseId,
        ?int $moduleId,
        ?int $phaseId
    ): void {
        $this->find($userId, $noteId);
        [$title, $content] = $this->validateText($title, $content);
        $pdo = Database::connection();
        [$courseId, $moduleId, $phaseId] = $this->validateScope(
            $pdo,
            $userId,
            $courseId,
            $moduleId,
            $phaseId
        );

        $stmt = $pdo->prepare(
            "UPDATE study_notes
             SET course_id = :course_id,
                 module_id = :module_id,
                 phase_id = :phase_id,
                 title = :title,
                 content = :content,
                 updated_at = NOW()
             WHERE id = :id
               AND user_id = :user_id"
        );
        $stmt->execute([
            'course_id' => $courseId,
            'module_id' => $moduleId,
            'phase_id' => $phaseId,
            'title' => $title,
            'content' => $content,
            'id' => $noteId,
            'user_id' => $userId,
        ]);
    }

    public function delete(int $userId, int $noteId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'DELETE FROM study_notes WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute(['id' => $noteId, 'user_id' => $userId]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Anotação não encontrada.');
        }
    }

    public function togglePin(int $userId, int $noteId): bool
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT pinned
                 FROM study_notes
                 WHERE id = :id
                   AND user_id = :user_id
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
            $current = $stmt->fetchColumn();
            if ($current === false) {
                throw new RuntimeException('Anotação não encontrada.');
            }

            $newValue = (int) $current === 1 ? 0 : 1;
            $pdo->prepare(
                'UPDATE study_notes SET pinned = :pinned, updated_at = NOW() WHERE id = :id'
            )->execute(['pinned' => $newValue, 'id' => $noteId]);

            $pdo->commit();
            return $newValue === 1;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function notes(
        PDO $pdo,
        int $userId,
        string $search,
        int $courseId,
        int $moduleId
    ): array {
        $where = ['n.user_id = :user_id'];
        $params = ['user_id' => $userId];

        $search = trim($search);
        if ($search !== '') {
            $where[] = '(n.title LIKE :search OR n.content LIKE :search)';
            $params['search'] = '%' . mb_substr($search, 0, 100) . '%';
        }
        if ($courseId > 0) {
            $where[] = 'n.course_id = :course_id';
            $params['course_id'] = $courseId;
        }
        if ($moduleId > 0) {
            $where[] = 'n.module_id = :module_id';
            $params['module_id'] = $moduleId;
        }

        $stmt = $pdo->prepare(
            "SELECT n.id, n.title, n.content, n.pinned, n.created_at, n.updated_at,
                    n.course_id, n.module_id, n.phase_id,
                    c.title AS course_title,
                    m.title AS module_title,
                    p.title AS phase_title
             FROM study_notes n
             LEFT JOIN courses c ON c.id = n.course_id
             LEFT JOIN modules m ON m.id = n.module_id
             LEFT JOIN phases p ON p.id = n.phase_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY n.pinned DESC, n.updated_at DESC, n.id DESC
             LIMIT 100"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function stats(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(pinned = 1) AS pinned,
                    MAX(updated_at) AS last_update
             FROM study_notes
             WHERE user_id = :user_id"
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'pinned' => (int) ($row['pinned'] ?? 0),
            'last_update' => $row['last_update'] ?? null,
        ];
    }

    private function scopeOptions(PDO $pdo, int $userId): array
    {
        $courseStmt = $pdo->prepare(
            "SELECT c.id, c.title
             FROM enrollments e
             INNER JOIN courses c ON c.id = e.course_id
             WHERE e.user_id = :user_id
               AND e.status = 'active'
               AND c.status = 'published'
             ORDER BY c.position, c.id"
        );
        $courseStmt->execute(['user_id' => $userId]);
        $courses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);
        $courseIds = array_map(static fn (array $row): int => (int) $row['id'], $courses);

        if ($courseIds === []) {
            return ['courses' => [], 'modules' => [], 'phases' => []];
        }

        $placeholders = implode(',', array_fill(0, count($courseIds), '?'));

        $moduleStmt = $pdo->prepare(
            "SELECT id, course_id, title, position
             FROM modules
             WHERE course_id IN ({$placeholders})
               AND status = 'published'
             ORDER BY course_id, position, id"
        );
        $moduleStmt->execute($courseIds);

        $phaseStmt = $pdo->prepare(
            "SELECT p.id, p.module_id, p.title, p.position, m.course_id
             FROM phases p
             INNER JOIN modules m ON m.id = p.module_id
             WHERE m.course_id IN ({$placeholders})
               AND m.status = 'published'
               AND p.status = 'published'
             ORDER BY m.course_id, m.position, p.position, p.id"
        );
        $phaseStmt->execute($courseIds);

        return [
            'courses' => $courses,
            'modules' => $moduleStmt->fetchAll(PDO::FETCH_ASSOC),
            'phases' => $phaseStmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    private function validateText(string $title, string $content): array
    {
        $title = trim($title);
        $content = trim($content);

        if (mb_strlen($title) < 3 || mb_strlen($title) > self::TITLE_MAX) {
            throw new RuntimeException('Informe um título entre 3 e 160 caracteres.');
        }
        if ($content === '') {
            throw new RuntimeException('Escreva o conteúdo da anotação.');
        }
        if (mb_strlen($content) > self::CONTENT_MAX) {
            throw new RuntimeException('A anotação pode ter no máximo 20.000 caracteres.');
        }

        return [$title, $content];
    }

    private function validateScope(
        PDO $pdo,
        int $userId,
        ?int $courseId,
        ?int $moduleId,
        ?int $phaseId
    ): array {
        $courseId = ($courseId ?? 0) > 0 ? (int) $courseId : null;
        $moduleId = ($moduleId ?? 0) > 0 ? (int) $moduleId : null;
        $phaseId = ($phaseId ?? 0) > 0 ? (int) $phaseId : null;

        if ($courseId === null) {
            return [null, null, null];
        }

        $courseStmt = $pdo->prepare(
            "SELECT 1
             FROM enrollments e
             INNER JOIN courses c ON c.id = e.course_id
             WHERE e.user_id = :user_id
               AND e.course_id = :course_id
               AND e.status = 'active'
               AND c.status = 'published'
             LIMIT 1"
        );
        $courseStmt->execute(['user_id' => $userId, 'course_id' => $courseId]);
        if (!$courseStmt->fetchColumn()) {
            throw new RuntimeException('Curso inválido para esta anotação.');
        }

        if ($moduleId === null) {
            return [$courseId, null, null];
        }

        $moduleStmt = $pdo->prepare(
            "SELECT 1
             FROM modules
             WHERE id = :module_id
               AND course_id = :course_id
               AND status = 'published'
             LIMIT 1"
        );
        $moduleStmt->execute(['module_id' => $moduleId, 'course_id' => $courseId]);
        if (!$moduleStmt->fetchColumn()) {
            throw new RuntimeException('Disciplina inválida para esta anotação.');
        }

        if ($phaseId === null) {
            return [$courseId, $moduleId, null];
        }

        $phaseStmt = $pdo->prepare(
            "SELECT 1
             FROM phases
             WHERE id = :phase_id
               AND module_id = :module_id
               AND status = 'published'
             LIMIT 1"
        );
        $phaseStmt->execute(['phase_id' => $phaseId, 'module_id' => $moduleId]);
        if (!$phaseStmt->fetchColumn()) {
            throw new RuntimeException('Tema inválido para esta anotação.');
        }

        return [$courseId, $moduleId, $phaseId];
    }
}
