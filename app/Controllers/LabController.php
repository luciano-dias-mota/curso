<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Session;
use App\Services\LabService;
use RuntimeException;
use Throwable;

final class LabController extends Controller
{
    public function show(int $id): void
    {
        try {
            $manifest = (new LabService())->manifest((int) Auth::id(), $id);

            $this->view('student/lab', [
                'title' => (string) ($manifest['activity']['title'] ?? 'Laboratório'),
                'manifest' => $manifest,
                'csrfToken' => Csrf::token(),
                'user' => Auth::user(),
            ], 'layouts/student');
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/dashboard');
        } catch (Throwable $e) {
            error_log('lab.show: ' . $e->getMessage());
            Session::flash('error', 'Não foi possível abrir o laboratório.');
            $this->redirect('/dashboard');
        }
    }

    public function submit(Request $request, int $id): void
    {
        try {
            $payload = $request->all();
            unset($payload['_token']);

            $result = (new LabService())->submit(
                (int) Auth::id(),
                $id,
                $payload
            );

            $this->json($result);
        } catch (RuntimeException $e) {
            $this->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            error_log('lab.submit: ' . $e->getMessage());
            $this->json([
                'ok' => false,
                'message' => 'Não foi possível validar a atividade prática.',
            ], 500);
        }
    }

    public function hint(int $id): void
    {
        try {
            $hint = (new LabService())->nextHint((int) Auth::id(), $id);

            $this->json([
                'ok' => true,
                'hint' => $hint,
            ]);
        } catch (RuntimeException $e) {
            $this->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            error_log('lab.hint: ' . $e->getMessage());
            $this->json([
                'ok' => false,
                'message' => 'Não foi possível liberar a dica.',
            ], 500);
        }
    }
}
