<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use finfo;
use PDO;
use RuntimeException;
use Throwable;

final class MediaService
{
    private const MAX_BYTES = 536870912; // 512 MB

    /** @var array<string,string> */
    private const ALLOWED_MIME = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
    ];

    public function storageDirectory(): string
    {
        return base_path('storage/media/videos');
    }

    public function upload(array $file, int $userId, string $title = ''): int
    {
        $this->ensureStorageDirectory();

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage($error));
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $originalName = trim((string) ($file['name'] ?? 'video'));
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('O arquivo recebido pelo servidor não é um upload válido.');
        }

        $size = filesize($tmp);

        if ($size === false || $size <= 0) {
            throw new RuntimeException('O vídeo enviado está vazio ou possui tamanho inválido.');
        }

        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('O vídeo excede o limite da aplicação de 512 MB.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';

        if (!isset(self::ALLOWED_MIME[$mime])) {
            throw new RuntimeException(
                'Formato não permitido. Envie vídeo MP4 (H.264/AAC) ou WebM.'
            );
        }

        $extension = self::ALLOWED_MIME[$mime];
        $storedName = bin2hex(random_bytes(20)) . '.' . $extension;
        $absolutePath = $this->storageDirectory() . DIRECTORY_SEPARATOR . $storedName;

        if (!move_uploaded_file($tmp, $absolutePath)) {
            throw new RuntimeException('Não foi possível salvar o vídeo na pasta de mídia.');
        }

        $safeOriginal = mb_substr($originalName !== '' ? $originalName : $storedName, 0, 255);
        $safeTitle = trim($title);
        if ($safeTitle === '') {
            $safeTitle = pathinfo($safeOriginal, PATHINFO_FILENAME);
        }
        $safeTitle = mb_substr($safeTitle !== '' ? $safeTitle : 'Vídeo', 0, 200);

        $relativePath = 'storage/media/videos/' . $storedName;
        $pdo = Database::connection();

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO media_files
                    (title, original_name, stored_name, file_path, mime_type,
                     size_bytes, duration_seconds, uploaded_by, status)
                 VALUES
                    (:title, :original_name, :stored_name, :file_path, :mime_type,
                     :size_bytes, NULL, :uploaded_by, 'active')"
            );
            $stmt->execute([
                'title' => $safeTitle,
                'original_name' => $safeOriginal,
                'stored_name' => $storedName,
                'file_path' => $relativePath,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'uploaded_by' => $userId,
            ]);

            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            @unlink($absolutePath);
            throw $e;
        }
    }

    public function find(int $id, bool $activeOnly = true): ?array
    {
        $sql = "SELECT * FROM media_files WHERE id = :id";
        if ($activeOnly) {
            $sql .= " AND status = 'active'";
        }
        $sql .= ' LIMIT 1';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function updateTitle(int $id, string $title): void
    {
        $title = trim($title);

        if ($title === '') {
            throw new RuntimeException('Informe um título para o vídeo.');
        }

        if (mb_strlen($title) > 200) {
            throw new RuntimeException('O título do vídeo deve ter no máximo 200 caracteres.');
        }

        $stmt = Database::connection()->prepare(
            "UPDATE media_files
             SET title = :title
             WHERE id = :id
               AND status = 'active'"
        );
        $stmt->execute([
            'title' => $title,
            'id' => $id,
        ]);

        if ($stmt->rowCount() === 0 && !$this->find($id)) {
            throw new RuntimeException('Vídeo não encontrado.');
        }
    }

    public function delete(int $id): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                "SELECT *
                 FROM media_files
                 WHERE id = :id
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute(['id' => $id]);
            $media = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$media) {
                throw new RuntimeException('Vídeo não encontrado.');
            }

            $uses = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM lesson_blocks
                 WHERE media_id = :id"
            );
            $uses->execute(['id' => $id]);

            if ((int) $uses->fetchColumn() > 0) {
                throw new RuntimeException(
                    'Este vídeo ainda está sendo usado em uma ou mais aulas. '
                    . 'Remova-o das aulas antes de excluir definitivamente.'
                );
            }

            $delete = $pdo->prepare("DELETE FROM media_files WHERE id = :id");
            $delete->execute(['id' => $id]);

            $pdo->commit();

            $path = $this->absolutePath($media);
            if (is_file($path) && !@unlink($path)) {
                error_log('Não foi possível apagar arquivo de mídia órfão: ' . $path);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function absolutePath(array $media): string
    {
        $storedName = basename((string) ($media['stored_name'] ?? ''));

        if ($storedName === '' || $storedName === '.' || $storedName === '..') {
            throw new RuntimeException('Registro de mídia inválido.');
        }

        return $this->storageDirectory() . DIRECTORY_SEPARATOR . $storedName;
    }

    private function ensureStorageDirectory(): void
    {
        $dir = $this->storageDirectory();

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(
                'Não foi possível criar storage/media/videos. Verifique as permissões da pasta.'
            );
        }

        if (!is_writable($dir)) {
            throw new RuntimeException(
                'A pasta storage/media/videos não possui permissão de escrita.'
            );
        }
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'O vídeo excedeu o limite de upload do PHP. '
                . 'Aumente upload_max_filesize e post_max_size no php.ini.',
            UPLOAD_ERR_PARTIAL => 'O upload do vídeo foi interrompido antes de terminar.',
            UPLOAD_ERR_NO_FILE => 'Selecione um arquivo de vídeo.',
            UPLOAD_ERR_NO_TMP_DIR => 'A pasta temporária de upload do PHP não está disponível.',
            UPLOAD_ERR_CANT_WRITE => 'O PHP não conseguiu gravar o arquivo temporário.',
            UPLOAD_ERR_EXTENSION => 'Uma extensão do PHP interrompeu o upload.',
            default => 'Falha desconhecida durante o upload do vídeo.',
        };
    }
}
