<?php
use App\Core\Csrf;

$session = $session ?? [];
$current = $current ?? [];
$total = max(1, (int) ($session['question_limit'] ?? 1));
$answered = (int) ($answered ?? 0);
$position = (int) ($current['position'] ?? ($answered + 1));
$progress = min(100, max(0, (($position - 1) / $total) * 100));
$scope = $session['phase_title_snapshot'] ?: ($session['module_title_snapshot'] ?: $session['course_title_snapshot']);
?>
<section class="study-tools-page exercise-attempt-page">
    <header class="exercise-attempt-header card">
        <div>
            <a class="study-back-link" href="<?= e(url('/exercicios')) ?>">← Central de exercícios</a>
            <span class="study-tools-eyebrow">FIXAÇÃO · NÍVEL MÉDIO</span>
            <h1><?= e((string) $scope) ?></h1>
            <p>Questão <?= $position ?> de <?= $total ?></p>
        </div>
        <div class="exercise-progress-box">
            <strong><?= $position ?>/<?= $total ?></strong>
            <div class="exercise-progress"><span style="width: <?= number_format($progress, 2, '.', '') ?>%"></span></div>
        </div>
    </header>

    <article class="exercise-question-card card">
        <div class="exercise-question-topline">
            <span>Questão <?= $position ?></span>
            <span class="study-chip">Média</span>
        </div>
        <h2><?= e((string) ($current['statement_snapshot'] ?? '')) ?></h2>

        <form data-exercise-answer-form
              action="<?= e(url('/exercicios/sessao/' . (int) $session['id'] . '/resposta')) ?>"
              data-next-url="<?= e(url('/exercicios/sessao/' . (int) $session['id'])) ?>"
              data-result-url="<?= e(url('/exercicios/sessao/' . (int) $session['id'] . '/resultado')) ?>">
            <input type="hidden" name="session_question_id" value="<?= (int) ($current['id'] ?? 0) ?>">
            <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">

            <div class="exercise-alternatives">
                <?php foreach (($current['alternatives'] ?? []) as $alternative): ?>
                    <label class="exercise-alternative">
                        <input type="radio" name="alternative_id" value="<?= (int) $alternative['id'] ?>">
                        <span class="exercise-alt-label"><?= e((string) $alternative['label']) ?></span>
                        <span><?= e((string) $alternative['text']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="exercise-answer-actions">
                <button class="primary" type="submit" data-exercise-confirm>Confirmar resposta</button>
            </div>
        </form>

        <div class="exercise-feedback" data-exercise-feedback hidden>
            <div class="exercise-feedback-head">
                <span data-exercise-feedback-icon></span>
                <div><strong data-exercise-feedback-title></strong><small data-exercise-feedback-correct></small></div>
            </div>
            <p data-exercise-feedback-explanation></p>
            <small class="exercise-feedback-source" data-exercise-feedback-source></small>
            <a class="primary" href="#" data-exercise-next>Próxima questão →</a>
        </div>
    </article>
</section>
