<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;

$userLayout = Auth::user() ?? [];
$pageTitle = isset($title)
    ? $title . ' • ' . (string) config('app.name', 'Curso')
    : (string) config('app.name', 'Curso');
?>
<!doctype html>
<html lang="pt-BR" data-theme="<?= e($userLayout['theme'] ?? 'dark') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <title><?= e($pageTitle) ?></title>

    <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/video-management.css')) ?>">

    <script>
        window.APP_URL = <?= json_encode(
            rtrim((string) config('app.url'), '/'),
            JSON_UNESCAPED_SLASHES
        ) ?>;
    </script>
    <script src="<?= e(url('/assets/js/app.js')) ?>" defer></script>
    <script src="<?= e(url('/assets/js/admin-media.js')) ?>" defer></script>
</head>
<body class="admin-body">
<div class="app-shell">
    <header class="topbar admin-topbar">
        <a class="brand" href="<?= e(url('/admin')) ?>">PMMT <span>ADMIN</span></a>

        <nav class="admin-nav" aria-label="Administração">
            <a href="<?= e(url('/admin')) ?>">Painel</a>
            <a href="<?= e(url('/admin/aulas')) ?>">Aulas</a>
            <a href="<?= e(url('/admin/videos')) ?>">Vídeos</a>
        </nav>

        <div class="admin-user-actions">
            <strong><?= e($userLayout['name'] ?? 'Administrador') ?></strong>
            <button class="btn secondary" type="button" data-theme-toggle aria-label="Alternar tema">
                <span data-theme-icon>◐</span>
            </button>
            <form class="inline" action="<?= e(url('/logout')) ?>" method="post">
                <?= Csrf::input() ?>
                <button class="btn secondary" type="submit">Sair</button>
            </form>
        </div>
    </header>

    <main class="page admin-page">
        <?php if ($message = Session::pullFlash('success')): ?>
            <div class="alert success"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if ($message = Session::pullFlash('error')): ?>
            <div class="alert error"><?= e($message) ?></div>
        <?php endif; ?>

        <?= $content ?>
    </main>
</div>
</body>
</html>
