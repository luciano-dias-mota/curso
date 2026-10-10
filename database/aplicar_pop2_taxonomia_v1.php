<?php

declare(strict_types=1);

/**
 * POP 2 - Taxonomia de estudo V1
 *
 * Cria/atualiza a taxonomia POP 2 -> Processo -> Procedimento e a relação N:N com questions.
 * Também garante metadados de filtro às sessões de exercícios.
 *
 * Segurança:
 *   php database/aplicar_pop2_taxonomia_v1.php
 *   php database/aplicar_pop2_taxonomia_v1.php --apply
 *   php database/aplicar_pop2_taxonomia_v1.php --apply --force-production
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
    ['POP2', null, 'pop', 'pop-2', 'POP 2 - Módulo II: Abordagens Policiais', 2],
    ['201', 'POP2', 'process', 'pop-2-processo-201', 'Processo 201 - Procedimentos Comuns Nas Abordagens', 10],
    ['201.1', '201', 'procedure', 'pop-2-201-1', '201.1 - Conhecimento da Ocorrência', 1],
    ['201.2', '201', 'procedure', 'pop-2-201-2', '201.2 - Deslocamento para o Local da Ocorrência', 2],
    ['201.3', '201', 'procedure', 'pop-2-201-3', '201.3 - Chegada ao Local da Ocorrência em Viatura', 3],
    ['201.4', '201', 'procedure', 'pop-2-201-4', '201.4 - Busca Pessoal', 4],
    ['201.5', '201', 'procedure', 'pop-2-201-5', '201.5 - Busca e Identificação Veicular', 5],
    ['201.6', '201', 'procedure', 'pop-2-201-6', '201.6 - Condução à Repartição Pública Competente', 6],
    ['201.7', '201', 'procedure', 'pop-2-201-7', '201.7 - Apresentação da Ocorrência na Repartição Pública Competente', 7],
    ['201.8', '201', 'procedure', 'pop-2-201-8', '201.8 - Encerramento da Ocorrência', 8],
    ['202', 'POP2', 'process', 'pop-2-processo-202', 'Processo 202 - Abordagem A Pessoa(S) Em Fundada Suspeita', 20],
    ['202.1', '202', 'procedure', 'pop-2-202-1', '202.1 - Localização da(s) Pessoa(s) em Fundada Suspeita', 1],
    ['202.2', '202', 'procedure', 'pop-2-202-2', '202.2 - Abordagem a Pessoa(s) em Fundada Suspeita', 2],
    ['202.3', '202', 'procedure', 'pop-2-202-3', '202.3 - Abordagem a(s) Pessoa(s) Surdo(s)', 3],
    ['202.4', '202', 'procedure', 'pop-2-202-4', '202.4 - Abordagem a Pessoa(s) Autista(s)', 4],
    ['203', 'POP2', 'process', 'pop-2-processo-203', 'Processo 203 - Abordagem A Infrator(Es) Da Lei', 30],
    ['203.1', '203', 'procedure', 'pop-2-203-1', '203.1 - Localização do(s) Infrator(es) da Lei', 1],
    ['203.2', '203', 'procedure', 'pop-2-203-2', '203.2 - Abordagem ao Infrator(es) da Lei', 2],
    ['204', 'POP2', 'process', 'pop-2-processo-204', 'Processo 204 - Abordagem A Veículo Ocupado Por Pessoa(S) Em Fundada Suspeita Por Viatura Quatro Rodas', 40],
    ['204.1', '204', 'procedure', 'pop-2-204-1', '204.1 - Abordagem a Veículo Ocupado por Pessoa(s) em Fundada Suspeita', 1],
    ['205', 'POP2', 'process', 'pop-2-processo-205', 'Processo 205 - Abordagem A Veículo Ocupado Por Infrator(Es) Da Lei Por Viatura Quatro Rodas', 50],
    ['205.1', '205', 'procedure', 'pop-2-205-1', '205.1 - Abordagem a Veículo Ocupado por Infrator(es) da Lei por Viatura Quatro Rodas', 1],
    ['205.2', '205', 'procedure', 'pop-2-205-2', '205.2 - Abordagem a Veículo Ocupado por Infrator(es) da Lei com Apoio de Viatura Quatro Rodas', 2],
    ['205.3', '205', 'procedure', 'pop-2-205-3', '205.3 - Abordagem a Veículo Ocupado por Infrator(es) da Lei com Apoio de Viatura Duas Rodas', 3],
    ['206', 'POP2', 'process', 'pop-2-processo-206', 'Processo 206 - Abordagem A Motocicleta Ocupada Por Pessoa(S) Em Fundada Suspeita Por Viatura Quatro Rodas', 60],
    ['206.1', '206', 'procedure', 'pop-2-206-1', '206.1 - Abordagem a Motocicleta Ocupada por Pessoa(s) em Fundada Suspeita por Viatura Quatro Rodas', 1],
    ['207', 'POP2', 'process', 'pop-2-processo-207', 'Processo 207 - Abordagem A Motocicleta Ocupada Por Infrator(Es) Da Lei Por Viatura Quatro Rodas', 70],
    ['207.1', '207', 'procedure', 'pop-2-207-1', '207.1 - Abordagem a Motocicleta Ocupada por Infrator(es) da Lei', 1],
    ['207.2', '207', 'procedure', 'pop-2-207-2', '207.2 - Abordagem a Motocicleta Ocupada por Infrator(es) da Lei com Apoio de Viatura Quatro Rodas', 2],
    ['207.3', '207', 'procedure', 'pop-2-207-3', '207.3 - Abordagem a Motocicleta Ocupada por Infrator(es) da Lei com Apoio de Viatura Duas Rodas', 3],
    ['208', 'POP2', 'process', 'pop-2-processo-208', 'Processo 208 - Abordagem A Veículo Ocupado Por Pessoa(S) Em Fundada Suspeita Por Vtr 02 Rodas (Motocicleta)', 80],
    ['208.1', '208', 'procedure', 'pop-2-208-1', '208.1 - Abordagem a Veículo Ocupado por Pessoa(s) em Fundada Suspeita por Viatura 02 Rodas (Motocicleta)', 1],
    ['209', 'POP2', 'process', 'pop-2-processo-209', 'Processo 209 - Abordagem A Veículo Ocupado Por Infrator(Es) Da Lei Por Vtr 02 Rodas (Motocicleta)', 90],
    ['209.1', '209', 'procedure', 'pop-2-209-1', '209.1 - Abordagem a Veículo Ocupado por Infrator(es) da Lei por Viatura 02 Rodas (Motocicleta)', 1],
    ['209.2', '209', 'procedure', 'pop-2-209-2', '209.2 - Abordagem a Veículo Ocupado por Infrator(es) da Lei por Viatura 02 Rodas (Motocicleta) com Apoio de Outra Equipe em Viatura 02 Rodas', 2],
    ['209.3', '209', 'procedure', 'pop-2-209-3', '209.3 - Abordagem a Veículo Ocupado por Infrator(es) da Lei por Viatura 02 Rodas (Motocicleta) com Apoio de Outra Equipe em Viatura 04 Rodas', 3],
    ['210', 'POP2', 'process', 'pop-2-processo-210', 'Processo 210 - Abordagem A Motocicleta Ocupada Por Pessoa(S) Em Fundada Suspeita Por Vtr 02 Rodas (Motocicleta)', 100],
    ['210.1', '210', 'procedure', 'pop-2-210-1', '210.1 - Abordagem a Motocicleta Ocupada por Pessoa(s) em Fundada Suspeita por Viatura 02 Rodas (Motocicleta)', 1],
    ['211', 'POP2', 'process', 'pop-2-processo-211', 'Processo 211 - Abordagem A Motocicleta Ocupada Por Infrator(Es) Da Lei Por Vtr 02 Rodas (Motocicleta)', 110],
    ['211.1', '211', 'procedure', 'pop-2-211-1', '211.1 - Abordagem a Motocicleta Ocupada por Infrator(es) da Lei por Viatura 02 Rodas (Motocicleta)', 1],
    ['211.2', '211', 'procedure', 'pop-2-211-2', '211.2 - Abordagem a Motocicleta Ocupada por Infrator(es) da Lei por Viatura 02 Rodas (Motocicleta) com Apoio de Outra Equipe em Viatura 02 Rodas', 2],
    ['211.3', '211', 'procedure', 'pop-2-211-3', '211.3 - Abordagem a Motocicleta Ocupada por Infrator(es) da Lei por Viatura 02 Rodas (Motocicleta) com Apoio de Outra Equipe em Viatura 04 Rodas', 3]
];

printf("=============================================================\n");
printf(" POP 2 - TAXONOMIA DE ESTUDO V1\n");
printf("=============================================================\n");
printf("Banco: %s\n", (string) envv('DB_DATABASE', 'curso'));
printf("Modo:  %s\n", $apply ? 'APLICAÇÃO' : 'DRY-RUN');
printf("Tópicos planejados: %d (1 POP + 11 processos + 30 procedimentos)\n\n", count($topics));

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
    echo "Para aplicar: php database/aplicar_pop2_taxonomia_v1.php --apply" . ($appEnv === 'production' ? " --force-production" : '') . "\n";
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
            'source_label' => 'POP 2 atualizado - arquivo pop-2--.pdf',
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

    printf("\n[OK] Taxonomia POP2 gravada/atualizada: %d tópicos.\n", count($ids));
    echo "[OK] Nenhuma questão existente foi apagada ou alterada.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "ERRO: {$e->getMessage()}\n");
    exit(3);
}
