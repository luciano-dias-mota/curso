<?php

declare(strict_types=1);


if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execução permitida apenas via CLI.');
}
define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

\Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

function envv(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }
    return is_string($value) ? trim($value, "\"'") : $value;
}

$pdo = new \PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        envv('DB_HOST', '127.0.0.1'),
        envv('DB_PORT', '3306'),
        envv('DB_DATABASE', 'curso')
    ),
    (string) envv('DB_USERNAME', 'root'),
    (string) envv('DB_PASSWORD', ''),
    [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]
);

echo "AUDITORIA POP PMMT 2023\n";
echo "=======================\n\n";

$moduleStmt = $pdo->query(
    "SELECT
        COUNT(DISTINCT m.id) AS modules,
        COUNT(DISTINCT p.id) AS phases,
        COUNT(DISTINCT l.id) AS lessons,
        COUNT(DISTINCT lb.id) AS blocks
     FROM modules m
     LEFT JOIN phases p ON p.module_id = m.id
     LEFT JOIN lessons l ON l.phase_id = p.id
     LEFT JOIN lesson_blocks lb ON lb.lesson_id = l.id
     WHERE m.slug LIKE 'pop-pmmt-2023-%'"
);
$counts = $moduleStmt->fetch();

echo "Módulos: {$counts['modules']} / esperado 6\n";
echo "Fases/processos: {$counts['phases']} / esperado 53\n";
echo "Aulas/POPs: {$counts['lessons']} / esperado 128\n";
echo "Telas: {$counts['blocks']}\n\n";

$quizRows = $pdo->query(
    "SELECT
        qz.quiz_type,
        COUNT(DISTINCT qz.id) AS quizzes,
        SUM(CASE WHEN x.qtd = qz.question_limit THEN 1 ELSE 0 END) AS ready
     FROM quizzes qz
     INNER JOIN modules m ON m.id = qz.module_id
     LEFT JOIN (
        SELECT quiz_id, COUNT(*) AS qtd
        FROM quiz_questions
        GROUP BY quiz_id
     ) x ON x.quiz_id = qz.id
     WHERE m.slug LIKE 'pop-pmmt-2023-%'
       AND qz.active = 1
     GROUP BY qz.quiz_type
     ORDER BY FIELD(qz.quiz_type, 'lesson_fixation', 'phase_exam', 'module_boss')"
)->fetchAll();

echo "AVALIAÇÕES\n";
echo "----------\n";
foreach ($quizRows as $row) {
    echo "{$row['quiz_type']}: {$row['ready']}/{$row['quizzes']} prontas\n";
}

echo "\nPROBLEMAS DE ESTRUTURA\n";
echo "----------------------\n";

$badQuizzes = $pdo->query(
    "SELECT qz.id, qz.quiz_type, qz.title, qz.question_limit,
            COUNT(qq.question_id) AS qtd
     FROM quizzes qz
     INNER JOIN modules m ON m.id = qz.module_id
     LEFT JOIN quiz_questions qq ON qq.quiz_id = qz.id
     WHERE m.slug LIKE 'pop-pmmt-2023-%'
       AND qz.active = 1
     GROUP BY qz.id
     HAVING qtd <> qz.question_limit
     ORDER BY qz.quiz_type, qz.id"
)->fetchAll();

if (!$badQuizzes) {
    echo "Nenhuma avaliação incompleta.\n";
} else {
    foreach ($badQuizzes as $row) {
        echo "#{$row['id']} {$row['quiz_type']} | {$row['qtd']}/{$row['question_limit']} | {$row['title']}\n";
    }
}

$badQuestions = $pdo->query(
    "SELECT q.id, q.source_label,
            COUNT(a.id) AS alternatives,
            SUM(CASE WHEN a.is_correct = 1 THEN 1 ELSE 0 END) AS corrects
     FROM questions q
     LEFT JOIN alternatives a ON a.question_id = q.id
     WHERE q.source_label LIKE 'Manual POP PMMT 2023|%'
     GROUP BY q.id
     HAVING alternatives <> 4 OR corrects <> 1
     ORDER BY q.id"
)->fetchAll();

if (!$badQuestions) {
    echo "Nenhuma questão com gabarito inconsistente.\n";
} else {
    echo "\nQuestões com problema:\n";
    foreach ($badQuestions as $row) {
        echo "#{$row['id']} | alternativas={$row['alternatives']} | corretas={$row['corrects']} | {$row['source_label']}\n";
    }
}

$sourceCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM questions
     WHERE source_label LIKE 'Manual POP PMMT 2023|%'"
)->fetchColumn();

echo "\nQuestões com fonte POP 2023: {$sourceCount} / esperado 640\n";

$sourceFile = BASE_PATH . '/public/materials/pop-pmmt-2023.pdf';
echo "PDF oficial no sistema: " . (is_file($sourceFile) ? "OK" : "NÃO ENCONTRADO") . "\n";
