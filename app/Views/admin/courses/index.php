<section class="ld-admin-page-head">
    <div>
        <span class="ld-admin-kicker">PLATAFORMA</span>
        <h1>Cursos</h1>
        <p>Cadastre e gerencie cursos independentes. Cada aluno pode ter uma ou várias matrículas.</p>
    </div>
    <div class="ld-admin-head-actions">
        <a class="ld-admin-btn is-ghost" href="<?= e(url('/admin/conteudo')) ?>">Visão de conteúdo</a>
        <a class="ld-admin-btn" href="<?= e(url('/admin/cursos/novo')) ?>">+ Novo curso</a>
    </div>
</section>

<div class="ld-admin-stats-grid">
    <article class="ld-admin-stat-card"><div><span>Cursos</span><strong><?= (int) ($stats['total'] ?? 0) ?></strong><small>cadastrados</small></div></article>
    <article class="ld-admin-stat-card"><div><span>Publicados</span><strong><?= (int) ($stats['published'] ?? 0) ?></strong><small>visíveis aos matriculados</small></div></article>
    <article class="ld-admin-stat-card"><div><span>Rascunhos</span><strong><?= (int) ($stats['draft'] ?? 0) ?></strong><small>em preparação</small></div></article>
    <article class="ld-admin-stat-card"><div><span>Matrículas ativas</span><strong><?= (int) ($stats['active_students'] ?? 0) ?></strong><small>somadas entre cursos</small></div></article>
</div>

<section class="ld-admin-panel">
    <div class="ld-admin-panel-heading">
        <div><span class="ld-admin-panel-kicker">CATÁLOGO</span><h2>Cursos da plataforma</h2></div>
        <span class="ld-admin-live-badge"><i></i>Arquitetura multi-curso</span>
    </div>

    <div class="ld-admin-table-wrap">
        <table class="ld-admin-table">
            <thead>
                <tr>
                    <th>Curso</th>
                    <th>Status</th>
                    <th>Estrutura</th>
                    <th>Alunos</th>
                    <th>Nota mínima</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($courses)): ?>
                <tr><td colspan="6">Nenhum curso cadastrado.</td></tr>
            <?php endif; ?>
            <?php foreach ($courses as $course): ?>
                <tr>
                    <td>
                        <strong><?= e($course['title']) ?></strong>
                        <small><?= e($course['slug']) ?> • <?= e($course['difficulty']) ?> • experiência: <?= e($course['experience_key'] ?? 'generic') ?></small>
                    </td>
                    <td><span class="ld-admin-status is-<?= e($course['status']) ?>"><?= e($course['status']) ?></span></td>
                    <td>
                        <strong><?= (int) $course['modules_count'] ?> módulos</strong>
                        <small><?= (int) $course['phases_count'] ?> fases • <?= (int) $course['lessons_count'] ?> aulas</small>
                    </td>
                    <td><strong><?= (int) $course['active_students'] ?></strong><small>matrícula(s) ativa(s)</small></td>
                    <td><?= number_format((float) $course['required_score'], 1, ',', '.') ?>%</td>
                    <td>
                        <div class="ld-admin-action-row">
                            <a class="ld-admin-btn is-small" href="<?= e(url('/admin/cursos/' . (int) $course['id'])) ?>">Gerenciar</a>
                            <a class="ld-admin-btn is-small is-ghost" href="<?= e(url('/admin/cursos/' . (int) $course['id'] . '/estrutura')) ?>">Estrutura</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
