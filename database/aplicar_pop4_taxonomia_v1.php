<?php

declare(strict_types=1);

/**
 * POP 4 - Taxonomia de estudo V1
 *
 * Cria/atualiza a taxonomia POP 4 -> Processo -> Procedimento e a relação N:N com questions.
 * Também garante metadados de filtro às sessões de exercícios.
 *
 * Segurança:
 *   php database/aplicar_pop4_taxonomia_v1.php
 *   php database/aplicar_pop4_taxonomia_v1.php --apply
 *   php database/aplicar_pop4_taxonomia_v1.php --apply --force-production
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
    ['POP4', null, 'pop', 'pop-4', 'POP 4 - Módulo IV: Ocorrências Policiais', 4],
    ['401', 'POP4', 'process', 'pop-4-processo-401', 'Processo 401 - Preservação de Local de Crime', 10],
    ['401.1', '401', 'procedure', 'pop-4-401-1', '401.1 - Avaliação do Local e dos Materiais Necessários para a Preservação', 1],
    ['401.2', '401', 'procedure', 'pop-4-401-2', '401.2 - Ação do Policial Militar para Preservar o Local de Crime', 2],
    ['401.3', '401', 'procedure', 'pop-4-401-3', '401.3 - Término da Preservação do Local de Crime e Registro da Ocorrência', 3],
    ['402', 'POP4', 'process', 'pop-4-processo-402', 'Processo 402 - Vias de Fato', 20],
    ['402.1', '402', 'procedure', 'pop-4-402-1', '402.1 - Medidas de Resolução da Ocorrência de Vias de Fato', 1],
    ['403', 'POP4', 'process', 'pop-4-processo-403', 'Processo 403 - Ocorrências de Perturbação da Paz Social', 30],
    ['403.1', '403', 'procedure', 'pop-4-403-1', '403.1 - Atendimento de Ocorrências de Perturbação da Paz Social', 1],
    ['404', 'POP4', 'process', 'pop-4-processo-404', 'Processo 404 - Ocorrências de Dano/Depredação', 40],
    ['404.1', '404', 'procedure', 'pop-4-404-1', '404.1 - Atendimento de Ocorrências de Dano/Depredação', 1],
    ['405', 'POP4', 'process', 'pop-4-processo-405', 'Processo 405 - Violência Doméstica e Familiar Contra a Mulher', 50],
    ['405.1', '405', 'procedure', 'pop-4-405-1', '405.1 - Atendimento de Ocorrências de Violência Doméstica e Familiar Contra a Mulher', 1],
    ['406', 'POP4', 'process', 'pop-4-processo-406', 'Processo 406 - Ocorrência Envolvendo Autoridade(s)', 60],
    ['406.1', '406', 'procedure', 'pop-4-406-1', '406.1 - Identificação do Nível Funcional da Autoridade', 1],
    ['406.2', '406', 'procedure', 'pop-4-406-2', '406.2 - Envolvendo Policial Civil, Federal, Militar, Militares das Forças Armadas (Fardado ou à Paisana)', 2],
    ['407', 'POP4', 'process', 'pop-4-processo-407', 'Processo 407 - Veículo Localizado', 70],
    ['407.1', '407', 'procedure', 'pop-4-407-1', '407.1 - Atendimento de Ocorrência de Veículo Abandonado/Localizado', 1],
    ['408', 'POP4', 'process', 'pop-4-processo-408', 'Processo 408 - Atendimento de Ocorrências em Horário de Folga', 80],
    ['408.1', '408', 'procedure', 'pop-4-408-1', '408.1 - Conhecimento da Ocorrência Estando o Policial Militar em Horário de Folga', 1],
    ['408.2', '408', 'procedure', 'pop-4-408-2', '408.2 - Chegada ao Local da Ocorrência', 2],
    ['408.3', '408', 'procedure', 'pop-4-408-3', '408.3 - Ação do Policial Militar Após a Chegada da GUPM', 3],
    ['409', 'POP4', 'process', 'pop-4-processo-409', 'Processo 409 - Morte de Policial Militar', 90],
    ['409.1', '409', 'procedure', 'pop-4-409-1', '409.1 - Atendimento de Ocorrência Envolvendo Morte de Policial Militar', 1],
    ['410', 'POP4', 'process', 'pop-4-processo-410', 'Processo 410 - Averiguação e Apreensão de Substância Ilegal', 100],
    ['410.1', '410', 'procedure', 'pop-4-410-1', '410.1 - Localização e Apreensão de Substância Ilegal', 1],
    ['410.2', '410', 'procedure', 'pop-4-410-2', '410.2 - Arrolamento de Testemunhas', 2],
    ['410.3', '410', 'procedure', 'pop-4-410-3', '410.3 - Identificação da Substância Ilegal', 3],
    ['411', 'POP4', 'process', 'pop-4-processo-411', 'Processo 411 - Busca e Apreensão Domiciliar', 110],
    ['411.1', '411', 'procedure', 'pop-4-411-1', '411.1 - Busca e Apreensão Domiciliar', 1],
    ['412', 'POP4', 'process', 'pop-4-processo-412', 'Processo 412 - Atendimento de Ocorrência com Indivíduos com Transtornos Psiquiátricos', 120],
    ['412.1', '412', 'procedure', 'pop-4-412-1', '412.1 - Atendimento de Ocorrências com Indivíduos com Transtornos Psiquiátricos', 1],
    ['413', 'POP4', 'process', 'pop-4-processo-413', 'Processo 413 - Ocorrência Envolvendo Indígenas', 130],
    ['413.1', '413', 'procedure', 'pop-4-413-1', '413.1 - Ocorrência Envolvendo Indígenas', 1],
    ['414', 'POP4', 'process', 'pop-4-processo-414', 'Processo 414 - Ocorrências Envolvendo Pessoa(s) Transgênera(s)', 140],
    ['414.1', '414', 'procedure', 'pop-4-414-1', '414.1 - Atendimento de Ocorrências Envolvendo Pessoa(s) Transgênera(s)', 1],
];

printf("=============================================================\n");
printf(" POP 4 - TAXONOMIA DE ESTUDO V1\n");
printf("=============================================================\n");
printf("Banco: %s\n", (string) envv('DB_DATABASE', 'curso'));
printf("Modo:  %s\n", $apply ? 'APLICAÇÃO' : 'DRY-RUN');
printf("Tópicos planejados: %d (1 POP + 14 processos + 21 procedimentos)\n\n", count($topics));

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
    echo "Para aplicar: php database/aplicar_pop4_taxonomia_v1.php --apply" . ($appEnv === 'production' ? " --force-production" : '') . "\n";
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
            'source_label' => 'POP 4 atualizado - arquivo pop-4--.pdf',
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

    printf("\n[OK] Taxonomia POP4 gravada/atualizada: %d tópicos.\n", count($ids));
    echo "[OK] Nenhuma questão existente foi apagada ou alterada.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "ERRO: {$e->getMessage()}\n");
    exit(3);
}
