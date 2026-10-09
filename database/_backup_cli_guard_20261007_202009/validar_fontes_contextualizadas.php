<?php

declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
$files=[
 'Português'=>BASE_PATH.'/database/sources/portugues_ufmt_reestruturado.json',
 'POP PMMT'=>BASE_PATH.'/database/sources/pop_pmmt_2023_course_data.json',
 'Complementar'=>BASE_PATH.'/database/questoes_simulados_complementares.json',
];
function walk(mixed $x,array &$out):void{if(is_array($x)){if(isset($x['statement'],$x['alternatives'])&&is_array($x['alternatives']))$out[]=$x;foreach($x as $v)walk($v,$out);}}
$total=0;$invalid=0;$easy=0;$medium=0;$hard=0;$statements=[];
echo "=============================================================\n VALIDAÇÃO DAS FONTES CONTEXTUALIZADAS - V3\n=============================================================\n";
foreach($files as $name=>$path){$d=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$qs=[];walk($d,$qs);$bad=0;$m=0;$h=0;$e=0;foreach($qs as $q){$diff=$q['difficulty']??'medium';$$diff++; if($diff==='medium')$m++;elseif($diff==='hard')$h++;elseif($diff==='easy')$e++;$correct=0;foreach($q['alternatives'] as $a){if(is_array($a)&&!empty($a['correct']))$correct++;}if(trim((string)$q['statement'])===''||count($q['alternatives'])<4||$correct!==1){$bad++;$invalid++;}$key=mb_strtolower(trim((string)$q['statement']),'UTF-8');$statements[$key]=($statements[$key]??0)+1;}$total+=count($qs);printf("%-14s Total:%4d | M:%3d | H:%3d | E:%2d | inválidas:%d\n",$name,count($qs),$m,$h,$e,$bad);} $dups=count(array_filter($statements,fn($n)=>$n>1));
printf("\nTotal:%d | Medium:%d | Hard:%d | Easy:%d | Inválidas:%d | Duplicidades:%d grupos\n",$total,$medium,$hard,$easy,$invalid,$dups);
printf($total===1048&&$invalid===0&&$dups===0?"OK: fontes V3 íntegras.\n":"ATENÇÃO: revisar validação.\n");
