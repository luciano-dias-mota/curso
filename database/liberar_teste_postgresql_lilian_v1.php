<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execução permitida apenas via CLI.');
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Database;

App::boot(BASE_PATH);

$apply = in_array('--apply', $argv ?? [], true);
$forceProduction = in_array('--force-production', $argv ?? [], true);
$isProduction = strtolower((string) config('app.env', 'production')) === 'production';

$userIdArg = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--user-id=')) {
        $userIdArg = (int) substr($arg, strlen('--user-id='));
    }
}

if ($apply && $isProduction && !$forceProduction) {
    fwrite(
        STDERR,
        "ABORTADO: APP_ENV=production. Revise o dry-run e use --apply --force-production.\n"
    );
    exit(1);
}

$pdo = Database::connection();

$courseStmt = $pdo->prepare(
    "SELECT id, title, slug, status, experience_key
       FROM courses
      WHERE slug = 'postgresql-zero-intermediario'
      LIMIT 1"
);
$courseStmt->execute();
$course = $courseStmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    fwrite(STDERR, "ABORTADO: curso PostgreSQL não encontrado.\n");
    exit(1);
}

if ((string) ($course['experience_key'] ?? '') !== 'postgresql') {
    fwrite(STDERR, "ABORTADO: experience_key do curso não é postgresql.\n");
    exit(1);
}

$courseId = (int) $course['id'];

if ($userIdArg !== null && $userIdArg > 0) {
    $userStmt = $pdo->prepare(
        "SELECT id, name, email, status
           FROM users
          WHERE id = :id
            AND status = 'active'
          LIMIT 1"
    );
    $userStmt->execute(['id' => $userIdArg]);
    $candidates = $userStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $userStmt = $pdo->prepare(
        "SELECT id, name, email, status
           FROM users
          WHERE status = 'active'
            AND (
                LOWER(name) LIKE :name_search
                OR LOWER(email) LIKE :email_search
            )
          ORDER BY id"
    );
    $userStmt->execute([
        'name_search' => '%lilian%',
        'email_search' => 'lilian%',
    ]);
    $candidates = $userStmt->fetchAll(PDO::FETCH_ASSOC);
}

if (count($candidates) !== 1) {
    fwrite(
        STDERR,
        "ABORTADO: era esperado exatamente 1 usuário ativo correspondente a 'lilian', mas foram encontrados "
        . count($candidates) . ".\n"
    );

    if ($candidates !== []) {
        fwrite(STDERR, "Candidatos encontrados:\n");
        foreach ($candidates as $candidate) {
            fwrite(
                STDERR,
                sprintf(
                    "  ID %d | %s | %s\n",
                    (int) $candidate['id'],
                    (string) $candidate['name'],
                    (string) $candidate['email']
                )
            );
        }
        fwrite(
            STDERR,
            "Repita informando o ID correto: php database/liberar_teste_postgresql_lilian_v1.php --user-id=ID\n"
        );
    }

    exit(1);
}

$user = $candidates[0];
$userId = (int) $user['id'];

$otherEnrollmentStmt = $pdo->prepare(
    "SELECT e.user_id, u.name, u.email
       FROM enrollments e
       INNER JOIN users u ON u.id = e.user_id
      WHERE e.course_id = :course_id
        AND e.status = 'active'
        AND e.user_id <> :user_id
      ORDER BY e.user_id"
);
$otherEnrollmentStmt->execute([
    'course_id' => $courseId,
    'user_id' => $userId,
]);
$otherEnrollments = $otherEnrollmentStmt->fetchAll(PDO::FETCH_ASSOC);

if ($otherEnrollments !== []) {
    fwrite(
        STDERR,
        "ABORTADO: já existem outras matrículas ativas no PostgreSQL. Revise antes de liberar o teste privado.\n"
    );
    foreach ($otherEnrollments as $row) {
        fwrite(
            STDERR,
            sprintf(
                "  ID %d | %s | %s\n",
                (int) $row['user_id'],
                (string) $row['name'],
                (string) $row['email']
            )
        );
    }
    exit(1);
}

$modulesStmt = $pdo->prepare(
    "SELECT m.id, m.slug, m.title, m.position, m.status,
            COUNT(DISTINCT l.id) AS lesson_count,
            SUM(
                CASE
                    WHEN l.id IS NOT NULL
                     AND EXISTS (
                         SELECT 1
                           FROM lesson_blocks lb
                          WHERE lb.lesson_id = l.id
                     )
                     AND EXISTS (
                         SELECT 1
                           FROM lab_activities la
                          WHERE la.lesson_id = l.id
                     )
                    THEN 1 ELSE 0
                END
            ) AS ready_lesson_count
       FROM modules m
       LEFT JOIN phases p
         ON p.module_id = m.id
        AND p.status <> 'archived'
       LEFT JOIN lessons l
         ON l.phase_id = p.id
        AND l.status <> 'archived'
      WHERE m.course_id = :course_id
        AND m.status <> 'archived'
      GROUP BY m.id, m.slug, m.title, m.position, m.status
      ORDER BY m.position, m.id"
);
$modulesStmt->execute(['course_id' => $courseId]);
$modules = $modulesStmt->fetchAll(PDO::FETCH_ASSOC);

$readyModules = [];
foreach ($modules as $module) {
    $lessonCount = (int) ($module['lesson_count'] ?? 0);
    $readyLessonCount = (int) ($module['ready_lesson_count'] ?? 0);

    if ($lessonCount > 0 && $lessonCount === $readyLessonCount) {
        $readyModules[] = $module;
    }
}

