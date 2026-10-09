<?php

declare(strict_types=1);


if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execução permitida apenas via CLI.');
}


define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Database;

App::boot(BASE_PATH);
$pdo = Database::connection();

$checks = [];
$check = static function (string $label, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = [$label, $ok, $detail];
};

$tables = ['users','roles','enrollments','audit_logs','user_course_progress','user_lesson_progress','quiz_attempts','simulation_attempts','xp_events'];
foreach ($tables as $table) {
    $stmt = $pdo->prepare('SHOW TABLES LIKE :table');
    $stmt->execute(['table' => $table]);
    $check("Tabela {$table}", (bool) $stmt->fetchColumn());
}

$requiredFiles = [
    'app/Controllers/Admin/StudentController.php',
    'app/Controllers/Admin/AdminOverviewController.php',
    'app/Views/admin/students/index.php',
    'app/Views/admin/students/create.php',
    'app/Views/admin/students/show.php',
    'app/Views/layouts/admin.php',
    'routes/admin.php',
    'public/assets/css/admin.css',
    'public/assets/js/admin-users.js',
];
foreach ($requiredFiles as $file) {
    $check("Arquivo {$file}", is_file(dirname(__DIR__) . '/' . $file));
}

$studentRole = (int) $pdo->query("SELECT COUNT(*) FROM roles WHERE slug='student'")->fetchColumn();
$adminRole = (int) $pdo->query("SELECT COUNT(*) FROM roles WHERE slug='admin'")->fetchColumn();
$check('Perfil student', $studentRole === 1, "encontrados={$studentRole}");
$check('Perfil admin', $adminRole === 1, "encontrados={$adminRole}");

$students = (int) $pdo->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='student'")->fetchColumn();
$courses = (int) $pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn();

fwrite(STDOUT, "=============================================================\n");
fwrite(STDOUT, " DIAGNÓSTICO LD ADMIN - GESTÃO V2\n");
fwrite(STDOUT, "=============================================================\n\n");
foreach ($checks as [$label,$ok,$detail]) {
    fwrite(STDOUT, sprintf("[%s] %s%s\n", $ok ? 'OK' : 'FALHA', $label, $detail !== '' ? " | {$detail}" : ''));
}
fwrite(STDOUT, "\nAlunos: {$students}\nCursos: {$courses}\n\n");
$failed = array_filter($checks, static fn(array $c): bool => !$c[1]);
if ($failed === []) {
    fwrite(STDOUT, "OK: estrutura administrativa pronta.\n");
    exit(0);
}
fwrite(STDOUT, "ATENÇÃO: há verificações com falha.\n");
exit(1);
