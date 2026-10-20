<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Session;
use App\Core\View;
use App\Services\CourseAccessService;
use RuntimeException;

final class LessonUnlockedMiddleware
{
    public function handle(array $params = []): void
    {
        if (!Auth::isStudent()) {
            return;
        }

        $lessonId = (int) ($params['id'] ?? 0);

        if ($lessonId <= 0) {
            $this->deny('Aula inválida.');
        }

        $pdo = Database::connection();

        try {
            $lesson = (new CourseAccessService())->requireLesson(
                (int) Auth::id(),
                $lessonId,
                $pdo
            );
        } catch (RuntimeException) {
            $this->deny('Esta aula não está disponível para sua matrícula.');
        }

        Session::put('active_course_id', (int) $lesson['course_id']);
        Session::put('active_course_experience', (string) ($lesson['experience_key'] ?? 'generic'));

        $routeCourseId = (int) ($params['courseId'] ?? 0);
        if ($routeCourseId > 0 && $routeCourseId !== (int) $lesson['course_id']) {
            $this->deny('Esta aula não pertence ao curso informado.');
        }

        $stmt = $pdo->prepare(
            "SELECT ulp.status
             FROM user_module_progress ump
             INNER JOIN user_phase_progress upp
               ON upp.user_id = ump.user_id
              AND upp.phase_id = :phase_id
              AND upp.status <> 'locked'
             INNER JOIN user_lesson_progress ulp
               ON ulp.user_id = ump.user_id
              AND ulp.lesson_id = :lesson_id
             WHERE ump.user_id = :user_id
               AND ump.module_id = :module_id
               AND ump.status <> 'locked'
             LIMIT 1"
        );
        $stmt->execute([
            'phase_id' => (int) $lesson['phase_id'],
            'lesson_id' => $lessonId,
            'user_id' => Auth::id(),
            'module_id' => (int) $lesson['module_id'],
        ]);

        $status = $stmt->fetchColumn();

        if (!$status || $status === 'locked') {
            $this->deny('Conclua a aula anterior para desbloquear esta etapa.');
        }
    }

    private function deny(string $message): never
    {
        http_response_code(403);
        View::render('errors/locked', ['message' => $message], 'layouts/app');
        exit;
    }
}
