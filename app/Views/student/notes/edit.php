<?php
use App\Core\Csrf;
use App\Core\Session;

$error = Session::pullFlash('error');
$note = $note ?? [];
$options = $options ?? ['courses' => [], 'modules' => [], 'phases' => []];
?>
<section class="study-tools-page note-edit-page">
    <?php if ($error): ?><div class="study-alert is-error"><?= e((string) $error) ?></div><?php endif; ?>

    <header class="study-page-head">
        <div>
            <a class="study-back-link" href="<?= e(url('/anotacoes')) ?>">← Minhas anotações</a>
            <span class="study-tools-eyebrow">EDITAR ANOTAÇÃO</span>
            <h1><?= e((string) ($note['title'] ?? 'Anotação')) ?></h1>
        </div>
    </header>

    <section class="study-panel card note-edit-panel">
        <form class="study-scope-form notes-form" action="<?= e(url('/anotacoes/' . (int) $note['id'])) ?>" method="post">
            <?= Csrf::input() ?>
            <input type="hidden" name="_method" value="PUT">

            <label>
                <span>Título</span>
                <input type="text" name="title" maxlength="160" required value="<?= e((string) $note['title']) ?>">
            </label>

            <div class="notes-scope-grid">
                <label>
                    <span>Curso</span>
                    <select name="course_id" data-scope-course data-selected="<?= (int) ($note['course_id'] ?? 0) ?>">
                        <option value="">Sem vínculo</option>
                        <?php foreach (($options['courses'] ?? []) as $course): ?>
                            <option value="<?= (int) $course['id'] ?>" <?= (int) ($note['course_id'] ?? 0) === (int) $course['id'] ? 'selected' : '' ?>><?= e($course['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Disciplina</span>
                    <select name="module_id" data-scope-module data-selected="<?= (int) ($note['module_id'] ?? 0) ?>">
                        <option value="">Todas / nenhuma</option>
                        <?php foreach (($options['modules'] ?? []) as $module): ?>
                            <option value="<?= (int) $module['id'] ?>" data-course-id="<?= (int) $module['course_id'] ?>" <?= (int) ($note['module_id'] ?? 0) === (int) $module['id'] ? 'selected' : '' ?>><?= e($module['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Tema</span>
                    <select name="phase_id" data-scope-phase data-selected="<?= (int) ($note['phase_id'] ?? 0) ?>">
                        <option value="">Todos / nenhum</option>
                        <?php foreach (($options['phases'] ?? []) as $phase): ?>
                            <option value="<?= (int) $phase['id'] ?>" data-course-id="<?= (int) $phase['course_id'] ?>" data-module-id="<?= (int) $phase['module_id'] ?>" <?= (int) ($note['phase_id'] ?? 0) === (int) $phase['id'] ? 'selected' : '' ?>><?= e($phase['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <label>
                <span>Anotação</span>
                <textarea name="content" maxlength="20000" required><?= e((string) $note['content']) ?></textarea>
            </label>

            <div class="note-edit-actions">
                <button class="primary" type="submit">Salvar alterações</button>
                <a class="secondary" href="<?= e(url('/anotacoes')) ?>">Cancelar</a>
            </div>
        </form>

        <div class="note-danger-zone">
            <div><strong>Excluir anotação</strong><small>Esta ação não pode ser desfeita.</small></div>
            <form action="<?= e(url('/anotacoes/' . (int) $note['id'])) ?>" method="post" onsubmit="return confirm('Excluir esta anotação definitivamente?')">
                <?= Csrf::input() ?>
                <input type="hidden" name="_method" value="DELETE">
                <button type="submit">Excluir</button>
            </form>
        </div>
    </section>
</section>
