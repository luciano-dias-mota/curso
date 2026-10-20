<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Session;
use App\Services\CourseAccessService;
use App\Services\CourseLayoutResolver;
use App\Services\LabService;
use RuntimeException;
use Throwable;

final class LabController extends Controller
{
    public function show(int $id, ?int $courseId = null): void
    {
        try {
            $scope = (new CourseAccessService())->requireLabActivity(
                (int) Auth::id(),
                $id
            );

            if ($courseId !== null && $courseId > 0 && $courseId !== (int) $scope['course_id']) {
                throw new RuntimeException('A atividade não pertence ao curso informado.');
            }

            Session::put('active_course_id', (int) $scope['course_id']);
            Session::put(
                'active_course_experience',
                (string) ($scope['experience_key'] ?? 'generic')
            );

            $manifest = (new LabService())->manifest((int) Auth::id(), $id);

            $this->view('student/lab', [
                'title' => (string) ($manifest['activity']['title'] ?? 'Laboratório'),
                'manifest' => $manifest,
                'csrfToken' => Csrf::token(),
                'user' => Auth::user(),
            ], (new CourseLayoutResolver())->layoutForKey(
                (string) ($scope['experience_key'] ?? 'generic')
            ));
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/dashboard');
        } catch (Throwable $e) {
            error_log('lab.show: ' . $e->getMessage());
            Session::flash('error', 'Não foi possível abrir o laboratório.');
            $this->redirect('/dashboard');
        }
    }

    public function submit(Request $request, int $id, ?int $courseId = null): void
    {
        try {
            $scope = (new CourseAccessService())->requireLabActivity(
                (int) Auth::id(),
                $id
            );
            if ($courseId !== null && $courseId > 0 && $courseId !== (int) $scope['course_id']) {
                throw new RuntimeException('A atividade não pertence ao curso informado.');
            }

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

    public function hint(int $id, ?int $courseId = null): void
    {
        try {
            $scope = (new CourseAccessService())->requireLabActivity(
                (int) Auth::id(),
                $id
            );
            if ($courseId !== null && $courseId > 0 && $courseId !== (int) $scope['course_id']) {
                throw new RuntimeException('A atividade não pertence ao curso informado.');
            }

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
