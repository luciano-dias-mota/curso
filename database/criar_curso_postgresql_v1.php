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

if ($apply && $isProduction && !$forceProduction) {
    fwrite(
        STDERR,
        "ABORTADO: APP_ENV=production. Use --apply --force-production somente após revisar o dry-run e fazer backup.\n"
    );
    exit(1);
}

$pdo = Database::connection();

function tableExistsPgCourseV1(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
         LIMIT 1"
    );
    $stmt->execute(['table_name' => $table]);

    return (bool) $stmt->fetchColumn();
}

$requiredTables = ['courses', 'modules', 'course_settings'];
$missing = [];

foreach ($requiredTables as $table) {
    if (!tableExistsPgCourseV1($pdo, $table)) {
        $missing[] = $table;
    }
}

echo "=================================================================\n";
echo " CURSO MI - POSTGRESQL DO ZERO AO INTERMEDIARIO V1\n";
echo "=================================================================\n";
echo 'Banco: ' . (string) $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo 'Modo:  ' . ($apply ? 'APLICAR' : 'DRY-RUN') . "\n\n";

if ($missing !== []) {
    fwrite(
        STDERR,
        "ABORTADO: a fundação multi-curso ainda não foi aplicada. Tabelas ausentes: "
        . implode(', ', $missing)
        . "\nExecute primeiro database/aplicar_plataforma_multicursos_v1.php.\n"
    );
    exit(1);
}

$slug = 'postgresql-zero-intermediario';
$courseStmt = $pdo->prepare(
    'SELECT id, title, status FROM courses WHERE slug = :slug LIMIT 1'
);
$courseStmt->execute(['slug' => $slug]);
$course = $courseStmt->fetch(PDO::FETCH_ASSOC);

$modules = [
    [
        'position' => 1,
        'slug' => 'primeiros-passos-postgresql',
        'title' => 'Módulo 0 — Primeiros Passos no PostgreSQL',
        'description' => 'Conceitos essenciais de banco de dados, PostgreSQL, tabelas, linhas, colunas e primeiro contato prático com SQL.',
        'icon' => '🐘',
    ],
    [
        'position' => 2,
        'slug' => 'consultando-dados-select',
        'title' => 'Módulo 1 — Consultando Dados com SELECT',
        'description' => 'SELECT, aliases, filtros, operadores, ordenação, LIMIT, DISTINCT, LIKE, IN, BETWEEN e tratamento de NULL.',
        'icon' => '🔎',
    ],
    [
        'position' => 3,
        'slug' => 'construindo-banco-ld-informatica',
        'title' => 'Módulo 2 — Construindo o Banco LD Informática',
        'description' => 'Criação de tabelas, tipos de dados, identity, chaves primárias e restrições essenciais.',
        'icon' => '🏗️',
    ],
    [
        'position' => 4,
        'slug' => 'crud-na-pratica',
        'title' => 'Módulo 3 — CRUD na Prática',
        'description' => 'INSERT, UPDATE, DELETE, RETURNING e uso seguro de filtros para alterar dados.',
        'icon' => '✍️',
    ],
    [
        'position' => 5,
        'slug' => 'relacionamentos-integridade',
        'title' => 'Módulo 4 — Relacionamentos e Integridade',
        'description' => 'Chaves estrangeiras, relacionamentos 1:1, 1:N e N:N, integridade referencial e ações ON DELETE/UPDATE.',
        'icon' => '🔗',
    ],
    [
        'position' => 6,
        'slug' => 'joins-consultas-multiplas-tabelas',
        'title' => 'Módulo 5 — JOINs e Consultas com Várias Tabelas',
        'description' => 'INNER, LEFT, RIGHT e FULL JOIN aplicados aos relatórios do projeto LD Informática.',
        'icon' => '🧩',
    ],
    [
        'position' => 7,
        'slug' => 'agregacoes-sql-intermediario',
        'title' => 'Módulo 6 — Agregações e SQL Intermediário',
        'description' => 'COUNT, SUM, AVG, MIN, MAX, GROUP BY, HAVING, CASE, subconsultas, CTEs e funções úteis.',
        'icon' => '📊',
    ],
    [
        'position' => 8,
        'slug' => 'recursos-profissionais-postgresql',
        'title' => 'Módulo 7 — Recursos Profissionais do PostgreSQL',
        'description' => 'Views, índices, EXPLAIN, transações, sequences/identity, schemas, roles e introdução a backup.',
        'icon' => '🛠️',
    ],
    [
        'position' => 9,
        'slug' => 'projeto-final-ld-informatica',
        'title' => 'Módulo 8 — Projeto Final: Banco LD Informática',
        'description' => 'Integração do banco completo, consultas de negócio, revisão prática e preparação para conclusão do curso.',
        'icon' => '🏁',
    ],
];

if ($course) {
    echo "[OK] Curso já existe: #{$course['id']} {$course['title']} ({$course['status']})\n";
    $courseId = (int) $course['id'];
} else {
    echo "[CRIAR] Curso PostgreSQL — Do Zero ao Intermediário (rascunho)\n";
    $courseId = 0;
}

if ($courseId > 0) {
    $moduleCheck = $pdo->prepare(
        'SELECT id, title, status FROM modules WHERE course_id = :course_id AND slug = :slug LIMIT 1'
    );

    foreach ($modules as $module) {
        $moduleCheck->execute([
            'course_id' => $courseId,
            'slug' => $module['slug'],
        ]);
        $existing = $moduleCheck->fetch(PDO::FETCH_ASSOC);

        printf(
            "%-58s %s\n",
            $module['title'],
            $existing ? 'OK' : 'CRIAR'
        );
    }
} else {
    foreach ($modules as $module) {
        printf("%-58s CRIAR\n", $module['title']);
    }
}

