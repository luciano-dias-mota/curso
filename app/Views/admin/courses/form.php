<?php
$action = $isEdit
    ? url('/admin/cursos/' . (int) $course['id'] . '/atualizar')
    : url('/admin/cursos');
?>
<section class="ld-admin-page-head">
    <div>
        <span class="ld-admin-kicker">CURSOS</span>
        <h1><?= $isEdit ? 'Editar curso' : 'Novo curso' ?></h1>
        <p>O cadastro é genérico: a metodologia e os recursos podem variar de um curso para outro.</p>
    </div>
    <a class="ld-admin-btn is-ghost" href="<?= e($isEdit ? url('/admin/cursos/' . (int) $course['id']) : url('/admin/cursos')) ?>">← Voltar</a>
</section>

<section class="ld-admin-panel ld-admin-form-panel">
    <form method="post" action="<?= e($action) ?>">
        <?= \App\Core\Csrf::input() ?>

        <div class="ld-admin-panel-heading">
            <div><span class="ld-admin-panel-kicker">IDENTIFICAÇÃO</span><h2>Dados do curso</h2></div>
        </div>

        <div class="ld-admin-form-grid two">
            <label>
                <span>Título</span>
                <input class="ld-admin-input" type="text" name="title" maxlength="180" required value="<?= e($course['title'] ?? '') ?>">
            </label>
            <label>
                <span>Slug</span>
                <input class="ld-admin-input" type="text" name="slug" maxlength="190" value="<?= e($course['slug'] ?? '') ?>" placeholder="gerado automaticamente se ficar vazio">
            </label>
            <label>
                <span>Dificuldade</span>
                <select class="ld-admin-select" name="difficulty">
                    <?php foreach (['beginner'=>'Iniciante','intermediate'=>'Intermediário','advanced'=>'Avançado'] as $value=>$label): ?>
                        <option value="<?= e($value) ?>" <?= ($course['difficulty'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Status</span>
                <select class="ld-admin-select" name="status">
                    <?php foreach (['draft'=>'Rascunho','published'=>'Publicado','archived'=>'Arquivado'] as $value=>$label): ?>
                        <option value="<?= e($value) ?>" <?= ($course['status'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Nota mínima (%)</span>
                <input class="ld-admin-input" type="number" name="required_score" min="0" max="100" step="0.01" value="<?= e((string) ($course['required_score'] ?? 70)) ?>">
            </label>
            <label>
                <span>Posição no catálogo</span>
                <input class="ld-admin-input" type="number" name="position" min="1" value="<?= (int) ($course['position'] ?? 1) ?>">
            </label>
            <label>
                <span>XP ao concluir o curso</span>
                <input class="ld-admin-input" type="number" name="xp_reward" min="0" value="<?= (int) ($course['xp_reward'] ?? 0) ?>">
            </label>
            <label>
                <span>Imagem de capa (caminho/URL interno)</span>
                <input class="ld-admin-input" type="text" name="cover_image" maxlength="255" value="<?= e($course['cover_image'] ?? '') ?>">
            </label>
        </div>

        <label>
            <span>Descrição curta</span>
            <textarea class="ld-admin-input" name="short_description" maxlength="500" rows="3"><?= e($course['short_description'] ?? '') ?></textarea>
        </label>
        <label>
            <span>Descrição completa</span>
            <textarea class="ld-admin-input" name="description" rows="7"><?= e($course['description'] ?? '') ?></textarea>
        </label>

        <div class="ld-admin-divider"></div>
        <div class="ld-admin-panel-heading compact">
            <div>
                <span class="ld-admin-panel-kicker">METODOLOGIA</span>
                <h2>Configuração independente</h2>
                <p>Estas opções deixam a base preparada para metodologias diferentes entre cursos. A lógica específica pode ser conectada a elas sem alterar o cadastro.</p>
            </div>
        </div>

        <div class="ld-admin-form-grid two">
            <label>
                <span>Acesso entre módulos</span>
                <select class="ld-admin-select" name="module_access_mode">
                    <option value="free" <?= ($settings['module_access_mode'] ?? 'free') === 'free' ? 'selected' : '' ?>>Livre entre módulos</option>
                    <option value="sequential" <?= ($settings['module_access_mode'] ?? '') === 'sequential' ? 'selected' : '' ?>>Sequencial</option>
                </select>
            </label>
            <label>
                <span>Acesso entre aulas</span>
                <select class="ld-admin-select" name="lesson_access_mode">
                    <option value="sequential" <?= ($settings['lesson_access_mode'] ?? 'sequential') === 'sequential' ? 'selected' : '' ?>>Sequencial</option>
                    <option value="free" <?= ($settings['lesson_access_mode'] ?? '') === 'free' ? 'selected' : '' ?>>Livre</option>
                </select>
            </label>
        </div>

        <div class="ld-admin-checkbox-grid">
            <?php
            $features = [
                'exercises_enabled' => ['Exercícios', 'Banco de questões e exercícios de fixação'],
                'simulations_enabled' => ['Simulados', 'Simulados livres e avaliações'],
                'notes_enabled' => ['Anotações', 'Caderno de estudo do aluno'],
                'certificate_enabled' => ['Certificado', 'Estrutura reservada para emissão de certificado'],
                'lab_enabled' => ['Laboratório', 'Recurso prático futuro, como laboratório SQL'],
            ];
            foreach ($features as $key => [$label, $help]):
            ?>
                <label class="ld-admin-check">
                    <input type="checkbox" name="<?= e($key) ?>" value="1" <?= ($settings[$key] ?? '0') === '1' ? 'checked' : '' ?>>
                    <span><strong><?= e($label) ?></strong><small><?= e($help) ?></small></span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="ld-admin-form-actions">
            <button class="ld-admin-primary-action" type="submit"><?= $isEdit ? 'Salvar alterações' : 'Criar curso' ?></button>
            <a class="ld-admin-btn is-ghost" href="<?= e($isEdit ? url('/admin/cursos/' . (int) $course['id']) : url('/admin/cursos')) ?>">Cancelar</a>
        </div>
    </form>
</section>
