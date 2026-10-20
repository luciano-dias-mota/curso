<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Compatibilidade temporária para chamadas antigas.
 *
 * Novos códigos devem depender diretamente de CourseExperienceRegistry.
 */
final class CourseExperienceService
{
    public function key(array $course): string
    {
        $key = trim((string) ($course['experience_key'] ?? ''));

        return $key !== '' ? $key : 'generic';
    }

    public function entryPath(array $course): string
    {
        return (new CourseExperienceRegistry())->entryPath($course);
    }
}
