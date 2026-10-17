<?php

declare(strict_types=1);

/**
 * POP 3 - Taxonomia de estudo V1
 *
 * Cria/atualiza a taxonomia POP 3 -> Processo -> Procedimento e a relação N:N com questions.
 * Também garante metadados de filtro às sessões de exercícios.
 *
 * Segurança:
 *   php database/aplicar_pop3_taxonomia_v1.php
 *   php database/aplicar_pop3_taxonomia_v1.php --apply
 *   php database/aplicar_pop3_taxonomia_v1.php --apply --force-production
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script só pode ser executado via CLI.\n");
}

define('BASE_PATH', dirname(__DIR__));
$autoload = BASE_PATH . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Erro: vendor/autoload.php não encontrado. Execute composer install.\n");
    exit(1);
}
require $autoload;

\Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

function envv(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null) return $default;
    return is_string($value) ? trim($value, "\"'") : $value;
}

function hasFlag(string $flag): bool
{
    global $argv;
    return in_array($flag, $argv ?? [], true);
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table"
    );
    $stmt->execute(['table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column"
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

$apply = hasFlag('--apply');
$forceProduction = hasFlag('--force-production');
$appEnv = strtolower((string) envv('APP_ENV', 'local'));

if ($apply && $appEnv === 'production' && !$forceProduction) {
    fwrite(STDERR, "ABORTADO: APP_ENV=production. Use --apply --force-production somente após backup.\n");
    exit(2);
}

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        envv('DB_HOST', '127.0.0.1'),
        (int) envv('DB_PORT', 3306),
        envv('DB_DATABASE', 'curso')
    ),
    (string) envv('DB_USERNAME', 'root'),
    (string) envv('DB_PASSWORD', ''),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$topics = [
    ['POP3', null, 'pop', 'pop-3', 'POP 3 - Módulo III: Procedimentos Diversos', 3],
    ['301', 'POP3', 'process', 'pop-3-processo-301', 'Processo 301 - Patrulhamento com VTR 04 (Quatro) Rodas', 10],
    ['301.1', '301', 'procedure', 'pop-3-301-1', '301.1 - Procedimentos Preliminares para o Patrulhamento', 1],
    ['301.2', '301', 'procedure', 'pop-3-301-2', '301.2 - Composição da Guarnição em Patrulhamento', 2],
    ['301.3', '301', 'procedure', 'pop-3-301-3', '301.3 - Patrulhamento', 3],
    ['301.4', '301', 'procedure', 'pop-3-301-4', '301.4 - Paradas da Viatura Decorrentes do Fluxo de Trânsito ou Outras Paradas Rápidas', 4],
    ['302', 'POP3', 'process', 'pop-3-processo-302', 'Processo 302 - Patrulhamento com VTR 02 (Duas) Rodas (Motocicleta)', 20],
    ['302.1', '302', 'procedure', 'pop-3-302-1', '302.1 - Procedimentos Preliminares ao Patrulhamento', 1],
    ['302.2', '302', 'procedure', 'pop-3-302-2', '302.2 - Composição da Guarnição em Patrulhamento', 2],
    ['302.3', '302', 'procedure', 'pop-3-302-3', '302.3 - Patrulhamento', 3],
    ['302.4', '302', 'procedure', 'pop-3-302-4', '302.4 - Parada da Viatura Decorrentes do Fluxo de Trânsito ou Outras Paradas Rápidas', 4],
    ['303', 'POP3', 'process', 'pop-3-processo-303', 'Processo 303 - Ponto de Estacionamento de Viatura (PE)', 30],
    ['303.1', '303', 'procedure', 'pop-3-303-1', '303.1 - Ponto de Estacionamento de Viatura 04 Rodas (PE)', 1],
    ['303.2', '303', 'procedure', 'pop-3-303-2', '303.2 - Ponto de Estacionamento de Viatura 02 Rodas (Motocicleta) (PE)', 2],
    ['304', 'POP3', 'process', 'pop-3-processo-304', 'Processo 304 - Acompanhamento e Cerco a Veículo', 40],
    ['304.1', '304', 'procedure', 'pop-3-304-1', '304.1 - Acompanhamento e Cerco a Veículo', 1],
    ['305', 'POP3', 'process', 'pop-3-processo-305', 'Processo 305 - Bloqueio Policial na Via Pública', 50],
    ['305.1', '305', 'procedure', 'pop-3-305-1', '305.1 - Planejamento do Bloqueio', 1],
    ['305.2', '305', 'procedure', 'pop-3-305-2', '305.2 - Montagem do Bloqueio', 2],
    ['305.3', '305', 'procedure', 'pop-3-305-3', '305.3 - Comando do Bloqueio', 3],
    ['305.4', '305', 'procedure', 'pop-3-305-4', '305.4 - Segurança no Bloqueio', 4],
    ['305.5', '305', 'procedure', 'pop-3-305-5', '305.5 - Seleção de Veículos no Bloqueio', 5],
    ['305.6', '305', 'procedure', 'pop-3-305-6', '305.6 - Finalização do Bloqueio', 6],
    ['306', 'POP3', 'process', 'pop-3-processo-306', 'Processo 306 - Atendimento Emergencial - 190', 60],
    ['306.1', '306', 'procedure', 'pop-3-306-1', '306.1 - Atendimento Emergencial - 190', 1],
    ['307', 'POP3', 'process', 'pop-3-processo-307', 'Processo 307 - Passagem de Serviço Motorizado', 70],
    ['307.1', '307', 'procedure', 'pop-3-307-1', '307.1 - Passagem de Serviço Motorizado', 1]
];

printf("=============================================================\n");
printf(" POP 3 - TAXONOMIA DE ESTUDO V1\n");
printf("=============================================================\n");
printf("Banco: %s\n", (string) envv('DB_DATABASE', 'curso'));
printf("Modo:  %s\n", $apply ? 'APLICAÇÃO' : 'DRY-RUN');
printf("Tópicos planejados: %d (1 POP + 7 processos + 19 procedimentos)\n\n", count($topics));

$ddl = [];
if (!tableExists($pdo, 'study_topics')) {
    $ddl[] = "CREATE TABLE study_topics (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        parent_id BIGINT UNSIGNED NULL,
        domain VARCHAR(32) NOT NULL DEFAULT 'pop',
        code VARCHAR(40) NOT NULL,
        slug VARCHAR(100) NOT NULL,
        topic_type ENUM('pop','general','process','procedure') NOT NULL,
        title VARCHAR(190) NOT NULL,
        position INT UNSIGNED NOT NULL DEFAULT 1,
        active TINYINT(1) NOT NULL DEFAULT 1,
        source_label VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_study_topics_domain_code (domain, code),
        UNIQUE KEY uq_study_topics_domain_slug (domain, slug),
        KEY idx_study_topics_parent (parent_id, position),
        CONSTRAINT fk_study_topics_parent FOREIGN KEY (parent_id) REFERENCES study_topics(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
}
if (!tableExists($pdo, 'question_topics')) {
    $ddl[] = "CREATE TABLE question_topics (
        question_id BIGINT UNSIGNED NOT NULL,
        topic_id BIGINT UNSIGNED NOT NULL,
        is_primary TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (question_id, topic_id),
        KEY idx_question_topics_topic (topic_id, question_id),
        CONSTRAINT fk_question_topics_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_question_topics_topic FOREIGN KEY (topic_id) REFERENCES study_topics(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
}
if (!tableExists($pdo, 'question_import_keys')) {
    $ddl[] = "CREATE TABLE question_import_keys (
        source_key VARCHAR(120) NOT NULL,
        question_id BIGINT UNSIGNED NOT NULL,
        source_name VARCHAR(190) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (source_key),
        KEY idx_question_import_keys_question (question_id),
        CONSTRAINT fk_question_import_keys_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
}

$exerciseExists = tableExists($pdo, 'exercise_sessions');
$exerciseAlters = [];
if ($exerciseExists && !columnExists($pdo, 'exercise_sessions', 'topic_id')) {
    $exerciseAlters[] = "ALTER TABLE exercise_sessions ADD COLUMN topic_id BIGINT UNSIGNED NULL AFTER phase_id";
}
if ($exerciseExists && !columnExists($pdo, 'exercise_sessions', 'topic_title_snapshot')) {
    $exerciseAlters[] = "ALTER TABLE exercise_sessions ADD COLUMN topic_title_snapshot VARCHAR(190) NULL AFTER phase_title_snapshot";
}
if ($exerciseExists && !columnExists($pdo, 'exercise_sessions', 'difficulty_filter')) {
    $exerciseAlters[] = "ALTER TABLE exercise_sessions ADD COLUMN difficulty_filter ENUM('medium','hard','all') NOT NULL DEFAULT 'all' AFTER topic_title_snapshot";
}

foreach ($ddl as $sql) {
    printf("[CRIAR] %s\n", preg_match('/CREATE TABLE\s+(\w+)/i', $sql, $m) ? $m[1] : 'tabela');
}
if ($ddl === []) echo "[OK] Tabelas da taxonomia já existem.\n";
foreach ($exerciseAlters as $_) echo "[ALTERAR] exercise_sessions\n";
if ($exerciseExists && $exerciseAlters === []) echo "[OK] exercise_sessions já possui metadados de tópico/dificuldade.\n";
if (!$exerciseExists) echo "[INFO] exercise_sessions não existe; rode primeiro a migration dos módulos de estudo.\n";

if (!$apply) {
    echo "\nDRY-RUN concluído. Nenhuma alteração foi gravada.\n";
    echo "Para aplicar: php database/aplicar_pop3_taxonomia_v1.php --apply" . ($appEnv === 'production' ? " --force-production" : '') . "\n";
    exit(0);
}

try {
    foreach ($ddl as $sql) $pdo->exec($sql);
    foreach ($exerciseAlters as $sql) $pdo->exec($sql);

    $pdo->beginTransaction();
    $select = $pdo->prepare("SELECT id FROM study_topics WHERE domain='pop' AND code=:code LIMIT 1");
    $insert = $pdo->prepare(
        "INSERT INTO study_topics (parent_id, domain, code, slug, topic_type, title, position, active, source_label)
         VALUES (:parent_id, 'pop', :code, :slug, :topic_type, :title, :position, 1, :source_label)"
    );
    $update = $pdo->prepare(
        "UPDATE study_topics SET parent_id=:parent_id, slug=:slug, topic_type=:topic_type,
                title=:title, position=:position, active=1, source_label=:source_label
         WHERE id=:id"
    );

    $ids = [];
    foreach ($topics as [$code, $parentCode, $type, $slug, $title, $position]) {
        $parentId = $parentCode === null ? null : ($ids[$parentCode] ?? null);
        if ($parentCode !== null && $parentId === null) {
            throw new RuntimeException("Pai não resolvido para {$code}: {$parentCode}");
        }
        $select->execute(['code' => $code]);
        $id = $select->fetchColumn();
        $payload = [
            'parent_id' => $parentId,
            'slug' => $slug,
            'topic_type' => $type,
            'title' => $title,
            'position' => $position,
            'source_label' => 'POP 3 atualizado - arquivo pop-3--.pdf',
        ];
        if ($id) {
            $update->execute($payload + ['id' => (int) $id]);
            $ids[$code] = (int) $id;
        } else {
            $insert->execute($payload + ['code' => $code]);
            $ids[$code] = (int) $pdo->lastInsertId();
        }
    }
    $pdo->commit();

    printf("\n[OK] Taxonomia POP3 gravada/atualizada: %d tópicos.\n", count($ids));
    echo "[OK] Nenhuma questão existente foi apagada ou alterada.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "ERRO: {$e->getMessage()}\n");
    exit(3);
}
