<section>
    <p class="muted">Curso</p>
    <h1><?= e($course['title']) ?></h1>
    <p><?= e($course['short_description'] ?? '') ?></p>

    <div class="grid grid-3">
        <?php foreach ($modules as $module): ?>
            <?php $locked = $module['user_status'] === 'locked'; ?>
            <article class="card <?= $locked ? 'phase-locked' : '' ?>">
                <small>Módulo <?= (int) $module['position'] ?></small>
                <h2><?= e($module['title']) ?></h2>
                <div class="progress">
                    <span style="width: <?= (float) $module['progress_pct'] ?>%"></span>
                </div>
                <p><?= $locked ? '🔒 Bloqueado' : e($module['user_status']) ?></p>
                <?php if (!$locked): ?>
                    <a class="btn" href="<?= e(url('/modulo/' . $module['id'])) ?>">Abrir módulo</a>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
