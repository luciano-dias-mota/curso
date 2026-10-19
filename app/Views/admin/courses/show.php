<?php
$statusLabels = ['draft'=>'Rascunho','published'=>'Publicado','archived'=>'Arquivado'];
$difficultyLabels = ['beginner'=>'Iniciante','intermediate'=>'Intermediário','advanced'=>'Avançado'];
?>
<section class="ld-admin-page-head">
    <div>
        <span class="ld-admin-kicker">CURSO #<?= (int) $course['id'] ?></span>
        <h1><?= e($course['title']) ?></h1>
        <p><?= e($course['short_description'] ?: 'Curso cadastrado na plataforma.') ?></p>
    </div>
    <div class="ld-admin-head-actions">
        <a class="ld-admin-btn is-ghost" href="<?= e(url('/admin/cursos')) ?>">← Cursos</a>
        <a class="ld-admin-btn is-ghost" href="<?= e(url('/admin/cursos/' . (int) $course['id'] . '/editar')) ?>">Editar</a>
        <a class="ld-admin-btn" href="<?= e(url('/admin/cursos/' . (int) $course['id'] . '/estrutura')) ?>">Montar estrutura</a>
    </div>
</section>

<div class="ld-admin-stats-grid">
    <article class="ld-admin-stat-card"><div><span>Módulos</span><strong><?= (int) ($counts['modules_count'] ?? 0) ?></strong><small>na estrutura</small></div></article>
    <article class="ld-admin-stat-card"><div><span>Fases</span><strong><?= (int) ($counts['phases_count'] ?? 0) ?></strong><small>cadastradas</small></div></article>
    <article class="ld-admin-stat-card"><div><span>Aulas</span><strong><?= (int) ($counts['lessons_count'] ?? 0) ?></strong><small>cadastradas</small></div></article>
    <article class="ld-admin-stat-card"><div><span>Alunos</span><strong><?= (int) ($counts['active_students'] ?? 0) ?></strong><small>matrículas ativas</small></div></article>
</div>

<div class="ld-admin-content-grid">
    <section class="ld-admin-panel ld-admin-panel-large">
        <div class="ld-admin-panel-heading">
            <div><span class="ld-admin-panel-kicker">CONFIGURAÇÃO</span><h2>Resumo acadêmico</h2></div>
            <span class="ld-admin-status is-<?= e($course['status']) ?>"><?= e($statusLabels[$course['status']] ?? $course['status']) ?></span>
        </div>
        <div class="ld-admin-two-tables">
            <div>
                <h3>Curso</h3>
                <p><strong>Slug:</strong> <?= e($course['slug']) ?></p>
                <p><strong>Dificuldade:</strong> <?= e($difficultyLabels[$course['difficulty']] ?? $course['difficulty']) ?></p>
                <p><strong>Nota mínima:</strong> <?= number_format((float) $course['required_score'], 1, ',', '.') ?>%</p>
                <p><strong>Questões:</strong> <?= (int) ($counts['questions_count'] ?? 0) ?></p>
            </div>
            <div>
                <h3>Metodologia configurável</h3>
                <p><strong>Módulos:</strong> <?= ($settings['module_access_mode'] ?? 'free') === 'sequential' ? 'sequenciais' : 'livres' ?></p>
                <p><strong>Aulas:</strong> <?= ($settings['lesson_access_mode'] ?? 'sequential') === 'free' ? 'livres' : 'sequenciais' ?></p>
                <?php $completionLabels = ['quiz'=>'quiz/exercício','lab'=>'laboratório','reading'=>'leitura']; ?>
                <p><strong>Conclusão das aulas:</strong> <?= e($completionLabels[$settings['lesson_completion_mode'] ?? 'quiz'] ?? 'quiz/exercício') ?></p>
                <p><strong>Conclusão das fases:</strong> <?= ($settings['phase_completion_mode'] ?? 'quiz') === 'lessons' ? 'pelas aulas' : 'por avaliação' ?></p>
                <p><strong>Conclusão dos módulos:</strong> <?= ($settings['module_completion_mode'] ?? 'quiz') === 'phases' ? 'pelas fases' : 'por avaliação final' ?></p>
                <p><strong>Laboratório:</strong> <?= ($settings['lab_enabled'] ?? '0') === '1' ? 'habilitado' : 'desabilitado' ?></p>
                <p><strong>Exercícios:</strong> <?= ($settings['exercises_enabled'] ?? '1') === '1' ? 'habilitados' : 'desabilitados' ?></p>
                <p><strong>Simulados:</strong> <?= ($settings['simulations_enabled'] ?? '1') === '1' ? 'habilitados' : 'desabilitados' ?></p>
            </div>
        </div>
    </section>

    <aside class="ld-admin-panel">
        <div class="ld-admin-panel-heading compact"><div><span class="ld-admin-panel-kicker">PUBLICAÇÃO</span><h2>Status do curso</h2></div></div>
        <p>Rascunhos não aparecem para o aluno. Arquivar preserva histórico e matrículas, mas remove o curso da área de estudo.</p>
        <div class="ld-admin-action-row">
            <?php foreach (['draft'=>'Rascunho','published'=>'Publicar','archived'=>'Arquivar'] as $value=>$label): ?>
                <form method="post" action="<?= e(url('/admin/cursos/' . (int) $course['id'] . '/status')) ?>">
                    <?= \App\Core\Csrf::input() ?>
                    <input type="hidden" name="status" value="<?= e($value) ?>">
                    <button class="ld-admin-btn <?= $value === 'published' ? 'is-success' : ($value === 'archived' ? 'is-danger' : '') ?>" type="submit" <?= $course['status'] === $value ? 'disabled' : '' ?>><?= e($label) ?></button>
                </form>
            <?php endforeach; ?>
        </div>
    </aside>
</div>

<section class="ld-admin-panel ld-admin-panel-spaced">
    <div class="ld-admin-panel-heading">
        <div><span class="ld-admin-panel-kicker">PRÓXIMOS PASSOS</span><h2>Construção do curso</h2></div>
    </div>
    <div class="ld-admin-form-actions">
        <a class="ld-admin-btn" href="<?= e(url('/admin/cursos/' . (int) $course['id'] . '/estrutura')) ?>">1. Criar módulos, fases e aulas</a>
        <a class="ld-admin-btn is-ghost" href="<?= e(url('/admin/aulas')) ?>">2. Editar conteúdo das aulas</a>
        <a class="ld-admin-btn is-ghost" href="<?= e(url('/admin/questoes')) ?>">3. Cadastrar questões</a>
        <a class="ld-admin-btn is-ghost" href="<?= e(url('/admin/usuarios')) ?>">4. Matricular alunos</a>
    </div>
</section>
