<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class CourseExperienceRegistry
{
    private function definitions(): array
    {
        $definitions = config('course_experiences.experiences', []);

        return is_array($definitions) ? $definitions : [];
    }

    public function has(string $key): bool
    {
        return isset($this->definitions()[$key]);
    }

    public function get(string $key): array
    {
        $key = trim($key) !== '' ? trim($key) : 'generic';
        $definitions = $this->definitions();

        if (!isset($definitions[$key]) || !is_array($definitions[$key])) {
            throw new RuntimeException('Experiência de curso não registrada: ' . $key);
        }

        return ['key' => $key] + $definitions[$key];
    }

    public function descriptors(): array
    {
        $items = [];

        foreach ($this->definitions() as $key => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $items[] = ['key' => (string) $key] + $definition;
        }

        return $items;
    }

    public function entryPath(array $course): string
    {
        $definition = $this->get((string) ($course['experience_key'] ?? 'generic'));

        return str_replace(
            '{id}',
            (string) (int) ($course['id'] ?? 0),
            (string) ($definition['entry_pattern'] ?? '/curso/{id}')
        );
    }

    public function assetNamespace(array $course): string
    {
        $definition = $this->get((string) ($course['experience_key'] ?? 'generic'));

        return (string) ($definition['asset_namespace'] ?? 'generic');
    }
}
