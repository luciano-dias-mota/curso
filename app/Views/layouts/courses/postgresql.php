<?php

use App\Core\Csrf;

$pageTitle = isset($title)
    ? $title . ' • PostgreSQL • ' . (string) config('app.name', 'Curso MI')
    : 'PostgreSQL • ' . (string) config('app.name', 'Curso MI');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(url('/assets/courses/postgresql/course.css') . '?v=20261020-1') ?>">
</head>
<body class="postgres-course-body">
    <header class="postgres-course-header">
        <a href="<?= e(url('/dashboard')) ?>" class="postgres-course-brand">
            <span>🐘</span>
            <strong>PostgreSQL</strong>
        </a>
        <form method="post" action="<?= e(url('/curso/sair')) ?>">
            <?= Csrf::input() ?>
            <button type="submit">Sair do curso</button>
        </form>
    </header>
    <main class="postgres-course-main"><?= $content ?? '' ?></main>
</body>
</html>
