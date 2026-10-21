<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida apenas via CLI.\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Database;
use App\Services\LessonCompletionService;
use App\Services\ProgressInitializer;

App::boot(BASE_PATH);

$apply = in_array('--apply', $argv ?? [], true);
$forceProduction = in_array('--force-production', $argv ?? [], true);
$isProduction = strtolower((string) config('app.env', 'production')) === 'production';

if ($apply && $isProduction && !$forceProduction) {
    fwrite(STDERR, "ABORTADO: revise o dry-run e use --apply --force-production.\n");
    exit(1);
}

$pdo = Database::connection();

$courseStmt = $pdo->prepare(
    "SELECT id, title, status
       FROM courses
      WHERE slug = 'postgresql-zero-intermediario'
        AND experience_key = 'postgresql'
      LIMIT 1"
);
$courseStmt->execute();
$course = $courseStmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    fwrite(STDERR, "Curso PostgreSQL não encontrado.\n");
    exit(2);
}

$courseId = (int) $course['id'];

$enrollStmt = $pdo->prepare(
    "SELECT e.user_id, u.name, u.email
       FROM enrollments e
       INNER JOIN users u ON u.id = e.user_id
      WHERE e.course_id = :course_id
        AND e.status = 'active'
      ORDER BY e.user_id"
);
$enrollStmt->execute(['course_id' => $courseId]);
$users = $enrollStmt->fetchAll(PDO::FETCH_ASSOC);

$lessonsStmt = $pdo->prepare(
    "SELECT l.id, l.title, l.slug, l.position, l.completion_mode,
            p.id AS phase_id, p.title AS phase_title, p.position AS phase_position,
            m.id AS module_id, m.title AS module_title, m.position AS module_position
       FROM lessons l
       INNER JOIN phases p ON p.id = l.phase_id
       INNER JOIN modules m ON m.id = p.module_id
      WHERE m.course_id = :course_id
        AND m.status = 'published'
        AND p.status = 'published'
        AND l.status = 'published'
        AND l.is_required = 1
      ORDER BY m.position, p.position, l.position, l.id"
);
$lessonsStmt->execute(['course_id' => $courseId]);
$lessons = $lessonsStmt->fetchAll(PDO::FETCH_ASSOC);

