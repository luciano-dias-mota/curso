<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Services\MediaService;
use RuntimeException;
use Throwable;

final class MediaController extends Controller
{
    public function index(): void
    {
        $pdo = Database::connection();

        $media = $pdo->query(
            "SELECT mf.*,
                    u.name AS uploaded_by_name,
                    (SELECT COUNT(*)
                     FROM lesson_blocks lb
                     WHERE lb.media_id = mf.id) AS usage_count
             FROM media_files mf
             INNER JOIN users u ON u.id = mf.uploaded_by
             WHERE mf.status = 'active'
             ORDER BY mf.created_at DESC, mf.id DESC"
        )->fetchAll();

        $this->view(
            'admin/media/index',
            [
                'title' => 'Biblioteca de Vídeos',
                'media' => $media,
            ],
            'layouts/admin'
        );
    }

    public function store(Request $request): void
    {
        try {
            if (!isset($_FILES['video']) || !is_array($_FILES['video'])) {
                throw new RuntimeException(
                    'Nenhum arquivo chegou ao servidor. '
                    . 'Verifique o tamanho do vídeo e os limites do php.ini.'
                );
            }

            $title = trim((string) $request->input('title', ''));

            (new MediaService())->upload(
                $_FILES['video'],
                (int) Auth::id(),
                $title
            );

            Session::flash('success', 'Vídeo enviado para a biblioteca.');
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log($e->__toString());
            Session::flash('error', 'Não foi possível enviar o vídeo.');
        }

        $this->redirect('/admin/videos');
    }

    public function update(Request $request, int $id): void
    {
        try {
            (new MediaService())->updateTitle(
                $id,
                (string) $request->input('title', '')
            );

            Session::flash('success', 'Título do vídeo atualizado.');
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log($e->__toString());
            Session::flash('error', 'Não foi possível atualizar o vídeo.');
        }

        $this->redirect('/admin/videos');
    }

    public function destroy(int $id): void
    {
        try {
            (new MediaService())->delete($id);
            Session::flash('success', 'Vídeo excluído definitivamente da biblioteca.');
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log($e->__toString());
            Session::flash('error', 'Não foi possível excluir o vídeo.');
        }

        $this->redirect('/admin/videos');
    }
}
