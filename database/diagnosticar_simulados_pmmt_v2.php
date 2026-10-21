<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute este arquivo somente pelo terminal.\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Database;
use App\Services\PmmtCourseScopeService;
use App\Services\SimulationService;

App::boot(BASE_PATH);

$pdo = Database::connection();

function diagOk(string $label, string $detail = ''): void
{
    echo '[OK]   ' . $label . ($detail !== '' ? ' -> ' . $detail : '') . PHP_EOL;
}

function diagFail(string $label, Throwable|string $error): void
{
    $message = $error instanceof Throwable ? $error->getMessage() : $error;
    echo '[ERRO] ' . $label . ' -> ' . $message . PHP_EOL;
}

echo "=============================================================\n";
echo " DIAGNÓSTICO PMMT - CENTRAL DE SIMULADOS V2\n";
echo "=============================================================\n";
echo 'Banco: ' . (string) $pdo->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;

try {
    $courseId = (new PmmtCourseScopeService())->courseId($pdo);
    diagOk('Curso PMMT publicado', '#' . $courseId);
} catch (Throwable $e) {
    diagFail('Curso PMMT publicado', $e);
    exit(2);
}

$tables = [
    'simulations',
    'simulation_attempts',
    'simulation_attempt_questions',
    'simulation_answers',
    'questions',
    'alternatives',
    'modules',
    'courses',
    'enrollments',
];

foreach ($tables as $table) {
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table
              LIMIT 1"
        );
        $stmt->execute(['table' => $table]);
        if (!$stmt->fetchColumn()) {
            echo "[FALTA] {$table}\n";
            continue;
        }
        $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        diagOk($table, $count . ' registro(s)');
    } catch (Throwable $e) {
        diagFail($table, $e);
    }
}

echo "\nColunas críticas de simulation_attempts:\n";
$requiredColumns = [
    'question_limit',
    'time_limit_minutes',
    'scope_module_id',
    'selection_mode',
];

try {
    $cols = $pdo->query('SHOW COLUMNS FROM simulation_attempts')->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($cols, 'Field');
    foreach ($requiredColumns as $column) {
        echo (in_array($column, $names, true) ? '[OK]   ' : '[FALTA] ')
            . 'simulation_attempts.' . $column . PHP_EOL;
    }
} catch (Throwable $e) {
    diagFail('SHOW COLUMNS simulation_attempts', $e);
}

echo "\nVínculo dos simulados ao PMMT:\n";
try {
    $stmt = $pdo->prepare(
        "SELECT id, title, course_id, active
           FROM simulations
          WHERE course_id = :course_id
             OR course_id IS NULL
          ORDER BY id"
    );
    $stmt->execute(['course_id' => $courseId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        printf(
            "  #%d | course_id=%s | active=%d | %s\n",
            (int) $row['id'],
            $row['course_id'] === null ? 'NULL' : (string) $row['course_id'],
            (int) $row['active'],
            (string) $row['title']
        );
    }
} catch (Throwable $e) {
    diagFail('Vínculo simulations.course_id', $e);
}

echo "\nQuestões válidas do PMMT:\n";
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
           FROM questions q
           INNER JOIN (
                SELECT question_id,
                       COUNT(*) AS alternatives_count,
                       SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) AS correct_count
                  FROM alternatives
                 GROUP BY question_id
           ) qa ON qa.question_id = q.id
          WHERE q.course_id = :course_id
            AND q.active = 1
            AND q.question_type = 'multiple_choice'
            AND q.difficulty IN ('medium','hard')
            AND qa.alternatives_count >= 4
            AND qa.correct_count = 1"
    );
    $stmt->execute(['course_id' => $courseId]);
    diagOk('Banco elegível para simulados', (string) (int) $stmt->fetchColumn() . ' questões');
} catch (Throwable $e) {
    diagFail('Banco elegível para simulados', $e);
}

echo "\nTeste do painel para matrículas PMMT ativas:\n";
try {
    $stmt = $pdo->prepare(
        "SELECT e.user_id
           FROM enrollments e
          WHERE e.course_id = :course_id
            AND e.status = 'active'
          ORDER BY e.user_id"
    );
    $stmt->execute(['course_id' => $courseId]);
    $userIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if ($userIds === []) {
        echo "[FALTA] Nenhuma matrícula PMMT ativa.\n";
    }

    foreach ($userIds as $userId) {
        try {
            $data = (new SimulationService())->dashboard($userId);
            diagOk(
                'dashboard user #' . $userId,
                count($data['activeAttempts'] ?? []) . ' ativa(s), '
                . count($data['history'] ?? []) . ' histórico(s)'
            );
        } catch (Throwable $e) {
            diagFail('dashboard user #' . $userId, $e);
        }
    }
} catch (Throwable $e) {
    diagFail('Matrículas/teste dashboard', $e);
}

echo "\nDiagnóstico concluído. Nenhum dado foi alterado.\n";
