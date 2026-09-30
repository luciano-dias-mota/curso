<section>
    <p class="muted">Bem-vindo à missão.</p>
    <h1><?= e($user['name'] ?? 'Estudante') ?></h1>

    <?php if (!$courses): ?>
        <div class="card">
            <h2>Nenhum curso ativo</h2>
            <p class="muted">Sua matrícula ainda não foi liberada pelo administrador.</p>
        </div>
    <?php else: ?>
        <div class="grid grid-3">
            <?php foreach ($courses as $course): ?>
                <article class="card">
                    <h2><?= e($course['title']) ?></h2>
                    <p class="muted"><?= e($course['short_description'] ?? '') ?></p>
                    <div class="progress">
                        <span style="width: <?= (float) $course['progress_pct'] ?>%"></span>
                    </div>
                    <p><?= number_format((float) $course['progress_pct'], 0) ?>% concluído</p>
                    <a class="btn" href="<?= e(url('/curso/' . $course['id'])) ?>">Continuar</a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
