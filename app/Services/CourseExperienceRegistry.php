<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class CourseExperienceRegistry
{
    private const DEFINITIONS = [
        'generic' => [
            'label' => 'Genérica',
            'description' => 'Experiência padrão da plataforma.',
            'entry_pattern' => '/curso/{id}',
            'asset_namespace' => 'generic',
        ],
        'pmmt' => [
            'label' => 'Preparatório PMMT',
            'description' => 'Experiência legada preservada do curso PMMT.',
            'entry_pattern' => '/curso/{id}/inicio',
            'asset_namespace' => 'pmmt',
        ],
        'postgresql' => [
            'label' => 'PostgreSQL',
            'description' => 'Experiência prática com laboratórios SQL.',
            'entry_pattern' => '/curso/{id}',
            'asset_namespace' => 'postgresql',
        ],
    ];

    public function has(string $key): bool
    {
        return isset(self::DEFINITIONS[$key]);
    }

    public function get(string $key): array
    {
        $key = trim($key) !== '' ? trim($key) : 'generic';

        if (!$this->has($key)) {
            throw new RuntimeException('Experiência de curso não registrada: ' . $key);
        }

        return ['key' => $key] + self::DEFINITIONS[$key];
    }

    public function descriptors(): array
    {
        $items = [];

        foreach (self::DEFINITIONS as $key => $definition) {
            $items[] = ['key' => $key] + $definition;
        }

        return $items;
    }

    public function entryPath(array $course): string
    {
        $definition = $this->get((string) ($course['experience_key'] ?? 'generic'));

        return str_replace(
            '{id}',
            (string) (int) ($course['id'] ?? 0),
            (string) $definition['entry_pattern']
        );
    }
}