echo "=========================================================================\n";
echo " POSTGRESQL - DIAGNÓSTICO/RECONCILIAÇÃO DE PROGRESSÃO V1\n";
echo "=========================================================================\n";
printf("Banco: %s\n", (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Curso: #%d - %s [%s]\n", $courseId, (string) $course['title'], (string) $course['status']);
printf("Aulas obrigatórias publicadas: %d\n", count($lessons));
printf("Matrículas ativas: %d\n", count($users));
printf("Modo: %s\n\n", $apply ? 'APLICAÇÃO' : 'DRY-RUN');

$progressStmt = $pdo->prepare(
    "SELECT status, progress_pct, blocks_viewed, completed_at
       FROM user_lesson_progress
      WHERE user_id = :user_id
        AND lesson_id = :lesson_id
      LIMIT 1"
);

$labStatsStmt = $pdo->prepare(
    "SELECT COUNT(la.id) AS total,
            SUM(CASE WHEN lap.status = 'completed' THEN 1 ELSE 0 END) AS completed
       FROM lab_activities la
       LEFT JOIN lab_activity_progress lap
         ON lap.activity_id = la.id
        AND lap.user_id = :user_id
      WHERE la.lesson_id = :lesson_id
        AND la.status = 'published'
        AND la.is_required = 1"
);

$phaseProgressStmt = $pdo->prepare(
    "SELECT status, progress_pct
       FROM user_phase_progress
      WHERE user_id = :user_id
        AND phase_id = :phase_id
      LIMIT 1"
);

$moduleProgressStmt = $pdo->prepare(
    "SELECT status, progress_pct
       FROM user_module_progress
      WHERE user_id = :user_id
        AND module_id = :module_id
      LIMIT 1"
);

$issues = [];
$repairs = 0;

foreach ($users as $user) {
    $userId = (int) $user['user_id'];
    echo "Usuário #{$userId} - " . (string) $user['name'] . "\n";

    if ($apply) {
        // Primeiro reaplica a regra de navegação atual. Isso é idempotente e
        // não altera conteúdo nem publica objetos em draft.
        (new ProgressInitializer())->initializeEnrollment($userId, $courseId, $pdo);
    }

    $lessonStates = [];

    foreach ($lessons as $lesson) {
        $lessonId = (int) $lesson['id'];

        $progressStmt->execute([
            'user_id' => $userId,
            'lesson_id' => $lessonId,
        ]);
        $progress = $progressStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $labStatsStmt->execute([
            'user_id' => $userId,
            'lesson_id' => $lessonId,
        ]);
        $labs = $labStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $labsTotal = (int) ($labs['total'] ?? 0);
        $labsCompleted = (int) ($labs['completed'] ?? 0);

        $status = (string) ($progress['status'] ?? 'missing');
        $pct = (float) ($progress['progress_pct'] ?? 0);

        $lessonStates[] = [
            'lesson' => $lesson,
            'status' => $status,
            'pct' => $pct,
            'labs_total' => $labsTotal,
            'labs_completed' => $labsCompleted,
        ];

        // Caso mais importante: leitura 100% + todos os labs concluídos, mas a
        // aula não foi finalizada por uma migração/estado legado.
        if (
            ($lesson['completion_mode'] ?? 'lab') === 'lab'
            && $labsTotal > 0
            && $labsCompleted === $labsTotal
            && $pct >= 100
            && $status !== 'completed'
            && $status !== 'locked'
        ) {
            $issues[] = "user {$userId}: aula #{$lessonId} possui todos os labs concluídos, mas status={$status}";

            if ($apply) {
                (new LessonCompletionService())->complete(
                    $pdo,
                    $userId,
                    $lessonId,
                    'Aula reconciliada após laboratórios concluídos'
                );
                $repairs++;
            }
        }

        // Estado inverso: aula aparece concluída mas ainda existem labs
        // obrigatórios pendentes. Não pode liberar a sequência.
        if (
            ($lesson['completion_mode'] ?? 'lab') === 'lab'
            && $labsTotal > 0
            && $labsCompleted < $labsTotal
            && $status === 'completed'
        ) {
            $issues[] = "user {$userId}: aula #{$lessonId} concluída prematuramente ({$labsCompleted}/{$labsTotal} labs)";

            if ($apply) {
                $pdo->prepare(
                    "UPDATE user_lesson_progress
                        SET status = 'in_progress',
                            progress_pct = 100,
                            completed_at = NULL,
                            last_accessed_at = NOW()
                      WHERE user_id = :user_id
                        AND lesson_id = :lesson_id"
                )->execute([
                    'user_id' => $userId,
                    'lesson_id' => $lessonId,
                ]);
                $repairs++;
            }
        }
    }

    if ($apply) {
        // Após completar/reabrir aulas, recalcula as liberações sequenciais.
        (new ProgressInitializer())->initializeEnrollment($userId, $courseId, $pdo);
    }

    // Segunda leitura para localizar onde a trilha efetivamente parou.
    $firstOpen = null;
    $previousCompleted = true;

    foreach ($lessons as $lesson) {
        $lessonId = (int) $lesson['id'];
        $progressStmt->execute([
            'user_id' => $userId,
            'lesson_id' => $lessonId,
        ]);
        $progress = $progressStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $status = (string) ($progress['status'] ?? 'missing');

        if ($firstOpen === null && $status !== 'completed') {
            $firstOpen = [
                'lesson' => $lesson,
                'status' => $status,
                'pct' => (float) ($progress['progress_pct'] ?? 0),
            ];
        }

        if ($previousCompleted && in_array($status, ['locked', 'missing'], true)) {
            $issues[] = sprintf(
                'user %d: primeira aula não concluída #%d está %s após sequência concluída',
                $userId,
                $lessonId,
                $status
            );
        }

        $previousCompleted = $status === 'completed';
    }

    if ($firstOpen) {
        $l = $firstOpen['lesson'];
        printf(
            "  Continuação: aula #%d | M%d/F%d | %s | status=%s | %.0f%%\n",
            (int) $l['id'],
            max(0, (int) $l['module_position'] - 1),
            (int) $l['phase_position'],
            (string) $l['title'],
            (string) $firstOpen['status'],
            (float) $firstOpen['pct']
        );
    } else {
        echo "  Continuação: todas as aulas obrigatórias publicadas estão concluídas.\n";
    }

    echo "\n";
}

if ($issues === []) {
    echo "[OK] Nenhuma inconsistência de progressão foi encontrada.\n";
} else {
    echo "Inconsistências encontradas:\n";
    foreach (array_values(array_unique($issues)) as $issue) {
        echo "  - {$issue}\n";
    }
}

if (!$apply) {
    echo "\nNenhum dado foi alterado.\n";
    echo "Para reconciliar estados de progresso sem tocar no conteúdo do curso:\n";
    echo "php database/reconciliar_progresso_postgresql_v1.php --apply";
    if ($isProduction) {
        echo " --force-production";
    }
    echo "\n";
} else {
    printf("\n[OK] Ajustes aplicados: %d.\n", $repairs);
    echo "[OK] ProgressInitializer reaplicado às matrículas PostgreSQL ativas.\n";
    echo "[OK] Nenhuma aula em draft foi publicada.\n";
    echo "[OK] Nenhum conteúdo do Preparatório PMMT foi alterado.\n";
}
