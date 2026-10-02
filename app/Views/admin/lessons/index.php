<section class="admin-lessons-page">
    <div class="admin-page-head">
        <div>
            <a class="back" href="<?= e(url('/admin')) ?>">← Administração</a>
            <span class="eyebrow">CONTEÚDO DO CURSO</span>
            <h1>Gerenciar aulas</h1>
            <p>Escolha uma aula para inserir, trocar, remover ou reposicionar vídeos.</p>
        </div>

        <a class="primary" href="<?= e(url('/admin/videos')) ?>">Biblioteca de vídeos</a>
    </div>

    <form class="card admin-search" method="get" action="<?= e(url('/admin/aulas')) ?>">
        <input
            class="form-control"
            type="search"
            name="q"
            value="<?= e($search) ?>"
            placeholder="Buscar por aula, fase, módulo ou curso..."
        >
        <button class="secondary" type="submit">Buscar</button>
        <?php if ($search !== ''): ?>
            <a class="ghost-btn" href="<?= e(url('/admin/aulas')) ?>">Limpar</a>
        <?php endif; ?>
    </form>

    <?php if (!$lessons): ?>
        <article class="card admin-empty">
            <h2>Nenhuma aula encontrada</h2>
            <p>Tente outro termo de busca.</p>
        </article>
    <?php else: ?>
        <div class="admin-lesson-list">
            <?php foreach ($lessons as $lesson): ?>
                <a class="card admin-lesson-row" href="<?= e(url('/admin/aulas/' . $lesson['id'])) ?>">
                    <div class="admin-lesson-main">
                        <span class="eyebrow"><?= e($lesson['course_title']) ?></span>
                        <h3><?= e($lesson['title']) ?></h3>
                        <p>
                            <?= e($lesson['module_title']) ?>
                            <span>•</span>
                            <?= e($lesson['phase_title']) ?>
                        </p>
                    </div>

                    <div class="admin-lesson-counts">
                        <span><strong><?= (int) $lesson['block_count'] ?></strong> blocos</span>
                        <span class="<?= (int) $lesson['video_count'] > 0 ? 'has-video' : '' ?>">
                            <strong><?= (int) $lesson['video_count'] ?></strong> vídeos
                        </span>
                        <strong class="lesson-open-arrow">→</strong>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
