<?php

declare(strict_types=1);

/**
 * PMMT Academy - Seed inicial
 *
 * Cria:
 * - 1 administrador
 * - 1 estudante de teste
 * - 1 curso
 * - 1 módulo
 * - 1 fase
 * - 1 aula
 * - 6 blocos/telas de aula
 * - 1 matrícula para o estudante
 * - registros iniciais de progresso
 *
 * Uso:
 *   php database/seed_admin.php
 */

define('BASE_PATH', dirname(__DIR__));

$autoload = BASE_PATH . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Erro: vendor/autoload.php não encontrado.\n");
    fwrite(STDERR, "Execute primeiro: composer install\n");
    exit(1);
}

require $autoload;

use Dotenv\Dotenv;
use PDO;
use Throwable;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

function envValue(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null) {
        return $default;
    }

    if (!is_string($value)) {
        return $value;
    }

    return trim($value, "\"'");
}

function ask(string $label, ?string $default = null, bool $secret = false): string
{
    $prompt = $default !== null && $default !== ''
        ? "{$label} [{$default}]: "
        : "{$label}: ";

    if ($secret && strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        echo $prompt;
        system('stty -echo');
        $value = trim((string) fgets(STDIN));
        system('stty echo');
        echo PHP_EOL;
    } else {
        echo $prompt;
        $value = trim((string) fgets(STDIN));
    }

    return $value !== '' ? $value : (string) ($default ?? '');
}

function slugify(string $text): string
{
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-');
}

$dbHost = (string) envValue('DB_HOST', '127.0.0.1');
$dbPort = (int) envValue('DB_PORT', 3306);
$dbName = (string) envValue('DB_DATABASE', 'curso');
$dbUser = (string) envValue('DB_USERNAME', 'root');
$dbPass = (string) envValue('DB_PASSWORD', '');

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $dbHost,
    $dbPort,
    $dbName
);

try {
    $pdo = new PDO(
        $dsn,
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "\nFalha ao conectar ao banco.\n");
    fwrite(STDERR, "Verifique seu arquivo .env e se o MySQL do XAMPP está ligado.\n");
    fwrite(STDERR, "Detalhe: {$e->getMessage()}\n");
    exit(1);
}

echo PHP_EOL;
echo "============================================================\n";
echo " PMMT ACADEMY - SEED INICIAL\n";
echo "============================================================\n\n";

$adminName = ask('Nome do administrador', 'Administrador');
$adminEmail = ask('E-mail do administrador', 'admin@pmmta.local');

do {
    $adminPassword = ask('Senha do administrador (mínimo 8 caracteres)', null, true);

    if (strlen($adminPassword) < 8) {
        echo "A senha precisa ter pelo menos 8 caracteres.\n";
    }
} while (strlen($adminPassword) < 8);

$studentName = ask('Nome do estudante de teste', 'Estudante Teste');
$studentEmail = ask('E-mail do estudante de teste', 'aluno@pmmta.local');

do {
    $studentPassword = ask('Senha do estudante (mínimo 8 caracteres)', 'Aluno@123', true);

    if (strlen($studentPassword) < 8) {
        echo "A senha precisa ter pelo menos 8 caracteres.\n";
    }
} while (strlen($studentPassword) < 8);

