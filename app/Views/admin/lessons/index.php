<section class="ld-admin-page-head ld-admin-page-head-compact">
    <div>
        <a class="ld-admin-back-link" href="<?= e(url('/admin/conteudo')) ?>">← Cursos e conteúdo</a>
        <span class="ld-admin-kicker">CONTEÚDO DO CURSO</span>
        <h1>Gerenciar aulas</h1>
        <p>Localize a aula e abra o editor para organizar os blocos e inserir ou reposicionar vídeos.</p>
    </div>
    <div class="ld-admin-head-actions">
        <a class="ld-admin-btn is-primary" href="<?= e(url('/admin/videos')) ?>">Biblioteca de vídeos</a>
    </div>
</section>

<section class="ld-admin-panel ld-admin-panel-compact">
    <form class="ld-admin-searchbar" method="get" action="<?= e(url('/admin/aulas')) ?>">
        <input
            class="ld-admin-input"
            type="search"
            name="q"
            value="<?= e($search) ?>"
            placeholder="Buscar aula, fase, módulo ou curso..."
        >
        <button class="ld-admin-btn" type="submit">Buscar</button>
        <?php if ($search !== ''): ?>
            <a class="ld-admin-btn is-ghost" href="<?= e(url('/admin/aulas')) ?>">Limpar</a>
        <?php endif; ?>
    </form>
</section>

<?php if (!$lessons): ?>
    <section class="ld-admin-panel ld-admin-empty">Nenhuma aula encontrada. Tente outro termo de busca.</section>
<?php else: ?>
    <div class="ld-admin-lesson-list">
        <?php foreach ($lessons as $lesson): ?>
            <a class="ld-admin-lesson-card" href="<?= e(url('/admin/aulas/' . $lesson['id'])) ?>">
                <div class="ld-admin-lesson-card-main">
                    <span class="ld-admin-kicker"><?= e($lesson['course_title']) ?></span>
                    <h2><?= e($lesson['title']) ?></h2>
                    <p><?= e($lesson['module_title']) ?> <span>•</span> <?= e($lesson['phase_title']) ?></p>
                </div>

                <div class="ld-admin-lesson-card-meta">
                    <span><strong><?= (int) $lesson['block_count'] ?></strong><small>blocos</small></span>
                    <span class="<?= (int) $lesson['video_count'] > 0 ? 'has-video' : '' ?>"><strong><?= (int) $lesson['video_count'] ?></strong><small>vídeos</small></span>
                    <b aria-hidden="true">→</b>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
