<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Session;
use PDO;

final class CourseContextService
{
    public function activate(
        int $userId,
        int $courseId,
        ?PDO $pdo = null
    ): array {
        $pdo ??= Database::connection();

        $course = (new CourseAccessService())->requireCourseEnrollment(
            $userId,
            $courseId,
            $pdo
        );

        $experience = (new CourseExperienceRegistry())->get(
            (string) ($course['experience_key'] ?? 'generic')
        );

        Session::put('active_course_id', $courseId);
        Session::put('active_course_experience', (string) $experience['key']);

        return [
            'course' => $course,
            'experience_key' => (string) $experience['key'],
            'layout' => (new CourseLayoutResolver())->layoutForKey(
                (string) $experience['key']
            ),
        ];
    }

    public function clear(): void
    {
        Session::forget('active_course_id');
        Session::forget('active_course_experience');
    }

    public function currentCourseId(): int
    {
        return max(0, (int) Session::get('active_course_id', 0));
    }

    public function currentExperienceKey(): string
    {
        return trim((string) Session::get(
            'active_course_experience',
            ''
        ));
    }

    public function currentLayout(string $fallback = 'layouts/student'): string
    {
        $key = $this->currentExperienceKey();

        if ($key === '') {
            return $fallback;
        }

        return (new CourseLayoutResolver())->layoutForKey($key);
    }
}
