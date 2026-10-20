<?php

use App\Core\Auth;
use App\Core\Csrf;

$user = Auth::user() ?? [];
$pageTitle = isset($title)
    ? $title . ' • LD Academy'
    : 'LD Academy';

$firstName = explode(' ', trim((string) ($user['name'] ?? 'Estudante')))[0];
$initial = mb_strtoupper(mb_substr((string) ($user['name'] ?? 'E'), 0, 1));
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#eaf5fb">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <title><?= e($pageTitle) ?></title>

    <!--
        O portal tem identidade própria.
        Não carregamos app.css nem layouts/student.php para impedir que
        futuras alterações de um curso afetem a vitrine da plataforma.
    -->
    <link rel="stylesheet" href="<?= e(url('/assets/css/portal.css') . '?v=20261020-5') ?>">
    <script src="<?= e(url('/assets/js/portal.js') . '?v=20261020-2') ?>" defer></script>
</head>
<body class="platform-portal-body">
    <header class="platform-portal-header">
        <div class="platform-portal-header-inner">
            <a class="platform-brand" href="<?= e(url('/dashboard')) ?>" aria-label="LD Academy - Portal de cursos">
                <img
                    class="platform-brand-logo"
                    src="<?= e(url('/assets/img/ld-academy-logo.webp')) ?>"
                    alt="LD Academy - Portal de cursos"
                    width="900"
                    height="295"
                >
            </a>

            <div class="platform-account">
                <div class="platform-account-copy">
                    <span>Olá,</span>
                    <strong><?= e($firstName) ?></strong>
                </div>
                <span class="platform-avatar" aria-hidden="true"><?= e($initial) ?></span>

                <form action="<?= e(url('/logout')) ?>" method="post">
                    <?= Csrf::input() ?>
                    <button class="platform-logout" type="submit">Sair</button>
                </form>
            </div>
        </div>
    </header>

    <main class="platform-portal-main">
        <?= $content ?? '' ?>
    </main>
</body>
</html>
