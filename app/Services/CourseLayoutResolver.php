<?php

declare(strict_types=1);

namespace App\Services;

final class CourseLayoutResolver
{
    public function layoutForKey(string $experienceKey): string
    {
        $definition = (new CourseExperienceRegistry())->get($experienceKey);

        return (string) ($definition['layout'] ?? 'layouts/courses/generic');
    }

    public function layoutForCourse(array $course): string
    {
        return $this->layoutForKey(
            (string) ($course['experience_key'] ?? 'generic')
        );
    }
}
