<?php
use App\Core\Csrf;
use App\Core\Session;

$error = Session::pullFlash('error');
$total = (int) $attempt['question_limit'];
$remaining = (int) ($attempt['remaining_seconds'] ?? 0);
?>
<section class="simulation-attempt-page"
    data-simulation-attempt
    data-attempt-id="<?= (int) $attempt['id'] ?>"
    data-save-url="<?= e(url('/simulados/tentativa/' . $attempt['id'] . '/resposta')) ?>"
    data-remaining-seconds="<?= $remaining ?>">

    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

    <header class="simulation-attempt-header card">
        <div>
            <span class="simulation-eyebrow">SIMULADO LIVRE</span>
            <h1><?= e($attempt['module_title'] ?: 'Simulado geral') ?></h1>
            <p><?= e($attempt['course_title'] ?? '') ?> • Tentativa <?= (int) $attempt['attempt_number'] ?></p>
        </div>
        <div class="simulation-timer" data-simulation-timer><small>Tempo restante</small><strong>--:--</strong></div>
    </header>

    <div class="simulation-attempt-progress card">
        <div><span data-simulation-counter>Questão 1 de <?= $total ?></span><strong data-simulation-answered><?= (int) ($attempt['answered_count'] ?? 0) ?>/<?= $total ?> respondidas</strong></div>
        <div class="progress"><span data-simulation-progress style="width:<?= $total > 0 ? (100 / $total) : 0 ?>%"></span></div>
        <small data-simulation-save-status>Suas respostas são salvas automaticamente.</small>
    </div>

    <form action="<?= e(url('/simulados/tentativa/' . $attempt['id'] . '/finalizar')) ?>" method="post" data-simulation-finish-form>
        <?= Csrf::input() ?>

        <div class="simulation-question-layout">
            <div>
                <div class="simulation-question-list">
                    <?php foreach ($attempt['questions'] as $index => $question): ?>
                        <?php $selected = (int) ($question['selected_alternative_id'] ?? 0); ?>
                        <article class="card simulation-question-card <?= $index === 0 ? 'is-active' : '' ?>" data-simulation-question data-index="<?= $index ?>" data-question-id="<?= (int) $question['id'] ?>">
                            <div class="simulation-question-top">
                                <div><span>QUESTÃO <?= $index + 1 ?> DE <?= $total ?></span><small><?= e($question['module_title']) ?></small></div>
                                <span class="difficulty-chip <?= e((string) $question['difficulty']) ?>"><?= $question['difficulty'] === 'hard' ? 'DIFÍCIL' : 'INTERMEDIÁRIA' ?></span>
                            </div>

                            <h2><?= e($question['statement']) ?></h2>

                            <div class="simulation-options">
                                <?php foreach ($question['alternatives'] as $alternative): ?>
                                    <label class="simulation-option">
                                        <input type="radio" name="answers[<?= (int) $question['id'] ?>]" value="<?= (int) $alternative['id'] ?>" <?= $selected === (int) $alternative['id'] ? 'checked' : '' ?>>
                                        <span class="simulation-option-letter"><?= e($alternative['label'] ?: chr(64 + (int) $alternative['position'])) ?></span>
                                        <span><?= e($alternative['text']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="simulation-question-controls card">
                    <button class="secondary" type="button" data-simulation-prev>← Anterior</button>
                    <button class="primary" type="button" data-simulation-next>Próxima →</button>
                </div>

                <div class="simulation-submit card">
                    <div><strong>Terminou?</strong><span>Questões não respondidas serão contabilizadas como erro.</span></div>
                    <button class="primary" type="submit">Finalizar e corrigir</button>
                </div>
            </div>

            <aside class="simulation-navigator card">
                <div class="simulation-timer simulation-timer-side" data-simulation-timer-mirror><small>Tempo restante</small><strong>--:--</strong></div>
                <h3>Questões</h3>
                <div class="simulation-jump-grid">
                    <?php foreach ($attempt['questions'] as $index => $question): ?>
                        <?php $answered = !empty($question['selected_alternative_id']); ?>
                        <button class="simulation-jump <?= $index === 0 ? 'active' : ($answered ? 'answered' : '') ?>" type="button" data-simulation-jump="<?= $index ?>"><?= $index + 1 ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="simulation-nav-legend"><span><i class="current"></i>Atual</span><span><i class="answered"></i>Respondida</span><span><i></i>Não respondida</span></div>
            </aside>
        </div>
    </form>
</section>
