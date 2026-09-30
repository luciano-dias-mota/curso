<?php

use App\Core\Auth;
use App\Core\Csrf;

$user = Auth::user();
$pageTitle = isset($title)
    ? $title . ' • ' . (string) config('app.name', 'Curso')
    : (string) config('app.name', 'Curso');
?>
<!doctype html>
<html lang="pt-BR" data-theme="<?= e($user['theme'] ?? 'dark') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle) ?></title>

    <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/learning-flow.css')) ?>">
    <script src="<?= e(url('/assets/js/app.js')) ?>" defer></script>
</head>
<body class="student-body">
<header class="academy-topbar">
    <a class="academy-brand" href="<?= e(url('/dashboard')) ?>">
        <span class="brand-mark">PMMT</span>
        <span class="brand-name">ACADEMY</span>
        <span class="brand-tag">MISSÃO APROVAÇÃO</span>
    </a>

    <div class="top-actions">
        <div class="player-chip">
            <span class="avatar"><?= e(mb_strtoupper(mb_substr($user['name'] ?? 'E', 0, 1))) ?></span>
            <span>
                <strong><?= e($user['name'] ?? 'Estudante') ?></strong>
                <small>
                    Nível <?= (int) ($user['current_level'] ?? 1) ?>
                    • <?= (int) ($user['xp_total'] ?? 0) ?> XP
                </small>
            </span>
        </div>

        <button class="icon-btn" type="button" data-theme-toggle title="Alternar tema">◐</button>

        <form class="inline" action="<?= e(url('/logout')) ?>" method="post">
            <?= Csrf::input() ?>
            <button class="ghost-btn" type="submit">Sair</button>
        </form>
    </div>
</header>

<main class="academy-shell">
    <?= $content ?>
</main>
</body>
</html>
