<?php

declare(strict_types=1);

/**
 * Importador da Mega Apostila Reforçada para o PMMT Academy.
 *
 * Coloque:
 *   database/import_mega_apostila.php
 *   database/mega_apostila_content.json
 *
 * Execute na raiz:
 *   php database/import_mega_apostila.php --replace
 *
 * --replace:
 *   reutiliza o curso de mesmo slug e substitui sua estrutura
 *   (módulos/fases/aulas/blocos), apagando o conteúdo de teste anterior.
 */

define('BASE_PATH', dirname(__DIR__));

$autoload = BASE_PATH . '/vendor/autoload.php';
$jsonFile = __DIR__ . '/mega_apostila_content.json';

if (!is_file($autoload)) {
    fwrite(STDERR, "Erro: vendor/autoload.php não encontrado. Execute composer install.\n");
    exit(1);
}

if (!is_file($jsonFile)) {
    fwrite(STDERR, "Erro: database/mega_apostila_content.json não encontrado.\n");
    exit(1);
}

require $autoload;

use Dotenv\Dotenv;
use PDO;
use Throwable;

Dotenv::createImmutable(BASE_PATH)->safeLoad();

function envv(string $key, mixed $default = null): mixed
{
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($v === false || $v === null) return $default;
    return is_string($v) ? trim($v, "\"'") : $v;
}

function findAdminId(PDO $pdo): int
{
    $id = $pdo->query(
        "SELECT u.id
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE r.slug = 'admin' AND u.status = 'active'
         ORDER BY u.id
         LIMIT 1"
    )->fetchColumn();

    if (!$id) {
        throw new RuntimeException('Nenhum administrador ativo encontrado. Execute seed_admin.php primeiro.');
    }

    return (int) $id;
}

function activeStudents(PDO $pdo): array
{
    return $pdo->query(
        "SELECT u.id
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE r.slug = 'student' AND u.status = 'active'
         ORDER BY u.id"
    )->fetchAll(PDO::FETCH_COLUMN);
}

$replace = in_array('--replace', $argv, true);

$dbHost = (string) envv('DB_HOST', '127.0.0.1');
$dbPort = (int) envv('DB_PORT', 3306);
$dbName = (string) envv('DB_DATABASE', 'curso');
$dbUser = (string) envv('DB_USERNAME', 'root');
$dbPass = (string) envv('DB_PASSWORD', '');

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName),
    $dbUser,
    $dbPass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$data = json_decode(file_get_contents($jsonFile), true, 512, JSON_THROW_ON_ERROR);

if (($data['format_version'] ?? null) !== 1 || empty($data['course']['modules'])) {
    throw new RuntimeException('Pacote JSON inválido ou vazio.');
}

$course = $data['course'];
$adminId = findAdminId($pdo);

echo "============================================================\n";
echo " IMPORTADOR - MEGA APOSTILA REFORÇADA\n";
echo "============================================================\n";
echo "Banco: {$dbName}\n";
echo "Curso: {$course['title']}\n";
echo "Modo: " . ($replace ? "SUBSTITUIR conteúdo existente" : "IMPORTAÇÃO SEGURA") . "\n\n";

