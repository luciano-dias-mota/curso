<?php

declare(strict_types=1);

/**
 * Auditoria somente-leitura: verifica se TODOS os Processos dos POPs 1-4
 * possuem pelo menos 4 questões válidas para a Central de Exercícios.
 *
 * Uso:
 *   php database/auditar_pop_minimo4_v1.php
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
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null) return $default;
    return is_string($value) ? trim($value, "\"'") : $value;
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table"
    );
    $stmt->execute(['table' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        envv('DB_HOST', '127.0.0.1'),
        (int)envv('DB_PORT', 3306),
        envv('DB_DATABASE', 'curso')
    ),
    (string)envv('DB_USERNAME', 'root'),
    (string)envv('DB_PASSWORD', ''),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

foreach (['study_topics','question_topics','questions','alternatives','question_import_keys'] as $required) {
    if (!tableExists($pdo, $required)) {
        fwrite(STDERR, "FALHA: tabela ausente: {$required}\n");
        exit(2);
    }
}

$rows = $pdo->query(
    "SELECT st.id,st.code,st.title,
            COUNT(DISTINCT CASE
                WHEN q.active=1
                 AND q.question_type IN ('multiple_choice','true_false')
                 AND q.difficulty IN ('medium','hard')
                 AND ((q.question_type='multiple_choice' AND quality.alternatives_count>=4)
                      OR (q.question_type='true_false' AND quality.alternatives_count>=2))
                 AND quality.correct_count=1
                THEN q.id END) AS qty
     FROM study_topics st
     LEFT JOIN question_topics qt ON qt.topic_id=st.id
     LEFT JOIN questions q ON q.id=qt.question_id
     LEFT JOIN (
        SELECT question_id,
               COUNT(*) AS alternatives_count,
               SUM(CASE WHEN is_correct=1 THEN 1 ELSE 0 END) AS correct_count
        FROM alternatives
        GROUP BY question_id
     ) quality ON quality.question_id=q.id
     WHERE st.domain='pop'
       AND st.active=1
       AND st.topic_type='process'
       AND st.code REGEXP '^(10[1-8]|20[1-9]|21[01]|30[1-7]|40[1-9]|41[0-4])$'
     GROUP BY st.id,st.code,st.title
     ORDER BY CAST(LEFT(st.code,1) AS UNSIGNED), CAST(st.code AS UNSIGNED)"
)->fetchAll(PDO::FETCH_ASSOC);

$supplementKeys = (int)$pdo->query(
    "SELECT COUNT(*) FROM question_import_keys
     WHERE source_key LIKE 'POP-MIN4-%'"
)->fetchColumn();

echo "=============================================================\n";
echo " AUDITORIA POPs 1-4 - MÍNIMO 4 QUESTÕES POR PROCESSO\n";
echo "=============================================================\n";
printf("Banco: %s\n", (string)envv('DB_DATABASE', 'curso'));
printf("Processos encontrados: %d / 40\n", count($rows));
printf("Questões complementares registradas: %d\n\n", $supplementKeys);

$failures = [];
$totalsByPop = [1=>0,2=>0,3=>0,4=>0];

foreach ($rows as $row) {
    $code = (string)$row['code'];
    $pop = (int)$code[0];
    $qty = (int)$row['qty'];
    $totalsByPop[$pop] = ($totalsByPop[$pop] ?? 0) + $qty;

    $status = $qty >= 4 ? 'OK' : 'FALTA';
    printf("[%s] %s -> %d questão(ões) | %s\n", $status, $code, $qty, $row['title']);

    if ($qty < 4) {
        $failures[] = "{$code} ({$qty}/4)";
    }
}

echo "\nTotais de vínculos por processo (uma questão pode contar em mais de um processo):\n";
foreach ($totalsByPop as $pop => $total) {
    printf("POP%d: %d vínculo(s) de questão em processos\n", $pop, $total);
}

$ok = count($rows) === 40 && $failures === [];

if (!$ok) {
    fwrite(STDERR, "\n[FALHA] Ainda há processo(s) abaixo do mínimo de 4 questões");
    if ($failures !== []) {
        fwrite(STDERR, ': ' . implode(', ', $failures));
    }
    fwrite(STDERR, ".\n");
    exit(3);
}

echo "\n[OK] Todos os 40 processos dos POPs 1, 2, 3 e 4 possuem pelo menos 4 questões válidas.\n";
