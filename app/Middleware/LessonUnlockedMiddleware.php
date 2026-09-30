<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;

final class LessonUnlockedMiddleware
{
    public function handle(array $params = []): void
    {
        if (!Auth::isStudent()) return;
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) return;

        $s = Database::connection()->prepare(
            "SELECT status FROM user_lesson_progress WHERE user_id=:u AND lesson_id=:l LIMIT 1"
        );
        $s->execute(['u'=>Auth::id(),'l'=>$id]);
        $status = $s->fetchColumn();

        if (!$status || $status === 'locked') {
            http_response_code(403);
            View::render('errors/locked', ['message'=>'Conclua a aula anterior para desbloquear esta etapa.'], 'layouts/app');
            exit;
        }
    }
}
