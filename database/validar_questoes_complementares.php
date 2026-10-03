<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
$path = BASE_PATH . '/database/questoes_simulados_complementares.json';
if (!is_file($path)) {
    fwrite(STDERR, "Arquivo não encontrado: {$path}\n");
    exit(1);
}

try {
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, "JSON inválido: {$e->getMessage()}\n");
    exit(2);
}

$total=0; $medium=0; $hard=0; $easy=0; $invalid=0; $statements=[];

function lowerText(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

echo "=============================================================\n";
echo " VALIDAÇÃO DO BANCO COMPLEMENTAR - V2\n";
echo "=============================================================\n\n";

foreach ($data['modules'] ?? [] as $module) {
    $questions = is_array($module['questions'] ?? null) ? $module['questions'] : [];
    $m=0; $h=0; $e=0; $bad=0;

    foreach ($questions as $q) {
        $total++;
        $difficulty = strtolower((string)($q['difficulty'] ?? ''));
        if ($difficulty === 'medium') { $medium++; $m++; }
        elseif ($difficulty === 'hard') { $hard++; $h++; }
        elseif ($difficulty === 'easy') { $easy++; $e++; }
        else { $invalid++; $bad++; }

        $statement=trim((string)($q['statement'] ?? ''));
        $alts=$q['alternatives'] ?? [];
        $correct=0;
        if (is_array($alts)) {
            foreach ($alts as $alt) if (!empty($alt['correct'])) $correct++;
        }
        if ($statement==='' || !is_array($alts) || count($alts)<4 || $correct!==1) {
            $invalid++; $bad++;
        }
        if ($statement!=='') $key = lowerText((string) preg_replace('/\s+/u',' ',$statement));
        $statements[$key]=($statements[$key]??0)+1;
    }

    printf("%-36s Total:%3d | M:%2d | H:%2d | E:%2d | inválidas:%2d\n",
        (string)($module['module_title_contains'] ?? 'Módulo'), count($questions),$m,$h,$e,$bad);
}

$duplicates=0;
foreach ($statements as $count) if ($count>1) $duplicates++;

echo "\n-------------------------------------------------------------\n";
printf("Total:          %d\n",$total);
printf("Medium:         %d\n",$medium);
printf("Hard:           %d\n",$hard);
printf("Easy:           %d\n",$easy);
printf("Inválidas:      %d\n",$invalid);
printf("Duplicidades:   %d grupos\n",$duplicates);
echo "-------------------------------------------------------------\n";

if ($total===300 && $medium===180 && $hard===120 && $easy===0 && $invalid===0 && $duplicates===0) {
    echo "OK: banco V2 íntegro e pronto para importação.\n";
    exit(0);
}

fwrite(STDERR,"ATENÇÃO: o banco não corresponde ao perfil esperado da V2.\n");
exit(3);
