<?php

use App\Core\Csrf;
use App\Core\Session;

$oldEmail = Session::pullFlash('old_email', '');
$errors = Session::pullFlash('errors', []);
?>
<h1>Entrar</h1>
<p class="muted">Continue sua missão de estudos.</p>

<form action="<?= e(url('/login')) ?>" method="post">
    <?= Csrf::input() ?>

    <div class="form-group">
        <label for="email">E-mail</label>
        <input class="form-control" id="email" type="email" name="email"
               value="<?= e($oldEmail) ?>" autocomplete="email" required>
        <?php if (!empty($errors['email'])): ?>
            <small><?= e($errors['email'][0]) ?></small>
        <?php endif; ?>
    </div>

    <div class="form-group">
        <label for="password">Senha</label>
        <input class="form-control" id="password" type="password" name="password"
               autocomplete="current-password" required>
    </div>

    <button class="btn green" type="submit">Entrar</button>
</form>
