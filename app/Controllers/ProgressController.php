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
            $result = (new ProgressService())->finishReading(
                (int) Auth::id(),
                $id
            );

            Session::flash(
                'success',
                'Leitura concluída. Responda ao exercício de fixação para liberar a próxima aula.'
            );

            $this->redirect('/prova/' . $result['quiz_id']);

        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/aula/' . $id);
        }
    }
}
