<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use PDO;
use RuntimeException;
use Throwable;

final class LessonContentController extends Controller
{
    public function index(Request $request): void
    {
        $pdo = Database::connection();
        $search = trim((string) $request->input('q', ''));

        $sql = "SELECT l.id, l.title, l.status, l.position,
                       p.title AS phase_title,
                       m.title AS module_title,
                       c.title AS course_title,
                       (SELECT COUNT(*) FROM lesson_blocks lb WHERE lb.lesson_id = l.id) AS block_count,
                       (SELECT COUNT(*) FROM lesson_blocks lb WHERE lb.lesson_id = l.id AND lb.block_type = 'video') AS video_count
                FROM lessons l
                INNER JOIN phases p ON p.id = l.phase_id
                INNER JOIN modules m ON m.id = p.module_id
                INNER JOIN courses c ON c.id = m.course_id";

        $params = [];

        if ($search !== '') {
            $sql .= " WHERE (
                        l.title LIKE :q
                        OR p.title LIKE :q
                        OR m.title LIKE :q
                        OR c.title LIKE :q
                      )";
            $params['q'] = '%' . $search . '%';
        }

        $sql .= " ORDER BY c.position, c.id, m.position, m.id, p.position, p.id, l.position, l.id
                  LIMIT 300";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $this->view(
            'admin/lessons/index',
            [
                'title' => 'Gerenciar Aulas',
                'lessons' => $stmt->fetchAll(),
                'search' => $search,
            ],
            'layouts/admin'
        );
    }

    public function edit(int $id): void
    {
        $pdo = Database::connection();
        $lesson = $this->lesson($pdo, $id);

        $blocksStmt = $pdo->prepare(
            "SELECT lb.*,
                    mf.title AS media_title,
                    mf.original_name AS media_original_name,
                    mf.mime_type AS media_mime_type,
                    mf.size_bytes AS media_size_bytes
             FROM lesson_blocks lb
             LEFT JOIN media_files mf ON mf.id = lb.media_id
             WHERE lb.lesson_id = :lesson_id
             ORDER BY lb.position, lb.id"
        );
        $blocksStmt->execute(['lesson_id' => $id]);

        $media = $pdo->query(
            "SELECT mf.*,
                    (SELECT COUNT(*) FROM lesson_blocks x WHERE x.media_id = mf.id) AS usage_count
             FROM media_files mf
             WHERE mf.status = 'active'
             ORDER BY mf.title, mf.id"
        )->fetchAll();

        $this->view(
            'admin/lessons/edit',
            [
                'title' => 'Editar Aula',
                'lesson' => $lesson,
                'blocks' => $blocksStmt->fetchAll(),
                'media' => $media,
            ],
            'layouts/admin'
        );
    }

    public function addVideo(Request $request, int $id): void
    {
        $pdo = Database::connection();

        try {
            $this->lesson($pdo, $id);

            $mediaId = (int) $request->input('media_id', 0);
            $media = $this->media($pdo, $mediaId);

            $customTitle = trim((string) $request->input('title', ''));
            $description = trim((string) $request->input('content', ''));
            $afterRaw = $request->input('after_block_id', '');

            $pdo->beginTransaction();

            $position = $this->resolveInsertPosition($pdo, $id, $afterRaw);

            $shift = $pdo->prepare(
                "UPDATE lesson_blocks
                 SET position = position + 1
                 WHERE lesson_id = :lesson_id
                   AND position >= :position
                 ORDER BY position DESC"
            );
            $shift->execute([
                'lesson_id' => $id,
                'position' => $position,
            ]);

            $insert = $pdo->prepare(
                "INSERT INTO lesson_blocks
                    (lesson_id, block_type, title, content, media_url, media_id, position, is_required)
                 VALUES
                    (:lesson_id, 'video', :title, :content, NULL, :media_id, :position, 1)"
            );
            $insert->execute([
                'lesson_id' => $id,
                'title' => mb_substr(
                    $customTitle !== '' ? $customTitle : (string) $media['title'],
                    0,
                    200
                ),
                'content' => $description,
                'media_id' => $mediaId,
                'position' => $position,
            ]);

            $this->refreshStudentReadingProgress($pdo, $id);
            $pdo->commit();

            Session::flash('success', 'Vídeo inserido na aula.');
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Session::flash('error', $e->getMessage());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($e->__toString());
            Session::flash('error', 'Não foi possível inserir o vídeo na aula.');
        }

        $this->redirect('/admin/aulas/' . $id);
    }

    public function updateVideo(Request $request, int $id, int $blockId): void
    {
        $pdo = Database::connection();

        try {
            $this->lesson($pdo, $id);

            $block = $this->videoBlock($pdo, $id, $blockId);
            $mediaId = (int) $request->input('media_id', (int) $block['media_id']);
            $media = $this->media($pdo, $mediaId);

            $title = trim((string) $request->input('title', ''));
            $content = trim((string) $request->input('content', ''));

            if ($title === '') {
                $title = (string) $media['title'];
            }

            $stmt = $pdo->prepare(
                "UPDATE lesson_blocks
                 SET media_id = :media_id,
                     media_url = NULL,
                     title = :title,
                     content = :content
                 WHERE id = :block_id
                   AND lesson_id = :lesson_id
                   AND block_type = 'video'"
            );
            $stmt->execute([
                'media_id' => $mediaId,
                'title' => mb_substr($title, 0, 200),
                'content' => $content,
                'block_id' => $blockId,
                'lesson_id' => $id,
            ]);

            Session::flash('success', 'Bloco de vídeo atualizado.');
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log($e->__toString());
            Session::flash('error', 'Não foi possível atualizar o bloco de vídeo.');
        }

        $this->redirect('/admin/aulas/' . $id);
    }

    public function destroyVideo(int $id, int $blockId): void
    {
        $pdo = Database::connection();

        try {
            $this->lesson($pdo, $id);
            $this->videoBlock($pdo, $id, $blockId);

            $pdo->beginTransaction();

            $delete = $pdo->prepare(
                "DELETE FROM lesson_blocks
                 WHERE id = :block_id
                   AND lesson_id = :lesson_id
                   AND block_type = 'video'"
            );
            $delete->execute([
                'block_id' => $blockId,
                'lesson_id' => $id,
            ]);

            $this->normalizePositions($pdo, $id);
            $this->refreshStudentReadingProgress($pdo, $id);
            $pdo->commit();

            Session::flash(
                'success',
                'Vídeo removido da aula. O arquivo continua disponível na biblioteca.'
            );
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Session::flash('error', $e->getMessage());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($e->__toString());
            Session::flash('error', 'Não foi possível remover o vídeo da aula.');
        }

        $this->redirect('/admin/aulas/' . $id);
    }

    public function reorder(Request $request, int $id): void
    {
        $pdo = Database::connection();

        try {
            $this->lesson($pdo, $id);

            $order = $request->input('order', []);

            if (!is_array($order)) {
                throw new RuntimeException('Ordem de blocos inválida.');
            }

            $order = array_values(array_unique(array_map('intval', $order)));

            $stmt = $pdo->prepare(
                "SELECT id
                 FROM lesson_blocks
                 WHERE lesson_id = :lesson_id
                 ORDER BY position, id"
            );
            $stmt->execute(['lesson_id' => $id]);
            $current = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            $a = $order;
            $b = $current;
            sort($a);
            sort($b);

            if ($a !== $b || count($order) !== count($current)) {
                throw new RuntimeException(
                    'A lista enviada não corresponde a todos os blocos atuais da aula. Atualize a página.'
                );
            }

            $pdo->beginTransaction();

            // Libera temporariamente todos os números de posição para não colidir
            // com a chave única (lesson_id, position).
            $offset = 1000000;
            $tmp = $pdo->prepare(
                "UPDATE lesson_blocks
                 SET position = position + :offset
                 WHERE lesson_id = :lesson_id"
            );
            $tmp->execute([
                'offset' => $offset,
                'lesson_id' => $id,
            ]);

            $update = $pdo->prepare(
                "UPDATE lesson_blocks
                 SET position = :position
                 WHERE id = :id
                   AND lesson_id = :lesson_id"
            );

            foreach ($order as $index => $blockId) {
                $update->execute([
                    'position' => $index + 1,
                    'id' => $blockId,
                    'lesson_id' => $id,
                ]);
            }

            $pdo->commit();

            if ($request->isAjax() || $request->isJson()) {
                $this->json(['ok' => true]);
            }

            Session::flash('success', 'Ordem dos blocos atualizada.');
            $this->redirect('/admin/aulas/' . $id);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($request->isAjax() || $request->isJson()) {
                $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }

            Session::flash('error', $e->getMessage());
            $this->redirect('/admin/aulas/' . $id);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($e->__toString());

            if ($request->isAjax() || $request->isJson()) {
                $this->json(
                    ['ok' => false, 'message' => 'Não foi possível reordenar os blocos.'],
                    500
                );
            }

            Session::flash('error', 'Não foi possível reordenar os blocos.');
            $this->redirect('/admin/aulas/' . $id);
        }
    }

    private function lesson(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare(
            "SELECT l.*,
                    p.title AS phase_title,
                    p.id AS phase_id,
                    m.title AS module_title,
                    m.id AS module_id,
                    c.title AS course_title,
                    c.id AS course_id
             FROM lessons l
             INNER JOIN phases p ON p.id = l.phase_id
             INNER JOIN modules m ON m.id = p.module_id
             INNER JOIN courses c ON c.id = m.course_id
             WHERE l.id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Aula não encontrada.');
        }

        return $row;
    }

    private function media(PDO $pdo, int $id): array
    {
        if ($id <= 0) {
            throw new RuntimeException('Selecione um vídeo da biblioteca.');
        }

        $stmt = $pdo->prepare(
            "SELECT *
             FROM media_files
             WHERE id = :id
               AND status = 'active'
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('O vídeo selecionado não existe ou está inativo.');
        }

        return $row;
    }

    private function videoBlock(PDO $pdo, int $lessonId, int $blockId): array
    {
        $stmt = $pdo->prepare(
            "SELECT *
             FROM lesson_blocks
             WHERE id = :block_id
               AND lesson_id = :lesson_id
               AND block_type = 'video'
             LIMIT 1"
        );
        $stmt->execute([
            'block_id' => $blockId,
            'lesson_id' => $lessonId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Bloco de vídeo não encontrado nesta aula.');
        }

        return $row;
    }

    private function resolveInsertPosition(PDO $pdo, int $lessonId, mixed $afterRaw): int
    {
        if ($afterRaw === '' || $afterRaw === null) {
            $stmt = $pdo->prepare(
                "SELECT COALESCE(MAX(position), 0) + 1
                 FROM lesson_blocks
                 WHERE lesson_id = :lesson_id"
            );
            $stmt->execute(['lesson_id' => $lessonId]);
            return max(1, (int) $stmt->fetchColumn());
        }

        $afterId = (int) $afterRaw;

        if ($afterId === 0) {
            return 1;
        }

        $stmt = $pdo->prepare(
            "SELECT position
             FROM lesson_blocks
             WHERE id = :block_id
               AND lesson_id = :lesson_id
             LIMIT 1"
        );
        $stmt->execute([
            'block_id' => $afterId,
            'lesson_id' => $lessonId,
        ]);

        $position = $stmt->fetchColumn();

        if ($position === false) {
            throw new RuntimeException('O ponto escolhido para inserir o vídeo não existe mais.');
        }

        return (int) $position + 1;
    }

    private function refreshStudentReadingProgress(PDO $pdo, int $lessonId): void
    {
        $totalStmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total_blocks,
                SUM(CASE WHEN is_required = 1 THEN 1 ELSE 0 END) AS required_blocks
             FROM lesson_blocks
             WHERE lesson_id = :lesson_id"
        );
        $totalStmt->execute(['lesson_id' => $lessonId]);
        $totals = $totalStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $totalBlocks = max(1, (int) ($totals['total_blocks'] ?? 0));
        $requiredBlocks = (int) ($totals['required_blocks'] ?? 0);

        $usersStmt = $pdo->prepare(
            "SELECT user_id, current_block, status
             FROM user_lesson_progress
             WHERE lesson_id = :lesson_id
               AND status <> 'completed'"
        );
        $usersStmt->execute(['lesson_id' => $lessonId]);
        $progressRows = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

        $viewedStmt = $pdo->prepare(
            "SELECT COUNT(v.id)
             FROM lesson_blocks lb
             LEFT JOIN user_lesson_block_views v
               ON v.lesson_block_id = lb.id
              AND v.user_id = :user_id
             WHERE lb.lesson_id = :lesson_id
               AND lb.is_required = 1
               AND v.id IS NOT NULL"
        );

        $update = $pdo->prepare(
            "UPDATE user_lesson_progress
             SET blocks_viewed = :blocks_viewed,
                 progress_pct = :progress_pct,
                 current_block = :current_block
             WHERE user_id = :user_id
               AND lesson_id = :lesson_id
               AND status <> 'completed'"
        );

        foreach ($progressRows as $row) {
            $userId = (int) $row['user_id'];

            $viewedStmt->execute([
                'user_id' => $userId,
                'lesson_id' => $lessonId,
            ]);
            $viewed = (int) $viewedStmt->fetchColumn();

            $pct = $requiredBlocks > 0
                ? min(100, round(($viewed / $requiredBlocks) * 100, 2))
                : 100;

            $update->execute([
                'blocks_viewed' => $viewed,
                'progress_pct' => $pct,
                'current_block' => min(
                    max(1, (int) ($row['current_block'] ?? 1)),
                    $totalBlocks
                ),
                'user_id' => $userId,
                'lesson_id' => $lessonId,
            ]);
        }
    }


    private function normalizePositions(PDO $pdo, int $lessonId): void
    {
        $stmt = $pdo->prepare(
            "SELECT id
             FROM lesson_blocks
             WHERE lesson_id = :lesson_id
             ORDER BY position, id"
        );
        $stmt->execute(['lesson_id' => $lessonId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if (!$ids) {
            return;
        }

        $tmp = $pdo->prepare(
            "UPDATE lesson_blocks
             SET position = position + 1000000
             WHERE lesson_id = :lesson_id"
        );
        $tmp->execute(['lesson_id' => $lessonId]);

        $update = $pdo->prepare(
            "UPDATE lesson_blocks
             SET position = :position
             WHERE id = :id
               AND lesson_id = :lesson_id"
        );

        foreach ($ids as $index => $blockId) {
            $update->execute([
                'position' => $index + 1,
                'id' => $blockId,
                'lesson_id' => $lessonId,
            ]);
        }
    }
}