try {
    $pdo->beginTransaction();

    $findCourse = $pdo->prepare('SELECT id FROM courses WHERE slug = :slug LIMIT 1');
    $findCourse->execute(['slug' => $course['slug']]);
    $courseId = (int) ($findCourse->fetchColumn() ?: 0);

    if ($courseId > 0 && !$replace) {
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM modules WHERE course_id = :id');
        $countStmt->execute(['id' => $courseId]);
        $existingModules = (int) $countStmt->fetchColumn();

        if ($existingModules > 0) {
            throw new RuntimeException(
                "O curso já possui {$existingModules} módulo(s). ".
                "Execute com --replace para substituir o conteúdo de teste:\n".
                "php database/import_mega_apostila.php --replace"
            );
        }
    }

    if ($courseId === 0) {
        $stmt = $pdo->prepare(
            "INSERT INTO courses
             (title, slug, short_description, description, status, difficulty,
              required_score, xp_reward, position, published_at, created_by)
             VALUES
             (:title, :slug, :short_description, :description, 'published', 'advanced',
              :required_score, 3000, 1, NOW(), :created_by)"
        );
        $stmt->execute([
            'title' => $course['title'],
            'slug' => $course['slug'],
            'short_description' => $course['short_description'],
            'description' => $course['description'],
            'required_score' => $course['required_score'] ?? 70,
            'created_by' => $adminId,
        ]);
        $courseId = (int) $pdo->lastInsertId();
    } else {
        $stmt = $pdo->prepare(
            "UPDATE courses
             SET title = :title,
                 short_description = :short_description,
                 description = :description,
                 status = 'published',
                 difficulty = 'advanced',
                 required_score = :required_score,
                 created_by = :created_by,
                 published_at = COALESCE(published_at, NOW())
             WHERE id = :id"
        );
        $stmt->execute([
            'title' => $course['title'],
            'short_description' => $course['short_description'],
            'description' => $course['description'],
            'required_score' => $course['required_score'] ?? 70,
            'created_by' => $adminId,
            'id' => $courseId,
        ]);

        if ($replace) {
            // Cascades remove phases, lessons, blocks e progressos dependentes.
            $del = $pdo->prepare('DELETE FROM modules WHERE course_id = :course_id');
            $del->execute(['course_id' => $courseId]);
        }
    }

    $insertModule = $pdo->prepare(
        "INSERT INTO modules
         (course_id, title, slug, description, icon, position, required_score, xp_reward, status)
         VALUES
         (:course_id, :title, :slug, :description, NULL, :position, 70.00, 250, 'published')"
    );

    $insertPhase = $pdo->prepare(
        "INSERT INTO phases
         (module_id, title, slug, description, phase_type, position,
          required_score, required_lessons_pct, max_attempts, xp_reward, status)
         VALUES
         (:module_id, :title, :slug, :description, :phase_type, :position,
          :required_score, 100.00, NULL, 75, 'published')"
    );

    $insertLesson = $pdo->prepare(
        "INSERT INTO lessons
         (phase_id, title, slug, summary, position, estimated_minutes, xp_reward, status)
         VALUES
         (:phase_id, :title, :slug, :summary, :position, :estimated_minutes, :xp_reward, 'published')"
    );

    $insertBlock = $pdo->prepare(
        "INSERT INTO lesson_blocks
         (lesson_id, block_type, title, content, media_url, position, is_required)
         VALUES
         (:lesson_id, :block_type, :title, :content, :media_url, :position, :is_required)"
    );

    $moduleRows = [];
    $phaseRows = [];
    $lessonRows = [];
    $countModules = $countPhases = $countLessons = $countBlocks = 0;

    foreach ($course['modules'] as $moduleData) {
        $insertModule->execute([
            'course_id' => $courseId,
            'title' => $moduleData['title'],
            'slug' => $moduleData['slug'],
            'description' => $moduleData['description'] ?? '',
            'position' => $moduleData['position'],
        ]);
        $moduleId = (int) $pdo->lastInsertId();
        $moduleRows[] = ['id' => $moduleId, 'position' => (int) $moduleData['position']];
        $countModules++;

        foreach ($moduleData['phases'] as $phaseData) {
            $phaseType = str_contains(mb_strtolower($phaseData['title']), 'simulado')
                ? 'boss'
                : (str_contains(mb_strtolower($phaseData['title']), 'revis')
                    ? 'review'
                    : 'normal');

            $insertPhase->execute([
                'module_id' => $moduleId,
                'title' => $phaseData['title'],
                'slug' => $phaseData['slug'],
                'description' => $phaseData['description'] ?? '',
                'phase_type' => $phaseType,
                'position' => $phaseData['position'],
                'required_score' => $phaseData['required_score'] ?? 70,
            ]);
            $phaseId = (int) $pdo->lastInsertId();
            $phaseRows[] = [
                'id' => $phaseId,
                'module_id' => $moduleId,
                'module_position' => (int) $moduleData['position'],
                'position' => (int) $phaseData['position']
            ];
            $countPhases++;

            foreach ($phaseData['lessons'] as $lessonData) {
                $insertLesson->execute([
                    'phase_id' => $phaseId,
                    'title' => $lessonData['title'],
                    'slug' => $lessonData['slug'],
                    'summary' => $lessonData['summary'] ?? '',
                    'position' => $lessonData['position'] ?? 1,
                    'estimated_minutes' => $lessonData['estimated_minutes'] ?? 10,
                    'xp_reward' => $lessonData['xp_reward'] ?? 20,
                ]);
                $lessonId = (int) $pdo->lastInsertId();
                $lessonRows[] = [
                    'id' => $lessonId,
                    'phase_id' => $phaseId,
                    'module_position' => (int) $moduleData['position'],
                    'phase_position' => (int) $phaseData['position'],
                    'position' => (int) ($lessonData['position'] ?? 1)
                ];
                $countLessons++;

                foreach ($lessonData['blocks'] as $i => $blockData) {
                    $insertBlock->execute([
                        'lesson_id' => $lessonId,
                        'block_type' => $blockData['type'] ?? 'theory',
                        'title' => $blockData['title'] ?? null,
                        'content' => $blockData['content'],
                        'media_url' => $blockData['media_url'] ?? null,
                        'position' => $i + 1,
                        'is_required' => !empty($blockData['required']) ? 1 : 0,
                    ]);
                    $countBlocks++;
                }
            }
        }
    }

    // Matrícula e progresso inicial para todos os estudantes ativos.
    $students = activeStudents($pdo);

    $enroll = $pdo->prepare(
        "INSERT INTO enrollments (user_id, course_id, status, enrolled_at)
         VALUES (:user_id, :course_id, 'active', NOW())
         ON DUPLICATE KEY UPDATE status = 'active'"
    );

    $courseProgress = $pdo->prepare(
        "INSERT INTO user_course_progress
         (user_id, course_id, status, progress_pct, average_score, started_at, last_accessed_at)
         VALUES (:user_id, :course_id, 'in_progress', 0, 0, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            status = 'in_progress',
            progress_pct = 0,
            average_score = 0,
            completed_at = NULL,
            last_accessed_at = NOW()"
    );

    $moduleProgress = $pdo->prepare(
        "INSERT INTO user_module_progress
         (user_id, module_id, status, best_score, progress_pct, unlocked_at, started_at)
         VALUES (:user_id, :module_id, :status, 0, 0, :unlocked_at, :started_at)"
    );

    $phaseProgress = $pdo->prepare(
        "INSERT INTO user_phase_progress
         (user_id, phase_id, status, best_score, attempts_count, progress_pct,
          unlocked_at, started_at)
         VALUES (:user_id, :phase_id, :status, 0, 0, 0, :unlocked_at, :started_at)"
    );

    $lessonProgress = $pdo->prepare(
        "INSERT INTO user_lesson_progress
         (user_id, lesson_id, status, current_block, blocks_viewed, progress_pct,
          started_at, completed_at, last_accessed_at)
         VALUES (:user_id, :lesson_id, :status, 1, 0, 0, NULL, NULL, NULL)"
    );

    foreach ($students as $studentIdRaw) {
        $studentId = (int) $studentIdRaw;

        $enroll->execute(['user_id' => $studentId, 'course_id' => $courseId]);
        $courseProgress->execute(['user_id' => $studentId, 'course_id' => $courseId]);

        foreach ($moduleRows as $m) {
            $available = $m['position'] === 1;
            $moduleProgress->execute([
                'user_id' => $studentId,
                'module_id' => $m['id'],
                'status' => $available ? 'available' : 'locked',
                'unlocked_at' => $available ? date('Y-m-d H:i:s') : null,
                'started_at' => null,
            ]);
        }

        foreach ($phaseRows as $p) {
            $available = $p['module_position'] === 1 && $p['position'] === 1;
            $phaseProgress->execute([
                'user_id' => $studentId,
                'phase_id' => $p['id'],
                'status' => $available ? 'available' : 'locked',
                'unlocked_at' => $available ? date('Y-m-d H:i:s') : null,
                'started_at' => null,
            ]);
        }

        foreach ($lessonRows as $l) {
            $available = $l['module_position'] === 1
                && $l['phase_position'] === 1
                && $l['position'] === 1;

            $lessonProgress->execute([
                'user_id' => $studentId,
                'lesson_id' => $l['id'],
                'status' => $available ? 'available' : 'locked',
            ]);
        }
    }

    $pdo->commit();

    echo "\nIMPORTAÇÃO CONCLUÍDA.\n";
    echo "Curso ID: {$courseId}\n";
    echo "Módulos: {$countModules}\n";
    echo "Fases: {$countPhases}\n";
    echo "Aulas: {$countLessons}\n";
    echo "Telas/blocos: {$countBlocks}\n";
    echo "Estudantes matriculados/atualizados: " . count($students) . "\n\n";
    echo "Acesse: " . rtrim((string) envv('APP_URL', ''), '/') . "/dashboard\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "\nIMPORTAÇÃO CANCELADA.\n");
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
