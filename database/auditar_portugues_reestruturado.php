<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
\Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

function envv(string $key, mixed $default = null): mixed {
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return ($v === false || $v === null) ? $default : (is_string($v) ? trim($v, "\"'") : $v);
}

$pdo = new \PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        envv('DB_HOST','127.0.0.1'), envv('DB_PORT','3306'), envv('DB_DATABASE','curso')),
    (string) envv('DB_USERNAME','root'), (string) envv('DB_PASSWORD',''),
    [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]
);

$stmt=$pdo->prepare("SELECT * FROM modules WHERE slug='lingua-portuguesa-ufmt-completa' ORDER BY id DESC LIMIT 1");
$stmt->execute();
$m=$stmt->fetch();
if(!$m){fwrite(STDERR,"Módulo reestruturado não encontrado.\n");exit(1);}

$moduleId=(int)$m['id'];
$stats=$pdo->prepare(
    "SELECT
      (SELECT COUNT(*) FROM phases WHERE module_id=:m1 AND status='published') phases,
      (SELECT COUNT(*) FROM lessons l JOIN phases p ON p.id=l.phase_id WHERE p.module_id=:m2 AND l.status='published') lessons,
      (SELECT COUNT(*) FROM lesson_blocks lb JOIN lessons l ON l.id=lb.lesson_id JOIN phases p ON p.id=l.phase_id WHERE p.module_id=:m3) blocks,
      (SELECT COUNT(*) FROM lesson_blocks lb JOIN lessons l ON l.id=lb.lesson_id JOIN phases p ON p.id=l.phase_id WHERE p.module_id=:m4 AND lb.block_type='question') static_questions,
      (SELECT COUNT(*) FROM questions WHERE module_id=:m5 AND active=1) questions"
);
$stats->execute(['m1'=>$moduleId,'m2'=>$moduleId,'m3'=>$moduleId,'m4'=>$moduleId,'m5'=>$moduleId]);
$s=$stats->fetch();

echo "AUDITORIA — LÍNGUA PORTUGUESA\n============================\n";
echo "Módulo #{$moduleId}: {$m['title']}\n";
echo "Fases: {$s['phases']} (esperado 15)\n";
echo "Aulas: {$s['lessons']} (esperado 36)\n";
echo "Telas: {$s['blocks']} (esperado 216)\n";
echo "Questões: {$s['questions']} (esperado 108)\n";
echo "Questões estáticas dentro das aulas: {$s['static_questions']} (esperado 0)\n\n";

$rows=$pdo->prepare(
    "SELECT qz.quiz_type, COUNT(*) total,
            SUM(CASE WHEN x.qtd=qz.question_limit THEN 1 ELSE 0 END) ready,
            MIN(qz.required_score) min_score,
            MAX(qz.required_score) max_score
     FROM quizzes qz
     LEFT JOIN (SELECT quiz_id,COUNT(*) qtd FROM quiz_questions GROUP BY quiz_id) x ON x.quiz_id=qz.id
     WHERE qz.module_id=:m
       AND qz.quiz_type IN('lesson_fixation','phase_exam','module_boss')
     GROUP BY qz.quiz_type
     ORDER BY FIELD(qz.quiz_type,'lesson_fixation','phase_exam','module_boss')"
);
$rows->execute(['m'=>$moduleId]);
foreach($rows->fetchAll() as $r){
    echo sprintf("%-16s %d/%d prontos | nota %.2f%%\n",
        $r['quiz_type'], $r['ready'], $r['total'], $r['min_score']);
}

echo "\nCHECK DE ALTERNATIVAS\n---------------------\n";
$bad=$pdo->prepare(
    "SELECT q.id, q.statement,
            COUNT(a.id) alternatives,
            SUM(CASE WHEN a.is_correct=1 THEN 1 ELSE 0 END) corrects
     FROM questions q
     LEFT JOIN alternatives a ON a.question_id=q.id
     WHERE q.module_id=:m
     GROUP BY q.id
     HAVING alternatives<>4 OR corrects<>1
     ORDER BY q.id"
);
$bad->execute(['m'=>$moduleId]);
$problems=$bad->fetchAll();
if(!$problems){echo "Todas as questões têm 4 alternativas e exatamente 1 correta.\n";}
else{foreach($problems as $r){echo "Questão #{$r['id']}: {$r['alternatives']} alternativas / {$r['corrects']} corretas\n";}}

echo "\nMÓDULOS ANTIGOS ARQUIVADOS\n--------------------------\n";
$old=$pdo->prepare("SELECT id,title,position,status FROM modules WHERE course_id=:c AND status='archived' AND (title LIKE '%Língua Portuguesa%' OR title LIKE '%Lingua Portuguesa%') ORDER BY id");
$old->execute(['c'=>$m['course_id']]);
foreach($old->fetchAll() as $r){echo "#{$r['id']} | pos {$r['position']} | {$r['title']}\n";}