try {
    $pdo->beginTransaction();

    // ------------------------------------------------------------
    // Perfis
    // ------------------------------------------------------------

    $roleStmt = $pdo->prepare(
        'SELECT id, slug FROM roles WHERE slug IN ("admin", "student")'
    );
    $roleStmt->execute();

    $roles = [];
    foreach ($roleStmt->fetchAll() as $role) {
        $roles[$role['slug']] = (int) $role['id'];
    }

    if (!isset($roles['admin'], $roles['student'])) {
        throw new RuntimeException(
            'Os perfis admin/student não foram encontrados. Importe database/schema.sql antes.'
        );
    }

    // ------------------------------------------------------------
    // Usuários
    // ------------------------------------------------------------

    $upsertUser = $pdo->prepare(
        "INSERT INTO users
            (role_id, name, email, password_hash, status, theme, xp_total, current_level)
         VALUES
            (:role_id, :name, :email, :password_hash, 'active', :theme, :xp_total, :level)
         ON DUPLICATE KEY UPDATE
            role_id = VALUES(role_id),
            name = VALUES(name),
            password_hash = VALUES(password_hash),
            status = 'active',
            theme = VALUES(theme),
            updated_at = CURRENT_TIMESTAMP"
    );

    $upsertUser->execute([
        'role_id' => $roles['admin'],
        'name' => $adminName,
        'email' => mb_strtolower($adminEmail),
        'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
        'theme' => 'dark',
        'xp_total' => 0,
        'level' => 1,
    ]);

    $upsertUser->execute([
        'role_id' => $roles['student'],
        'name' => $studentName,
        'email' => mb_strtolower($studentEmail),
        'password_hash' => password_hash($studentPassword, PASSWORD_DEFAULT),
        'theme' => 'dark',
        'xp_total' => 120,
        'level' => 1,
    ]);

    $idStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');

    $idStmt->execute(['email' => mb_strtolower($adminEmail)]);
    $adminId = (int) $idStmt->fetchColumn();

    $idStmt->execute(['email' => mb_strtolower($studentEmail)]);
    $studentId = (int) $idStmt->fetchColumn();

    // ------------------------------------------------------------
    // Curso
    // ------------------------------------------------------------

    $courseTitle = 'Preparação PMMT - Mérito Intelectual e CAOC';
    $courseSlug = slugify($courseTitle);

    $courseStmt = $pdo->prepare(
        "INSERT INTO courses
            (
                title, slug, short_description, description, status,
                difficulty, required_score, xp_reward, position,
                published_at, created_by
            )
         VALUES
            (
                :title, :slug, :short_description, :description, 'published',
                'advanced', 70.00, 1000, 1,
                NOW(), :created_by
            )
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            short_description = VALUES(short_description),
            description = VALUES(description),
            status = 'published',
            difficulty = 'advanced',
            required_score = 70.00,
            created_by = VALUES(created_by)"
    );

    $courseStmt->execute([
        'title' => $courseTitle,
        'slug' => $courseSlug,
        'short_description' => 'Trilha gamificada baseada na Mega Apostila UFMT/PMMT.',
        'description' => 'Curso principal da plataforma, estruturado em módulos, fases, aulas e desafios progressivos.',
        'created_by' => $adminId,
    ]);

    $stmt = $pdo->prepare('SELECT id FROM courses WHERE slug = :slug LIMIT 1');
    $stmt->execute(['slug' => $courseSlug]);
    $courseId = (int) $stmt->fetchColumn();

    // ------------------------------------------------------------
    // Módulo
    // ------------------------------------------------------------

    $moduleTitle = 'Direito Constitucional';
    $moduleSlug = slugify($moduleTitle);

    $moduleStmt = $pdo->prepare(
        "INSERT INTO modules
            (
                course_id, title, slug, description, icon,
                position, required_score, xp_reward, status
            )
         VALUES
            (
                :course_id, :title, :slug, :description, :icon,
                1, 70.00, 250, 'published'
            )
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            description = VALUES(description),
            icon = VALUES(icon),
            required_score = 70.00,
            status = 'published'"
    );

    $moduleStmt->execute([
        'course_id' => $courseId,
        'title' => $moduleTitle,
        'slug' => $moduleSlug,
        'description' => 'Princípios fundamentais, direitos e garantias, Administração Pública, militares e segurança pública.',
        'icon' => 'shield',
    ]);

    $stmt = $pdo->prepare(
        'SELECT id FROM modules WHERE course_id = :course_id AND slug = :slug LIMIT 1'
    );
    $stmt->execute([
        'course_id' => $courseId,
        'slug' => $moduleSlug,
    ]);
    $moduleId = (int) $stmt->fetchColumn();

    // ------------------------------------------------------------
    // Fase
    // ------------------------------------------------------------

    $phaseTitle = 'Princípios Fundamentais da Constituição';
    $phaseSlug = slugify($phaseTitle);

    $phaseStmt = $pdo->prepare(
        "INSERT INTO phases
            (
                module_id, title, slug, description, phase_type,
                position, required_score, required_lessons_pct,
                max_attempts, xp_reward, status
            )
         VALUES
            (
                :module_id, :title, :slug, :description, 'normal',
                1, 70.00, 100.00,
                NULL, 75, 'published'
            )
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            description = VALUES(description),
            required_score = 70.00,
            required_lessons_pct = 100.00,
            status = 'published'"
    );

    $phaseStmt->execute([
        'module_id' => $moduleId,
        'title' => $phaseTitle,
        'slug' => $phaseSlug,
        'description' => 'Primeira missão: fundamentos, separação dos Poderes, objetivos fundamentais e relações internacionais.',
    ]);

    $stmt = $pdo->prepare(
        'SELECT id FROM phases WHERE module_id = :module_id AND slug = :slug LIMIT 1'
    );
    $stmt->execute([
        'module_id' => $moduleId,
        'slug' => $phaseSlug,
    ]);
    $phaseId = (int) $stmt->fetchColumn();

    // ------------------------------------------------------------
    // Aula
    // ------------------------------------------------------------

    $lessonTitle = 'Fundamentos da República Federativa do Brasil';
    $lessonSlug = slugify($lessonTitle);

    $lessonStmt = $pdo->prepare(
        "INSERT INTO lessons
            (
                phase_id, title, slug, summary,
                position, estimated_minutes, xp_reward, status
            )
         VALUES
            (
                :phase_id, :title, :slug, :summary,
                1, 12, 20, 'published'
            )
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            summary = VALUES(summary),
            estimated_minutes = 12,
            xp_reward = 20,
            status = 'published'"
    );

    $lessonStmt->execute([
        'phase_id' => $phaseId,
        'title' => $lessonTitle,
        'slug' => $lessonSlug,
        'summary' => 'Art. 1º da CF/88 e diferenciação entre fundamentos e objetivos fundamentais.',
    ]);

    $stmt = $pdo->prepare(
        'SELECT id FROM lessons WHERE phase_id = :phase_id AND slug = :slug LIMIT 1'
    );
    $stmt->execute([
        'phase_id' => $phaseId,
        'slug' => $lessonSlug,
    ]);
    $lessonId = (int) $stmt->fetchColumn();

    // ------------------------------------------------------------
    // Blocos / Telas da aula
    // ------------------------------------------------------------

    $blocks = [
        [
            'type' => 'theory',
            'title' => 'Missão: compreender o Art. 1º',
            'content' => 'A República Federativa do Brasil é formada pela união indissolúvel dos Estados, Municípios e Distrito Federal e constitui-se em Estado Democrático de Direito. Nesta aula, o foco é dominar os fundamentos constitucionais cobrados em prova.'
        ],
        [
            'type' => 'memory',
            'title' => 'Mnemônico SO-CI-DI-VA-PLU',
            'content' => "SO = Soberania\nCI = Cidadania\nDI = Dignidade da pessoa humana\nVA = Valores sociais do trabalho e da livre iniciativa\nPLU = Pluralismo político"
        ],
        [
            'type' => 'comparison',
            'title' => 'Fundamentos x Objetivos Fundamentais',
            'content' => 'Fundamentos estão no art. 1º e representam bases estruturantes do Estado. Objetivos fundamentais aparecem no art. 3º e descrevem metas constitucionais, normalmente apresentadas por verbos no infinitivo, como construir, garantir, erradicar, reduzir e promover.'
        ],
        [
            'type' => 'example',
            'title' => 'Exemplo de prova',
            'content' => 'Se uma questão disser que “erradicar a pobreza” é fundamento da República, a afirmação está errada. Trata-se de objetivo fundamental do art. 3º, e não fundamento do art. 1º.'
        ],
        [
            'type' => 'warning',
            'title' => 'Pegadinha de banca',
            'content' => 'A banca pode misturar itens dos arts. 1º e 3º. Uma forma prática de separar: fundamentos são substantivos estruturantes; objetivos fundamentais aparecem como ações a serem perseguidas pelo Estado.'
        ],
        [
            'type' => 'summary',
            'title' => 'Checkpoint',
            'content' => 'Antes de avançar, memorize os cinco fundamentos: soberania, cidadania, dignidade da pessoa humana, valores sociais do trabalho e da livre iniciativa e pluralismo político.'
        ],
    ];

    $deleteBlocks = $pdo->prepare(
        'DELETE FROM lesson_blocks WHERE lesson_id = :lesson_id'
    );
    $deleteBlocks->execute(['lesson_id' => $lessonId]);

    $blockStmt = $pdo->prepare(
        "INSERT INTO lesson_blocks
            (lesson_id, block_type, title, content, position, is_required)
         VALUES
            (:lesson_id, :block_type, :title, :content, :position, 1)"
    );

    foreach ($blocks as $index => $block) {
        $blockStmt->execute([
            'lesson_id' => $lessonId,
            'block_type' => $block['type'],
            'title' => $block['title'],
            'content' => $block['content'],
            'position' => $index + 1,
        ]);
    }

    // ------------------------------------------------------------
    // Matrícula do estudante
    // ------------------------------------------------------------

    $enrollStmt = $pdo->prepare(
        "INSERT INTO enrollments
            (user_id, course_id, status, enrolled_at)
         VALUES
            (:user_id, :course_id, 'active', NOW())
         ON DUPLICATE KEY UPDATE
            status = 'active'"
    );

    $enrollStmt->execute([
        'user_id' => $studentId,
        'course_id' => $courseId,
    ]);

    // ------------------------------------------------------------
    // Progresso inicial
    // ------------------------------------------------------------

    $courseProgress = $pdo->prepare(
        "INSERT INTO user_course_progress
            (user_id, course_id, status, progress_pct, average_score, started_at, last_accessed_at)
         VALUES
            (:user_id, :course_id, 'in_progress', 0.00, 0.00, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            status = 'in_progress',
            last_accessed_at = NOW()"
    );

    $courseProgress->execute([
        'user_id' => $studentId,
        'course_id' => $courseId,
    ]);

    $moduleProgress = $pdo->prepare(
        "INSERT INTO user_module_progress
            (user_id, module_id, status, best_score, progress_pct, unlocked_at, started_at)
         VALUES
            (:user_id, :module_id, 'available', 0.00, 0.00, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            status = 'available',
            unlocked_at = COALESCE(unlocked_at, NOW())"
    );

    $moduleProgress->execute([
        'user_id' => $studentId,
        'module_id' => $moduleId,
    ]);

    $phaseProgress = $pdo->prepare(
        "INSERT INTO user_phase_progress
            (user_id, phase_id, status, best_score, attempts_count, progress_pct, unlocked_at, started_at)
         VALUES
            (:user_id, :phase_id, 'available', 0.00, 0, 0.00, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            status = 'available',
            unlocked_at = COALESCE(unlocked_at, NOW())"
    );

    $phaseProgress->execute([
        'user_id' => $studentId,
        'phase_id' => $phaseId,
    ]);

    $lessonProgress = $pdo->prepare(
        "INSERT INTO user_lesson_progress
            (
                user_id, lesson_id, status, current_block,
                blocks_viewed, progress_pct, started_at, last_accessed_at
            )
         VALUES
            (
                :user_id, :lesson_id, 'available', 1,
                0, 0.00, NULL, NULL
            )
         ON DUPLICATE KEY UPDATE
            status = 'available'"
    );

    $lessonProgress->execute([
        'user_id' => $studentId,
        'lesson_id' => $lessonId,
    ]);

    $pdo->commit();

    echo PHP_EOL;
    echo "============================================================\n";
    echo " SEED CONCLUÍDO COM SUCESSO\n";
    echo "============================================================\n\n";

    echo "Administrador:\n";
    echo "  Nome:  {$adminName}\n";
    echo "  E-mail: {$adminEmail}\n";
    echo "  Senha:  [a senha que você informou]\n\n";

    echo "Estudante de teste:\n";
    echo "  Nome:  {$studentName}\n";
    echo "  E-mail: {$studentEmail}\n";
    echo "  Senha:  [a senha que você informou]\n\n";

    echo "Conteúdo criado:\n";
    echo "  Curso:  {$courseTitle}\n";
    echo "  Módulo: {$moduleTitle}\n";
    echo "  Fase:   {$phaseTitle}\n";
    echo "  Aula:   {$lessonTitle}\n";
    echo "  Telas:  " . count($blocks) . "\n\n";

    echo "Agora acesse:\n";
    echo "  " . rtrim((string) envValue('APP_URL', 'http://localhost/pmmta-academy/public'), '/') . "/login\n\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "\nErro durante o seed.\n");
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
