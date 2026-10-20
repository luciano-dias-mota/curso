<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Services\CourseAccessService;
use App\Services\CourseContextService;
use App\Services\SimulationService;
use PDOException;
use RuntimeException;
use Throwable;

final class SimulationController extends Controller
{
    public function index(?int $courseId = null): void
    {
        try {
            $courseId = $this->resolveCourseContext($courseId);
            $data = (new SimulationService())->dashboard(
                (int) Auth::id(),
                $courseId > 0 ? $courseId : null
            );
            $this->view(
                'student/simulations/index',
                array_merge([
                    'title' => 'Simulados',
                    'activeCourseId' => $courseId,
                ], $data),
                (new CourseContextService())->currentLayout()
            );
        } catch (Throwable $e) {
            $this->logSimulationError('simulations.index', $e);
            Session::flash('error', $this->userError('Não foi possível carregar os simulados agora.', $e));
            $this->redirect('/dashboard');
        }
    }

    public function create(?int $courseId = null): void
    {
        try {
            $courseId = $this->resolveCourseContext($courseId);
            $data = (new SimulationService())->configuration(
                (int) Auth::id(),
                $courseId > 0 ? $courseId : null
            );
            $this->view(
                'student/simulations/create',
                array_merge([
                    'title' => 'Novo simulado',
                    'activeCourseId' => $courseId,
                ], $data),
                (new CourseContextService())->currentLayout()
            );
        } catch (Throwable $e) {
            $this->logSimulationError('simulations.create', $e);
            Session::flash('error', $this->userError('Não foi possível preparar um novo simulado.', $e));
            $this->redirect('/simulados');
        }
    }

    public function generate(Request $request, ?int $courseId = null): void
    {
        try {
            $routeCourseId = $this->resolveCourseContext($courseId, false);
            $courseId = $routeCourseId > 0
                ? $routeCourseId
                : (int) $request->input('course_id', 0);

            if ($courseId > 0) {
                (new CourseContextService())->activate(
                    (int) Auth::id(),
                    $courseId
                );
            }
            $questionLimit = (int) $request->input('question_limit', 20);
            $moduleId = (int) $request->input('module_id', 0);

            $attemptId = (new SimulationService())->generate(
                (int) Auth::id(),
                $courseId,
                $questionLimit,
                $moduleId > 0 ? $moduleId : null
            );

            $this->redirect('/simulados/tentativa/' . $attemptId);
        } catch (RuntimeException $e) {
            if ($e instanceof PDOException) {
                $this->logSimulationError('simulations.generate', $e);
                Session::flash('error', 'Não foi possível gerar o simulado.');
            } else {
                Session::flash('error', $e->getMessage());
            }
            $this->redirect('/simulados/novo');
        } catch (Throwable $e) {
            $this->logSimulationError('simulations.generate', $e);
            Session::flash('error', $this->userError('Não foi possível gerar o simulado.', $e));
            $this->redirect('/simulados/novo');
        }
    }

    public function attempt(int $id): void
    {
        try {
            $attempt = (new SimulationService())->attemptData($id, (int) Auth::id());
            $attemptCourseId = (int) ($attempt['course_id'] ?? 0);
            if ($attemptCourseId > 0) {
                (new CourseContextService())->activate(
                    (int) Auth::id(),
                    $attemptCourseId
                );
            }

            if (($attempt['status'] ?? '') === 'finished') {
                $this->redirect('/simulados/tentativa/' . $id . '/resultado');
            }
            if (($attempt['status'] ?? '') !== 'in_progress') {
                $this->redirect('/simulados');
            }

            $this->view(
                'student/simulations/attempt',
                ['title' => 'Realizar simulado', 'attempt' => $attempt],
                (new CourseContextService())->currentLayout()
            );
        } catch (RuntimeException $e) {
            if ($e instanceof PDOException) {
                $this->logSimulationError('simulations.attempt', $e);
                Session::flash('error', 'Não foi possível abrir esta tentativa.');
            } else {
                Session::flash('error', $e->getMessage());
            }
            $this->redirect('/simulados');
        } catch (Throwable $e) {
            $this->logSimulationError('simulations.attempt', $e);
            Session::flash('error', $this->userError('Não foi possível abrir esta tentativa.', $e));
            $this->redirect('/simulados');
        }
    }

