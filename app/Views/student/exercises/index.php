<?php
use App\Core\Csrf;
use App\Core\Session;

$error = Session::pullFlash('error');
$success = Session::pullFlash('success');
$courses = $courses ?? [];
$modules = $modules ?? [];
$phases = $phases ?? [];
$history = $history ?? [];
$stats = $stats ?? [];
$allowedLimits = $allowedLimits ?? [5, 10, 15, 20, 30];
?>
<section class="study-tools-page exercise-center">
    <?php if ($error): ?><div class="study-alert is-error"><?= e((string) $error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="study-alert is-success"><?= e((string) $success) ?></div><?php endif; ?>

    <header class="study-tools-hero card">
        <div>
            <span class="study-tools-eyebrow">CENTRAL DE EXERCÍCIOS</span>
            <h1>Fixe a base antes de encarar a prova</h1>
            <p>Gere listas com questões de dificuldade média, escolhendo curso, disciplina e tema. O sistema prioriza perguntas que você ainda não viu.</p>
        </div>
        <div class="study-tools-hero-badge" aria-hidden="true">🧠</div>
    </header>

    <div class="study-tools-stat-grid">
        <article class="study-tools-stat card"><span>📝</span><div><small>Listas concluídas</small><strong><?= (int) ($stats['total'] ?? 0) ?></strong></div></article>
        <article class="study-tools-stat card"><span>🎯</span><div><small>Média</small><strong><?= number_format((float) ($stats['average'] ?? 0), 1, ',', '.') ?>%</strong></div></article>
        <article class="study-tools-stat card"><span>🏆</span><div><small>Melhor resultado</small><strong><?= number_format((float) ($stats['best'] ?? 0), 1, ',', '.') ?>%</strong></div></article>
        <article class="study-tools-stat card"><span>✅</span><div><small>Acertos acumulados</small><strong><?= (int) ($stats['correct_answers'] ?? 0) ?></strong></div></article>
    </div>

    <div class="exercise-center-grid">
        <section class="study-panel card exercise-generator-panel">
            <div class="study-panel-heading">
                <div><span>GERADOR</span><h2>Monte seu exercício</h2></div>
                <span class="study-chip">Somente nível médio</span>
            </div>

            <?php if (empty($courses)): ?>
                <div class="study-empty">Você precisa de uma matrícula ativa para gerar exercícios.</div>
            <?php else: ?>
                <form class="study-scope-form exercise-generator-form" action="<?= e(url('/exercicios/gerar')) ?>" method="post">
                    <?= Csrf::input() ?>

                    <label>
                        <span>Curso</span>
                        <select name="course_id" required data-scope-course>
                            <option value="">Selecione o curso</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?= (int) $course['id'] ?>">
                                    <?= e($course['title']) ?> · <?= (int) ($course['medium_questions'] ?? 0) ?> questões
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <span>Disciplina</span>
                        <select name="module_id" data-scope-module disabled>
                            <option value="">Todas as disciplinas</option>
                            <?php foreach ($modules as $module): ?>
                                <option value="<?= (int) $module['id'] ?>"
                                        data-course-id="<?= (int) $module['course_id'] ?>">
                                    <?= e($module['title']) ?> · <?= (int) ($module['medium_questions'] ?? 0) ?> questões
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <span>Tema</span>
                        <select name="phase_id" data-scope-phase disabled>
                            <option value="">Todos os temas</option>
                            <?php foreach ($phases as $phase): ?>
                                <option value="<?= (int) $phase['id'] ?>"
                                        data-course-id="<?= (int) $phase['course_id'] ?>"
                                        data-module-id="<?= (int) $phase['module_id'] ?>">
                                    <?= e($phase['title']) ?> · <?= (int) ($phase['medium_questions'] ?? 0) ?> questões
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <fieldset class="exercise-limit-fieldset">
                        <legend>Quantidade</legend>
                        <div class="exercise-limit-grid">
                            <?php foreach ($allowedLimits as $limit): ?>
                                <label class="exercise-limit-option">
                                    <input type="radio" name="question_limit" value="<?= (int) $limit ?>" <?= (int) $limit === 10 ? 'checked' : '' ?>>
                                    <span><strong><?= (int) $limit ?></strong><small>questões</small></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <div class="exercise-generator-note">
                        <span>🔀</span>
                        <p>Primeiro entram questões ainda não vistas. Quando o conteúdo disponível for esgotado, o sistema reutiliza as menos vistas e mais antigas.</p>
                    </div>

                    <button class="primary exercise-generate-button" type="submit">Gerar exercícios →</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="study-panel card exercise-history-panel">
            <div class="study-panel-heading">
                <div><span>HISTÓRICO</span><h2>Exercícios recentes</h2></div>
            </div>

            <div class="exercise-history-list">
                <?php if (empty($history)): ?>
                    <div class="study-empty">Seus resultados aparecerão aqui após a primeira lista.</div>
                <?php endif; ?>

                <?php foreach ($history as $item): ?>
                    <?php
                    $scope = $item['phase_title_snapshot'] ?: ($item['module_title_snapshot'] ?: $item['course_title_snapshot']);
                    $finished = ($item['status'] ?? '') === 'finished';
                    ?>
                    <article class="exercise-history-item">
                        <span class="exercise-history-score <?= $finished && (float) $item['percentage'] >= 70 ? 'is-good' : '' ?>">
                            <?= $finished ? number_format((float) $item['percentage'], 0, ',', '.') . '%' : '…' ?>
                        </span>
                        <div>
                            <strong><?= e((string) $scope) ?></strong>
                            <small><?= (int) $item['question_limit'] ?> questões · <?= e(date('d/m/Y H:i', strtotime((string) $item['started_at']))) ?></small>
                        </div>
                        <?php if ($finished): ?>
                            <a href="<?= e(url('/exercicios/sessao/' . (int) $item['id'] . '/resultado')) ?>">Ver resultado</a>
                        <?php else: ?>
                            <a href="<?= e(url('/exercicios/sessao/' . (int) $item['id'])) ?>">Continuar</a>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</section>
