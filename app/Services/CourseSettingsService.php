<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class CourseSettingsService
{
    private const DEFAULTS = [
        'module_access_mode' => 'free',
        'lesson_access_mode' => 'sequential',
        'lesson_completion_mode' => 'quiz',
        'exercises_enabled' => '1',
        'simulations_enabled' => '1',
        'notes_enabled' => '1',
        'certificate_enabled' => '0',
        'lab_enabled' => '0',
    ];

    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    public function all(int $courseId, PDO $pdo): array
    {
        $settings = self::DEFAULTS;

        if ($courseId <= 0 || !$this->tableExists($pdo)) {
            return $settings;
        }

        $stmt = $pdo->prepare(
            'SELECT setting_key, setting_value FROM course_settings WHERE course_id = :course_id'
        );
        $stmt->execute(['course_id' => $courseId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }

        return $settings;
    }

    public function save(int $courseId, array $settings, PDO $pdo): void
    {
        if ($courseId <= 0 || !$this->tableExists($pdo)) {
            return;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO course_settings (course_id, setting_key, setting_value, value_type)
             VALUES (:course_id, :setting_key, :setting_value, :value_type)
             ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                value_type = VALUES(value_type)"
        );

        foreach ($settings as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }

            $stmt->execute([
                'course_id' => $courseId,
                'setting_key' => $key,
                'setting_value' => (string) $value,
                'value_type' => str_ends_with($key, '_enabled') ? 'boolean' : 'string',
            ]);
        }
    }

    public function enabled(
        int $courseId,
        string $key,
        PDO $pdo,
        bool $default = true
    ): bool {
        $fallback = $default ? '1' : '0';
        $settings = $this->all($courseId, $pdo);
        return ($settings[$key] ?? $fallback) === '1';
    }

    public function moduleAccessMode(int $courseId, PDO $pdo): string
    {
        $mode = (string) ($this->all($courseId, $pdo)['module_access_mode'] ?? 'free');
        return in_array($mode, ['free', 'sequential'], true) ? $mode : 'free';
    }

    public function lessonAccessMode(int $courseId, PDO $pdo): string
    {
        $mode = (string) ($this->all($courseId, $pdo)['lesson_access_mode'] ?? 'sequential');
        return in_array($mode, ['free', 'sequential'], true) ? $mode : 'sequential';
    }

    public function lessonCompletionMode(int $courseId, PDO $pdo): string
    {
        $mode = (string) ($this->all($courseId, $pdo)['lesson_completion_mode'] ?? 'quiz');
        return in_array($mode, ['reading', 'quiz', 'lab'], true) ? $mode : 'quiz';
    }

    private function tableExists(PDO $pdo): bool
    {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'course_settings'
             LIMIT 1"
        );
        $stmt->execute();
        return (bool) $stmt->fetchColumn();
    }
}
