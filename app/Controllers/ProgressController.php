<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Session;
use App\Services\ProgressService;
use PDOException;
use RuntimeException;
use Throwable;

final class ProgressController extends Controller
{
    public function completeLesson(int $id): void
    {
        try {
            $result = (new ProgressService())->finishReading(
                (int) Auth::id(),
                $id
            );

            if (!empty($result['optional'])) {
                Session::flash('success', 'Leitura concluída.');
                $this->redirect('/fase/' . (int) $result['phase_id']);
            }

            if (($result['next_step'] ?? '') === 'lab' && !empty($result['lab_activity_id'])) {
                Session::flash(
                    'success',
                    !empty($result['lab_review'])
                        ? 'Aula já concluída. Abrindo o laboratório para revisão.'
                        : 'Leitura concluída. Agora finalize a atividade prática para liberar a progressão.'
                );
                $this->redirect('/laboratorio/atividade/' . (int) $result['lab_activity_id']);
            }

            if (($result['next_step'] ?? '') === 'completed') {
                Session::flash('success', 'Aula concluída.');
                $this->redirect('/fase/' . (int) $result['phase_id']);
            }

            Session::flash(
                'success',
                'Leitura concluída. Responda ao exercício de fixação para liberar a próxima aula.'
            );

            $this->redirect('/prova/' . (int) $result['quiz_id']);
        } catch (Throwable $e) {
            $this->flashSafeError($e);
            $this->redirect('/aula/' . $id);
        }
    }

    private function flashSafeError(Throwable $e): void
    {
        error_log(sprintf(
            '[ProgressController] %s: %s in %s:%d',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        if ($e instanceof RuntimeException && !$e instanceof PDOException) {
            Session::flash('error', $e->getMessage());
            return;
        }

        Session::flash('error', 'Não foi possível concluir a operação. Tente novamente.');
    }
}
