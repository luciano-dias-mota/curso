<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class LessonCompletionPolicyService
{
    private const MODES = ['reading', 'quiz', 'lab'];

    public function modeForLesson(int $lessonId, ?PDO $pdo = null): string
    {
        $pdo ??= Database::connection();

        $stmt = $pdo->prepare(
            "SELECT m.course_id
             FROM lessons l
             INNER JOIN phases p ON p.id = l.phase_id
             INNER JOIN modules m ON m.id = p.module_id
             WHERE l.id = :lesson_id
             LIMIT 1"
        );
        $stmt->execute(['lesson_id' => $lessonId]);
        $courseId = (int) ($stmt->fetchColumn() ?: 0);

        if ($courseId <= 0) {
            return 'quiz';
        }

        $override = null;
        if ($this->columnExists($pdo, 'lessons', 'completion_mode')) {
            $overrideStmt = $pdo->prepare(
                'SELECT completion_mode FROM lessons WHERE id = :lesson_id LIMIT 1'
            );
            $overrideStmt->execute(['lesson_id' => $lessonId]);
            $value = trim((string) ($overrideStmt->fetchColumn() ?: ''));
            if ($value !== '' && in_array($value, self::MODES, true)) {
                $override = $value;
            }
        }

        if ($override !== null) {
            return $override;
        }

        return (new CourseSettingsService())->lessonCompletionMode($courseId, $pdo);
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name
             LIMIT 1"
        );
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return (bool) $stmt->fetchColumn();
    }
}
