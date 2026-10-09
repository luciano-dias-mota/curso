<?php
$isEdit = ($mode ?? 'create') === 'edit';
$question = $question ?? [];
$alternatives = $alternatives ?? [];
$usage = $usage ?? [];
$scopeLocked = (bool) ($scopeLocked ?? false);
$immutable = (bool) ($immutable ?? false);

if (!$isEdit && $alternatives === []) {
    for ($i = 1; $i <= 5; $i++) {
        $alternatives[] = [
            'id' => 0,
            'label' => chr(64 + $i),
            'text' => '',
            'is_correct' => 0,
            'position' => $i,
        ];
    }
}

$active = array_key_exists('active', $question) ? (int) $question['active'] === 1 : true;
$currentDifficulty = (string) ($question['difficulty'] ?? 'medium');
$currentModuleId = (int) ($question['module_id'] ?? 0);
$correctPosition = (int) ($question['correct_position'] ?? 0);
if ($isEdit) {
    foreach ($alternatives as $alternative) {
        if ((int) ($alternative['is_correct'] ?? 0) === 1) {
            $correctPosition = (int) ($alternative['position'] ?? 0);
            break;
        }
    }
}
?>

<section class="ld-admin-page-head ld-admin-page-head-compact">
    <div>
        <a class="ld-admin-back-link" href="<?= e(url('/admin/questoes')) ?>">← Banco de questões</a>
        <span class="ld-admin-kicker"><?= $isEdit ? 'EDIÇÃO' : 'CADASTRO' ?></span>
        <h1><?= $isEdit ? 'Editar questão #' . (int) ($question['id'] ?? 0) : 'Nova questão' ?></h1>
        <p><?= $isEdit ? 'Atualize o enunciado, metadados, alternativas e gabarito sem apagar a questão.' : 'Cadastre uma questão objetiva com quatro ou cinco alternativas e exatamente um gabarito.' ?></p>
    </div>
</section>

<?php if ($isEdit && $immutable): ?>
    <div class="ld-admin-notice">
        <strong>Questão protegida por histórico.</strong>
        <span>
            Ela possui <?= (int) ($usage['quiz_links'] ?? 0) ?> vínculo(s) com prova(s),
            <?= (int) ($usage['simulation_links'] ?? 0) ?> vínculo(s) com simulado(s) fixo(s) e
            <?= (int) ($usage['simulation_uses'] ?? 0) ?> uso(s) em tentativa(s) dinâmica(s).
            Para impedir que provas antigas mudem depois de realizadas, enunciado, alternativas, gabarito, dificuldade, fonte e status ficam imutáveis.
            Para corrigir ou substituir o conteúdo, cadastre uma nova questão.
        </span>
        <a class="ld-admin-btn is-primary is-small" href="<?= e(url('/admin/questoes/nova')) ?>">Criar nova questão</a>
    </div>
<?php endif; ?>

