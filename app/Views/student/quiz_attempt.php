<?php
use App\Core\Csrf;
use App\Core\Session;

$error = Session::pullFlash('error');
$total = (int) $attempt['question_limit'];
?>
<section class="quiz-page quiz-attempt-page" data-quiz-attempt>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

    <header class="quiz-attempt-header card">
        <div>
            <span class="quiz-kind"><?= e($attempt['type_label']) ?></span>
            <h1><?= e($attempt['quiz_title']) ?></h1>
            <p class="muted">Tentativa <?= (int) $attempt['attempt_number'] ?> • responda todas as <?= $total ?> questões.</p>
        </div>
        <div class="quiz-score-target"><strong><?= (int) $attempt['required_correct'] ?>/<?= $total ?></strong><span>para aprovar</span></div>
    </header>

    <div class="quiz-attempt-progress card" aria-live="polite">
        <div><span>PROGRESSO DA TENTATIVA</span><strong data-quiz-counter>Questão 1 de <?= $total ?></strong></div>
        <div class="progress"><span data-quiz-progress-bar style="width:<?= $total > 0 ? (100 / $total) : 0 ?>%"></span></div>
    </div>

    <form action="<?= e(url('/prova/tentativa/' . $attempt['id'] . '/finalizar')) ?>" method="post" data-quiz-form>
        <?= Csrf::input() ?>

        <div class="quiz-reference-layout">
            <div>
                <div class="quiz-question-list">
                    <?php foreach ($attempt['questions'] as $index => $question): ?>
                        <article class="card quiz-question-card <?= $index === 0 ? 'is-active' : '' ?>" data-quiz-question data-index="<?= $index ?>">
                            <div class="quiz-question-topline">
                                <div class="quiz-question-number">QUESTÃO <?= $index + 1 ?> DE <?= $total ?></div>
                                <?php if (!empty($question['difficulty'])): ?><span class="difficulty-chip"><?= e(mb_strtoupper((string) $question['difficulty'])) ?></span><?php endif; ?>
                            </div>

                            <h2><?= e($question['statement']) ?></h2>

                            <div class="quiz-alternatives">
                                <?php foreach ($question['alternatives'] as $alternative): ?>
                                    <label class="quiz-option">
                                        <input type="radio" name="answers[<?= (int) $question['id'] ?>]" value="<?= (int) $alternative['id'] ?>" required>
                                        <span class="quiz-option-letter"><?= e($alternative['label'] ?: chr(64 + (int) $alternative['position'])) ?></span>
                                        <span class="quiz-option-text"><?= e($alternative['text']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="quiz-mobile-controls card" data-quiz-mobile-controls>
                    <button class="secondary" type="button" data-quiz-prev>← Anterior</button>
                    <button class="primary" type="button" data-quiz-next>Próxima →</button>
                </div>

                <div class="card quiz-submit-bar" data-quiz-submit-bar>
                    <div><strong>Revise antes de finalizar.</strong><span class="muted">A correção é feita no servidor.</span></div>
                    <button class="primary" type="submit">Corrigir e finalizar</button>
                </div>
            </div>

            <aside class="quiz-question-nav" aria-label="Navegação entre questões">
                <h3>Questões</h3>
                <div class="quiz-jump-grid">
                    <?php foreach ($attempt['questions'] as $index => $question): ?>
                        <button class="quiz-jump <?= $index === 0 ? 'active' : '' ?>" type="button" data-quiz-jump="<?= $index ?>"><?= $index + 1 ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="quiz-question-nav-legend">
                    <span class="current"><i></i>Atual</span>
                    <span class="answered"><i></i>Respondida</span>
                    <span><i></i>Não respondida</span>
                </div>
            </aside>
        </div>
    </form>
</section>
