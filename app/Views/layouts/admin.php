<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;

$userLayout = Auth::user();
$pageTitle = isset($title) ? $title . ' • LD Admin' : 'LD Admin';
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin', PHP_URL_PATH) ?: '/admin';
$basePos = strrpos($requestPath, '/admin');
$adminPath = $basePos !== false ? substr($requestPath, $basePos) : '/admin';
$adminPath = '/' . trim($adminPath, '/');

$section = 'home';
if (str_starts_with($adminPath, '/admin/usuarios')) $section = 'users';
elseif (str_starts_with($adminPath, '/admin/conteudo') || str_starts_with($adminPath, '/admin/aulas') || str_starts_with($adminPath, '/admin/videos')) $section = 'content';
elseif (str_starts_with($adminPath, '/admin/questoes')) $section = 'questions';
elseif (str_starts_with($adminPath, '/admin/simulados')) $section = 'simulations';
elseif (str_starts_with($adminPath, '/admin/relatorios')) $section = 'reports';

$adminInitial = mb_strtoupper(mb_substr((string) ($userLayout['name'] ?? 'A'), 0, 1));
$success = Session::pullFlash('success');
$error = Session::pullFlash('error');
?>
<!doctype html>
<html lang="pt-BR" data-theme="<?= e($userLayout['theme'] ?? 'dark') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <meta name="theme-color" content="#0c1020">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/admin.css')) ?>">
    <script>window.APP_URL = <?= json_encode(rtrim((string) config('app.url'), '/')) ?>;</script>
    <script src="<?= e(url('/assets/js/app.js')) ?>" defer></script>
    <script src="<?= e(url('/assets/js/admin-users.js')) ?>" defer></script>
    <script src="<?= e(url('/assets/js/admin-media.js')) ?>" defer></script>
</head>
<body class="ld-admin-body<?= $section === 'home' ? ' ld-admin-home' : '' ?>">
<div class="ld-admin-frame">
    <aside class="ld-admin-sidebar" aria-label="Navegação administrativa">
        <a class="ld-admin-brand" href="<?= e(url('/admin')) ?>">
            <span class="ld-admin-brand-mark">LD</span>
            <span class="ld-admin-brand-copy"><strong>ADMIN</strong><small>Painel de gestão</small></span>
        </a>

        <nav class="ld-admin-nav">
            <span class="ld-admin-nav-label">GERAL</span>
            <a class="ld-admin-nav-item <?= $section === 'home' ? 'is-active' : '' ?>" href="<?= e(url('/admin')) ?>">
                <span class="ld-admin-nav-icon">⌂</span><span>Painel</span>
            </a>

            <span class="ld-admin-nav-label">GESTÃO</span>
            <a class="ld-admin-nav-item <?= $section === 'content' ? 'is-active' : '' ?>" href="<?= e(url('/admin/conteudo')) ?>">
                <span class="ld-admin-nav-icon">▦</span><span>Cursos e conteúdo</span>
            </a>
            <a class="ld-admin-nav-item <?= $section === 'questions' ? 'is-active' : '' ?>" href="<?= e(url('/admin/questoes')) ?>">
                <span class="ld-admin-nav-icon">?</span><span>Questões</span>
            </a>
            <a class="ld-admin-nav-item <?= $section === 'simulations' ? 'is-active' : '' ?>" href="<?= e(url('/admin/simulados')) ?>">
                <span class="ld-admin-nav-icon">◎</span><span>Simulados</span>
            </a>
            <a class="ld-admin-nav-item <?= $section === 'users' ? 'is-active' : '' ?>" href="<?= e(url('/admin/usuarios')) ?>">
                <span class="ld-admin-nav-icon">♙</span><span>Alunos</span>
            </a>
            <a class="ld-admin-nav-item <?= $section === 'reports' ? 'is-active' : '' ?>" href="<?= e(url('/admin/relatorios')) ?>">
                <span class="ld-admin-nav-icon">▥</span><span>Relatórios</span>
            </a>
            <a class="ld-admin-nav-item <?= $adminPath === '/admin/videos' ? 'is-active' : '' ?>" href="<?= e(url('/admin/videos')) ?>">
                <span class="ld-admin-nav-icon">▶</span><span>Biblioteca de vídeos</span>
            </a>
        </nav>

        <div class="ld-admin-sidebar-bottom">
            <div class="ld-admin-system-chip"><span class="ld-admin-status-dot"></span><div><strong>Área administrativa</strong><small>Acesso restrito</small></div></div>
        </div>
    </aside>

    <div class="ld-admin-workspace">
        <header class="ld-admin-topbar">
            <div class="ld-admin-topbar-copy">
                <span class="ld-admin-mobile-brand">LD <strong>ADMIN</strong></span>
                <div><span class="ld-admin-eyebrow">Administração</span><strong><?= e($title ?? 'Painel') ?></strong></div>
            </div>
            <div class="ld-admin-top-actions">
                <button class="ld-admin-icon-btn" type="button" data-theme-toggle title="Alternar tema">◐</button>
                <div class="ld-admin-user-chip"><span class="ld-admin-avatar"><?= e($adminInitial) ?></span><span class="ld-admin-user-copy"><strong><?= e($userLayout['name'] ?? 'Administrador') ?></strong><small>Administrador</small></span></div>
                <form class="ld-admin-inline-form" action="<?= e(url('/logout')) ?>" method="post"><?= Csrf::input() ?><button class="ld-admin-logout" type="submit">Sair</button></form>
            </div>
        </header>

        <main class="ld-admin-main">
            <?php if ($success): ?><div class="ld-admin-alert is-success"><?= e((string) $success) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="ld-admin-alert is-error"><?= e((string) $error) ?></div><?php endif; ?>
            <?= $content ?>
        </main>
    </div>
</div>

<nav class="ld-admin-mobile-nav" aria-label="Navegação administrativa móvel">
    <a class="<?= $section === 'home' ? 'is-active' : '' ?>" href="<?= e(url('/admin')) ?>"><span>⌂</span><small>Painel</small></a>
    <a class="<?= $section === 'content' ? 'is-active' : '' ?>" href="<?= e(url('/admin/conteudo')) ?>"><span>▦</span><small>Conteúdo</small></a>
    <a class="<?= $section === 'questions' ? 'is-active' : '' ?>" href="<?= e(url('/admin/questoes')) ?>"><span>?</span><small>Questões</small></a>
    <a class="<?= $section === 'users' ? 'is-active' : '' ?>" href="<?= e(url('/admin/usuarios')) ?>"><span>♙</span><small>Alunos</small></a>
    <form action="<?= e(url('/logout')) ?>" method="post"><?= Csrf::input() ?><button type="submit"><span>↪</span><small>Sair</small></button></form>
</nav>
</body>
</html>
