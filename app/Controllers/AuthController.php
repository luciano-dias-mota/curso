<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Core\Validator;
use App\Services\LoginThrottle;
use App\Services\PmmtCourseScopeService;
use App\Services\ProgressInitializer;
use App\Services\UserAccessMonitor;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        $this->view(
            'auth/login',
            ['title' => 'Entrar'],
            'layouts/auth'
        );
    }

    public function login(Request $request): void
    {
        $data = $request->only(['email', 'password']);

        $errors = Validator::validate($data, [
            'email' => 'required|email|max:190',
            'password' => 'required|min:6|max:255',
        ]);

        if ($errors !== []) {
            Session::flash('error', 'Verifique os dados informados.');
            Session::flash('errors', $errors);
            Session::flash('old_email', (string) ($data['email'] ?? ''));
            $this->redirect('/login');
        }

        $email = mb_strtolower(trim((string) $data['email']));
        $password = (string) $data['password'];
        $throttle = new LoginThrottle();

        if ($throttle->isBlocked($email)) {
            Session::flash(
                'error',
                'Muitas tentativas de login foram realizadas. Aguarde alguns minutos e tente novamente.'
            );
            Session::flash('old_email', $email);
            $this->redirect('/login');
        }

        if (!Auth::attempt($email, $password)) {
            $throttle->recordFailure($email);
            Session::flash('error', 'E-mail ou senha inválidos.');
            Session::flash('old_email', $email);
            $this->redirect('/login');
        }

        $throttle->clear($email);

        if (Auth::isStudent() && Auth::id() !== null) {
            $studentId = (int) Auth::id();

            // O ambiente de produção /cursomi é exclusivo do Preparatório PMMT.
            // Uma matrícula usada em outro ambiente/curso não pode alterar esta trilha.
            $pdo = Database::connection();
            $pmmtCourseId = (new PmmtCourseScopeService())->courseId($pdo);

            (new ProgressInitializer())->initializeEnrollment(
                $studentId,
                $pmmtCourseId,
                $pdo
            );
            (new UserAccessMonitor())->registerLogin($studentId);
        }

        $this->redirect('/dashboard');
    }

    public function logout(): void
    {
        $userId = Auth::id();
        $isStudent = Auth::isStudent();

        if ($isStudent && $userId !== null) {
            (new UserAccessMonitor())->logout((int) $userId);
        }

        Auth::logout();
        $this->redirect('/login');
    }
}
