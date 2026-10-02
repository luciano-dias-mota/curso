<section class="admin-command">
    <div class="admin-page-head">
        <div>
            <span class="eyebrow">ADMINISTRAÇÃO</span>
            <h1>Central de Comando</h1>
            <p>Gerencie o conteúdo do curso e a biblioteca de mídia.</p>
        </div>
    </div>

    <div class="admin-stat-grid">
        <article class="card admin-stat"><strong><?= (int) $stats['students'] ?></strong><span>Estudantes</span></article>
        <article class="card admin-stat"><strong><?= (int) $stats['courses'] ?></strong><span>Cursos</span></article>
        <article class="card admin-stat"><strong><?= (int) $stats['modules'] ?></strong><span>Módulos</span></article>
        <article class="card admin-stat"><strong><?= (int) $stats['lessons'] ?></strong><span>Aulas</span></article>
        <article class="card admin-stat"><strong><?= (int) $stats['questions'] ?></strong><span>Questões</span></article>
        <article class="card admin-stat"><strong><?= (int) $stats['videos'] ?></strong><span>Vídeos</span></article>
    </div>

    <div class="admin-shortcuts">
        <a class="card admin-shortcut" href="<?= e(url('/admin/aulas')) ?>">
            <span class="admin-shortcut-icon">▤</span>
            <div>
                <h2>Gerenciar aulas</h2>
                <p>Escolha uma aula, insira vídeos e altere a ordem dos blocos.</p>
            </div>
            <strong>→</strong>
        </a>

        <a class="card admin-shortcut" href="<?= e(url('/admin/videos')) ?>">
            <span class="admin-shortcut-icon">▶</span>
            <div>
                <h2>Biblioteca de vídeos</h2>
                <p>Envie, visualize, renomeie e exclua arquivos de vídeo.</p>
            </div>
            <strong>→</strong>
        </a>
    </div>
</section>
