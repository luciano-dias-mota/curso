<?php

use App\Core\Auth;
use App\Core\Csrf;

$user = Auth::user() ?? [];
$firstName = explode(' ', trim((string) ($user['name'] ?? 'Estudante')))[0];
$initial = mb_strtoupper(mb_substr((string) ($user['name'] ?? 'E'), 0, 1));
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
    <meta name="theme-color" content="#e9f6ff">
    <title><?= e($pageTitle) ?></title>

    <link rel="stylesheet" href="<?= e(url('/assets/courses/postgresql/course.css') . '?v=20261020-2') ?>">
</head>
<body class="postgres-course-body">
    <header class="postgres-course-header">
        <div class="postgres-course-header-left">
            <a href="<?= e(url('/dashboard')) ?>" class="postgres-academy-brand" aria-label="LD Academy">
                <img
                    src="<?= e(url('/assets/img/ld-academy-logo.webp')) ?>"
                    alt="LD Academy"
                    width="900"
                    height="295"
                >
            </a>

            <span class="postgres-header-separator" aria-hidden="true"></span>

            <a href="<?= e(url('/dashboard')) ?>" class="postgres-back-portal">
                ← Portal de cursos
            </a>
        </div>

        <div class="postgres-course-account">
            <div class="postgres-account-copy">
                <span>PostgreSQL</span>
                <strong><?= e($firstName) ?></strong>
            </div>

            <span class="postgres-avatar" aria-hidden="true"><?= e($initial) ?></span>

            <form method="post" action="<?= e(url('/curso/sair')) ?>">
                <?= Csrf::input() ?>
                <button type="submit">Sair do curso</button>
            </form>
        </div>
    </header>

    <main class="postgres-course-main">
        <?= $content ?? '' ?>
    </main>
</body>
</html>