<section class="ld-admin-panel ld-admin-question-editor">
    <form
        method="post"
        action="<?= e($isEdit ? url('/admin/questoes/' . (int) $question['id']) : url('/admin/questoes')) ?>"
        data-question-editor
    >
        <?= \App\Core\Csrf::input() ?>
        <?php if ($isEdit): ?><input type="hidden" name="_method" value="PUT"><?php endif; ?>

        <fieldset class="ld-admin-question-fieldset" <?= $immutable ? 'disabled' : '' ?>>
        <div class="ld-admin-form-grid two ld-admin-question-meta-grid">
            <label>
                <span>Módulo</span>
                <?php if ($scopeLocked): ?>
                    <input type="hidden" name="module_id" value="<?= $currentModuleId ?>">
                    <select class="ld-admin-select" disabled>
                <?php else: ?>
                    <select class="ld-admin-select" name="module_id" required>
                <?php endif; ?>
                    <option value="">Selecione o módulo...</option>
                    <?php foreach ($modules as $module): ?>
                        <option value="<?= (int) $module['id'] ?>" <?= $currentModuleId === (int) $module['id'] ? 'selected' : '' ?>>
                            <?= e($module['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($scopeLocked): ?><small class="ld-admin-field-note">Escopo preservado porque a questão já possui uso registrado.</small><?php endif; ?>
            </label>

            <label>
                <span>Dificuldade</span>
                <select class="ld-admin-select" name="difficulty" required>
                    <option value="medium" <?= $currentDifficulty === 'medium' ? 'selected' : '' ?>>Intermediária</option>
                    <option value="hard" <?= $currentDifficulty === 'hard' ? 'selected' : '' ?>>Difícil</option>
                    <?php if ($currentDifficulty === 'easy'): ?>
                        <option value="easy" selected>Fácil</option>
                    <?php endif; ?>
                </select>
            </label>
        </div>

        <label class="ld-admin-field-full">
            <span>Fonte / referência</span>
            <input
                class="ld-admin-input"
                type="text"
                name="source_label"
                maxlength="255"
                value="<?= e((string) ($question['source_label'] ?? '')) ?>"
                placeholder="Ex.: Lei nº 13.869/2019, art. X • Material do curso"
            >
            <small class="ld-admin-field-note">Use uma referência curta que ajude o aluno a localizar a base normativa.</small>
        </label>

        <label class="ld-admin-field-full">
            <span>Enunciado</span>
            <textarea class="ld-admin-textarea ld-admin-question-statement" name="statement" rows="6" required><?= e((string) ($question['statement'] ?? '')) ?></textarea>
            <small class="ld-admin-field-note">Prefira uma contextualização breve e depois formule a pergunta. Não inclua pistas do gabarito.</small>
        </label>

        <label class="ld-admin-field-full">
            <span>Comentário / explicação do gabarito</span>
            <textarea class="ld-admin-textarea" name="explanation" rows="4" placeholder="Explique por que a alternativa correta é a adequada."><?= e((string) ($question['explanation'] ?? '')) ?></textarea>
        </label>

        <div class="ld-admin-form-section ld-admin-alternatives-section">
            <div class="ld-admin-panel-heading">
                <div>
                    <span class="ld-admin-panel-kicker">ALTERNATIVAS</span>
                    <h2>Opções e gabarito</h2>
                    <p><?= $isEdit ? 'Edite os textos e marque o gabarito. A quantidade atual é preservada.' : 'A, B, C e D são obrigatórias. E é opcional.' ?></p>
                </div>
            </div>

            <div class="ld-admin-alternatives-list">
                <?php foreach ($alternatives as $index => $alternative): ?>
                    <?php
                    $position = $index + 1;
                    $label = chr(64 + $position);
                    $alternativeId = (int) ($alternative['id'] ?? 0);
                    $checked = $isEdit
                        ? (int) ($alternative['is_correct'] ?? 0) === 1
                        : $correctPosition === $position;
                    ?>
                    <div class="ld-admin-alternative-row">
                        <div class="ld-admin-alt-label"><?= e($label) ?></div>

                        <?php if ($isEdit): ?>
                            <input type="hidden" name="alternative_id[]" value="<?= $alternativeId ?>">
                        <?php endif; ?>

                        <textarea
                            class="ld-admin-textarea ld-admin-alt-text"
                            name="alternative_text[]"
                            rows="2"
                            <?= (!$isEdit && $position === 5) ? '' : 'required' ?>
                            placeholder="<?= !$isEdit && $position === 5 ? 'Alternativa E (opcional)' : 'Texto da alternativa ' . $label ?>"
                        ><?= e((string) ($alternative['text'] ?? '')) ?></textarea>

                        <label class="ld-admin-correct-choice">
                            <input
                                type="radio"
                                name="<?= $isEdit ? 'correct_alternative_id' : 'correct_position' ?>"
                                value="<?= $isEdit ? $alternativeId : $position ?>"
                                <?= $checked ? 'checked' : '' ?>
                                required
                            >
                            <span>Gabarito</span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="ld-admin-question-footer">
            <label class="ld-admin-toggle-row">
                <input type="hidden" name="active" value="0">
                <input type="checkbox" name="active" value="1" <?= $active ? 'checked' : '' ?>>
                <span><strong>Questão ativa</strong><small>Quando ativa, poderá ser selecionada pelo simulador conforme o escopo.</small></span>
            </label>

            <div class="ld-admin-form-actions">
                <a class="ld-admin-btn is-ghost" href="<?= e(url('/admin/questoes')) ?>">Cancelar</a>
                <button class="ld-admin-btn is-primary" type="submit"><?= $isEdit ? 'Salvar alterações' : 'Cadastrar questão' ?></button>
            </div>
        </div>
        </fieldset>
    </form>
</section>
