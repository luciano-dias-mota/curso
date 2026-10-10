<?php

declare(strict_types=1);

/**
 * POP 1 - Taxonomia de estudo V1
 *
 * Cria a taxonomia POP -> Processo -> Procedimento e a relação N:N com questions.
 * Também acrescenta metadados de filtro às sessões de exercícios, se a tabela existir.
 *
 * Segurança:
 *   php database/aplicar_pop1_taxonomia_v1.php                  # dry-run
 *   php database/aplicar_pop1_taxonomia_v1.php --apply          # aplica fora de production
 *   php database/aplicar_pop1_taxonomia_v1.php --apply --force-production
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
    if ($value === false || $value === null) {
        return $default;
    }
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
    ['POP1', null, 'pop', 'pop-1', 'POP 1 - Módulo I: Níveis do Uso da Força Policial', 1],
    ['POP1-GERAL', 'POP1', 'general', 'pop-1-geral', 'Doutrina geral e comentários iniciais', 1],
    ['101', 'POP1', 'process', 'pop-1-processo-101', 'Processo 101 - Montagem do Equipamento de Proteção e Porte Individual', 10],
    ['101.1', '101', 'procedure', 'pop-1-101-1', '101.1 - Montagem do EPI', 1],
    ['101.2', '101', 'procedure', 'pop-1-101-2', '101.2 - Posicionamento do EPI', 2],
    ['102', 'POP1', 'process', 'pop-1-processo-102', 'Processo 102 - Uso de Algemas', 20],
    ['102.1', '102', 'procedure', 'pop-1-102-1', '102.1 - Preparação do EPI das Algemas', 1],
    ['102.2', '102', 'procedure', 'pop-1-102-2', '102.2 - Ato de Algemação', 2],
    ['102.3', '102', 'procedure', 'pop-1-102-3', '102.3 - Preparação do EPI das Algemas Descartáveis', 3],
    ['102.4', '102', 'procedure', 'pop-1-102-4', '102.4 - Ato de Algemação com Algemas Descartáveis', 4],
    ['103', 'POP1', 'process', 'pop-1-processo-103', 'Processo 103 - Uso do Espargidor de Gás Pimenta O.C.', 30],
    ['103.1', '103', 'procedure', 'pop-1-103-1', '103.1 - Espargidor O.C. para Ação Individual', 1],
    ['103.2', '103', 'procedure', 'pop-1-103-2', '103.2 - Espargidor O.C. para Ação Coletiva', 2],
    ['104', 'POP1', 'process', 'pop-1-processo-104', 'Processo 104 - Armas de Incapacitação Neuromuscular - TASER X-2', 40],
    ['104.1', '104', 'procedure', 'pop-1-104-1', '104.1 - Verificação e Checagem da TASER X-2', 1],
    ['104.2', '104', 'procedure', 'pop-1-104-2', '104.2 - Utilização Operacional da TASER X-2', 2],
    ['104.3', '104', 'procedure', 'pop-1-104-3', '104.3 - Manutenção da TASER X-2', 3],
    ['105', 'POP1', 'process', 'pop-1-processo-105', 'Processo 105 - Uso do Bastão Policial - Tonfa', 50],
    ['105.1', '105', 'procedure', 'pop-1-105-1', '105.1 - Bastão em Abordagem a Pessoa com Instrumento Contundente', 1],
    ['105.2', '105', 'procedure', 'pop-1-105-2', '105.2 - Bastão Policial em Situações Adversas', 2],
    ['106', 'POP1', 'process', 'pop-1-processo-106', 'Processo 106 - Manutenção de 1º Escalão - Glock G17/G19', 60],
    ['106.1', '106', 'procedure', 'pop-1-106-1', '106.1 - Inspeção da Pistola', 1],
    ['106.2', '106', 'procedure', 'pop-1-106-2', '106.2 - Limpeza da Pistola', 2],
    ['106.3', '106', 'procedure', 'pop-1-106-3', '106.3 - Cautela Diária da Pistola e Munição 9mm', 3],
    ['106.4', '106', 'procedure', 'pop-1-106-4', '106.4 - Check de Funcionamento da Pistola', 4],
    ['107', 'POP1', 'process', 'pop-1-processo-107', 'Processo 107 - Uso da Força Policial', 70],
    ['107.1', '107', 'procedure', 'pop-1-107-1', '107.1 - Pessoa com Instrumento Contundente', 1],
    ['107.2', '107', 'procedure', 'pop-1-107-2', '107.2 - Pessoa com Instrumento Cortante/Perfurante/Perfurocortante', 2],
    ['107.3', '107', 'procedure', 'pop-1-107-3', '107.3 - Pessoa com Má Visualização das Mãos', 3],
    ['107.4', '107', 'procedure', 'pop-1-107-4', '107.4 - Pessoa Empunhando Arma de Fogo ou Simulacro', 4],
    ['107.5', '107', 'procedure', 'pop-1-107-5', '107.5 - Infrator com Arma de Fogo de Costas para a Guarnição', 5],
    ['107.6', '107', 'procedure', 'pop-1-107-6', '107.6 - Infrator Disparando Arma de Fogo em Local com Público', 6],
    ['107.7', '107', 'procedure', 'pop-1-107-7', '107.7 - Infrator Disparando com Colete de Proteção Balística', 7],
    ['107.8', '107', 'procedure', 'pop-1-107-8', '107.8 - Causador da Crise Armado Ameaçando a Vítima', 8],
    ['107.9', '107', 'procedure', 'pop-1-107-9', '107.9 - Veículo em Situação de Fuga', 9],
    ['107.10', '107', 'procedure', 'pop-1-107-10', '107.10 - Pessoas Menores de Idade e/ou Idosos', 10],
    ['107.11', '107', 'procedure', 'pop-1-107-11', '107.11 - Edificações, Corredores, Janelas, Esquinas e Muros', 11],
    ['108', 'POP1', 'process', 'pop-1-processo-108', 'Processo 108 - APH Policial / MARC1', 80],
    ['108.1', '108', 'procedure', 'pop-1-108-1', '108.1 - Montagem do IFAK e Disposição dos Materiais', 1],
    ['108.2', '108', 'procedure', 'pop-1-108-2', '108.2 - Resgate do Policial Ferido', 2],
    ['108.3', '108', 'procedure', 'pop-1-108-3', '108.3 - MARC1: Massivo, Ar e Respiração', 3],
    ['108.4', '108', 'procedure', 'pop-1-108-4', '108.4 - MARC1: Calor, Hipotermia e Evacuação', 4],
];

printf("=============================================================\n");
printf(" POP 1 - TAXONOMIA DE ESTUDO V1\n");
printf("=============================================================\n");
printf("Banco: %s\n", (string) envv('DB_DATABASE', 'curso'));
printf("Modo:  %s\n", $apply ? 'APLICAÇÃO' : 'DRY-RUN');
printf("Tópicos planejados: %d\n\n", count($topics));

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
if ($ddl === []) {
    echo "[OK] Tabelas da taxonomia já existem.\n";
}
foreach ($exerciseAlters as $sql) {
    echo "[ALTERAR] exercise_sessions\n";
}
if ($exerciseExists && $exerciseAlters === []) {
    echo "[OK] exercise_sessions já possui metadados de tópico/dificuldade.\n";
}
if (!$exerciseExists) {
    echo "[INFO] exercise_sessions não existe neste banco; alterações dessa tabela serão ignoradas.\n";
}

if (!$apply) {
    echo "\nDRY-RUN concluído. Nenhuma alteração foi gravada.\n";
    echo "Para aplicar: php database/aplicar_pop1_taxonomia_v1.php --apply" . ($appEnv === 'production' ? " --force-production" : '') . "\n";
    exit(0);
}

try {
    // DDL em MariaDB/MySQL pode realizar COMMIT implícito; execute após backup.
    foreach ($ddl as $sql) {
        $pdo->exec($sql);
    }
    foreach ($exerciseAlters as $sql) {
        $pdo->exec($sql);
    }

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
            'source_label' => 'POP 1 atualizado - arquivo POP-1---.pdf',
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

    printf("\n[OK] Taxonomia gravada/atualizada: %d tópicos.\n", count($ids));
    echo "[OK] Nenhuma questão existente foi apagada ou alterada.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "ERRO: {$e->getMessage()}\n");
    exit(3);
}
