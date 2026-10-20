<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
use App\Services\CourseAccessService;
use App\Services\ProgressInitializer;
use RuntimeException;

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

        try {
            $module = (new CourseAccessService())->requireModule(
                (int) Auth::id(),
                $moduleId,
                $pdo
            );
        } catch (RuntimeException) {
            $this->deny('Este módulo não está disponível para sua matrícula.');
        }

        $courseId = (int) $module['course_id'];
        $routeCourseId = (int) ($params['courseId'] ?? 0);
        if ($routeCourseId > 0 && $routeCourseId !== $courseId) {
            $this->deny('Este módulo não pertence ao curso informado.');
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
