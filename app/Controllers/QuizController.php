<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Services\QuizService;
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
            Session::flash('error', $e->getMessage());
            $this->redirect('/dashboard');
        }
    }

    public function start(int $id): void
    {
        try {
            $attemptId = (new QuizService())->startAttempt($id, (int) Auth::id());
            $this->redirect('/prova/tentativa/' . $attemptId);
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
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

            $this->view(
                'student/quiz_attempt',
                ['title' => $attempt['type_label'], 'attempt' => $attempt],
                'layouts/student'
            );
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
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
            Session::flash('error', $e->getMessage());
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
            Session::flash('error', $e->getMessage());
            $this->redirect('/dashboard');
        }
    }
}
