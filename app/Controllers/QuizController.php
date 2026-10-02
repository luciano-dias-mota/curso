<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Services\QuizService;
use PDOException;
use RuntimeException;
use Throwable;

final class QuizController extends Controller
{
    public function show(int $id): void
    {
        try {
            $quiz = (new QuizService())->getQuiz($id, (int) Auth::id());

            $this->view(
                'student/quiz',
                ['title' => $quiz['type_label'], 'quiz' => $quiz],
                'layouts/student'
            );
        } catch (Throwable $e) {
            $this->flashSafeError($e);
            $this->redirect('/dashboard');
        }
    }

    public function start(int $id): void
    {
        try {
            $attemptId = (new QuizService())->startAttempt($id, (int) Auth::id());
            $this->redirect('/prova/tentativa/' . $attemptId);
        } catch (Throwable $e) {
            $this->flashSafeError($e);
            $this->redirect('/prova/' . $id);
        }
    }

    public function attempt(int $id): void
    {
        try {
            $attempt = (new QuizService())->attemptData($id, (int) Auth::id());

            if ($attempt['status'] === 'finished') {
                $this->redirect('/prova/tentativa/' . $id . '/resultado');
            }

            if ($attempt['status'] === 'abandoned') {
                Session::flash('error', 'Esta tentativa foi encerrada. Inicie uma nova tentativa, se disponível.');
                $this->redirect('/prova/' . (int) $attempt['quiz_id']);
            }

            $this->view(
                'student/quiz_attempt',
                ['title' => $attempt['type_label'], 'attempt' => $attempt],
                'layouts/student'
            );
        } catch (Throwable $e) {
            $this->flashSafeError($e);
            $this->redirect('/dashboard');
        }
    }

    public function submit(Request $request, int $id): void
    {
        try {
            $answers = $request->input('answers', []);
            if (!is_array($answers)) {
                $answers = [];
            }

            (new QuizService())->submitAttempt(
                $id,
                (int) Auth::id(),
                $answers
            );

            $this->redirect('/prova/tentativa/' . $id . '/resultado');
        } catch (Throwable $e) {
            $this->flashSafeError($e);
            $this->redirect('/prova/tentativa/' . $id);
        }
    }

    public function result(int $id): void
    {
        try {
            $attempt = (new QuizService())->attemptData($id, (int) Auth::id());

            if ($attempt['status'] !== 'finished') {
                $this->redirect('/prova/tentativa/' . $id);
            }

            $this->view(
                'student/quiz_result',
                ['title' => 'Resultado', 'attempt' => $attempt],
                'layouts/student'
            );
        } catch (Throwable $e) {
            $this->flashSafeError($e);
            $this->redirect('/dashboard');
        }
    }

    private function flashSafeError(Throwable $e): void
    {
        error_log(sprintf(
            '[QuizController] %s: %s in %s:%d',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        if ($e instanceof RuntimeException && !$e instanceof PDOException) {
            Session::flash('error', $e->getMessage());
            return;
        }

        Session::flash('error', 'Não foi possível concluir a avaliação. Tente novamente.');
    }
}
