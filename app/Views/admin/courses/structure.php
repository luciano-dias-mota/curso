<section class="ld-admin-page-head">
    <div>
        <span class="ld-admin-kicker">ESTRUTURA ACADÊMICA</span>
        <h1><?= e($course['title']) ?></h1>
        <p>Monte a árvore Curso → Módulo → Fase → Aula. O conteúdo interno das aulas continua no editor de aulas.</p>
    </div>
    <div class="ld-admin-head-actions">
        <a class="ld-admin-btn is-ghost" href="<?= e(url('/admin/cursos/' . (int) $course['id'])) ?>">← Curso</a>
        <a class="ld-admin-btn" href="<?= e(url('/admin/aulas')) ?>">Editor de aulas</a>
    </div>
</section>

<section class="ld-admin-panel">
    <div class="ld-admin-panel-heading">
        <div><span class="ld-admin-panel-kicker">NOVO MÓDULO</span><h2>Adicionar módulo</h2><p>Se deixar a posição vazia, ele será colocado ao final.</p></div>
    </div>
    <form method="post" action="<?= e(url('/admin/cursos/' . (int) $course['id'] . '/modulos')) ?>">
        <?= \App\Core\Csrf::input() ?>
        <div class="ld-admin-form-grid two">
            <label><span>Título</span><input class="ld-admin-input" name="title" maxlength="180" required></label>
            <label><span>Slug opcional</span><input class="ld-admin-input" name="slug" maxlength="190"></label>
            <label><span>Posição opcional</span><input class="ld-admin-input" type="number" name="position" min="1"></label>
            <label><span>Nota mínima</span><input class="ld-admin-input" type="number" name="required_score" min="0" max="100" step="0.01" value="<?= e((string) $course['required_score']) ?>"></label>
            <label><span>XP</span><input class="ld-admin-input" type="number" name="xp_reward" min="0" value="0"></label>
            <label><span>Status</span><select class="ld-admin-select" name="status"><option value="draft">Rascunho</option><option value="published">Publicado</option></select></label>
        </div>
        <label><span>Descrição</span><textarea class="ld-admin-input" name="description" rows="3"></textarea></label>
        <div class="ld-admin-form-actions"><button class="ld-admin-btn" type="submit">Adicionar módulo</button></div>
    </form>
</section>

<?php if (empty($modules)): ?>
    <section class="ld-admin-panel ld-admin-panel-spaced"><div class="ld-admin-empty">Este curso ainda não possui módulos.</div></section>
<?php endif; ?>

<?php foreach ($modules as $module): ?>
<section class="ld-admin-panel ld-admin-panel-spaced">
    <div class="ld-admin-panel-heading">
        <div>
            <span class="ld-admin-panel-kicker">MÓDULO <?= (int) $module['position'] ?></span>
            <h2><?= e($module['title']) ?></h2>
            <p><?= e($module['slug']) ?> • <?= e($module['status']) ?> • nota mínima <?= number_format((float) $module['required_score'], 1, ',', '.') ?>%</p>
        </div>
    </div>

    <div class="ld-admin-table-wrap">
        <table class="ld-admin-table">
            <thead><tr><th>Fase</th><th>Tipo</th><th>Obrigatória</th><th>Aulas</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (empty($module['phases'])): ?><tr><td colspan="5">Nenhuma fase neste módulo.</td></tr><?php endif; ?>
            <?php foreach ($module['phases'] as $phase): ?>
                <tr>
                    <td><strong><?= (int) $phase['position'] ?>. <?= e($phase['title']) ?></strong><small><?= e($phase['slug']) ?></small></td>
                    <td><?= e($phase['phase_type']) ?></td>
                    <td><?= (int) $phase['is_required'] === 1 ? 'Sim' : 'Não' ?></td>
                    <td>
                        <?php if (empty($phase['lessons'])): ?>
                            <small>Sem aulas</small>
                        <?php else: ?>
                            <?php foreach ($phase['lessons'] as $lesson): ?>
                                <div>
                                    <strong><?= (int) $lesson['position'] ?>. <?= e($lesson['title']) ?></strong>
                                    <small><?= (int) $lesson['estimated_minutes'] ?> min • <?= e($lesson['status']) ?><?= (int) $lesson['is_required'] === 0 ? ' • opcional' : '' ?> • <a href="<?= e(url('/admin/aulas/' . (int) $lesson['id'])) ?>">editar conteúdo</a></small>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td><?= e($phase['status']) ?></td>
                </tr>
                <tr>
                    <td colspan="5">
                        <details>
                            <summary>+ Adicionar aula em “<?= e($phase['title']) ?>”</summary>
                            <form method="post" action="<?= e(url('/admin/cursos/' . (int) $course['id'] . '/fases/' . (int) $phase['id'] . '/aulas')) ?>">
                                <?= \App\Core\Csrf::input() ?>
                                <div class="ld-admin-form-grid two">
                                    <label><span>Título</span><input class="ld-admin-input" name="title" maxlength="200" required></label>
                                    <label><span>Posição opcional</span><input class="ld-admin-input" type="number" name="position" min="1"></label>
                                    <label><span>Duração estimada</span><input class="ld-admin-input" type="number" name="estimated_minutes" min="1" value="10"></label>
                                    <label><span>XP</span><input class="ld-admin-input" type="number" name="xp_reward" min="0" value="10"></label>
                                    <label><span>Status</span><select class="ld-admin-select" name="status"><option value="draft">Rascunho</option><option value="published">Publicado</option></select></label>
                                    <label class="ld-admin-check"><input type="checkbox" name="is_required" value="1" checked><span><strong>Aula obrigatória</strong><small>Desmarque para conteúdo complementar.</small></span></label>
                                </div>
                                <label><span>Resumo</span><textarea class="ld-admin-input" name="summary" rows="2"></textarea></label>
                                <button class="ld-admin-btn is-small" type="submit">Criar aula</button>
                            </form>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <details>
        <summary>+ Adicionar fase neste módulo</summary>
        <form method="post" action="<?= e(url('/admin/cursos/' . (int) $course['id'] . '/modulos/' . (int) $module['id'] . '/fases')) ?>">
            <?= \App\Core\Csrf::input() ?>
            <div class="ld-admin-form-grid two">
                <label><span>Título</span><input class="ld-admin-input" name="title" maxlength="180" required></label>
                <label><span>Posição opcional</span><input class="ld-admin-input" type="number" name="position" min="1"></label>
                <label><span>Tipo</span><select class="ld-admin-select" name="phase_type"><option value="normal">Normal</option><option value="checkpoint">Checkpoint</option><option value="boss">Avaliação final</option><option value="review">Revisão</option></select></label>
                <label><span>Nota mínima</span><input class="ld-admin-input" type="number" name="required_score" min="0" max="100" step="0.01" value="<?= e((string) $module['required_score']) ?>"></label>
                <label><span>XP</span><input class="ld-admin-input" type="number" name="xp_reward" min="0" value="50"></label>
                <label><span>Status</span><select class="ld-admin-select" name="status"><option value="draft">Rascunho</option><option value="published">Publicado</option></select></label>
                <label class="ld-admin-check"><input type="checkbox" name="is_required" value="1" checked><span><strong>Fase obrigatória</strong><small>Desmarque para biblioteca/conteúdo opcional.</small></span></label>
            </div>
            <label><span>Descrição</span><textarea class="ld-admin-input" name="description" rows="2"></textarea></label>
            <button class="ld-admin-btn is-small" type="submit">Criar fase</button>
        </form>
    </details>
</section>
<?php endforeach; ?>
