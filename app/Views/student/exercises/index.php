<?php
use App\Core\Csrf;
use App\Core\Session;

$error = Session::pullFlash('error');
$success = Session::pullFlash('success');
$courses = $courses ?? [];
$modules = $modules ?? [];
$phases = $phases ?? [];
$topics = $topics ?? [];
$taxonomyAvailable = (bool) ($taxonomyAvailable ?? false);
$history = $history ?? [];
$stats = $stats ?? [];
$allowedLimits = $allowedLimits ?? [5, 10, 15, 20, 30];

$popTopics = array_values(array_filter($topics, static fn (array $t): bool => ($t['topic_type'] ?? '') === 'pop'));
$processTopics = array_values(array_filter($topics, static fn (array $t): bool => ($t['topic_type'] ?? '') === 'process'));
$procedureTopics = array_values(array_filter($topics, static fn (array $t): bool => ($t['topic_type'] ?? '') === 'procedure'));
?>
<section class="study-tools-page exercise-center">
    <?php if ($error): ?><div class="study-alert is-error"><?= e((string) $error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="study-alert is-success"><?= e((string) $success) ?></div><?php endif; ?>

    <header class="study-tools-hero card">
        <div>
            <span class="study-tools-eyebrow">CENTRAL DE EXERCÍCIOS</span>
            <h1>Estude exatamente o conteúdo que precisa revisar</h1>
            <p>Escolha curso, disciplina e tema. No banco de POP, você também pode selecionar POP, processo e procedimento específico.</p>
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
                <span class="study-chip">Médio + difícil</span>
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
                                    <?= e($course['title']) ?> · <?= (int) ($course['question_count'] ?? 0) ?> questões
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
                                        data-course-id="<?= (int) $module['course_id'] ?>"
                                        data-is-pop="<?= !empty($module['is_pop']) ? '1' : '0' ?>">
                                    <?= e($module['title']) ?> · <?= (int) ($module['question_count'] ?? 0) ?> questões
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <span>Tema da trilha <em>(opcional)</em></span>
                        <select name="phase_id" data-scope-phase disabled>
                            <option value="">Todos os temas</option>
                            <?php foreach ($phases as $phase): ?>
                                <option value="<?= (int) $phase['id'] ?>"
                                        data-course-id="<?= (int) $phase['course_id'] ?>"
                                        data-module-id="<?= (int) $phase['module_id'] ?>">
                                    <?= e($phase['title']) ?> · <?= (int) ($phase['question_count'] ?? 0) ?> questões
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <span>Dificuldade</span>
                        <select name="difficulty">
                            <option value="all">Médio + difícil</option>
                            <option value="medium">Somente médio</option>
                            <option value="hard">Somente difícil</option>
                        </select>
                    </label>

                    <label class="exercise-pop-filter">
                        <span>POP <em>(opcional)</em></span>
                        <select name="pop_topic_id" data-pop-topic disabled>
                            <option value="">Todos os POPs</option>
                            <?php foreach ($popTopics as $topic): ?>
                                <option value="<?= (int) $topic['id'] ?>">
                                    <?= e($topic['title']) ?> · <?= (int) ($topic['question_count'] ?? 0) ?> questões
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="exercise-pop-filter">
                        <span>Processo <em>(opcional)</em></span>
                        <select name="process_topic_id" data-process-topic disabled>
                            <option value="">Todos os processos</option>
                            <?php foreach ($processTopics as $topic): ?>
                                <option value="<?= (int) $topic['id'] ?>"
                                        data-parent-id="<?= (int) ($topic['parent_id'] ?? 0) ?>">
                                    <?= e($topic['title']) ?> · <?= (int) ($topic['question_count'] ?? 0) ?> questões
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="exercise-pop-filter">
                        <span>Procedimento <em>(opcional)</em></span>
                        <select name="procedure_topic_id" data-procedure-topic disabled>
                            <option value="">Todos os procedimentos</option>
                            <?php foreach ($procedureTopics as $topic): ?>
                                <option value="<?= (int) $topic['id'] ?>"
                                        data-parent-id="<?= (int) ($topic['parent_id'] ?? 0) ?>">
                                    <?= e($topic['title']) ?> · <?= (int) ($topic['question_count'] ?? 0) ?> questões
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <?php if ($taxonomyAvailable): ?>
                        <div class="exercise-pop-note">
                            <span>🗂️</span>
                            <p><strong>Banco organizado por POP.</strong> Escolha a disciplina de Procedimentos Operacionais Padrão para liberar POP → Processo → Procedimento. O filtro usa o nível mais específico selecionado.</p>
                        </div>
                    <?php else: ?>
                        <div class="exercise-pop-note is-warning">
                            <span>⚠️</span>
                            <p>A taxonomia POP ainda não está instalada. Execute primeiro <code>database/aplicar_pop1_taxonomia_v1.php</code>.</p>
                        </div>
                    <?php endif; ?>

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
                        <p>Primeiro entram questões ainda não vistas. Depois de esgotar o filtro escolhido, o sistema reutiliza as menos vistas e mais antigas.</p>
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
                    $scope = $item['topic_title_snapshot']
                        ?: ($item['phase_title_snapshot'] ?: ($item['module_title_snapshot'] ?: $item['course_title_snapshot']));
                    $finished = ($item['status'] ?? '') === 'finished';
                    $difficultyLabel = match ((string) ($item['difficulty_filter'] ?? 'all')) {
                        'medium' => 'médio',
                        'hard' => 'difícil',
                        default => 'médio + difícil',
                    };
                    ?>
                    <article class="exercise-history-item">
                        <span class="exercise-history-score <?= $finished && (float) $item['percentage'] >= 70 ? 'is-good' : '' ?>">
                            <?= $finished ? number_format((float) $item['percentage'], 0, ',', '.') . '%' : '…' ?>
                        </span>
                        <div>
                            <strong><?= e((string) $scope) ?></strong>
                            <small><?= (int) $item['question_limit'] ?> questões · <?= e($difficultyLabel) ?> · <?= e(date('d/m/Y H:i', strtotime((string) $item['started_at']))) ?></small>
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