    public function saveAnswer(Request $request, int $id): void
    {
        try {
            $result = (new SimulationService())->saveAnswer(
                $id,
                (int) Auth::id(),
                (int) $request->input('question_id', 0),
                (int) $request->input('alternative_id', 0)
            );
            $this->json(['ok' => true] + $result);
        } catch (RuntimeException $e) {
            if ($e instanceof PDOException) {
                $this->logSimulationError('simulations.saveAnswer', $e);
                $this->json(['ok' => false, 'message' => 'Não foi possível salvar a resposta.'], 500);
            }
            $this->json(['ok' => false, 'message' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            $this->logSimulationError('simulations.saveAnswer', $e);
            $this->json(['ok' => false, 'message' => 'Não foi possível salvar a resposta.'], 500);
        }
    }

    public function submit(int $id): void
    {
        try {
            (new SimulationService())->finishAttempt($id, (int) Auth::id());
            $this->redirect('/simulados/tentativa/' . $id . '/resultado');
        } catch (RuntimeException $e) {
            if ($e instanceof PDOException) {
                $this->logSimulationError('simulations.submit', $e);
                Session::flash('error', 'Não foi possível finalizar o simulado.');
            } else {
                Session::flash('error', $e->getMessage());
            }
            $this->redirect('/simulados/tentativa/' . $id);
        } catch (Throwable $e) {
            $this->logSimulationError('simulations.submit', $e);
            Session::flash('error', $this->userError('Não foi possível finalizar o simulado.', $e));
            $this->redirect('/simulados/tentativa/' . $id);
        }
    }

    public function result(int $id): void
    {
        try {
            $attempt = (new SimulationService())->resultData($id, (int) Auth::id());
            $attemptCourseId = (int) ($attempt['course_id'] ?? 0);
            if ($attemptCourseId > 0) {
                (new CourseContextService())->activate(
                    (int) Auth::id(),
                    $attemptCourseId
                );
            }

            $this->view(
                'student/simulations/result',
                ['title' => 'Resultado do simulado', 'attempt' => $attempt],
                (new CourseContextService())->currentLayout()
            );
        } catch (RuntimeException $e) {
            if ($e instanceof PDOException) {
                $this->logSimulationError('simulations.result', $e);
                Session::flash('error', 'Não foi possível carregar o resultado.');
            } else {
                Session::flash('error', $e->getMessage());
            }
            $this->redirect('/simulados');
        } catch (Throwable $e) {
            $this->logSimulationError('simulations.result', $e);
            Session::flash('error', $this->userError('Não foi possível carregar o resultado.', $e));
            $this->redirect('/simulados');
        }
    }

    public function review(int $id): void
    {
        try {
            $attempt = (new SimulationService())->resultData($id, (int) Auth::id());
            $attemptCourseId = (int) ($attempt['course_id'] ?? 0);
            if ($attemptCourseId > 0) {
                (new CourseContextService())->activate(
                    (int) Auth::id(),
                    $attemptCourseId
                );
            }

            $this->view(
                'student/simulations/review',
                ['title' => 'Revisão do simulado', 'attempt' => $attempt],
                (new CourseContextService())->currentLayout()
            );
        } catch (RuntimeException $e) {
            if ($e instanceof PDOException) {
                $this->logSimulationError('simulations.review', $e);
                Session::flash('error', 'Não foi possível abrir a revisão.');
            } else {
                Session::flash('error', $e->getMessage());
            }
            $this->redirect('/simulados');
        } catch (Throwable $e) {
            $this->logSimulationError('simulations.review', $e);
            Session::flash('error', $this->userError('Não foi possível abrir a revisão.', $e));
            $this->redirect('/simulados');
        }
    }

    public function abandon(int $id): void
    {
        try {
            (new SimulationService())->abandonAttempt($id, (int) Auth::id());
            Session::flash('success', 'Simulado abandonado. Você pode gerar outro quando quiser.');
        } catch (Throwable $e) {
            $this->logSimulationError('simulations.abandon', $e);
            Session::flash('error', $this->userError('Não foi possível abandonar a tentativa.', $e));
        }
        $this->redirect('/simulados');
    }
    private function resolveCourseContext(?int $courseId, bool $useSession = true): int
    {
        $resolved = max(0, (int) ($courseId ?? 0));

        if ($resolved <= 0 && $useSession) {
            $resolved = max(0, (int) Session::get('active_course_id', 0));
        }

        if ($resolved > 0) {
            (new CourseContextService())->activate(
                (int) Auth::id(),
                $resolved
            );
        }

        return $resolved;
    }

    private function logSimulationError(string $context, Throwable $e): void
    {
        error_log(sprintf(
            '[%s] %s: %s em %s:%d\n%s',
            $context,
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));
    }

    private function userError(string $generic, Throwable $e): string
    {
        if ((bool) config('app.debug', false)) {
            return $generic . ' Detalhe técnico: ' . $e->getMessage();
        }

        return $generic;
    }
}
