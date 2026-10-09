<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Services\ExerciseService;
use RuntimeException;
use Throwable;

final class ExerciseController extends Controller
{
    public function index(): void
    {
        try {
            $data = (new ExerciseService())->dashboard((int) Auth::id());
            $this->view(
                'student/exercises/index',
                array_merge(['title' => 'Exercícios'], $data),
                'layouts/student'
            );
        } catch (Throwable $e) {
            error_log('exercise.index: ' . $e->getMessage());
            Session::flash('error', 'Não foi possível carregar a central de exercícios agora.');
            $this->redirect('/dashboard');
        }
    }

    public function generate(Request $request): void
    {
        try {
            $courseId = (int) $request->input('course_id', 0);
            $moduleId = (int) $request->input('module_id', 0);
            $phaseId = (int) $request->input('phase_id', 0);
            $questionLimit = (int) $request->input('question_limit', 10);

            $sessionId = (new ExerciseService())->generate(
                (int) Auth::id(),
                $courseId,
                $questionLimit,
                $moduleId > 0 ? $moduleId : null,
                $phaseId > 0 ? $phaseId : null
            );

            $this->redirect('/exercicios/sessao/' . $sessionId);
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/exercicios');
        } catch (Throwable $e) {
            error_log('exercise.generate: ' . $e->getMessage());
            Session::flash('error', 'Não foi possível gerar os exercícios agora.');
            $this->redirect('/exercicios');
        }
    }

    public function session(int $id): void
    {
        try {
            $data = (new ExerciseService())->sessionData($id, (int) Auth::id());

            if (($data['session']['status'] ?? '') === 'finished' || empty($data['current'])) {
                $this->redirect('/exercicios/sessao/' . $id . '/resultado');
            }

            $this->view(
                'student/exercises/attempt',
                array_merge(['title' => 'Resolver exercícios'], $data),
                'layouts/student'
            );
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/exercicios');
        } catch (Throwable $e) {
            error_log('exercise.session: ' . $e->getMessage());
            Session::flash('error', 'Não foi possível abrir esta sessão de exercícios.');
            $this->redirect('/exercicios');
        }
    }

    public function answer(int $id, Request $request): void
    {
        try {
            $result = (new ExerciseService())->answer(
                $id,
                (int) Auth::id(),
                (int) $request->input('session_question_id', 0),
                (int) $request->input('alternative_id', 0)
            );

            $this->json($result);
        } catch (RuntimeException $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            error_log('exercise.answer: ' . $e->getMessage());
            $this->json(['ok' => false, 'message' => 'Não foi possível registrar a resposta.'], 500);
        }
    }

    public function result(int $id): void
    {
        try {
            $data = (new ExerciseService())->resultData($id, (int) Auth::id());
            $this->view(
                'student/exercises/result',
                array_merge(['title' => 'Resultado dos exercícios'], $data),
                'layouts/student'
            );
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/exercicios/sessao/' . $id);
        } catch (Throwable $e) {
            error_log('exercise.result: ' . $e->getMessage());
            Session::flash('error', 'Não foi possível carregar o resultado.');
            $this->redirect('/exercicios');
        }
    }
}
