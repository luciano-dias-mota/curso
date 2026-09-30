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
            $result = (new ProgressService())->completeLesson((int) Auth::id(), $id);

            if (!empty($result['optional'])) {
                Session::flash('success', 'Leitura de referência concluída.');
                $this->redirect('/fase/' . $result['phase_id']);
            }

            if (!empty($result['next_lesson_id'])) {
                Session::flash('success', 'Aula concluída. Próxima aula desbloqueada.');
                $this->redirect('/aula/' . $result['next_lesson_id']);
            }

            if (!empty($result['quiz_id'])) {
                Session::flash(
                    'success',
                    'Leitura da fase concluída. Agora você precisa ser aprovado no checkpoint com pelo menos 4 de 5 acertos.'
                );
                $this->redirect('/prova/' . $result['quiz_id']);
            }

            if (!empty($result['quiz_missing'])) {
                Session::flash(
                    'error',
                    'A leitura foi concluída, mas a prova desta fase ainda não foi configurada. A próxima fase permanecerá bloqueada.'
                );
                $this->redirect('/fase/' . $result['phase_id']);
            }

            Session::flash('success', 'Aula concluída.');
            $this->redirect('/fase/' . $result['phase_id']);
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/aula/' . $id);
        }
    }
}
