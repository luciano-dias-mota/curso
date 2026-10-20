<?php

use App\Core\Csrf;
use App\Core\Session;

$oldEmail = Session::pullFlash('old_email', '');
$errors = Session::pullFlash('errors', []);
?>
<section class="academy-login-card" aria-labelledby="login-title">
    <div class="academy-login-heading">
        <span class="academy-login-kicker">ACESSO À PLATAFORMA</span>
        <h2 id="login-title">Bem-vindo de volta</h2>
        <p>Entre com seu e-mail e senha para acessar a LD Academy.</p>
    </div>

    <form class="academy-login-form" action="<?= e(url('/login')) ?>" method="post" novalidate>
        <?= Csrf::input() ?>

        <div class="academy-login-field">
            <label for="email">E-mail</label>
            <div class="academy-input-shell <?= !empty($errors['email']) ? 'is-invalid' : '' ?>">
                <span class="academy-input-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                        <path d="m3 7 9 6 9-6"></path>
                    </svg>
                </span>

                <input
                    id="email"
                    type="email"
                    name="email"
                    value="<?= e($oldEmail) ?>"
                    placeholder="Digite seu e-mail"
                    autocomplete="email"
                    maxlength="190"
                    required
                    autofocus
                >
            </div>

            <?php if (!empty($errors['email'])): ?>
                <small class="academy-field-error"><?= e($errors['email'][0]) ?></small>
            <?php endif; ?>
        </div>

        <div class="academy-login-field">
            <label for="password">Senha</label>
            <div class="academy-input-shell <?= !empty($errors['password']) ? 'is-invalid' : '' ?>">
                <span class="academy-input-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="5" y="10" width="14" height="10" rx="2"></rect>
                        <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
                    </svg>
                </span>

                <input
                    id="password"
                    type="password"
                    name="password"
                    placeholder="Digite sua senha"
                    autocomplete="current-password"
                    minlength="6"
                    maxlength="255"
                    required
                >

                <button
                    class="academy-password-toggle"
                    type="button"
                    data-password-toggle="password"
                    aria-label="Mostrar senha"
                    title="Mostrar senha"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path>
                        <circle cx="12" cy="12" r="2.5"></circle>
                    </svg>
                </button>
            </div>

            <?php if (!empty($errors['password'])): ?>
                <small class="academy-field-error"><?= e($errors['password'][0]) ?></small>
            <?php endif; ?>
        </div>

        <button class="academy-login-submit" type="submit">
            <span>Entrar na LD Academy</span>
            <span aria-hidden="true">→</span>
        </button>
    </form>

    <p class="academy-login-footnote">
        Acesso restrito a usuários autorizados.
    </p>
</section>
