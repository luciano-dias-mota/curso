<?php

declare(strict_types=1);

/**
 * Auditoria somente-leitura do pacote POP 2 V1.
 * Uso: php database/auditar_pop2_v1.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script só pode ser executado via CLI.\n");
}

define('BASE_PATH', dirname(__DIR__));
$autoload = BASE_PATH . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Erro: vendor/autoload.php não encontrado.\n");
    exit(1);
}
require $autoload;
\Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

function envv(string $key, mixed $default = null): mixed
{
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($v === false || $v === null) return $default;
    return is_string($v) ? trim($v, "\"'") : $v;
}

function tableExists(PDO $pdo, string $table): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $s->execute(['t'=>$table]);
    return (int)$s->fetchColumn() > 0;
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', envv('DB_HOST','127.0.0.1'), (int)envv('DB_PORT',3306), envv('DB_DATABASE','curso')),
    (string)envv('DB_USERNAME','root'),
    (string)envv('DB_PASSWORD',''),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
);

$required = ['study_topics','question_topics','question_import_keys','questions','alternatives','phases','lessons'];
$missing = array_values(array_filter($required, static fn(string $t): bool => !tableExists($pdo,$t)));
if ($missing !== []) {
    fwrite(STDERR, 'FALHA: tabelas ausentes: '.implode(', ',$missing)."\n");
    exit(2);
}

$topicCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM study_topics
     WHERE domain='pop'
       AND (code='POP2' OR code REGEXP '^(20[1-9]|21[01])(\\.[0-9]+)?$')"
)->fetchColumn();

$keyCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM question_import_keys WHERE source_key LIKE 'POP2-ATUALIZADO-Q%'"
)->fetchColumn();

$quality = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN a.alt_count < CASE WHEN q.question_type='true_false' THEN 2 ELSE 4 END THEN 1 ELSE 0 END) AS bad_alts,
            SUM(CASE WHEN a.correct_count <> 1 THEN 1 ELSE 0 END) AS bad_key,
            SUM(CASE WHEN q.difficulty='medium' THEN 1 ELSE 0 END) AS medium_count,
            SUM(CASE WHEN q.difficulty='hard' THEN 1 ELSE 0 END) AS hard_count
     FROM question_import_keys k
     INNER JOIN questions q ON q.id=k.question_id
     INNER JOIN (
        SELECT question_id,COUNT(*) alt_count,SUM(CASE WHEN is_correct=1 THEN 1 ELSE 0 END) correct_count
        FROM alternatives GROUP BY question_id
     ) a ON a.question_id=q.id
     WHERE k.source_key LIKE 'POP2-ATUALIZADO-Q%'"
)->fetch() ?: [];

$rootLinked = (int)$pdo->query(
    "SELECT COUNT(DISTINCT k.question_id)
     FROM question_import_keys k
     INNER JOIN question_topics qt ON qt.question_id=k.question_id
     INNER JOIN study_topics st ON st.id=qt.topic_id
     WHERE k.source_key LIKE 'POP2-ATUALIZADO-Q%'
       AND st.domain='pop' AND st.code='POP2'"
)->fetchColumn();

$phase = $pdo->query(
    "SELECT id,title FROM phases
     WHERE slug='pop-2-modulo-ii-abordagens-policiais-atualizado'
     LIMIT 1"
)->fetch();

$managedLessons = 0;
if ($phase) {
    $s = $pdo->prepare(
        "SELECT COUNT(*) FROM lessons
         WHERE phase_id=:phase_id
           AND (slug='pop2-visao-geral-atualizada' OR slug LIKE 'pop2-processo-%-atualizado')"
    );
    $s->execute(['phase_id'=>(int)$phase['id']]);
    $managedLessons = (int)$s->fetchColumn();
}

$byProcess = $pdo->query(
    "SELECT st.code, st.title, COUNT(DISTINCT k.question_id) AS qty
     FROM study_topics st
     LEFT JOIN question_topics qt ON qt.topic_id=st.id
     LEFT JOIN question_import_keys k
       ON k.question_id=qt.question_id
      AND k.source_key LIKE 'POP2-ATUALIZADO-Q%'
     WHERE st.domain='pop'
       AND st.topic_type='process'
       AND st.code REGEXP '^(20[1-9]|21[01])$'
     GROUP BY st.id,st.code,st.title
     ORDER BY st.position,st.id"
)->fetchAll();

printf("=============================================================\n");
printf(" AUDITORIA POP 2 V1 - SOMENTE LEITURA\n");
printf("=============================================================\n");
printf("Banco: %s\n", (string)envv('DB_DATABASE','curso'));
printf("Tópicos POP2:                 %d / 42\n", $topicCount);
printf("Chaves de questões POP2:      %d / 28\n", $keyCount);
printf("Questões auditadas:           %d / 28\n", (int)($quality['total'] ?? 0));
printf("Vinculadas à raiz POP2:       %d / 28\n", $rootLinked);
printf("Médias / difíceis:            %d / 20 | %d / 8\n", (int)($quality['medium_count'] ?? 0), (int)($quality['hard_count'] ?? 0));
printf("Alternativas insuficientes:   %d\n", (int)($quality['bad_alts'] ?? 0));
printf("Gabarito diferente de 1 certa:%d\n", (int)($quality['bad_key'] ?? 0));
printf("Fase POP2:                    %s\n", $phase ? '#'.(int)$phase['id'].' - '.$phase['title'] : 'FALTA');
printf("Aulas gerenciadas POP2:       %d / 12\n\n", $managedLessons);

foreach ($byProcess as $row) {
    printf("%s -> %d questão(ões) | %s\n", $row['code'], (int)$row['qty'], $row['title']);
}

$ok = $topicCount === 42
    && $keyCount === 28
    && (int)($quality['total'] ?? 0) === 28
    && $rootLinked === 28
    && (int)($quality['medium_count'] ?? 0) === 20
    && (int)($quality['hard_count'] ?? 0) === 8
    && (int)($quality['bad_alts'] ?? 0) === 0
    && (int)($quality['bad_key'] ?? 0) === 0
    && $phase !== false
    && $managedLessons === 12;

if (!$ok) {
    fwrite(STDERR, "\n[FALHA] A instalação POP2 não está completa ou há inconsistência.\n");
    exit(3);
}

echo "\n[OK] POP 2 V1 consistente: 42 tópicos, 28 questões curadas, 12 aulas e gabaritos válidos.\n";
