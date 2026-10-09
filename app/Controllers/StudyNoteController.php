<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Services\StudyNoteService;
use RuntimeException;
use Throwable;

final class StudyNoteController extends Controller
{
    public function index(Request $request): void
    {
        try {
            $search = trim((string) $request->input('q', ''));
            $courseId = max(0, (int) $request->input('course_id', 0));
            $moduleId = max(0, (int) $request->input('module_id', 0));

            $data = (new StudyNoteService())->dashboard(
                (int) Auth::id(),
                $search,
                $courseId,
                $moduleId
            );

            $this->view(
                'student/notes/index',
                array_merge(['title' => 'Anotações'], $data),
                'layouts/student'
            );
        } catch (Throwable $e) {
            error_log('notes.index: ' . $e->getMessage());
            Session::flash('error', 'Não foi possível carregar suas anotações.');
            $this->redirect('/dashboard');
        }
    }

    public function store(Request $request): void
    {
        try {
            (new StudyNoteService())->create(
                (int) Auth::id(),
                (string) $request->input('title', ''),
                (string) $request->input('content', ''),
                $this->nullableId($request->input('course_id')),
                $this->nullableId($request->input('module_id')),
                $this->nullableId($request->input('phase_id'))
            );

            Session::flash('success', 'Anotação salva com sucesso.');
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('notes.store: ' . $e->getMessage());
            Session::flash('error', 'Não foi possível salvar a anotação.');
        }

        $this->redirect('/anotacoes');
    }

    public function edit(int $id): void
    {
        try {
            $data = (new StudyNoteService())->editData((int) Auth::id(), $id);
            $this->view(
                'student/notes/edit',
                array_merge(['title' => 'Editar anotação'], $data),
                'layouts/student'
            );
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/anotacoes');
        } catch (Throwable $e) {
            error_log('notes.edit: ' . $e->getMessage());
            Session::flash('error', 'Não foi possível abrir a anotação.');
            $this->redirect('/anotacoes');
        }
    }

    public function update(int $id, Request $request): void
    {
        try {
            (new StudyNoteService())->update(
                (int) Auth::id(),
                $id,
                (string) $request->input('title', ''),
                (string) $request->input('content', ''),
                $this->nullableId($request->input('course_id')),
                $this->nullableId($request->input('module_id')),
                $this->nullableId($request->input('phase_id'))
            );
            Session::flash('success', 'Anotação atualizada.');
            $this->redirect('/anotacoes');
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/anotacoes/' . $id . '/editar');
        } catch (Throwable $e) {
            error_log('notes.update: ' . $e->getMessage());
            Session::flash('error', 'Não foi possível atualizar a anotação.');
            $this->redirect('/anotacoes/' . $id . '/editar');
        }
    }

    public function destroy(int $id): void
    {
        try {
            (new StudyNoteService())->delete((int) Auth::id(), $id);
            Session::flash('success', 'Anotação excluída.');
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('notes.destroy: ' . $e->getMessage());
            Session::flash('error', 'Não foi possível excluir a anotação.');
        }

        $this->redirect('/anotacoes');
    }

    public function togglePin(int $id): void
    {
        try {
            $pinned = (new StudyNoteService())->togglePin((int) Auth::id(), $id);
            Session::flash('success', $pinned ? 'Anotação fixada.' : 'Anotação desafixada.');
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('notes.pin: ' . $e->getMessage());
            Session::flash('error', 'Não foi possível alterar o destaque da anotação.');
        }

        $this->redirect('/anotacoes');
    }

    private function nullableId(mixed $value): ?int
    {
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }
}
