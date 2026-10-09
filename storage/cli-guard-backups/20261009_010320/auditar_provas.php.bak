<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use Dotenv\Dotenv;

Dotenv::createImmutable(BASE_PATH)->safeLoad();

function envv(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null) return $default;
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
    [\PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]
);

$rows = $pdo->query(
    "SELECT m.position AS module_position, m.title AS module_title,
            p.position AS phase_position, p.id AS phase_id, p.title AS phase_title,
            qz.id AS quiz_id,
            COUNT(DISTINCT qq.question_id) AS question_count
     FROM phases p
     INNER JOIN modules m ON m.id = p.module_id
     LEFT JOIN quizzes qz ON qz.phase_id = p.id AND qz.quiz_type = 'phase_exam' AND qz.active = 1
     LEFT JOIN quiz_questions qq ON qq.quiz_id = qz.id
     WHERE p.status = 'published'
       AND p.is_required = 1
     GROUP BY m.position, m.title, p.position, p.id, p.title, qz.id
     ORDER BY m.position, p.position"
)->fetchAll();

$total = count($rows);
$ready = 0;

foreach ($rows as $row) {
    if ((int) $row['question_count'] === 5) $ready++;
}

$missing = $total - $ready;

echo "==============================================\n";
echo " AUDITORIA DAS PROVAS DE FASE\n";
echo "==============================================\n";
echo "Fases obrigatórias: {$total}\n";
echo "Provas prontas (5/5): {$ready}\n";
echo "Provas incompletas: {$missing}\n\n";

foreach ($rows as $row) {
    $count = (int) $row['question_count'];
    $status = $count === 5 ? 'OK' : 'PENDENTE';
    echo sprintf(
        "[%s] M%s F%s | fase_id=%d | quiz_id=%s | %d/5 | %s\n",
        $status,
        $row['module_position'],
        $row['phase_position'],
        $row['phase_id'],
        $row['quiz_id'] ?: '-',
        $count,
        $row['phase_title']
    );
}

echo "\nQuestões com estrutura inválida (menos de 2 alternativas ou sem exatamente 1 correta):\n";
$invalid = $pdo->query(
    "SELECT q.id, q.phase_id, LEFT(q.statement, 100) AS statement,
            COUNT(a.id) AS alternatives,
            SUM(CASE WHEN a.is_correct = 1 THEN 1 ELSE 0 END) AS correct_answers
     FROM questions q
     LEFT JOIN alternatives a ON a.question_id = q.id
     WHERE q.active = 1
     GROUP BY q.id, q.phase_id, q.statement
     HAVING COUNT(a.id) < 2
         OR SUM(CASE WHEN a.is_correct = 1 THEN 1 ELSE 0 END) <> 1
     ORDER BY q.phase_id, q.id"
)->fetchAll();

if (!$invalid) {
    echo "Nenhuma.\n";
} else {
    foreach ($invalid as $row) {
        echo sprintf(
            "[INVÁLIDA] question_id=%d | phase_id=%s | alternativas=%d | corretas=%d | %s\n",
            $row['id'],
            $row['phase_id'] ?: '-',
            $row['alternatives'],
            $row['correct_answers'],
            $row['statement']
        );
    }
}
