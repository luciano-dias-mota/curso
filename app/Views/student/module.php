<section>
    <p class="muted"><?= e($module['course_title']) ?></p>
    <h1><?= e($module['title']) ?></h1>

    <div class="grid grid-3">
        <?php foreach ($phases as $phase): ?>
            <?php $locked = $phase['user_status'] === 'locked'; ?>
            <article class="card <?= $locked ? 'phase-locked' : '' ?>">
                <small>
                    <?= $phase['phase_type'] === 'boss' ? '👑 BOSS' : 'FASE ' . (int) $phase['position'] ?>
                </small>
                <h2><?= e($phase['title']) ?></h2>
                <p class="muted"><?= e($phase['description'] ?? '') ?></p>
                <p>Melhor nota: <?= number_format((float) $phase['best_score'], 0) ?>%</p>

                <?php if ($locked): ?>
                    <strong>🔒 Bloqueada</strong>
                <?php else: ?>
                    <a class="btn" href="<?= e(url('/fase/' . $phase['id'])) ?>">Entrar na fase</a>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
