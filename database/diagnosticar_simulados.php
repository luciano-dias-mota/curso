<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute este arquivo somente pelo terminal.\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
\Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

function envv(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return is_string($value) ? trim($value, "\"'") : $value;
}

function ok(string $label, string $detail = ''): void
{
    echo '[OK]   ' . $label . ($detail !== '' ? ' -> ' . $detail : '') . PHP_EOL;
}

function fail(string $label, Throwable|string $error): void
{
    $message = $error instanceof Throwable ? $error->getMessage() : $error;
    echo '[ERRO] ' . $label . ' -> ' . $message . PHP_EOL;
}

function section(string $title): void
{
    echo PHP_EOL . str_repeat('-', 68) . PHP_EOL;
    echo $title . PHP_EOL;
    echo str_repeat('-', 68) . PHP_EOL;
}

$userId = 2;
foreach ($argv as $arg) {
    if (preg_match('/^--user=(\d+)$/', $arg, $m)) {
        $userId = (int) $m[1];
    }
}

try {
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
} catch (Throwable $e) {
    fail('Conexão com o banco', $e);
    exit(1);
}

echo "=============================================================\n";
echo " DIAGNÓSTICO DO MÓDULO DE SIMULADOS\n";
echo "=============================================================\n";
echo 'Banco: ' . envv('DB_DATABASE', 'curso') . PHP_EOL;
echo 'Usuário testado: #' . $userId . PHP_EOL;

ok('Conexão com o banco');
try {
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    ok('Servidor SQL', $version);
    $mode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    ok('SQL_MODE', $mode !== '' ? $mode : '(vazio)');
} catch (Throwable $e) {
    fail('Informações do servidor', $e);
}

section('1. TABELAS E COLUNAS');
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
    'users',
];
foreach ($tables as $table) {
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        if ($stmt->fetchColumn()) {
            $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            ok("Tabela {$table}", "{$count} registro(s)");
        } else {
            echo "[FALTA] Tabela {$table}\n";
        }
    } catch (Throwable $e) {
        fail("Tabela {$table}", $e);
    }
}

$requiredColumns = [
    'question_limit',
    'time_limit_minutes',
    'scope_module_id',
    'selection_mode',
];
try {
    $cols = $pdo->query('SHOW COLUMNS FROM simulation_attempts')->fetchAll();
    $names = array_column($cols, 'Field');
    foreach ($requiredColumns as $column) {
        if (in_array($column, $names, true)) {
            ok("simulation_attempts.{$column}");
        } else {
            echo "[FALTA] simulation_attempts.{$column}\n";
        }
    }
} catch (Throwable $e) {
    fail('SHOW COLUMNS simulation_attempts', $e);
}

section('2. USUÁRIO / MATRÍCULA');
try {
    $stmt = $pdo->prepare(
        "SELECT u.id, u.name, u.email, u.status, r.slug AS role_slug
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.id = :id"
    );
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    if ($user) {
        ok('Usuário', sprintf('%s | %s | role=%s | status=%s', $user['name'], $user['email'], $user['role_slug'] ?? '?', $user['status']));
    } else {
        echo "[FALTA] Usuário #{$userId}\n";
    }

    $stmt = $pdo->prepare(
        "SELECT e.course_id, e.status, c.title, c.status AS course_status
         FROM enrollments e
         INNER JOIN courses c ON c.id = e.course_id
         WHERE e.user_id = :user_id"
    );
    $stmt->execute(['user_id' => $userId]);
    $rows = $stmt->fetchAll();
    if ($rows === []) {
        echo "[FALTA] Matrícula para usuário #{$userId}\n";
    } else {
        foreach ($rows as $row) {
            ok('Matrícula', sprintf('curso #%d | %s | matrícula=%s | curso=%s', $row['course_id'], $row['title'], $row['status'], $row['course_status']));
        }
    }
} catch (Throwable $e) {
    fail('Usuário/matrícula', $e);
}

