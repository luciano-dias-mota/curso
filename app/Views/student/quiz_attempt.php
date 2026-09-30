<?php
use App\Core\Csrf;
use App\Core\Session;

$error = Session::pullFlash('error');
$total = (int) $attempt['question_limit'];
?>
<section class="quiz-page quiz-attempt-page">
    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <header class="quiz-attempt-header card">
        <div>
            <span class="quiz-kind"><?= e($attempt['type_label']) ?></span>
            <h1><?= e($attempt['quiz_title']) ?></h1>
            <p class="muted">
                Tentativa <?= (int) $attempt['attempt_number'] ?>
                • responda todas as <?= $total ?> questões.
            </p>
        </div>

        <div class="quiz-score-target">
            <strong><?= (int) $attempt['required_correct'] ?>/<?= $total ?></strong>
            <span>para aprovar</span>
        </div>
    </header>

    <form
        action="<?= e(url('/prova/tentativa/' . $attempt['id'] . '/finalizar')) ?>"
        method="post"
    >
        <?= Csrf::input() ?>

        <div class="quiz-question-list">
            <?php foreach ($attempt['questions'] as $index => $question): ?>
                <article class="card quiz-question-card">
                    <div class="quiz-question-number">
                        QUESTÃO <?= $index + 1 ?> DE <?= $total ?>
                    </div>

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
                                <span class="quiz-option-letter">
                                    <?= e($alternative['label'] ?: chr(64 + (int) $alternative['position'])) ?>
                                </span>
                                <span class="quiz-option-text"><?= e($alternative['text']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="card quiz-submit-bar">
            <div>
                <strong>Responda tudo antes de finalizar.</strong>
                <span class="muted">A correção é feita no servidor.</span>
            </div>
            <button class="primary" type="submit">Corrigir e finalizar</button>
        </div>
    </form>
</section>
