<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execução permitida apenas via CLI.');
}
define('BASE_PATH',dirname(__DIR__));require BASE_PATH.'/vendor/autoload.php';\Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();
function ev(string $k,mixed $d=null):mixed{$v=$_ENV[$k]??$_SERVER[$k]??getenv($k);return ($v===false||$v===null)?$d:(is_string($v)?trim($v,"\"'"):$v);} 
$pdo=new \PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',ev('DB_HOST','127.0.0.1'),(int)ev('DB_PORT',3306),ev('DB_DATABASE','curso')),(string)ev('DB_USERNAME','root'),(string)ev('DB_PASSWORD',''),[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
$checks=[
'Cursos duplicados'=>"SELECT title,COUNT(*) qtd FROM courses GROUP BY title HAVING COUNT(*)>1",
'Módulos/fases/aulas/telas'=>"SELECT m.position,m.title,COUNT(DISTINCT p.id) fases,COUNT(DISTINCT l.id) aulas,COUNT(lb.id) telas FROM modules m LEFT JOIN phases p ON p.module_id=m.id LEFT JOIN lessons l ON l.phase_id=p.id LEFT JOIN lesson_blocks lb ON lb.lesson_id=l.id WHERE m.status='published' GROUP BY m.id,m.position,m.title ORDER BY m.position",
'Aulas sem telas'=>"SELECT l.id,l.title FROM lessons l LEFT JOIN lesson_blocks b ON b.lesson_id=l.id WHERE l.status='published' GROUP BY l.id,l.title HAVING COUNT(b.id)=0",
'Telas vazias'=>"SELECT id,title FROM lesson_blocks WHERE content IS NULL OR TRIM(content)='' LIMIT 50",
'Fases de biblioteca'=>"SELECT p.id,p.title,COUNT(l.id) aulas FROM phases p LEFT JOIN lessons l ON l.phase_id=p.id WHERE p.is_required=0 GROUP BY p.id,p.title ORDER BY p.id"
];
echo "=== AUDITORIA PMMT ACADEMY ===\n\n";foreach($checks as $name=>$sql){echo "[$name]\n";$rows=$pdo->query($sql)->fetchAll();if(!$rows){echo "OK - nenhum item.\n\n";continue;}foreach($rows as $r)echo json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";echo "\n";}
