<?php

declare(strict_types=1);


if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execução permitida apenas via CLI.');
}


define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

use Dotenv\Dotenv;

Dotenv::createImmutable(BASE_PATH)->safeLoad();

function envv(string $key, mixed $default = null): mixed
{
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($v === false || $v === null) {
        return $default;
    }

    return is_string($v) ? trim($v, "\"'") : $v;
}

$apply = in_array('--apply', $argv ?? [], true);
$isProduction = strtolower((string) envv('APP_ENV', 'production')) === 'production';
$forceProduction = in_array('--force-production', $argv ?? [], true);

if ($apply && $isProduction && !$forceProduction) {
    fwrite(STDERR, "ABORTADO: APP_ENV=production. Use --apply --force-production somente após backup.\n");
    exit(1);
}

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        envv('DB_HOST', '127.0.0.1'),
        envv('DB_PORT', '3306'),
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

$sourceTitles = [
    'Fonte e escopo',
    'Fonte utilizada',
    'Fontes utilizadas',
    'Fonte oficial',
    'Referências da aula',
    'Referencias da aula',
];

$placeholders = implode(',', array_fill(0, count($sourceTitles), '?'));

$selectSql = "
    SELECT lb.id, lb.title, l.title AS lesson_title, m.title AS module_title
    FROM lesson_blocks lb
    INNER JOIN lessons l ON l.id = lb.lesson_id
    INNER JOIN phases p ON p.id = l.phase_id
    INNER JOIN modules m ON m.id = p.module_id
    WHERE (
        m.slug = 'lingua-portuguesa-ufmt-completa'
        OR LOWER(m.title) LIKE '%língua portuguesa%'
        OR LOWER(m.title) LIKE '%lingua portuguesa%'
    )
    AND (
        lb.title IN ($placeholders)
        OR LOWER(lb.content) LIKE '%conteúdo elaborado a partir de:%'
        OR LOWER(lb.content) LIKE '%conteudo elaborado a partir de:%'
    )
    ORDER BY m.id, p.position, l.position, lb.position
";

$stmt = $pdo->prepare($selectSql);
$stmt->execute($sourceTitles);
$rows = $stmt->fetchAll();

echo "============================================\n";
echo " LIMPEZA DE FONTES DIDATICAS - PORTUGUES\n";
echo "============================================\n\n";

if (!$rows) {
    echo "Nenhuma tela de fonte desnecessaria foi encontrada.\n";
    exit(0);
}

echo "Telas encontradas: " . count($rows) . "\n\n";

foreach ($rows as $row) {
    echo "- Bloco #{$row['id']} | {$row['module_title']} | {$row['lesson_title']} | {$row['title']}\n";
}

if (!$apply) {
    echo "\nDRY-RUN: nenhum registro foi removido.\n";
    echo "Para aplicar a exclusão, execute novamente com --apply.\n";
    exit(0);
}

$ids = array_map(
    static fn (array $row): int => (int) $row['id'],
    $rows
);

$deletePlaceholders = implode(',', array_fill(0, count($ids), '?'));

$pdo->beginTransaction();

try {
    $delete = $pdo->prepare(
        "DELETE FROM lesson_blocks WHERE id IN ($deletePlaceholders)"
    );
    $delete->execute($ids);

    /*
     * A exclusão das telas não altera regras, questões ou gabaritos.
     * Apenas remove telas de atribuição/referência do fluxo do estudante.
     *
     * O progresso será recalculado normalmente quando a aula for acessada
     * novamente, pois o sistema conta os blocos obrigatórios existentes.
     */
    $pdo->commit();

    echo "\nRemovidas: " . $delete->rowCount() . " telas.\n";
    echo "Conteudo didatico, exercicios e gabaritos foram preservados.\n";
    echo "Fontes juridicas/legislativas de outros modulos NAO foram alteradas.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "Erro: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
