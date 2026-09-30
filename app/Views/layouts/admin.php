<?php

use App\Core\Auth;
use App\Core\Csrf;

$userLayout = Auth::user();
$pageTitle = isset($title) ? $title . ' • ' . config('app.name') : config('app.name');
?>
<!doctype html>
<html lang="pt-BR" data-theme="<?= e($userLayout['theme'] ?? 'dark') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
    <script>window.APP_URL = <?= json_encode(rtrim((string) config('app.url'), '/')) ?>;</script>
    <script src="<?= e(url('/assets/js/app.js')) ?>" defer></script>
</head>
<body>
<div class="app-shell">
    <header class="topbar">
        <a class="brand" href="<?= e(url('/admin')) ?>">PMMT <span>ADMIN</span></a>
        <div>
            <strong><?= e($userLayout['name'] ?? 'Administrador') ?></strong>
            <button class="btn secondary" type="button" data-theme-toggle>☀/🌙</button>
            <form class="inline" action="<?= e(url('/logout')) ?>" method="post">
                <?= Csrf::input() ?>
                <button class="btn secondary" type="submit">Sair</button>
            </form>
        </div>
    </header>
    <main class="page"><?= $content ?></main>
</div>
</body>
</html>
