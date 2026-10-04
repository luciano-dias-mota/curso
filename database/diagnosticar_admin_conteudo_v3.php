<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
$autoload = $basePath . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "ERRO: vendor/autoload.php não encontrado. Execute composer install na raiz do projeto.\n");
    exit(1);
}

require $autoload;

use App\Core\App;
use App\Core\Database;

App::boot($basePath);
$pdo = Database::connection();

$checks = [];
$check = static function (string $label, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = [$label, $ok, $detail];
};

fwrite(STDOUT, "=============================================================\n");
fwrite(STDOUT, " DIAGNÓSTICO LD ADMIN - CONTEÚDO E QUESTÕES V3.3\n");
fwrite(STDOUT, "=============================================================\n\n");

try {
    $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $serverVersion = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    $check('Conexão com o banco', $dbName !== '', "banco={$dbName} | servidor={$serverVersion}");

    $tables = [
        'questions', 'alternatives', 'modules', 'courses', 'quiz_questions',
        'simulation_questions', 'simulation_attempt_questions', 'simulation_answers',
        'media_files', 'lessons', 'lesson_blocks', 'audit_logs',
    ];

    // MariaDB/MySQL: use information_schema com bind de parâmetro.
    // Importante: o SQL abaixo contém quebras de linha reais, não os caracteres literais "\\n".
    $tableSql = <<<'SQL'
SELECT COUNT(*)
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = :table_name
SQL;

    $tableStmt = $pdo->prepare($tableSql);
    $tableExists = [];

    foreach ($tables as $table) {
        $tableStmt->execute(['table_name' => $table]);
        $exists = (int) $tableStmt->fetchColumn() === 1;
        $tableExists[$table] = $exists;
        $check("Tabela {$table}", $exists);
    }

    $requiredFiles = [
        'app/Controllers/Admin/QuestionController.php',
        'app/Views/admin/questions/index.php',
        'app/Views/admin/questions/form.php',
        'app/Views/admin/media/index.php',
        'app/Views/admin/lessons/index.php',
        'app/Views/admin/lessons/edit.php',
        'app/Views/layouts/admin.php',
        'routes/admin.php',
        'public/assets/css/admin.css',
        'public/assets/js/admin-media.js',
    ];

    foreach ($requiredFiles as $file) {
        $check("Arquivo {$file}", is_file($basePath . '/' . $file));
    }

    if (($tableExists['questions'] ?? false) && ($tableExists['alternatives'] ?? false)) {
        $totalQuestions = (int) $pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn();
        $activeQuestions = (int) $pdo->query('SELECT COUNT(*) FROM questions WHERE active = 1')->fetchColumn();

        $validQuestionsSql = <<<'SQL'
SELECT COUNT(*)
FROM questions q
WHERE q.active = 1
  AND (SELECT COUNT(*) FROM alternatives a WHERE a.question_id = q.id) >= 2
  AND (SELECT COUNT(*) FROM alternatives a2 WHERE a2.question_id = q.id AND a2.is_correct = 1) = 1
SQL;
        $validQuestions = (int) $pdo->query($validQuestionsSql)->fetchColumn();

        $invalidCorrectSql = <<<'SQL'
SELECT COUNT(*)
FROM questions q
WHERE (SELECT COUNT(*) FROM alternatives a WHERE a.question_id = q.id AND a.is_correct = 1) <> 1
SQL;
        $invalidCorrect = (int) $pdo->query($invalidCorrectSql)->fetchColumn();

        $withoutAlternativesSql = <<<'SQL'
SELECT COUNT(*)
FROM questions q
WHERE NOT EXISTS (
    SELECT 1
    FROM alternatives a
    WHERE a.question_id = q.id
)
SQL;
        $withoutAlternatives = (int) $pdo->query($withoutAlternativesSql)->fetchColumn();

        $check('Questões cadastradas', $totalQuestions > 0, "total={$totalQuestions}");
        $check('Questões ativas', $activeQuestions > 0, "ativas={$activeQuestions}");
        $check('Questões válidas para uso', $validQuestions > 0, "ativas_validas={$validQuestions}");
        $check('Gabarito único por questão', $invalidCorrect === 0, "fora_padrao={$invalidCorrect}");
        $check('Questões com alternativas', $withoutAlternatives === 0, "sem_alternativas={$withoutAlternatives}");
    } else {
        $check(
            'Integridade do banco de questões',
            false,
            'não foi possível auditar porque questions e/ou alternatives não existem'
        );
    }
} catch (Throwable $e) {
    $check('Execução das consultas de diagnóstico', false, $e->getMessage());
}

foreach ($checks as [$label, $ok, $detail]) {
    fwrite(STDOUT, sprintf(
        "[%s] %s%s\n",
        $ok ? 'OK' : 'FALHA',
        $label,
        $detail !== '' ? " | {$detail}" : ''
    ));
}

$failed = array_filter($checks, static fn (array $item): bool => !$item[1]);
fwrite(STDOUT, "\n");

if ($failed === []) {
    fwrite(STDOUT, "OK: gestão de aulas, vídeos e questões pronta.\n");
    exit(0);
}

fwrite(STDOUT, "ATENÇÃO: há verificações com falha. Revise apenas os itens marcados como FALHA.\n");
exit(1);
