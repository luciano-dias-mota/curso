<section class="lesson-stage">
    <header class="card">
        <small><?= e($lesson['phase_title']) ?></small>
        <strong><?= e($lesson['title']) ?></strong>
        <span class="muted">
            &nbsp;•&nbsp; Tela <?= (int) $currentIndex ?> de <?= (int) $totalBlocks ?>
        </span>
    </header>

    <article class="card lesson-card">
        <?php if ($currentBlock): ?>
            <small><?= e(strtoupper(str_replace('_', ' ', $currentBlock['block_type']))) ?></small>
            <?php if ($currentBlock['title']): ?>
                <h1><?= e($currentBlock['title']) ?></h1>
            <?php endif; ?>

            <div><?= nl2br(e($currentBlock['content'])) ?></div>
        <?php else: ?>
            <h2>Aula sem conteúdo cadastrado.</h2>
            <p class="muted">O administrador ainda não inseriu os blocos desta aula.</p>
        <?php endif; ?>
    </article>

    <nav class="lesson-nav">
        <?php if ($currentIndex > 1): ?>
            <a class="btn secondary" data-prev
               href="<?= e(url('/aula/' . $lesson['id'] . '?bloco=' . ($currentIndex - 1))) ?>">
                ← Anterior
            </a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>

        <?php if ($currentIndex < $totalBlocks): ?>
            <a class="btn" data-next
               href="<?= e(url('/aula/' . $lesson['id'] . '?bloco=' . ($currentIndex + 1))) ?>">
                Próximo →
            </a>
        <?php else: ?>
            <a class="btn green" href="<?= e(url('/fase/' . $lesson['phase_id'])) ?>">
                Finalizar leitura ✓
            </a>
        <?php endif; ?>
    </nav>
</section>
