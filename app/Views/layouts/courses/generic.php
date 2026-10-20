<?php

use App\Core\Csrf;

$pageTitle = isset($title)
    ? $title . ' • ' . (string) config('app.name', 'Curso MI')
    : (string) config('app.name', 'Curso MI');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(url('/assets/courses/generic/course.css') . '?v=20261020-1') ?>">
</head>
<body class="course-generic-body">
    <header class="course-generic-header">
        <strong>Curso MI</strong>
        <form method="post" action="<?= e(url('/curso/sair')) ?>">
            <?= Csrf::input() ?>
            <button type="submit">Voltar ao portal</button>
        </form>
    </header>
    <main class="course-generic-main"><?= $content ?? '' ?></main>
</body>
</html>
