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
echo " AUDITORIA DO BANCO DE QUESTÕES PARA SIMULADOS\n";
echo "=============================================================\n";
echo "Curso: #{$courseId} - {$course['title']}\n\n";

$sql = "SELECT m.id, m.position, m.title,
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
        GROUP BY m.id,m.position,m.title
        ORDER BY m.position,m.id";
$stmt = $pdo->prepare($sql);
$stmt->execute(['course_id'=>$courseId]);
$rows = $stmt->fetchAll();

$totalMedium=0; $totalHard=0; $totalValid=0; $totalAll=0;
foreach ($rows as $row) {
    $m=(int)$row['medium_count'];
    $h=(int)$row['hard_count'];
    $t=(int)$row['total_count'];
    $v=(int)$row['valid_count'];
    $totalMedium += $m;
    $totalHard += $h;
    $totalAll += $t;
    $totalValid += $v;

    printf("%-72s M:%4d | H:%4d | Válidas:%4d | Total:%4d\n", mb_strimwidth($row['title'],0,72,'…'),$m,$h,$v,$t);
}

echo "\n-------------------------------------------------------------\n";
printf("Intermediárias: %d\n", $totalMedium);
printf("Difíceis:       %d\n", $totalHard);
printf("Total ativo:    %d\n", $totalAll);
printf("Total válido:   %d\n", $totalValid);
echo "-------------------------------------------------------------\n\n";

$popStmt=$pdo->prepare("SELECT COUNT(*) FROM questions WHERE course_id=:c AND active=1 AND source_label LIKE 'Manual POP PMMT 2023|%'");
$popStmt->execute(['c'=>$courseId]);
$pop=(int)$popStmt->fetchColumn();
echo "Questões POP PMMT 2023: {$pop} / esperado na fonte estruturada: 640\n";

$portStmt=$pdo->prepare("SELECT COUNT(*) FROM questions q INNER JOIN modules m ON m.id=q.module_id WHERE q.course_id=:c AND q.active=1 AND (m.slug LIKE '%lingua-portuguesa%' OR m.title LIKE '%Língua Portuguesa%')");
$portStmt->execute(['c'=>$courseId]);
$port=(int)$portStmt->fetchColumn();
echo "Questões de Língua Portuguesa no módulo: {$port}\n\n";

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
echo "Questões inválidas para o simulador (alternativas/gabarito): {$invalid}\n";

$unassignedStmt=$pdo->prepare("SELECT COUNT(*) FROM questions WHERE course_id=:c AND active=1 AND module_id IS NULL");
$unassignedStmt->execute(['c'=>$courseId]);
echo "Questões ativas sem módulo: ".(int)$unassignedStmt->fetchColumn()."\n";

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
    $valid=(int)$row['valid_count'];
    $hard=(int)$row['hard_count'];
    if ($valid===0) {
        echo "[SEM BANCO] {$row['title']}\n";
    } elseif ($valid<40) {
        echo "[BAIXA COBERTURA: {$valid}] {$row['title']}\n";
    } elseif ($hard===0) {
        echo "[SEM HARD] {$row['title']}\n";
    } elseif ($hard<10) {
        echo "[POUCAS HARD: {$hard}] {$row['title']}\n";
    } else {
        echo "[OK] {$row['title']}\n";
    }
}

echo "\n";
if ($totalValid < 200) {
    echo "ATENÇÃO: há menos de 200 questões válidas no banco.\n";
} elseif ($totalHard === 0) {
    echo "ATENÇÃO: não há questões classificadas como hard.\n";
    echo "O simulador continuará funcionando e preencherá a cota difícil com medium.\n";
} else {
    echo "Banco suficiente para simulados dinâmicos. Continue ampliando os módulos com baixa cobertura.\n";
}
