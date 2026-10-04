<?php

use App\Core\Auth;
use App\Core\Csrf;

$user = Auth::user() ?? [];
$pageTitle = isset($title)
    ? $title . ' • ' . (string) config('app.name', 'Curso')
    : (string) config('app.name', 'Curso');

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$isDashboard = str_ends_with(rtrim($currentPath, '/'), '/dashboard');
$isLesson = str_contains($currentPath, '/aula/');
$isQuiz = str_contains($currentPath, '/prova/');
$isSimulation = str_contains($currentPath, '/simulados');
$initial = mb_strtoupper(mb_substr((string) ($user['name'] ?? 'E'), 0, 1));
$firstName = explode(' ', trim((string) ($user['name'] ?? 'Aluno')))[0];
?>
<!doctype html>
<html lang="pt-BR" data-theme="<?= e($user['theme'] ?? 'dark') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#080f17">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <title><?= e($pageTitle) ?></title>

    <link rel="stylesheet" href="<?= e(url('/assets/css/app.css') . '?v=20261003-2') ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/learning-flow.css') . '?v=20261003-2') ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/video-management.css') . '?v=20261003-2') ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/mockup-v3.css') . '?v=20261003-2') ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/simulation.css') . '?v=20261003-1') ?>">
    <script>window.APP_URL = <?= json_encode(rtrim((string) config('app.url'), '/'), JSON_UNESCAPED_SLASHES) ?>;</script>
    <script src="<?= e(url('/assets/js/app.js') . '?v=20261003-2') ?>" defer></script>
    <script src="<?= e(url('/assets/js/simulation.js') . '?v=20261003-1') ?>" defer></script>
</head>
<body class="student-body <?= $isLesson ? 'page-lesson' : '' ?> <?= $isQuiz ? 'page-quiz' : '' ?> <?= $isSimulation ? 'page-simulation' : '' ?>">
<div class="academy-app-frame">
    <aside class="academy-sidebar" aria-label="Navegação principal">
        <a class="sidebar-brand" href="<?= e(url('/dashboard')) ?>" aria-label="PMMT Academy">
            <span class="sidebar-brand-shield">✦</span>
            <span>
                <strong>LD</strong>
                <small>ACADEMY</small>
            </span>
        </a>

        <nav class="sidebar-menu">
            <a class="<?= $isDashboard ? 'active' : '' ?>" href="<?= e(url('/dashboard')) ?>"><span>⌂</span>Início</a>
            <a class="<?= $isLesson ? 'active' : '' ?>" href="<?= e(url('/dashboard#trilhas')) ?>"><span>▣</span>Minhas Aulas</a>
            <a class="<?= $isSimulation ? 'active' : '' ?>" href="<?= e(url('/simulados')) ?>"><span>◉</span>Simulados</a>
            <a href="<?= e(url('/dashboard#missoes')) ?>"><span>✓</span>Exercícios</a>
            <a href="<?= e(url('/dashboard#missoes')) ?>"><span>✥</span>Missões</a>
            <a href="<?= e(url('/dashboard#conquistas')) ?>"><span>♙</span>Conquistas</a>
            <a href="<?= e(url('/dashboard#desempenho')) ?>"><span>▥</span>Meu Desempenho</a>
            <a href="<?= e(url('/dashboard#perfil')) ?>"><span>□</span>Anotações</a>
            <a href="<?= e(url('/dashboard#perfil')) ?>"><span>♧</span>Comunidade</a>
        </nav>

        <div class="sidebar-bottom">
            <button class="sidebar-action" type="button" data-theme-toggle>
                <span data-theme-icon>◐</span>Alternar tema
            </button>
            <form action="<?= e(url('/logout')) ?>" method="post">
                <?= Csrf::input() ?>
                <button class="sidebar-action" type="submit"><span>↪</span>Sair</button>
            </form>
        </div>
    </aside>

    <div class="academy-workspace">
        <header class="academy-commandbar">
            <a class="mobile-brand" href="<?= e(url('/dashboard')) ?>">
                <span>✦</span><strong>PMMT <em>ACADEMY</em></strong>
            </a>

            <label class="academy-search" aria-label="Buscar na tela atual">
                <span>⌕</span>
                <input type="search" placeholder="Buscar aulas, tópicos ou simulados..." data-global-search>
            </label>

            <div class="commandbar-actions">
                <button class="command-icon" type="button" title="Notificações" aria-label="Notificações">♢<i></i></button>
                <button class="command-icon theme-command" type="button" data-theme-toggle title="Alternar tema"><span data-theme-icon>◐</span></button>
                <div class="command-user">
                    <span class="avatar"><?= e($initial) ?></span>
                    <span><strong>Olá, <?= e($firstName) ?>!</strong><small>Estudante</small></span>
                </div>
            </div>
        </header>

        <main class="academy-shell">
            <?= $content ?>
        </main>
    </div>
</div>

<nav class="mobile-bottom-nav" aria-label="Navegação móvel">
    <a class="<?= $isDashboard ? 'active' : '' ?>" href="<?= e(url('/dashboard')) ?>"><span>⌂</span><small>Início</small></a>
    <a class="<?= $isLesson ? 'active' : '' ?>" href="<?= e(url('/dashboard#trilhas')) ?>"><span>▣</span><small>Aulas</small></a>
    <a class="<?= $isSimulation ? 'active' : '' ?>" href="<?= e(url('/simulados')) ?>"><span>◉</span><small>Simulados</small></a>
    <a href="<?= e(url('/dashboard#missoes')) ?>"><span>✥</span><small>Missões</small></a>
    <a href="<?= e(url('/dashboard#perfil')) ?>"><span>○</span><small>Perfil</small></a>
</nav>
</body>
</html>
