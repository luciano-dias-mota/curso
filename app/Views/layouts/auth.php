<?php

use App\Core\Csrf;
use App\Core\Session;

$pageTitle = isset($title)
    ? $title . ' • LD Academy'
    : 'LD Academy';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <meta name="theme-color" content="#dcecf8">
    <title><?= e($pageTitle) ?></title>

    <!-- Login da plataforma: isolado visualmente dos cursos. -->
    <link rel="stylesheet" href="<?= e(url('/assets/css/auth.css') . '?v=20261020-2') ?>">
    <script src="<?= e(url('/assets/js/auth.js') . '?v=20261009-1') ?>" defer></script>
</head>
<body class="academy-auth-body">
    <main class="academy-auth-stage">
        <section class="academy-auth-brand-panel" aria-label="LD Academy">
            <img
                class="academy-auth-logo"
                src="<?= e(url('/assets/img/ld-academy-logo.webp')) ?>"
                alt="LD Academy - Portal de cursos"
                width="900"
                height="295"
            >

            <div class="academy-auth-copy">
                <span class="academy-auth-kicker">PORTAL DE CURSOS</span>
                <h1>Seu ambiente de aprendizagem começa aqui.</h1>
                <p>
                    Acesse sua conta e entre nos cursos liberados para o seu perfil,
                    cada um com identidade, trilha e experiência próprias.
                </p>
            </div>

            <div class="academy-auth-trust" aria-hidden="true">
                <span>Ambiente seguro</span>
                <i></i>
                <span>Acesso individual</span>
                <i></i>
                <span>Progresso preservado</span>
            </div>
        </section>

        <section class="academy-auth-access">
            <?php if ($message = Session::pullFlash('error')): ?>
                <div class="academy-auth-alert" role="alert"><?= e($message) ?></div>
            <?php endif; ?>

            <?= $content ?>
        </section>
    </main>
</body>
</html>
