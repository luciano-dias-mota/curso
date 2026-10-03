<?php

declare(strict_types=1);

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

function lowerText(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function trimWidth(string $value, int $width): string
{
    if (function_exists('mb_strimwidth')) return mb_strimwidth($value, 0, $width, '…', 'UTF-8');
    return strlen($value) <= $width ? $value : substr($value, 0, max(0, $width - 3)) . '...';
}

function auxiliaryModule(array $row): bool
{
    $slug = strtolower((string) ($row['slug'] ?? ''));
    $title = lowerText((string) ($row['title'] ?? ''));

    return str_contains($slug, 'modulo-0-')
        || str_contains($slug, 'simulado-final-geral')
        || str_contains($slug, 'fontes-utilizadas')
        || str_contains($title, 'módulo 0 - orientações')
        || str_contains($title, 'simulado final geral')
        || str_contains($title, 'fontes utilizadas por módulo');
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

$course = $pdo->query(
    "SELECT id, title
     FROM courses
     WHERE status='published'
     ORDER BY position, id
     LIMIT 1"
)->fetch();

if (!$course) {
    fwrite(STDERR, "Nenhum curso publicado encontrado.\n");
    exit(2);
}

$courseId = (int) $course['id'];

echo "=============================================================\n";
echo " AUDITORIA DO BANCO DE QUESTÕES PARA SIMULADOS - V2\n";
echo "=============================================================\n";
echo "Curso: #{$courseId} - {$course['title']}\n\n";

$sql = "SELECT m.id, m.position, m.title, m.slug,
               SUM(CASE WHEN q.difficulty='easy' THEN 1 ELSE 0 END) AS easy_count,
               SUM(CASE WHEN q.difficulty='medium' THEN 1 ELSE 0 END) AS medium_count,
               SUM(CASE WHEN q.difficulty='hard' THEN 1 ELSE 0 END) AS hard_count,
               COUNT(q.id) AS total_count,
               SUM(CASE WHEN q.id IS NOT NULL AND quality.alternatives_count >= 4 AND quality.correct_count = 1 THEN 1 ELSE 0 END) AS valid_count
        FROM modules m
        LEFT JOIN questions q
          ON q.module_id=m.id
         AND q.course_id=m.course_id
         AND q.active=1
         AND q.question_type='multiple_choice'
        LEFT JOIN (
            SELECT question_id,
                   COUNT(*) AS alternatives_count,
                   SUM(CASE WHEN is_correct=1 THEN 1 ELSE 0 END) AS correct_count
            FROM alternatives
            GROUP BY question_id
        ) quality ON quality.question_id=q.id
        WHERE m.course_id=:course_id
          AND m.status='published'
        GROUP BY m.id,m.position,m.title,m.slug
        ORDER BY m.position,m.id";
$stmt = $pdo->prepare($sql);
$stmt->execute(['course_id'=>$courseId]);
$rows = $stmt->fetchAll();

$totalMedium=0; $totalHard=0; $totalEasy=0; $totalValid=0; $totalAll=0;
$cobravelMedium=0; $cobravelHard=0; $cobravelValid=0;
foreach ($rows as $row) {
    $e=(int)$row['easy_count'];
    $m=(int)$row['medium_count'];
    $h=(int)$row['hard_count'];
    $t=(int)$row['total_count'];
    $v=(int)$row['valid_count'];
    $aux=auxiliaryModule($row);

    $totalEasy += $e;
    $totalMedium += $m;
    $totalHard += $h;
    $totalAll += $t;
    $totalValid += $v;

    if (!$aux) {
        $cobravelMedium += $m;
        $cobravelHard += $h;
        $cobravelValid += $v;
    }

    printf(
        "%-68s E:%3d | M:%4d | H:%3d | Válidas:%4d | Total:%4d%s\n",
        trimWidth((string)$row['title'],68),
        $e,$m,$h,$v,$t,
        $aux ? ' | AUXILIAR' : ''
    );
}

echo "\n-------------------------------------------------------------\n";
printf("Fáceis:         %d\n", $totalEasy);
printf("Intermediárias: %d\n", $totalMedium);
printf("Difíceis:       %d\n", $totalHard);
printf("Total ativo:    %d\n", $totalAll);
printf("Total válido:   %d\n", $totalValid);
echo "-------------------------------------------------------------\n";
printf("Banco cobrável (sem módulos auxiliares): %d válidas | M:%d | H:%d\n\n", $cobravelValid, $cobravelMedium, $cobravelHard);

$invalidStmt=$pdo->prepare(
    "SELECT COUNT(*)
     FROM questions q
     LEFT JOIN (
       SELECT question_id, COUNT(*) alternatives_count,
              SUM(CASE WHEN is_correct=1 THEN 1 ELSE 0 END) correct_count
       FROM alternatives GROUP BY question_id
     ) x ON x.question_id=q.id
     WHERE q.course_id=:c AND q.active=1
       AND q.question_type='multiple_choice'
       AND (COALESCE(x.alternatives_count,0) < 4 OR COALESCE(x.correct_count,0) <> 1)"
);
$invalidStmt->execute(['c'=>$courseId]);
$invalid=(int)$invalidStmt->fetchColumn();
echo "Questões inválidas para o simulador: {$invalid}\n";

$dupStmt=$pdo->prepare(
    "SELECT COUNT(*) FROM (
        SELECT statement
        FROM questions
        WHERE course_id=:c AND active=1
        GROUP BY statement
        HAVING COUNT(*) > 1
     ) d"
);
$dupStmt->execute(['c'=>$courseId]);
echo "Enunciados duplicados (grupos): ".(int)$dupStmt->fetchColumn()."\n\n";

echo "COBERTURA RECOMENDADA\n";
echo "----------------------\n";
foreach ($rows as $row) {
    if (auxiliaryModule($row)) {
        echo "[AUXILIAR - NÃO EXIGIR BANCO] {$row['title']}\n";
        continue;
    }

    $valid=(int)$row['valid_count'];
    $hard=(int)$row['hard_count'];
    $easy=(int)$row['easy_count'];

    if ($valid===0) {
        echo "[SEM BANCO] {$row['title']}\n";
    } elseif ($valid<40) {
        echo "[BAIXA COBERTURA: {$valid}] {$row['title']}\n";
    } elseif ($hard===0) {
        echo "[SEM HARD] {$row['title']}\n";
    } elseif ($hard<10) {
        echo "[POUCAS HARD: {$hard}] {$row['title']}\n";
    } elseif ($easy>0) {
        echo "[OK, MAS HÁ {$easy} EASY] {$row['title']}\n";
    } else {
        echo "[OK] {$row['title']}\n";
    }
}

echo "\n";
if ($totalEasy > 0) {
    echo "ATENÇÃO: existem questões classificadas como easy; o pacote V2 não cria nenhuma questão easy.\n";
}
if ($cobravelValid >= 1000 && $totalHard >= 100) {
    echo "Banco amplo para simulados dinâmicos, com cobertura intermediária e difícil.\n";
} elseif ($cobravelValid >= 200) {
    echo "Banco suficiente para simulados; continue ampliando dificuldade e cobertura temática.\n";
} else {
    echo "ATENÇÃO: há menos de 200 questões válidas no banco cobrável.\n";
}
