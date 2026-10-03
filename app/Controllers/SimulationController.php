<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Services\SimulationService;
use RuntimeException;
use Throwable;

final class SimulationController extends Controller
{
    public function index(): void
    {
        try {
            $data = (new SimulationService())->dashboard((int) Auth::id());
            $this->view(
                'student/simulations/index',
                array_merge(['title' => 'Simulados'], $data),
                'layouts/student'
            );
        } catch (Throwable $e) {
            $this->logSimulationError('simulations.index', $e);
            Session::flash('error', $this->userError('Não foi possível carregar os simulados agora.', $e));
            $this->redirect('/dashboard');
        }
    }

    public function create(): void
    {
        try {
            $data = (new SimulationService())->configuration((int) Auth::id());
            $this->view(
                'student/simulations/create',
                array_merge(['title' => 'Novo simulado'], $data),
                'layouts/student'
            );
        } catch (Throwable $e) {
            $this->logSimulationError('simulations.create', $e);
            Session::flash('error', $this->userError('Não foi possível preparar um novo simulado.', $e));
            $this->redirect('/simulados');
        }
    }

    public function generate(Request $request): void
    {
        try {
            $courseId = (int) $request->input('course_id', 0);
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
            Session::flash('error', $e->getMessage());
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
            if (($attempt['status'] ?? '') === 'finished') {
                $this->redirect('/simulados/tentativa/' . $id . '/resultado');
            }
            if (($attempt['status'] ?? '') !== 'in_progress') {
                $this->redirect('/simulados');
            }

            $this->view(
                'student/simulations/attempt',
                ['title' => 'Realizar simulado', 'attempt' => $attempt],
                'layouts/student'
            );
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
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
            Session::flash('error', $e->getMessage());
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
            $this->view(
                'student/simulations/result',
                ['title' => 'Resultado do simulado', 'attempt' => $attempt],
                'layouts/student'
            );
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
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
            $this->view(
                'student/simulations/review',
                ['title' => 'Revisão do simulado', 'attempt' => $attempt],
                'layouts/student'
            );
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
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
