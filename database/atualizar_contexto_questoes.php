<?php

declare(strict_types=1);


if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execução permitida apenas via CLI.');
}


define('BASE_PATH', dirname(__DIR__));
$autoload = BASE_PATH . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Erro: vendor/autoload.php não encontrado. Execute composer install.\n");
    exit(1);
}
require $autoload;
\Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

function envv(string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null) return $default;
    return is_string($value) ? trim($value, "\"'") : $value;
}
function hasFlag(string $flag): bool { global $argv; return in_array($flag, $argv ?? [], true); }
function loadJson(string $path): array {
    if (!is_file($path)) throw new RuntimeException("Arquivo não encontrado: {$path}");
    $data=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException("JSON inválido: {$path}");
    return $data;
}

$apply=hasFlag('--apply');
$dryRun=!$apply;
$isProduction = strtolower((string) envv('APP_ENV', 'production')) === 'production';
$forceProduction = hasFlag('--force-production');
if ($apply && $isProduction && !$forceProduction) {
    fwrite(STDERR, "ABORTADO: APP_ENV=production. Use --apply --force-production somente após backup.\n");
    exit(1);
}
$manifest=loadJson(BASE_PATH . '/database/contextualizacao_questoes_v3.json');
$changes=$manifest['changes'] ?? [];

$pdo=new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',envv('DB_HOST','127.0.0.1'),(int)envv('DB_PORT',3306),envv('DB_DATABASE','curso')),
    (string)envv('DB_USERNAME','root'),(string)envv('DB_PASSWORD',''),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]
);

$findOld=$pdo->prepare("SELECT id, statement FROM questions WHERE statement=:statement AND source_label=:source_label LIMIT 2");
$findNew=$pdo->prepare("SELECT id FROM questions WHERE statement=:statement AND source_label=:source_label LIMIT 2");
$update=$pdo->prepare("UPDATE questions SET statement=:new_statement, updated_at=CURRENT_TIMESTAMP WHERE id=:id AND statement=:old_statement");

$stats=['update'=>0,'already'=>0,'missing'=>0,'ambiguous'=>0,'error'=>0];
$byGroup=[];

printf("=============================================================\n");
printf(" CONTEXTUALIZAÇÃO DOS ENUNCIADOS - V3\n");
printf("=============================================================\n");
printf("Banco: %s\n", (string)envv('DB_DATABASE','curso'));
printf("Modo:  %s\n", $dryRun ? 'DRY-RUN (nenhuma alteração)' : 'GRAVAÇÃO');
printf("Mapeamentos: %d\n\n", count($changes));

if (!$dryRun) $pdo->beginTransaction();
try {
    foreach ($changes as $c) {
        $old=trim((string)($c['old_statement'] ?? ''));
        $new=trim((string)($c['new_statement'] ?? ''));
        $src=trim((string)($c['source_label'] ?? ''));
        $group=(string)($c['group'] ?? 'Outros');
        $byGroup[$group] ??= ['update'=>0,'already'=>0,'missing'=>0,'ambiguous'=>0];

        $findNew->execute(['statement'=>$new,'source_label'=>$src]);
        $newRows=$findNew->fetchAll();
        if (count($newRows)===1) { $stats['already']++; $byGroup[$group]['already']++; continue; }
        if (count($newRows)>1) { $stats['ambiguous']++; $byGroup[$group]['ambiguous']++; continue; }

        $findOld->execute(['statement'=>$old,'source_label'=>$src]);
        $oldRows=$findOld->fetchAll();
        if (count($oldRows)===0) { $stats['missing']++; $byGroup[$group]['missing']++; continue; }
        if (count($oldRows)>1) { $stats['ambiguous']++; $byGroup[$group]['ambiguous']++; continue; }

        if (!$dryRun) {
            $update->execute(['new_statement'=>$new,'id'=>(int)$oldRows[0]['id'],'old_statement'=>$old]);
            if ($update->rowCount()!==1) throw new RuntimeException('Falha ao atualizar questão ID '.(int)$oldRows[0]['id']);
        }
        $stats['update']++; $byGroup[$group]['update']++;
    }
    if (!$dryRun) $pdo->commit();
} catch (Throwable $e) {
    if (!$dryRun && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,"ERRO: {$e->getMessage()}\n"); exit(1);
}

foreach ($byGroup as $group=>$s) {
    printf("%-36s atualizar=%3d | já contextualizadas=%3d | não localizadas=%3d | ambíguas=%3d\n",
        mb_strimwidth($group,0,36,'…','UTF-8'),$s['update'],$s['already'],$s['missing'],$s['ambiguous']);
}
printf("\n-------------------------------------------------------------\n");
printf("Atualizar agora:       %d\n",$stats['update']);
printf("Já contextualizadas:   %d\n",$stats['already']);
printf("Não localizadas:       %d\n",$stats['missing']);
printf("Ambíguas:              %d\n",$stats['ambiguous']);
printf("-------------------------------------------------------------\n");
if ($stats['missing']>0 || $stats['ambiguous']>0) {
    printf("ATENÇÃO: há itens que exigem conferência antes de considerar a atualização completa.\n");
}
printf($dryRun
    ? "DRY-RUN concluído. Se os números estiverem corretos, execute com --apply.\n"
    : "Atualização concluída. Nenhuma alternativa, gabarito, explicação ou dificuldade foi alterada.\n");
