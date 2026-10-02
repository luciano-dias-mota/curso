<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Services\MediaService;
use RuntimeException;

final class MediaController extends Controller
{
    public function video(int $id): void
    {
        $service = new MediaService();
        $media = $service->find($id);

        if (!$media) {
            $this->notFound();
        }

        if (!Auth::isAdmin() && !$this->studentCanAccess($id, (int) Auth::id())) {
            http_response_code(403);
            exit('Você não possui acesso a este vídeo.');
        }

        $path = $service->absolutePath($media);

        if (!is_file($path) || !is_readable($path)) {
            $this->notFound();
        }

        $size = filesize($path);

        if ($size === false || $size <= 0) {
            $this->notFound();
        }

        // Libera o lock da sessão antes de transmitir um arquivo potencialmente grande.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        @set_time_limit(0);

        $start = 0;
        $end = $size - 1;
        $status = 200;

        $range = $_SERVER['HTTP_RANGE'] ?? null;

        if (is_string($range) && preg_match('/bytes=(\d*)-(\d*)/i', $range, $m)) {
            $rawStart = $m[1];
            $rawEnd = $m[2];

            if ($rawStart === '' && $rawEnd !== '') {
                $suffixLength = (int) $rawEnd;
                if ($suffixLength <= 0) {
                    $this->rangeNotSatisfiable($size);
                }
                $start = max(0, $size - $suffixLength);
            } else {
                $start = $rawStart === '' ? 0 : (int) $rawStart;
                $end = $rawEnd === '' ? $size - 1 : min((int) $rawEnd, $size - 1);
            }

            if ($start < 0 || $start >= $size || $end < $start) {
                $this->rangeNotSatisfiable($size);
            }

            $status = 206;
        }

        $length = $end - $start + 1;

        http_response_code($status);
        header('Content-Type: ' . (string) $media['mime_type']);
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . $length);
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');

        if ($status === 206) {
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            $this->notFound();
        }

        fseek($handle, $start);

        $remaining = $length;
        $chunkSize = 1024 * 1024;

        while ($remaining > 0 && !feof($handle)) {
            $read = min($chunkSize, $remaining);
            $buffer = fread($handle, $read);

            if ($buffer === false || $buffer === '') {
                break;
            }

            echo $buffer;
            $remaining -= strlen($buffer);

            if (function_exists('fastcgi_finish_request')) {
                // Não chamar aqui: encerraria a resposta antes do vídeo completo.
            }

            flush();
        }

        fclose($handle);
        exit;
    }

    private function studentCanAccess(int $mediaId, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $stmt = Database::connection()->prepare(
            "SELECT 1
             FROM lesson_blocks lb
             INNER JOIN lessons l ON l.id = lb.lesson_id
             INNER JOIN phases p ON p.id = l.phase_id
             INNER JOIN modules m ON m.id = p.module_id
             INNER JOIN enrollments e
                     ON e.course_id = m.course_id
                    AND e.user_id = :user_id
                    AND e.status IN ('active', 'completed')
             LEFT JOIN user_lesson_progress ulp
                    ON ulp.lesson_id = l.id
                   AND ulp.user_id = :progress_user_id
             WHERE lb.media_id = :media_id
               AND l.status = 'published'
               AND (
                    ulp.status IN ('available', 'in_progress', 'completed')
                    OR l.is_required = 0
                    OR p.is_required = 0
               )
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => $userId,
            'progress_user_id' => $userId,
            'media_id' => $mediaId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    private function notFound(): never
    {
        http_response_code(404);
        exit('Vídeo não encontrado.');
    }

    private function rangeNotSatisfiable(int $size): never
    {
        http_response_code(416);
        header("Content-Range: bytes */{$size}");
        exit;
    }
}
