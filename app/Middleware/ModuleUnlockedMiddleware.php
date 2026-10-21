<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
use App\Services\PmmtCourseScopeService;
use App\Services\ProgressInitializer;

final class ModuleUnlockedMiddleware
{
    public function handle(array $params = []): void
    {
        if (!Auth::isStudent()) {
            return;
        }

        $moduleId = (int) ($params['id'] ?? 0);

        if ($moduleId <= 0) {
            $this->deny('Módulo inválido.');
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT m.course_id
             FROM modules m
             INNER JOIN courses c
               ON c.id = m.course_id
              AND c.status = 'published'
             INNER JOIN enrollments e
               ON e.course_id = c.id
              AND e.user_id = :user_id
              AND e.status = 'active'
             WHERE m.id = :module_id
               AND m.status = 'published'
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => Auth::id(),
            'module_id' => $moduleId,
        ]);

        $courseId = (int) ($stmt->fetchColumn() ?: 0);

        if ($courseId <= 0) {
            $this->deny('Este módulo não está disponível para sua matrícula.');
        }

        if ($courseId !== (new PmmtCourseScopeService())->courseId($pdo)) {
            $this->deny('Este módulo não pertence ao curso Preparatório PMMT.');
        }

        // Autocorreção para alunos antigos: ao abrir qualquer módulo, garante
        // todos os módulos livres e mantém a sequência obrigatória interna.
        (new ProgressInitializer())->initializeEnrollment(
            (int) Auth::id(),
            $courseId,
            $pdo
        );
    }

    private function deny(string $message): never
    {
        http_response_code(403);
        View::render('errors/locked', ['message' => $message], 'layouts/app');
        exit;
    }
}
