<?php

declare(strict_types=1);

namespace App\Services;

final class CourseExperienceService
{
    public function key(array $course): string
    {
        $explicit = trim((string) ($course['student_experience'] ?? ''));
        if ($explicit !== '' && $explicit !== 'generic') {
            return $explicit;
        }

        $slug = mb_strtolower((string) ($course['slug'] ?? ''));
        $title = mb_strtolower((string) ($course['title'] ?? ''));

        // Compatibilidade do curso legado que existia antes do portal.
        if (
            str_contains($slug, 'pmmt')
            || str_contains($title, 'pmmt')
            || str_contains($title, 'mérito intelectual')
            || str_contains($title, 'merito intelectual')
        ) {
            return 'pmmt';
        }

        if (str_contains($slug, 'postgres') || str_contains($title, 'postgres')) {
            return 'postgresql';
        }

        return 'generic';
    }

    public function entryPath(array $course): string
    {
        $id = (int) ($course['id'] ?? 0);

        return $this->key($course) === 'pmmt'
            ? '/curso/' . $id . '/inicio'
            : '/curso/' . $id;
    }
}
