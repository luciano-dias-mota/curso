<?php

use App\Core\Auth;
use App\Core\Csrf;

$user = Auth::user() ?? [];
$pageTitle = isset($title)
    ? $title . ' • ' . (string) config('app.name', 'Curso')
    : (string) config('app.name', 'Curso');

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$isDashboard = str_ends_with(rtrim($currentPath, '/'), '/dashboard');
?>
<!doctype html>
<html lang="pt-BR" data-theme="<?= e($user['theme'] ?? 'dark') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#0b0f14">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <title><?= e($pageTitle) ?></title>

    <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/learning-flow.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/video-management.css')) ?>">
    <script>window.APP_URL = <?= json_encode(rtrim((string) config('app.url'), '/'), JSON_UNESCAPED_SLASHES) ?>;</script>
    <script src="<?= e(url('/assets/js/app.js')) ?>" defer></script>
</head>
<body class="student-body">
<header class="academy-topbar">
    <a class="academy-brand" href="<?= e(url('/dashboard')) ?>" aria-label="PMMT Academy - início">
        <span class="brand-emblem">◆</span>
        <span class="brand-copy">
            <span class="brand-mark">PMMT</span>
            <span class="brand-name">ACADEMY</span>
        </span>
        <span class="brand-tag">MISSÃO APROVAÇÃO</span>
    </a>

    <nav class="academy-nav" aria-label="Navegação principal">
        <a href="<?= e(url('/dashboard')) ?>">Início</a>
        <a href="<?= e(url('/dashboard#trilhas')) ?>">Aulas</a>
        <a href="<?= e(url('/dashboard#missoes')) ?>">Missões</a>
        <a href="<?= e(url('/dashboard#conquistas')) ?>">Conquistas</a>
    </nav>

    <div class="top-actions">
        <div class="streak-chip" title="Sequência atual de estudos">
            <span>🔥</span>
            <strong><?= (int) ($user['current_streak'] ?? 0) ?></strong>
            <small>dias</small>
        </div>

        <div class="player-chip" id="perfil">
            <span class="avatar"><?= e(mb_strtoupper(mb_substr($user['name'] ?? 'E', 0, 1))) ?></span>
            <span>
                <strong><?= e($user['name'] ?? 'Estudante') ?></strong>
                <small>
                    Nível <?= (int) ($user['current_level'] ?? 1) ?>
                    • <?= number_format((int) ($user['xp_total'] ?? 0), 0, ',', '.') ?> XP
                </small>
            </span>
        </div>

        <button class="icon-btn theme-toggle" type="button" data-theme-toggle title="Alternar tema" aria-label="Alternar entre tema claro e escuro">
            <span data-theme-icon>◐</span>
        </button>

        <form class="inline desktop-logout" action="<?= e(url('/logout')) ?>" method="post">
            <?= Csrf::input() ?>
            <button class="ghost-btn" type="submit">Sair</button>
        </form>
    </div>
</header>

<main class="academy-shell">
    <?= $content ?>
</main>

<nav class="mobile-bottom-nav" aria-label="Navegação móvel">
    <a class="<?= $isDashboard ? 'active' : '' ?>" href="<?= e(url('/dashboard')) ?>">
        <span>⌂</span><small>Início</small>
    </a>
    <a href="<?= e(url('/dashboard#trilhas')) ?>">
        <span>▶</span><small>Aulas</small>
    </a>
    <a href="<?= e(url('/dashboard#missoes')) ?>">
        <span>✦</span><small>Missões</small>
    </a>
    <a href="<?= e(url('/dashboard#perfil')) ?>">
        <span>●</span><small>Perfil</small>
    </a>
</nav>
</body>
</html>
