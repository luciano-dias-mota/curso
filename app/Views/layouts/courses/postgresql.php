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
$currentPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';

$pageTitle = isset($title)
    ? $title . ' • PostgreSQL • LD Academy'
    : 'PostgreSQL • LD Academy';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <meta name="theme-color" content="#075a9e">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(url('/assets/courses/postgresql/course.css') . '?v=20261020-3') ?>">
</head>
<body class="postgres-course-body">
    <div class="pg-app-shell">
        <aside class="pg-sidebar">
            <div class="pg-sidebar-brand">
                <a href="<?= e(url('/dashboard')) ?>" aria-label="LD Academy">
                    <img
                        src="<?= e(url('/assets/img/ld-academy-logo.webp')) ?>"
                        alt="LD Academy"
                        width="900"
                        height="295"
                    >
                </a>
            </div>

            <nav class="pg-side-nav" aria-label="Navegação do curso">
                <a
                    class="pg-side-link <?= $currentPath === url($courseHome) ? 'is-active' : '' ?>"
                    href="<?= e(url($courseHome)) ?>"
                >
                    <span aria-hidden="true">⌂</span>
                    <strong>Início</strong>
                </a>

                <a class="pg-side-link is-primary" href="<?= e(url('/dashboard')) ?>">
                    <span aria-hidden="true">◇</span>
                    <strong>Meus Cursos</strong>
                </a>

                <span class="pg-side-link is-passive">
                    <span aria-hidden="true">▣</span>
                    <strong>Certificados</strong>
                </span>

                <span class="pg-side-link is-passive">
                    <span aria-hidden="true">▥</span>
                    <strong>Ranking</strong>
                </span>

                <span class="pg-side-link is-passive">
                    <span aria-hidden="true">♙</span>
                    <strong>Meu Perfil</strong>
                </span>

                <span class="pg-side-link is-passive">
                    <span aria-hidden="true">?</span>
                    <strong>Ajuda</strong>
                </span>
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
                    <span class="pg-top-nav-item"><span aria-hidden="true">▣</span> Certificados</span>
                    <span class="pg-top-nav-item"><span aria-hidden="true">▥</span> Ranking</span>
                    <span class="pg-top-nav-item"><span aria-hidden="true">☆</span> Ajuda</span>
                </nav>

                <div class="pg-top-account">
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
