<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class PmmtCourseScopeService
{
    public const COURSE_SLUG = 'preparacao-pmmt-merito-intelectual-e-caoc';

    public function courseId(PDO $pdo): int
    {
        $stmt = $pdo->prepare(
            "SELECT id
             FROM courses
             WHERE slug = :slug
               AND status = 'published'
             LIMIT 1"
        );
        $stmt->execute(['slug' => self::COURSE_SLUG]);

        $courseId = (int) ($stmt->fetchColumn() ?: 0);

        if ($courseId <= 0) {
            throw new RuntimeException(
                'O curso Preparatório PMMT não está disponível no momento.'
            );
        }

        return $courseId;
    }

    public function assertCourse(PDO $pdo, int $courseId): void
    {
        if ($courseId !== $this->courseId($pdo)) {
            throw new RuntimeException(
                'Este conteúdo não pertence ao curso Preparatório PMMT.'
            );
        }
    }
}