echo "\nConfiguração prevista:\n";
echo "  módulos: sequenciais\n";
echo "  aulas: sequenciais\n";
echo "  conclusão de aula: laboratório\n";
echo "  conclusão de fase: pelas aulas\n";
echo "  conclusão de módulo: pelas fases\n";
echo "  engine: postgresql_pglite\n";
echo "  laboratório: habilitado\n";
echo "  curso: RASCUNHO (não aparece para alunos)\n";
echo "  aulas/desafios: NÃO serão criados nesta versão\n";

if (!$apply) {
    echo "\nNenhuma alteração foi realizada.\n";
    echo "Após backup e revisão, execute:\n";
    echo "php database/criar_curso_postgresql_v1.php --apply";
    if ($isProduction) {
        echo " --force-production";
    }
    echo "\n";
    exit(0);
}

try {
    $pdo->beginTransaction();

    if ($courseId <= 0) {
        $nextPosition = (int) $pdo->query(
            'SELECT COALESCE(MAX(position), 0) + 1 FROM courses'
        )->fetchColumn();

        $insertCourse = $pdo->prepare(
            "INSERT INTO courses
                (title, slug, short_description, description, cover_image, status,
                 difficulty, required_score, xp_reward, position, published_at, created_by)
             VALUES
                (:title, :slug, :short_description, :description, NULL, 'draft',
                 'beginner', 70.00, 500, :position, NULL, NULL)"
        );
        $insertCourse->execute([
            'title' => 'PostgreSQL — Do Zero ao Intermediário',
            'slug' => $slug,
            'short_description' => 'Aprenda PostgreSQL fazendo: digite SQL, execute no navegador e construa um banco real passo a passo.',
            'description' => 'Curso prático de PostgreSQL do zero ao nível intermediário. A trilha usa um projeto cumulativo de banco de dados para a LD Informática e laboratórios executáveis no navegador.',
            'position' => max(1, $nextPosition),
        ]);
        $courseId = (int) $pdo->lastInsertId();

        echo "\n[OK] Curso criado em rascunho com ID {$courseId}.\n";
    }

    $settings = [
        ['module_access_mode', 'sequential', 'string'],
        ['lesson_access_mode', 'sequential', 'string'],
        ['lesson_completion_mode', 'lab', 'string'],
        ['phase_completion_mode', 'lessons', 'string'],
        ['module_completion_mode', 'phases', 'string'],
        ['exercises_enabled', '1', 'boolean'],
        ['simulations_enabled', '0', 'boolean'],
        ['notes_enabled', '1', 'boolean'],
        ['certificate_enabled', '1', 'boolean'],
        ['lab_enabled', '1', 'boolean'],
        ['lab_engine_key', 'postgresql_pglite', 'string'],
    ];

    $insertSetting = $pdo->prepare(
        "INSERT INTO course_settings
            (course_id, setting_key, setting_value, value_type)
         VALUES
            (:course_id, :setting_key, :setting_value, :value_type)
         ON DUPLICATE KEY UPDATE
            setting_key = VALUES(setting_key)"
    );

    foreach ($settings as [$key, $value, $type]) {
        $insertSetting->execute([
            'course_id' => $courseId,
            'setting_key' => $key,
            'setting_value' => $value,
            'value_type' => $type,
        ]);
    }

    $moduleExists = $pdo->prepare(
        'SELECT id FROM modules WHERE course_id = :course_id AND slug = :slug LIMIT 1'
    );
    $positionExists = $pdo->prepare(
        'SELECT id, title FROM modules WHERE course_id = :course_id AND position = :position LIMIT 1'
    );
    $insertModule = $pdo->prepare(
        "INSERT INTO modules
            (course_id, title, slug, description, icon, position,
             required_score, xp_reward, status)
         VALUES
            (:course_id, :title, :slug, :description, :icon, :position,
             70.00, 0, 'draft')"
    );

    foreach ($modules as $module) {
        $moduleExists->execute([
            'course_id' => $courseId,
            'slug' => $module['slug'],
        ]);

        if ($moduleExists->fetchColumn()) {
            continue;
        }

        $positionExists->execute([
            'course_id' => $courseId,
            'position' => $module['position'],
        ]);
        $collision = $positionExists->fetch(PDO::FETCH_ASSOC);

        if ($collision) {
            throw new RuntimeException(
                sprintf(
                    'A posição %d já está ocupada pelo módulo "%s". Nenhuma alteração parcial será mantida.',
                    $module['position'],
                    $collision['title']
                )
            );
        }

        $insertModule->execute([
            'course_id' => $courseId,
            'title' => $module['title'],
            'slug' => $module['slug'],
            'description' => $module['description'],
            'icon' => $module['icon'],
            'position' => $module['position'],
        ]);
    }

    $pdo->commit();

    echo "[OK] Configurações práticas registradas.\n";
    echo "[OK] 9 módulos-base garantidos como rascunho.\n";
    echo "[OK] Nenhuma fase, aula ou atividade prática foi criada.\n";
    echo "[OK] O curso não será mostrado aos alunos enquanto permanecer em rascunho.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "\nERRO: {$e->getMessage()}\n");
    exit(1);
}
