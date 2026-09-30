<section>
    <p class="muted"><?= e($phase['module_title']) ?></p>
    <h1><?= e($phase['title']) ?></h1>
    <p><?= e($phase['description'] ?? '') ?></p>

    <div class="grid grid-3">
        <?php foreach ($lessons as $lesson): ?>
            <article class="card">
                <small>Aula <?= (int) $lesson['position'] ?></small>
                <h2><?= e($lesson['title']) ?></h2>
                <p class="muted"><?= e($lesson['summary'] ?? '') ?></p>
                <p>⏱ <?= (int) $lesson['estimated_minutes'] ?> min &nbsp;•&nbsp; +<?= (int) $lesson['xp_reward'] ?> XP</p>
                <a class="btn" href="<?= e(url('/aula/' . $lesson['id'])) ?>">Iniciar aula</a>
            </article>
        <?php endforeach; ?>
    </div>
</section>
