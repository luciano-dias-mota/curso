<?php
use App\Core\Csrf;
use App\Core\Session;

$error = Session::pullFlash('error');
?>
<section class="quiz-page quiz-attempt-page">
    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <header class="quiz-attempt-header card">
        <div>
            <span class="eyebrow">PROVA DA FASE</span>
            <h1><?= e($attempt['phase_title']) ?></h1>
            <p class="muted">Tentativa <?= (int) $attempt['attempt_number'] ?> • responda todas as 5 questões.</p>
        </div>
        <div class="quiz-score-target">
            <strong>4/5</strong>
            <span>para aprovar</span>
        </div>
    </header>

    <form action="<?= e(url('/prova/tentativa/' . $attempt['id'] . '/finalizar')) ?>" method="post" id="phaseExamForm">
        <?= Csrf::input() ?>

        <div class="quiz-question-list">
            <?php foreach ($attempt['questions'] as $index => $question): ?>
                <article class="card quiz-question-card" data-question>
                    <div class="quiz-question-number">QUESTÃO <?= (int) ($index + 1) ?> DE 5</div>
                    <h2><?= e($question['statement']) ?></h2>

                    <div class="quiz-alternatives">
                        <?php foreach ($question['alternatives'] as $alternative): ?>
                            <label class="quiz-option">
                                <input
                                    type="radio"
                                    name="answers[<?= (int) $question['id'] ?>]"
                                    value="<?= (int) $alternative['id'] ?>"
                                    required
                                >
                                <span class="quiz-option-letter"><?= e($alternative['label'] ?: chr(64 + (int) $alternative['position'])) ?></span>
                                <span class="quiz-option-text"><?= e($alternative['text']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="card quiz-submit-bar">
            <div>
                <strong>Revise suas respostas antes de enviar.</strong>
                <span class="muted">Após finalizar, a tentativa será corrigida pelo servidor.</span>
            </div>
            <button class="primary" type="submit">Finalizar prova</button>
        </div>
    </form>
</section>
