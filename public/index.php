<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

$autoload = BASE_PATH . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    exit(
        'Dependências não instaladas. Abra o terminal na raiz do projeto e execute: composer install'
    );
}

require $autoload;

use App\Core\App;

App::boot(BASE_PATH);
App::run();
