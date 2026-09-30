<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Session;
use App\Services\ProgressService;
use Throwable;

final class ProgressController extends Controller
{
    public function completeLesson(int $id): void
    {
        try {
            $result = (new ProgressService())->completeLesson(
                (int) Auth::id(),
                $id
            );

            // Há outra aula dentro da mesma fase.
            if (!empty($result['next_lesson_id'])) {
                Session::flash('success', 'Aula concluída. Continue para a próxima aula.');
                $this->redirect('/aula/' . $result['next_lesson_id']);
            }

            // Terminou a fase e ainda NÃO há prova cadastrada.
            // Libera provisoriamente a próxima fase/aula.
            if (
                !empty($result['all_lessons_completed'])
                && empty($result['quiz_id'])
                && !empty($result['next_content_lesson_id'])
            ) {
                Session::flash(
                    'success',
                    'Fase concluída. Próxima aula desbloqueada.'
                );

                $this->redirect('/aula/' . $result['next_content_lesson_id']);
            }

            // Quando houver prova, o fluxo para aqui.
            if (!empty($result['quiz_id'])) {
                Session::flash(
                    'success',
                    'Leitura concluída. Faça a prova da fase para continuar.'
                );
                $this->redirect('/fase/' . $result['phase_id']);
            }

            if (!empty($result['course_completed'])) {
                Session::flash(
                    'success',
                    'Parabéns! Você concluiu todo o conteúdo disponível do curso.'
                );
                $this->redirect('/dashboard');
            }

            Session::flash('success', 'Aula concluída com sucesso.');
            $this->redirect('/fase/' . $result['phase_id']);

        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/aula/' . $id);
        }
    }
}
