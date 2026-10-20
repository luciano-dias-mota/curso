<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Session;
use App\Core\View;
use App\Services\CourseAccessService;
use RuntimeException;

final class PhaseUnlockedMiddleware
{
    public function handle(array $params = []): void
    {
        if (!Auth::isStudent()) {
            return;
        }

        $phaseId = (int) ($params['id'] ?? 0);

        if ($phaseId <= 0) {
            $this->deny('Fase inválida.');
        }

        $pdo = Database::connection();

        try {
            $phase = (new CourseAccessService())->requirePhase(
                (int) Auth::id(),
                $phaseId,
                $pdo
            );
        } catch (RuntimeException) {
            $this->deny('Esta fase não está disponível para sua matrícula.');
        }

        Session::put('active_course_id', (int) $phase['course_id']);
        Session::put('active_course_experience', (string) ($phase['experience_key'] ?? 'generic'));

        $routeCourseId = (int) ($params['courseId'] ?? 0);
        if ($routeCourseId > 0 && $routeCourseId !== (int) $phase['course_id']) {
            $this->deny('Esta fase não pertence ao curso informado.');
        }

        $stmt = $pdo->prepare(
            "SELECT upp.status
             FROM user_module_progress ump
             INNER JOIN user_phase_progress upp
               ON upp.user_id = ump.user_id
              AND upp.phase_id = :phase_id
             WHERE ump.user_id = :user_id
               AND ump.module_id = :module_id
               AND ump.status <> 'locked'
             LIMIT 1"
        );
        $stmt->execute([
            'phase_id' => $phaseId,
            'user_id' => Auth::id(),
            'module_id' => (int) $phase['module_id'],
        ]);

        $status = $stmt->fetchColumn();

        if (!$status || $status === 'locked') {
            $this->deny('Conclua a fase anterior para desbloquear esta missão.');
        }
    }

    private function deny(string $message): never
    {
        http_response_code(403);
        View::render('errors/locked', ['message' => $message], 'layouts/app');
        exit;
    }
}