section('3. CONSULTAS DA CENTRAL /simulados');
$queries = [];
$queries['Tentativas expiradas'] = [
    "SELECT id
     FROM simulation_attempts
     WHERE user_id = :user_id
       AND status = 'in_progress'
       AND TIMESTAMPDIFF(SECOND, started_at, NOW()) >= (time_limit_minutes * 60)",
    ['user_id' => $userId],
];
$queries['Tentativas em andamento'] = [
    "SELECT sa.id, sa.started_at, sa.question_limit, sa.time_limit_minutes,
            sa.selection_mode, sa.scope_module_id,
            s.title AS simulation_title, c.title AS course_title,
            m.title AS module_title,
            COALESCE(ans.answered, 0) AS answered
     FROM simulation_attempts sa
     INNER JOIN simulations s ON s.id = sa.simulation_id
     LEFT JOIN courses c ON c.id = s.course_id
     LEFT JOIN modules m ON m.id = sa.scope_module_id
     LEFT JOIN (
        SELECT attempt_id, COUNT(*) AS answered
        FROM simulation_answers
        GROUP BY attempt_id
     ) ans ON ans.attempt_id = sa.id
     WHERE sa.user_id = :user_id
       AND sa.status = 'in_progress'
     ORDER BY sa.started_at DESC",
    ['user_id' => $userId],
];
$queries['Histórico'] = [
    "SELECT sa.id, sa.attempt_number, sa.started_at, sa.finished_at,
            sa.question_limit, sa.score, sa.max_score, sa.percentage,
            sa.passed, sa.selection_mode,
            c.title AS course_title,
            m.title AS module_title
     FROM simulation_attempts sa
     INNER JOIN simulations s ON s.id = sa.simulation_id
     LEFT JOIN courses c ON c.id = s.course_id
     LEFT JOIN modules m ON m.id = sa.scope_module_id
     WHERE sa.user_id = :user_id
       AND sa.status = 'finished'
     ORDER BY sa.finished_at DESC, sa.id DESC
     LIMIT 30",
    ['user_id' => $userId],
];
$queries['Desempenho por módulo'] = [
    "SELECT COALESCE(m.id, 0) AS module_id,
            COALESCE(m.title, 'Questões gerais') AS module_title,
            COUNT(*) AS total,
            SUM(CASE WHEN COALESCE(sa2.is_correct, 0) = 1 THEN 1 ELSE 0 END) AS correct
     FROM simulation_attempt_questions saq
     INNER JOIN simulation_attempts sa ON sa.id = saq.attempt_id
     INNER JOIN questions q ON q.id = saq.question_id
     LEFT JOIN modules m ON m.id = q.module_id
     LEFT JOIN simulation_answers sa2
       ON sa2.attempt_id = sa.id
      AND sa2.question_id = q.id
     WHERE sa.user_id = :user_id
       AND sa.status = 'finished'
     GROUP BY m.id, m.title, m.position
     ORDER BY m.position, m.id",
    ['user_id' => $userId],
];

foreach ($queries as $label => [$sql, $params]) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        ok($label, count($rows) . ' linha(s)');
    } catch (Throwable $e) {
        fail($label, $e);
    }
}

section('4. CONSULTA DA TELA NOVO SIMULADO');
try {
    $stmt = $pdo->prepare(
        "SELECT c.id, c.title,
                SUM(CASE WHEN vp.difficulty = 'medium' THEN 1 ELSE 0 END) AS medium_count,
                SUM(CASE WHEN vp.difficulty = 'hard' THEN 1 ELSE 0 END) AS hard_count,
                COUNT(vp.id) AS total_count
         FROM enrollments e
         INNER JOIN courses c ON c.id = e.course_id
         LEFT JOIN (
            SELECT q.id, q.course_id, q.module_id, q.difficulty
            FROM questions q
            INNER JOIN (
                SELECT question_id,
                       COUNT(*) AS alternatives_count,
                       SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) AS correct_count
                FROM alternatives
                GROUP BY question_id
            ) qa ON qa.question_id = q.id
            WHERE q.active = 1
              AND q.question_type = 'multiple_choice'
              AND q.difficulty IN ('medium', 'hard')
              AND qa.alternatives_count >= 4
              AND qa.correct_count = 1
         ) vp ON vp.course_id = c.id
         WHERE e.user_id = :user_id
           AND e.status = 'active'
           AND c.status = 'published'
         GROUP BY c.id, c.title, c.position
         ORDER BY c.position, c.id"
    );
    $stmt->execute(['user_id' => $userId]);
    $courses = $stmt->fetchAll();
    ok('Cursos elegíveis', count($courses) . ' curso(s)');
    foreach ($courses as $course) {
        echo sprintf(
            "       #%d %s | medium=%d | hard=%d | total=%d\n",
            $course['id'],
            $course['title'],
            $course['medium_count'],
            $course['hard_count'],
            $course['total_count']
        );
    }
} catch (Throwable $e) {
    fail('Cursos elegíveis', $e);
}

section('5. ARQUIVOS DO MÓDULO');
$files = [
    'app/Controllers/SimulationController.php',
    'app/Services/SimulationService.php',
    'app/Views/student/simulations/index.php',
    'app/Views/student/simulations/create.php',
    'app/Views/student/simulations/attempt.php',
    'app/Views/student/simulations/result.php',
    'app/Views/student/simulations/review.php',
    'public/assets/css/simulation.css',
    'public/assets/js/simulation.js',
];
foreach ($files as $relative) {
    if (is_file(BASE_PATH . '/' . $relative)) {
        ok($relative);
    } else {
        echo '[FALTA] ' . $relative . PHP_EOL;
    }
}

echo PHP_EOL . "Diagnóstico concluído.\n";
