<?php

declare(strict_types=1);

/**
 * Diagnóstico e reset seguro de login - PMMT Academy
 *
 * Uso (a partir da raiz do projeto):
 *   C:\xampp\php\php.exe database\diagnosticar_resetar_login.php
 *
 * O script:
 * - usa o mesmo .env da aplicação;
 * - mostra qual banco está sendo usado;
 * - localiza o usuário pelo e-mail;
 * - valida status e perfil;
 * - permite redefinir a senha;
 * - confirma o novo hash com password_verify().
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script só pode ser executado pelo terminal.\n");
}

define('BASE_PATH', dirname(__DIR__));

$autoload = BASE_PATH . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "ERRO: vendor/autoload.php não encontrado.\n");
    fwrite(STDERR, "Execute composer install na raiz do projeto.\n");
    exit(1);
}

require $autoload;

\Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

function envValue(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null) {
        return $default;
    }

    return is_string($value) ? trim($value, "\"'") : $value;
}

function ask(string $label): string
{
    echo $label;
    return trim((string) fgets(STDIN));
}

$dbHost = (string) envValue('DB_HOST', '127.0.0.1');
$dbPort = (int) envValue('DB_PORT', 3306);
$dbName = (string) envValue('DB_DATABASE', 'curso');
$dbUser = (string) envValue('DB_USERNAME', 'root');
$dbPass = (string) envValue('DB_PASSWORD', '');

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $dbHost,
    $dbPort,
    $dbName
);

try {
    $pdo = new PDO(
        $dsn,
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "\nERRO DE CONEXÃO: {$e->getMessage()}\n");
    exit(1);
}

echo "\n============================================================\n";
echo " DIAGNÓSTICO / RESET DE LOGIN - PMMT ACADEMY\n";
echo "============================================================\n";
echo "Host:     {$dbHost}:{$dbPort}\n";
echo "Banco:    {$dbName}\n";
echo "Usuário DB: {$dbUser}\n\n";

$email = mb_strtolower(trim(ask('E-mail do usuário: ')));

if ($email === '') {
    fwrite(STDERR, "E-mail não informado.\n");
    exit(1);
}

$stmt = $pdo->prepare(
    "SELECT
        u.id,
        u.name,
        u.email,
        u.status,
        u.role_id,
        u.password_hash,
        r.slug AS role_slug,
        r.name AS role_name
     FROM users u
     LEFT JOIN roles r ON r.id = u.role_id
     WHERE LOWER(TRIM(u.email)) = :email
     LIMIT 1"
);
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

if (!$user) {
    echo "\n[ERRO] Usuário NÃO encontrado neste banco.\n";
    echo "Isso indica que você pode estar alterando outro banco/ambiente.\n";
    exit(2);
}

echo "\nUsuário encontrado:\n";
echo "ID:       {$user['id']}\n";
echo "Nome:     {$user['name']}\n";
echo "E-mail:   {$user['email']}\n";
echo "Status:   {$user['status']}\n";
echo "Role ID:  {$user['role_id']}\n";
echo "Perfil:   " . ($user['role_slug'] ?? 'SEM PERFIL') . "\n";
echo "Hash:     " . (str_starts_with((string) $user['password_hash'], '$2')
        ? 'bcrypt'
        : 'formato diferente') . "\n";
echo "Tamanho:  " . strlen((string) $user['password_hash']) . "\n";

if (empty($user['role_slug'])) {
    echo "\n[ERRO] O role_id do usuário não corresponde a nenhum registro da tabela roles.\n";
    echo "O Auth usa INNER JOIN com roles e, nessa situação, o login sempre falhará.\n";
    exit(3);
}

$newPassword = ask("\nNova senha (mínimo 8 caracteres): ");

if (strlen($newPassword) < 8) {
    fwrite(STDERR, "A senha precisa ter pelo menos 8 caracteres.\n");
    exit(4);
}

$hash = password_hash($newPassword, PASSWORD_DEFAULT);

if ($hash === false) {
    fwrite(STDERR, "Não foi possível gerar o hash da senha.\n");
    exit(5);
}

$pdo->beginTransaction();

try {
    $update = $pdo->prepare(
        "UPDATE users
         SET password_hash = :password_hash,
             status = 'active',
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id"
    );
    $update->execute([
        'password_hash' => $hash,
        'id' => (int) $user['id'],
    ]);

    $check = $pdo->prepare(
        "SELECT u.password_hash, u.status, r.slug AS role_slug
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE u.id = :id
         LIMIT 1"
    );
    $check->execute(['id' => (int) $user['id']]);
    $fresh = $check->fetch();

    if (!$fresh) {
        throw new RuntimeException('Usuário deixou de ser retornado no INNER JOIN com roles.');
    }

    if ($fresh['status'] !== 'active') {
        throw new RuntimeException('Status não ficou active.');
    }

    if (!password_verify($newPassword, (string) $fresh['password_hash'])) {
        throw new RuntimeException('password_verify() falhou após o UPDATE.');
    }

    $pdo->commit();

    echo "\n============================================================\n";
    echo " RESET CONCLUÍDO E VALIDADO\n";
    echo "============================================================\n";
    echo "E-mail:  {$email}\n";
    echo "Status:  active\n";
    echo "Perfil:  {$fresh['role_slug']}\n";
    echo "Teste:   password_verify() = OK\n\n";
    echo "Agora tente entrar no sistema usando exatamente esse e-mail e a nova senha.\n";
    echo "\nSe o navegador ainda disser 'E-mail ou senha inválidos', então o Apache/PHP\n";
    echo "está apontando para outro projeto, outro .env ou outro banco de dados.\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "\nERRO: {$e->getMessage()}\n");
    exit(6);
}
