<?php

use App\Core\Csrf;
use App\Core\Session;

$error = Session::pullFlash('error');
?>
<section class="lesson-stage">
    <header class="card">
        <small><?= e($lesson['phase_title']) ?></small>
        <strong><?= e($lesson['title']) ?></strong>
        <span class="muted">
            &nbsp;•&nbsp; Tela <?= (int) $currentIndex ?> de <?= (int) $totalBlocks ?>
        </span>
    </header>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <article class="card lesson-card">
        <?php if ($currentBlock): ?>
            <small><?= e(strtoupper(str_replace('_', ' ', $currentBlock['block_type']))) ?></small>

            <?php if ($currentBlock['title']): ?>
                <h1><?= e($currentBlock['title']) ?></h1>
            <?php endif; ?>

            <div class="lesson-content">
                <?= nl2br(e($currentBlock['content'])) ?>
            </div>
        <?php else: ?>
            <h2>Aula sem conteúdo cadastrado.</h2>
            <p class="muted">O administrador ainda não inseriu os blocos desta aula.</p>
        <?php endif; ?>
    </article>

    <nav class="lesson-nav">
        <?php if ($currentIndex > 1): ?>
            <a
                class="btn secondary"
                data-prev
                href="<?= e(url('/aula/' . $lesson['id'] . '?bloco=' . ($currentIndex - 1))) ?>"
            >
                ← Anterior
            </a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>

        <div class="muted">
            <?= (int) ($progress['blocks_viewed'] ?? 0) ?>/<?= (int) $totalBlocks ?> telas visitadas
        </div>

        <?php if ($currentIndex < $totalBlocks): ?>
            <a
                class="btn"
                data-next
                href="<?= e(url('/aula/' . $lesson['id'] . '?bloco=' . ($currentIndex + 1))) ?>"
            >
                Próximo →
            </a>
        <?php else: ?>
            <form
                action="<?= e(url('/aula/' . $lesson['id'] . '/concluir')) ?>"
                method="post"
                class="inline"
            >
                <?= Csrf::input() ?>
                <button class="btn green" type="submit">
                    Finalizar leitura ✓
                </button>
            </form>
        <?php endif; ?>
    </nav>
</section>
