<?php

use App\Core\Csrf;
use App\Core\Session;

$pageTitle = isset($title)
    ? $title . ' • ' . (string) config('app.name', 'LD Academy')
    : (string) config('app.name', 'LD Academy');
?>
<!doctype html>
<html lang="pt-BR" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <meta name="theme-color" content="#061426">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(url('/assets/css/app.css') . '?v=20261009-1') ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/auth.css') . '?v=20261009-1') ?>">
    <script src="<?= e(url('/assets/js/auth.js') . '?v=20261009-1') ?>" defer></script>
</head>
<body class="academy-auth-body">
<div class="academy-auth-stage">
    <div class="academy-auth-radiance" aria-hidden="true"></div>

    <svg class="academy-auth-art" viewBox="0 0 1200 820" preserveAspectRatio="xMidYMid meet" aria-hidden="true">
        <defs>
            <linearGradient id="shieldLine" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="#f9d77b" stop-opacity=".55"/>
                <stop offset=".45" stop-color="#5aa8d9" stop-opacity=".30"/>
                <stop offset="1" stop-color="#f1c85a" stop-opacity=".18"/>
            </linearGradient>
            <linearGradient id="leafLine" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="#f8d36c" stop-opacity=".34"/>
                <stop offset="1" stop-color="#8db6cf" stop-opacity=".10"/>
            </linearGradient>
        </defs>

        <path class="shield-main" d="M600 42 934 208v220c0 193-139 305-334 365-195-60-334-172-334-365V208L600 42Z" fill="none" stroke="url(#shieldLine)" stroke-width="4"/>
        <path class="shield-inner" d="M600 103 872 240v181c0 148-103 239-272 294-169-55-272-146-272-294V240L600 103Z" fill="none" stroke="#7fb9db" stroke-opacity=".10" stroke-width="2"/>

        <g class="laurel" fill="none" stroke="url(#leafLine)" stroke-width="3">
            <path d="M318 652C196 548 168 385 226 237"/>
            <path d="M882 652c122-104 150-267 92-415"/>
        </g>
        <g class="leaves" fill="#adc9d8" fill-opacity=".08" stroke="#f2d071" stroke-opacity=".20" stroke-width="1.3">
            <ellipse cx="268" cy="580" rx="22" ry="62" transform="rotate(-35 268 580)"/>
            <ellipse cx="228" cy="509" rx="22" ry="60" transform="rotate(-24 228 509)"/>
            <ellipse cx="209" cy="426" rx="22" ry="57" transform="rotate(-12 209 426)"/>
            <ellipse cx="210" cy="344" rx="21" ry="53" transform="rotate(3 210 344)"/>
            <ellipse cx="236" cy="269" rx="20" ry="48" transform="rotate(18 236 269)"/>

            <ellipse cx="932" cy="580" rx="22" ry="62" transform="rotate(35 932 580)"/>
            <ellipse cx="972" cy="509" rx="22" ry="60" transform="rotate(24 972 509)"/>
            <ellipse cx="991" cy="426" rx="22" ry="57" transform="rotate(12 991 426)"/>
            <ellipse cx="990" cy="344" rx="21" ry="53" transform="rotate(-3 990 344)"/>
            <ellipse cx="964" cy="269" rx="20" ry="48" transform="rotate(-18 964 269)"/>
        </g>

        <g class="academy-columns" fill="none" stroke="#78a8c5" stroke-opacity=".10" stroke-width="5">
            <path d="M1045 264h105M1060 290h75M1068 305v275M1128 305v275M1058 580h80M1042 605h112"/>
            <path d="M1028 242h140l-70-44-70 44Z"/>
        </g>
    </svg>

    <div class="academy-auth-spark spark-a" aria-hidden="true">✦</div>
    <div class="academy-auth-spark spark-b" aria-hidden="true">＋</div>
    <div class="academy-auth-spark spark-c" aria-hidden="true">✦</div>

    <main class="academy-auth-shell">
        <?php if ($message = Session::pullFlash('error')): ?>
            <div class="academy-auth-alert" role="alert"><?= e($message) ?></div>
        <?php endif; ?>

        <?= $content ?>

        <p class="academy-auth-footer">Ambiente de estudos • acesso restrito</p>
    </main>
</div>
</body>
</html>
