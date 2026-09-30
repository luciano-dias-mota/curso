<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;

final class PhaseUnlockedMiddleware
{
    public function handle(array $params = []): void
    {
        if (!Auth::isStudent()) {
            return;
        }

        $phaseId = isset($params['id']) ? (int) $params['id'] : 0;

        if ($phaseId <= 0) {
            return;
        }

        $stmt = Database::connection()->prepare(
            "SELECT status
             FROM user_phase_progress
             WHERE user_id = :user_id AND phase_id = :phase_id
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => Auth::id(),
            'phase_id' => $phaseId,
        ]);

        $progress = $stmt->fetch();

        if (!$progress || $progress['status'] === 'locked') {
            http_response_code(403);
            View::render(
                'errors/locked',
                ['message' => 'Conclua a fase anterior para desbloquear esta missão.'],
                'layouts/app'
            );
            exit;
        }
    }
}
