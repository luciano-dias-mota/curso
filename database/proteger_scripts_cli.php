<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execução permitida apenas via CLI.');
}

$apply = in_array('--apply', $argv, true);
$directory = __DIR__;
$basePath = dirname(__DIR__);
$files = glob($directory . DIRECTORY_SEPARATOR . '*.php') ?: [];
$skip = [
    basename(__FILE__),
];

$guard = <<<'PHP'

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execução permitida apenas via CLI.');
}

PHP;

$targets = [];

foreach ($files as $file) {
    if (in_array(basename($file), $skip, true)) {
        continue;
    }

    $contents = file_get_contents($file);
    if ($contents === false) {
        continue;
    }

    if (
        str_contains($contents, "PHP_SAPI !== 'cli'")
        || str_contains($contents, 'PHP_SAPI !== "cli"')
        || str_contains($contents, 'php_sapi_name()')
    ) {
        continue;
    }

    $targets[] = $file;
}

echo "=============================================================\n";
echo " PROTEÇÃO CLI DOS SCRIPTS database/*.php\n";
echo "=============================================================\n";
echo 'Modo: ' . ($apply ? 'APLICAR' : 'DRY-RUN') . "\n";
echo 'Scripts sem guarda: ' . count($targets) . "\n\n";

foreach ($targets as $file) {
    echo ' - ' . basename($file) . "\n";
}

if (!$apply) {
    echo "\nNenhum arquivo foi alterado.\n";
    echo "Para aplicar: php database/proteger_scripts_cli.php --apply\n";
    exit(0);
}

if ($targets === []) {
    echo "\nNada a alterar.\n";
    exit(0);
}

$backupDir = $basePath
    . DIRECTORY_SEPARATOR . 'storage'
    . DIRECTORY_SEPARATOR . 'cli-guard-backups'
    . DIRECTORY_SEPARATOR . date('Ymd_His');

if (!mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
    fwrite(STDERR, "Não foi possível criar a pasta de backup.\n");
    exit(1);
}

foreach ($targets as $file) {
    $contents = file_get_contents($file);
    if ($contents === false) {
        fwrite(STDERR, 'Falha ao ler ' . basename($file) . "\n");
        continue;
    }

    copy($file, $backupDir . DIRECTORY_SEPARATOR . basename($file) . '.bak');

    if (preg_match('/^<\?php\s+declare\(strict_types=1\);\s*/', $contents, $match)) {
        $prefix = $match[0];
        $patched = $prefix . $guard . substr($contents, strlen($prefix));
    } elseif (str_starts_with($contents, '<?php')) {
        $patched = "<?php\n" . $guard . substr($contents, 5);
    } else {
        fwrite(STDERR, 'Ignorado (sem abertura PHP reconhecida): ' . basename($file) . "\n");
        continue;
    }

    if (file_put_contents($file, $patched) === false) {
        fwrite(STDERR, 'Falha ao gravar ' . basename($file) . "\n");
        continue;
    }

    echo '[OK] ' . basename($file) . "\n";
}

echo "\nBackup dos originais: {$backupDir}\n";
echo "Proteção concluída.\n";
