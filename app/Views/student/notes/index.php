<?php
use App\Core\Csrf;
use App\Core\Session;

$error = Session::pullFlash('error');
$success = Session::pullFlash('success');
$notes = $notes ?? [];
$options = $options ?? ['courses' => [], 'modules' => [], 'phases' => []];
$stats = $stats ?? [];
$filters = $filters ?? ['search' => '', 'courseId' => 0, 'moduleId' => 0];
?>
<section class="study-tools-page notes-center">
    <?php if ($error): ?><div class="study-alert is-error"><?= e((string) $error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="study-alert is-success"><?= e((string) $success) ?></div><?php endif; ?>

    <header class="study-tools-hero notes-hero card">
        <div>
            <span class="study-tools-eyebrow">CADERNO DE ESTUDOS</span>
            <h1>Suas anotações, organizadas na plataforma</h1>
            <p>Registre resumos, dúvidas, macetes, prazos, artigos e pontos de revisão. Você pode vincular cada anotação a um curso, disciplina e tema.</p>
        </div>
        <div class="study-tools-hero-badge" aria-hidden="true">✍️</div>
    </header>

    <div class="notes-stat-grid">
        <article class="study-tools-stat card"><span>🗒️</span><div><small>Anotações</small><strong><?= (int) ($stats['total'] ?? 0) ?></strong></div></article>
        <article class="study-tools-stat card"><span>📌</span><div><small>Fixadas</small><strong><?= (int) ($stats['pinned'] ?? 0) ?></strong></div></article>
        <article class="study-tools-stat card notes-last-update"><span>🕒</span><div><small>Última atualização</small><strong><?= !empty($stats['last_update']) ? e(date('d/m/Y', strtotime((string) $stats['last_update']))) : '—' ?></strong></div></article>
    </div>

    <div class="notes-layout">
        <section class="study-panel card notes-editor-panel">
            <div class="study-panel-heading"><div><span>NOVA ANOTAÇÃO</span><h2>Registre algo importante</h2></div></div>

            <form class="study-scope-form notes-form" action="<?= e(url('/anotacoes')) ?>" method="post">
                <?= Csrf::input() ?>

                <label class="notes-title-field">
                    <span>Título</span>
                    <input type="text" name="title" maxlength="160" required placeholder="Ex.: Prazos do IPM">
                </label>

                <div class="notes-scope-grid">
                    <label>
                        <span>Curso <em>opcional</em></span>
                        <select name="course_id" data-scope-course>
                            <option value="">Sem vínculo</option>
                            <?php foreach (($options['courses'] ?? []) as $course): ?>
                                <option value="<?= (int) $course['id'] ?>"><?= e($course['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Disciplina</span>
                        <select name="module_id" data-scope-module disabled>
                            <option value="">Todas / nenhuma</option>
                            <?php foreach (($options['modules'] ?? []) as $module): ?>
                                <option value="<?= (int) $module['id'] ?>" data-course-id="<?= (int) $module['course_id'] ?>"><?= e($module['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Tema</span>
                        <select name="phase_id" data-scope-phase disabled>
                            <option value="">Todos / nenhum</option>
                            <?php foreach (($options['phases'] ?? []) as $phase): ?>
                                <option value="<?= (int) $phase['id'] ?>" data-course-id="<?= (int) $phase['course_id'] ?>" data-module-id="<?= (int) $phase['module_id'] ?>"><?= e($phase['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <label>
                    <span>Anotação</span>
                    <textarea name="content" maxlength="20000" required placeholder="Escreva seu resumo, dúvida, macete, artigo, prazo ou ponto de revisão..."></textarea>
                </label>

                <button class="primary" type="submit">Salvar anotação</button>
            </form>
        </section>

        <section class="study-panel card notes-library-panel">
            <div class="study-panel-heading"><div><span>BIBLIOTECA</span><h2>Minhas anotações</h2></div></div>

            <form class="notes-filter-form" action="<?= e(url('/anotacoes')) ?>" method="get">
                <input type="search" name="q" value="<?= e((string) ($filters['search'] ?? '')) ?>" placeholder="Buscar nas anotações...">
                <select name="course_id">
                    <option value="">Todos os cursos</option>
                    <?php foreach (($options['courses'] ?? []) as $course): ?>
                        <option value="<?= (int) $course['id'] ?>" <?= (int) ($filters['courseId'] ?? 0) === (int) $course['id'] ? 'selected' : '' ?>><?= e($course['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="secondary" type="submit">Filtrar</button>
            </form>

            <div class="notes-list">
                <?php if (empty($notes)): ?><div class="study-empty">Nenhuma anotação encontrada. Crie a primeira ao lado.</div><?php endif; ?>

                <?php foreach ($notes as $note): ?>
                    <article class="note-card <?= (int) $note['pinned'] === 1 ? 'is-pinned' : '' ?>">
                        <div class="note-card-top">
                            <div>
                                <span class="note-card-kicker"><?= (int) $note['pinned'] === 1 ? '📌 FIXADA' : 'ANOTAÇÃO' ?></span>
                                <h3><?= e((string) $note['title']) ?></h3>
                            </div>
                            <div class="note-card-actions">
                                <form action="<?= e(url('/anotacoes/' . (int) $note['id'] . '/fixar')) ?>" method="post">
                                    <?= Csrf::input() ?>
                                    <button type="submit" title="<?= (int) $note['pinned'] === 1 ? 'Desafixar' : 'Fixar' ?>"><?= (int) $note['pinned'] === 1 ? '📍' : '📌' ?></button>
                                </form>
                                <a href="<?= e(url('/anotacoes/' . (int) $note['id'] . '/editar')) ?>" title="Editar">✏️</a>
                            </div>
                        </div>

                        <p class="note-card-content"><?= nl2br(e((string) $note['content'])) ?></p>

                        <div class="note-card-meta">
                            <?php if (!empty($note['course_title'])): ?><span><?= e((string) $note['course_title']) ?></span><?php endif; ?>
                            <?php if (!empty($note['module_title'])): ?><span><?= e((string) $note['module_title']) ?></span><?php endif; ?>
                            <?php if (!empty($note['phase_title'])): ?><span><?= e((string) $note['phase_title']) ?></span><?php endif; ?>
                            <small>Atualizada em <?= e(date('d/m/Y H:i', strtotime((string) $note['updated_at']))) ?></small>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</section>
