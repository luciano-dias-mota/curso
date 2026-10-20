<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;

$user = Auth::user() ?? [];
$pageTitle = isset($title)
    ? $title . ' • ' . (string) config('app.name', 'Curso')
    : (string) config('app.name', 'Curso');

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$activeCourseId = (int) Session::get('active_course_id', 0);
$courseHomePath = $activeCourseId > 0
    ? '/curso/' . $activeCourseId . '/inicio'
    : '/dashboard';
$courseHomeUrl = url($courseHomePath);
$exerciseUrl = $activeCourseId > 0
    ? url('/curso/' . $activeCourseId . '/exercicios')
    : url('/exercicios');
$simulationUrl = $activeCourseId > 0
    ? url('/curso/' . $activeCourseId . '/simulados')
    : url('/simulados');
$notesUrl = $activeCourseId > 0
    ? url('/curso/' . $activeCourseId . '/anotacoes')
    : url('/anotacoes');
$isDashboard = preg_match('#/curso/\\d+/inicio$#', rtrim($currentPath, '/')) === 1;
$isLesson = str_contains($currentPath, '/aula/');
$isQuiz = str_contains($currentPath, '/prova/');
$isSimulation = str_contains($currentPath, '/simulados');
$isSimulationHub = str_ends_with(rtrim($currentPath, '/'), '/simulados');
$isExercise = str_contains($currentPath, '/exercicios');
$isNotes = str_contains($currentPath, '/anotacoes');
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

    <link rel="stylesheet" href="<?= e(url('/assets/css/app.css') . '?v=20261020-pmmt1') ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/learning-flow.css') . '?v=20261003-2') ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/video-management.css') . '?v=20261003-2') ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/mockup-v3.css') . '?v=20261003-2') ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/simulation.css') . '?v=20261017-2') ?>">
    <?php if ($isExercise || $isNotes): ?>
        <link rel="stylesheet" href="<?= e(url('/assets/css/study-tools.css') . '?v=20261009-1') ?>">
    <?php endif; ?>
    <script>window.APP_URL = <?= json_encode(rtrim((string) config('app.url'), '/'), JSON_UNESCAPED_SLASHES) ?>;</script>
    <script src="<?= e(url('/assets/js/app.js') . '?v=20261003-2') ?>" defer></script>
    <script src="<?= e(url('/assets/js/simulation.js') . '?v=20261003-1') ?>" defer></script>
    <?php if ($isExercise || $isNotes): ?>
        <script src="<?= e(url('/assets/js/study-tools.js') . '?v=20261009-1') ?>" defer></script>
    <?php endif; ?>
</head>
<body class="student-body <?= $isLesson ? 'page-lesson' : '' ?> <?= $isQuiz ? 'page-quiz' : '' ?> <?= $isSimulation ? 'page-simulation' : '' ?> <?= $isSimulationHub ? 'page-simulation-hub' : '' ?> <?= $isExercise ? 'page-exercise' : '' ?> <?= $isNotes ? 'page-notes' : '' ?>">
<div class="academy-app-frame">
    <aside class="academy-sidebar" aria-label="Navegação principal">
        <a class="sidebar-brand" href="<?= e($courseHomeUrl) ?>" aria-label="PMMT Academy">
            <span class="sidebar-brand-shield">✦</span>
            <span>
                <strong>LD</strong>
                <small>ACADEMY</small>
            </span>
        </a>

        <nav class="sidebar-menu">
            <a class="<?= $isDashboard ? 'active' : '' ?>" href="<?= e($courseHomeUrl) ?>"><span>⌂</span>Início</a>
            <a class="<?= $isLesson ? 'active' : '' ?>" href="<?= e($courseHomeUrl . '#trilhas') ?>"><span>▣</span>Minhas Aulas</a>
            <a class="<?= $isSimulation ? 'active' : '' ?>" href="<?= e($simulationUrl) ?>"><span>◉</span>Simulados</a>
            <a class="<?= $isExercise ? 'active' : '' ?>" href="<?= e($exerciseUrl) ?>"><span>✓</span>Exercícios</a>
            <a href="<?= e($courseHomeUrl . '#missoes') ?>"><span>✥</span>Missões</a>
            <a href="<?= e($courseHomeUrl . '#conquistas') ?>"><span>♙</span>Conquistas</a>
            <a href="<?= e($courseHomeUrl . '#desempenho') ?>"><span>▥</span>Meu Desempenho</a>
            <a class="<?= $isNotes ? 'active' : '' ?>" href="<?= e($notesUrl) ?>"><span>□</span>Anotações</a>
            <a href="<?= e($courseHomeUrl . '#perfil') ?>"><span>♧</span>Comunidade</a>
        </nav>

        <div class="sidebar-bottom">
            <button class="sidebar-action" type="button" data-theme-toggle>
                <span data-theme-icon>◐</span>Alternar tema
            </button>
            <form action="<?= e(url('/curso/sair')) ?>" method="post">
                <?= Csrf::input() ?>
                <button class="sidebar-action" type="submit"><span>↪</span>Sair</button>
            </form>
        </div>
    </aside>

    <div class="academy-workspace">
        <header class="academy-commandbar">
            <a class="mobile-brand" href="<?= e($courseHomeUrl) ?>">
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
    <a class="<?= $isDashboard ? 'active' : '' ?>" href="<?= e($courseHomeUrl) ?>"><span>⌂</span><small>Início</small></a>
    <a class="<?= $isLesson ? 'active' : '' ?>" href="<?= e($courseHomeUrl . '#trilhas') ?>"><span>▣</span><small>Aulas</small></a>
    <a class="<?= $isExercise ? 'active' : '' ?>" href="<?= e($exerciseUrl) ?>"><span>✓</span><small>Exercícios</small></a>
    <a class="<?= $isSimulation ? 'active' : '' ?>" href="<?= e($simulationUrl) ?>"><span>◉</span><small>Simulados</small></a>
    <a class="<?= $isNotes ? 'active' : '' ?>" href="<?= e($notesUrl) ?>"><span>□</span><small>Notas</small></a>
</nav>
</body>
</html>
