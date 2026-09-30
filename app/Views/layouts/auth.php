<?php

use App\Core\Csrf;
use App\Core\Session;

$pageTitle = isset($title) ? $title . ' • ' . config('app.name') : config('app.name');
?>
<!doctype html>
<html lang="pt-BR" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
</head>
<body>
<div class="auth-wrapper">
    <div class="card auth-card">
        <?php if ($message = Session::pullFlash('error')): ?>
            <div class="alert error"><?= e($message) ?></div>
        <?php endif; ?>
        <?= $content ?>
    </div>
</div>
</body>
</html>
