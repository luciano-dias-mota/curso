<?php

declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
$autoload=BASE_PATH.'/vendor/autoload.php';
if (!is_file($autoload)) { fwrite(STDERR,"Erro: vendor/autoload.php não encontrado.\n"); exit(1); }
require $autoload;
\Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();
function envv(string $k,mixed $d=null):mixed{$v=$_ENV[$k]??$_SERVER[$k]??getenv($k);return($v===false||$v===null)?$d:(is_string($v)?trim($v,"\"'"):$v);} 
$m=json_decode((string)file_get_contents(BASE_PATH.'/database/contextualizacao_questoes_v3.json'),true,512,JSON_THROW_ON_ERROR);
$pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',envv('DB_HOST','127.0.0.1'),(int)envv('DB_PORT',3306),envv('DB_DATABASE','curso')),(string)envv('DB_USERNAME','root'),(string)envv('DB_PASSWORD',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$st=$pdo->prepare("SELECT id FROM questions WHERE statement=:statement AND source_label=:source_label LIMIT 2");
$ok=0;$old=0;$missing=0;
foreach($m['changes']??[] as $c){
 $st->execute(['statement'=>$c['new_statement'],'source_label'=>$c['source_label']]);$n=count($st->fetchAll());
 if($n===1){$ok++;continue;}
 $st->execute(['statement'=>$c['old_statement'],'source_label'=>$c['source_label']]);$n2=count($st->fetchAll());
 if($n2===1)$old++; else $missing++;
}
printf("=============================================================\n AUDITORIA DE CONTEXTUALIZAÇÃO - V3\n=============================================================\n");
printf("Questões revisadas no projeto: %d\n",(int)($m['reviewed_total']??0));
printf("Enunciados previstos para ajuste: %d\n",count($m['changes']??[]));
printf("Contextualizados no banco: %d\n",$ok);
printf("Ainda no texto antigo:      %d\n",$old);
printf("Não localizados:            %d\n",$missing);
printf("POP preservadas (já contextualizadas): %d\n",(int)($m['preserved_total']??0));
if($old===0&&$missing===0) echo "\nOK: contextualização V3 aplicada integralmente.\n"; else echo "\nATENÇÃO: contextualização ainda não está integralmente aplicada.\n";
