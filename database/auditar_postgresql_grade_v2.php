<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida apenas via CLI.\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Database;

App::boot(BASE_PATH);

$pdo = Database::connection();

$courseStmt = $pdo->prepare(
    "SELECT id, title, status
       FROM courses
      WHERE slug = 'postgresql-zero-intermediario'
      LIMIT 1"
);
$courseStmt->execute();
$course = $courseStmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    fwrite(STDERR, "Curso PostgreSQL não encontrado.\n");
    exit(1);
}

$courseId = (int) $course['id'];

$stmt = $pdo->prepare(
    "SELECT
        m.position AS module_position,
        m.title AS module_title,
        m.status AS module_status,
        COUNT(DISTINCT l.id) AS lessons_total,
        SUM(CASE WHEN l.status = 'published' THEN 1 ELSE 0 END) AS lessons_published,
        COUNT(DISTINCT CASE WHEN lb.id IS NOT NULL THEN l.id END) AS lessons_with_blocks,
        COUNT(DISTINCT CASE WHEN la.id IS NOT NULL THEN l.id END) AS lessons_with_labs,
        COUNT(DISTINCT CASE WHEN la.position = 1 THEN la.id END) AS guided_or_first_labs,
        COUNT(DISTINCT CASE WHEN la.position = 2 THEN la.id END) AS challenge_labs,
        COUNT(DISTINCT CASE WHEN la.position = 3 THEN la.id END) AS reinforcement_labs
     FROM modules m
     LEFT JOIN phases p
       ON p.module_id = m.id
      AND p.status <> 'archived'
     LEFT JOIN lessons l
       ON l.phase_id = p.id
      AND l.status <> 'archived'
     LEFT JOIN lesson_blocks lb
       ON lb.lesson_id = l.id
     LEFT JOIN lab_activities la
       ON la.lesson_id = l.id
      AND la.status <> 'archived'
     WHERE m.course_id = :course_id
       AND m.status <> 'archived'
     GROUP BY m.id, m.position, m.title, m.status
     ORDER BY m.position, m.id"
);
$stmt->execute(['course_id' => $courseId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=========================================================================\n";
echo " AUDITORIA PEDAGÓGICA - POSTGRESQL GRADE V2\n";
echo "=========================================================================\n";
printf("Curso: #%d - %s [%s]\n\n", $courseId, (string) $course['title'], (string) $course['status']);

printf(
    "%-4s %-43s %5s %5s %7s %6s %4s %4s %4s\n",
    'MOD',
    'MÓDULO',
    'AULAS',
    'PUB.',
    'BLOCOS',
    'LABS',
    'A1',
    'A2',
    'A3'
);
echo str_repeat('-', 100) . "\n";

$totals = [
    'lessons' => 0,
    'published' => 0,
    'blocks' => 0,
    'labs' => 0,
    'a1' => 0,
    'a2' => 0,
    'a3' => 0,
];

foreach ($rows as $row) {
    printf(
        "%-4d %-43s %5d %5d %7d %6d %4d %4d %4d\n",
        max(0, (int) $row['module_position'] - 1),
        mb_strimwidth((string) $row['module_title'], 0, 43, '…'),
        (int) $row['lessons_total'],
        (int) $row['lessons_published'],
        (int) $row['lessons_with_blocks'],
        (int) $row['lessons_with_labs'],
        (int) $row['guided_or_first_labs'],
        (int) $row['challenge_labs'],
        (int) $row['reinforcement_labs']
    );

    $totals['lessons'] += (int) $row['lessons_total'];
    $totals['published'] += (int) $row['lessons_published'];
    $totals['blocks'] += (int) $row['lessons_with_blocks'];
    $totals['labs'] += (int) $row['lessons_with_labs'];
    $totals['a1'] += (int) $row['guided_or_first_labs'];
    $totals['a2'] += (int) $row['challenge_labs'];
    $totals['a3'] += (int) $row['reinforcement_labs'];
}

echo str_repeat('-', 100) . "\n";
printf(
    "%-48s %5d %5d %7d %6d %4d %4d %4d\n",
    'TOTAL',
    $totals['lessons'],
    $totals['published'],
    $totals['blocks'],
    $totals['labs'],
    $totals['a1'],
    $totals['a2'],
    $totals['a3']
);

echo "\nLegenda:\n";
echo "  BLOCOS = aulas com pelo menos um bloco teórico/visual.\n";
echo "  LABS   = aulas com pelo menos uma atividade prática.\n";
echo "  A1     = atividade posição 1 (prática guiada/primeira prática).\n";
echo "  A2     = desafio posição 2.\n";
echo "  A3     = reforço posição 3.\n";

echo "\nCritério da nova metodologia:\n";
echo "  aula SQL pronta = conteúdo claro + visual + prática guiada + desafio + reforço.\n";
echo "  A grade estrutural pode estar pronta mesmo quando o conteúdo de uma aula ainda está em draft.\n";
