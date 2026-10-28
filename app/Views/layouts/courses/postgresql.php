<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;

$user = Auth::user() ?? [];
$firstName = explode(' ', trim((string) ($user['name'] ?? 'Estudante')))[0];
$initial = mb_strtoupper(mb_substr((string) ($user['name'] ?? 'E'), 0, 1));

$courseId = (int) (
    $course['id']
    ?? $module['course_id']
    ?? $phase['course_id']
    ?? $lesson['course_id']
    ?? Session::get('active_course_id', 0)
);

$courseHome = $courseId > 0 ? '/curso/' . $courseId : '/dashboard';
$certificatesUrl = $courseId > 0 ? '/curso/' . $courseId . '/certificados' : '/dashboard';
$profileUrl = $courseId > 0 ? '/curso/' . $courseId . '/perfil' : '/dashboard';
$helpUrl = $courseId > 0 ? '/curso/' . $courseId . '/ajuda' : '/dashboard';
$currentPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
$courseHomePath = parse_url(url($courseHome), PHP_URL_PATH) ?: '';
$isPgHome = $courseId > 0 && rtrim($currentPath, '/') === rtrim($courseHomePath, '/');

$pageTitle = isset($title)
    ? $title . ' • PostgreSQL • LD Academy'
    : 'PostgreSQL • LD Academy';
?>
<!doctype html>
<html lang="pt-BR" data-pg-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <meta name="theme-color" content="#075a9e">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(url('/assets/courses/postgresql/course.css') . '?v=20261021-2') ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/courses/postgresql/theme-viewport.css') . '?v=20261028-1') ?>">
    <script>try{if(localStorage.getItem("ldacademy:postgresql:theme")==="dark")document.documentElement.dataset.pgTheme="dark"}catch(_){}</script>
    <script defer src="<?= e(url('/assets/courses/postgresql/theme-viewport.js') . '?v=20261028-1') ?>"></script>
</head>
<body class="postgres-course-body <?= $isPgHome ? 'pg-is-home' : '' ?>">
    <div class="pg-app-shell">
        <aside class="pg-sidebar">
            <div class="pg-sidebar-brand">
                <a href="<?= e(url('/dashboard')) ?>" aria-label="LD Academy">
                    <img
                        src="<?= e(url('/assets/img/ld-academy-logo.webp')) ?>"
                        alt="LD Academy"
                        width="300"
                        height="98"
                    >
                </a>
            </div>

            <nav class="pg-side-nav" aria-label="Navegação do curso">
                <a
                    class="pg-side-link <?= $isPgHome ? 'is-active' : '' ?>"
                    href="<?= e(url($courseHome)) ?>"
                >
                    <span aria-hidden="true">⌂</span>
                    <strong>Início</strong>
                </a>

                <a class="pg-side-link" href="<?= e(url('/dashboard')) ?>">
                    <span aria-hidden="true">◇</span>
                    <strong>Meus Cursos</strong>
                </a>

                <a
                    class="pg-side-link <?= str_contains($currentPath, '/certificados') ? 'is-active' : '' ?>"
                    href="<?= e(url($certificatesUrl)) ?>"
                >
                    <span aria-hidden="true">▣</span>
                    <strong>Certificados</strong>
                </a>

                <a
                    class="pg-side-link <?= str_contains($currentPath, '/perfil') ? 'is-active' : '' ?>"
                    href="<?= e(url($profileUrl)) ?>"
                >
                    <span aria-hidden="true">♙</span>
                    <strong>Meu Perfil</strong>
                </a>

                <a
                    class="pg-side-link <?= str_contains($currentPath, '/ajuda') ? 'is-active' : '' ?>"
                    href="<?= e(url($helpUrl)) ?>"
                >
                    <span aria-hidden="true">?</span>
                    <strong>Ajuda</strong>
                </a>
            </nav>

            <form class="pg-sidebar-logout" method="post" action="<?= e(url('/curso/sair')) ?>">
                <?= Csrf::input() ?>
                <button type="submit">
                    <span aria-hidden="true">↪</span>
                    <strong>Sair</strong>
                </button>
            </form>
        </aside>

        <div class="pg-workspace">
            <header class="pg-topbar">
                <nav class="pg-top-nav" aria-label="Atalhos da plataforma">
                    <a href="<?= e(url('/dashboard')) ?>"><span aria-hidden="true">▤</span> Meus Cursos</a>
                    <a class="pg-top-nav-item <?= str_contains($currentPath, '/certificados') ? 'is-active' : '' ?>" href="<?= e(url($certificatesUrl)) ?>"><span aria-hidden="true">▣</span> Certificados</a>
                    <a class="pg-top-nav-item <?= str_contains($currentPath, '/perfil') ? 'is-active' : '' ?>" href="<?= e(url($profileUrl)) ?>"><span aria-hidden="true">♙</span> Meu Perfil</a>
                    <a class="pg-top-nav-item <?= str_contains($currentPath, '/ajuda') ? 'is-active' : '' ?>" href="<?= e(url($helpUrl)) ?>"><span aria-hidden="true">☆</span> Ajuda</a>
                </nav>

                <div class="pg-top-account">
                    <button type="button" id="pg-theme-toggle" class="pg-theme-toggle" aria-pressed="false" aria-label="Ativar modo escuro">
                        <span data-pg-theme-icon aria-hidden="true">☾</span>
                        <span data-pg-theme-label>Modo escuro</span>
                    </button>
                    <span class="pg-notification" aria-label="Notificações">●</span>
                    <span class="pg-top-avatar" aria-hidden="true"><?= e($initial) ?></span>
                    <strong><?= e($firstName) ?></strong>
                    <span aria-hidden="true">⌄</span>
                </div>
            </header>

            <main class="postgres-course-main">
                <?= $content ?? '' ?>
            </main>
        </div>
    </div>
</body>
</html>
