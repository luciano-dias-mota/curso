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

$tableExists = static function (string $table) use ($pdo): bool {
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
         LIMIT 1"
    );
    $stmt->execute(['table' => $table]);
    return (bool) $stmt->fetchColumn();
};

echo "=============================================================\n";
echo " AUDITORIA MONITOR DE ACESSOS - V1\n";
echo "=============================================================\n";
printf("Banco: %s\n", (string) $pdo->query('SELECT DATABASE()')->fetchColumn());

$sessions = $tableExists('user_access_sessions');
$alerts = $tableExists('user_access_alerts');

printf("user_access_sessions: %s\n", $sessions ? 'OK' : 'FALTA');
printf("user_access_alerts:   %s\n", $alerts ? 'OK' : 'FALTA');

if (!$sessions || !$alerts) {
    fwrite(STDERR, "\n[FALHA] Monitor ainda não está instalado por completo.\n");
    exit(2);
}

printf("Sessões registradas:  %d\n", (int) $pdo->query("SELECT COUNT(*) FROM user_access_sessions")->fetchColumn());
printf("Dispositivos únicos:  %d\n", (int) $pdo->query("SELECT COUNT(DISTINCT device_hash) FROM user_access_sessions")->fetchColumn());
printf(
    "Sessões ativas (10m): %d\n",
    (int) $pdo->query(
        "SELECT COUNT(*) FROM user_access_sessions
         WHERE logged_out_at IS NULL
           AND last_activity_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
    )->fetchColumn()
);
printf(
    "Alertas abertos:      %d\n",
    (int) $pdo->query("SELECT COUNT(*) FROM user_access_alerts WHERE resolved_at IS NULL")->fetchColumn()
);

echo "\n[OK] Estrutura do monitor de acessos V1 consistente.\n";