echo "=================================================================\n";
echo " LD ACADEMY - ACESSO PRIVADO POSTGRESQL / LILIAN V1\n";
echo "=================================================================\n";
echo 'Banco:   ' . (string) $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo 'Curso:   #' . $courseId . ' - ' . (string) $course['title'] . "\n";
echo 'Status:  ' . (string) $course['status'] . "\n";
echo 'Usuário: #' . $userId . ' - ' . (string) $user['name'] . ' <' . (string) $user['email'] . ">\n";
echo 'Modo:    ' . ($apply ? 'APLICAR' : 'DRY-RUN') . "\n\n";

echo "Módulos detectados:\n";
foreach ($modules as $module) {
    $lessonCount = (int) ($module['lesson_count'] ?? 0);
    $readyLessonCount = (int) ($module['ready_lesson_count'] ?? 0);
    $ready = $lessonCount > 0 && $lessonCount === $readyLessonCount;

    printf(
        "  %-46s %2d/%2d aulas prontas | %s\n",
        (string) $module['title'],
        $readyLessonCount,
        $lessonCount,
        $ready ? 'LIBERAR NO TESTE' : 'MANTER EM RASCUNHO'
    );
}

if ($readyModules === []) {
    fwrite(STDERR, "\nABORTADO: nenhum módulo possui todas as aulas com teoria + laboratório configurados.\n");
    exit(1);
}

echo "\nAção prevista:\n";
echo "  - manter o card 'Em construção' para alunos sem matrícula;\n";
echo "  - permitir entrada somente para a Lilian;\n";
echo "  - publicar tecnicamente apenas os módulos completos listados acima;\n";
echo "  - manter módulos incompletos em rascunho;\n";
echo "  - criar/reativar somente a matrícula da Lilian.\n";

if (!$apply) {
    echo "\nNenhuma alteração foi realizada.\n";
    echo "Para aplicar:\n";
    echo "php database/liberar_teste_postgresql_lilian_v1.php";
    if ($userIdArg !== null && $userIdArg > 0) {
        echo " --user-id={$userId}";
    }
    echo " --apply";
    if ($isProduction) {
        echo " --force-production";
    }
    echo "\n";
    exit(0);
}

$pdo->beginTransaction();

try {
    $pdo->prepare(
        "UPDATE courses
            SET status = 'published',
                published_at = COALESCE(published_at, NOW())
          WHERE id = :course_id"
    )->execute(['course_id' => $courseId]);

    $settingStmt = $pdo->prepare(
        "INSERT INTO course_settings
            (course_id, setting_key, setting_value, value_type)
         VALUES
            (:course_id, :setting_key, :setting_value, :value_type)
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            value_type = VALUES(value_type)"
    );

    $settingStmt->execute([
        'course_id' => $courseId,
        'setting_key' => 'catalog_preview_enabled',
        'setting_value' => '1',
        'value_type' => 'boolean',
    ]);
    $settingStmt->execute([
        'course_id' => $courseId,
        'setting_key' => 'catalog_preview_message',
        'setting_value' => 'Curso em construção. Estamos preparando aulas práticas e laboratórios. Em breve estará disponível.',
        'value_type' => 'string',
    ]);

    $moduleIds = array_map(
        static fn (array $module): int => (int) $module['id'],
        $readyModules
    );

    $modulePlaceholders = implode(',', array_fill(0, count($moduleIds), '?'));

    $pdo->prepare(
        "UPDATE modules
            SET status = 'published'
          WHERE course_id = ?
            AND id IN ({$modulePlaceholders})"
    )->execute([$courseId, ...$moduleIds]);

    $pdo->prepare(
        "UPDATE phases
            SET status = 'published'
          WHERE module_id IN ({$modulePlaceholders})
            AND status <> 'archived'"
    )->execute($moduleIds);

    $pdo->prepare(
        "UPDATE lessons l
         INNER JOIN phases p ON p.id = l.phase_id
            SET l.status = 'published'
          WHERE p.module_id IN ({$modulePlaceholders})
            AND l.status <> 'archived'"
    )->execute($moduleIds);

    $pdo->prepare(
        "UPDATE lab_activities la
         INNER JOIN lessons l ON l.id = la.lesson_id
         INNER JOIN phases p ON p.id = l.phase_id
            SET la.status = 'published'
          WHERE p.module_id IN ({$modulePlaceholders})
            AND la.status <> 'archived'"
    )->execute($moduleIds);

    $enrollmentStmt = $pdo->prepare(
        "INSERT INTO enrollments
            (user_id, course_id, status, enrolled_at, completed_at)
         VALUES
            (:user_id, :course_id, 'active', NOW(), NULL)
         ON DUPLICATE KEY UPDATE
            status = 'active',
            completed_at = NULL"
    );
    $enrollmentStmt->execute([
        'user_id' => $userId,
        'course_id' => $courseId,
    ]);

    $pdo->commit();

    echo "\n[OK] Acesso de teste liberado para {$user['name']}.\n";
    echo "[OK] Matrícula PostgreSQL ativa apenas para o usuário selecionado.\n";
    echo "[OK] " . count($readyModules) . " módulo(s) completo(s) liberado(s) para teste.\n";
    echo "[OK] Módulos incompletos permanecem em rascunho.\n";
    echo "[OK] Para os demais alunos, o portal continua mostrando 'Em construção'.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "\nERRO: {$e->getMessage()}\n");
    exit(1);
}
