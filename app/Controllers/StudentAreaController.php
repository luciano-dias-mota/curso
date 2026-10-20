<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Services\CourseContextService;
use RuntimeException;

final class StudentAreaController extends Controller
{
    public function profile(int $courseId): void
    {
        $context = $this->courseContext($courseId);
        if ($context === null) {
            return;
        }

        $this->view(
            'student/area',
            [
                'title' => 'Meu Perfil',
                'course' => $context['course'],
                'pageType' => 'profile',
                'user' => Auth::user() ?? [],
            ],
            (string) $context['layout']
        );
    }

    public function certificates(int $courseId): void
    {
        $context = $this->courseContext($courseId);
        if ($context === null) {
            return;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT COALESCE(progress_pct, 0) AS progress_pct,
                    COALESCE(average_score, 0) AS average_score,
                    COALESCE(status, 'not_started') AS progress_status
               FROM user_course_progress
              WHERE user_id = :user_id
                AND course_id = :course_id
              LIMIT 1"
        );
        $stmt->execute([
            'user_id' => (int) Auth::id(),
            'course_id' => $courseId,
        ]);

        $progress = $stmt->fetch() ?: [
            'progress_pct' => 0,
            'average_score' => 0,
            'progress_status' => 'not_started',
        ];

        $this->view(
            'student/area',
            [
                'title' => 'Certificados',
                'course' => $context['course'],
                'pageType' => 'certificates',
                'progress' => $progress,
            ],
            (string) $context['layout']
        );
    }

    public function help(int $courseId): void
    {
        $context = $this->courseContext($courseId);
        if ($context === null) {
            return;
        }

        $this->view(
            'student/area',
            [
                'title' => 'Ajuda',
                'course' => $context['course'],
                'pageType' => 'help',
            ],
            (string) $context['layout']
        );
    }

    private function courseContext(int $courseId): ?array
    {
        try {
            return (new CourseContextService())->activate(
                (int) Auth::id(),
                $courseId,
                Database::connection()
            );
        } catch (RuntimeException) {
            http_response_code(404);
            $this->view('errors/404');
            return null;
        }
    }
}
