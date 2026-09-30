<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;

$pageTitle = isset($title) ? $title . ' • ' . config('app.name') : config('app.name');
?>
<!doctype html>
<html lang="pt-BR" data-theme="<?= e(Auth::user()['theme'] ?? config('app.default_theme', 'dark')) ?>">
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
        <a class="brand" href="<?= e(url('/')) ?>">PMMT <span>ACADEMY</span></a>
        <div>
            <button class="btn secondary" type="button" data-theme-toggle>☀/🌙</button>
            <?php if (Auth::check()): ?>
                <form class="inline" action="<?= e(url('/logout')) ?>" method="post">
                    <?= Csrf::input() ?>
                    <button class="btn secondary" type="submit">Sair</button>
                </form>
            <?php endif; ?>
        </div>
    </header>
    <main class="page">
        <?php if ($message = Session::pullFlash('error')): ?>
            <div class="alert error"><?= e($message) ?></div>
        <?php endif; ?>
        <?= $content ?>
    </main>
</div>
</body>
</html>
