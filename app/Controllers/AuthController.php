<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Core\Validator;
use App\Services\LoginThrottle;

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
        $this->redirect('/dashboard');
    }

    public function logout(): void
    {
        Auth::logout();
        $this->redirect('/login');
    }
}
