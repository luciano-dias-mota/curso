<?php
$passed = (int) $attempt['passed'] === 1;
$correct = (int) round((float) $attempt['score']);
$answers = $attempt['answers'] ?? [];
?>
<section class="quiz-page">
    <header class="quiz-result-hero card <?= $passed ? 'passed' : 'failed' ?>">
        <div class="quiz-result-icon"><?= $passed ? '🏆' : '🎯' ?></div>
        <div>
            <span class="eyebrow">RESULTADO DO CHECKPOINT</span>
            <h1><?= $passed ? 'Fase vencida!' : 'Ainda não foi desta vez' ?></h1>
            <p>
                Você acertou <strong><?= $correct ?> de 5 questões</strong>
                (<?= number_format((float) $attempt['percentage'], 0) ?>%).
            </p>
            <?php if ($passed): ?>
                <p>A próxima fase foi desbloqueada.</p>
            <?php else: ?>
                <p>Revise o conteúdo e tente novamente. É necessário acertar pelo menos 4 questões.</p>
            <?php endif; ?>
        </div>
        <div class="quiz-result-score">
            <strong><?= $correct ?>/5</strong>
            <span><?= $passed ? 'APROVADO' : 'REVISAR' ?></span>
        </div>
    </header>

    <div class="quiz-review-list">
        <?php foreach ($attempt['questions'] as $index => $question): ?>
            <?php
            $answer = $answers[(int) $question['id']] ?? null;
            $isCorrect = !empty($answer['is_correct']);
            $selectedId = (int) ($answer['alternative_id'] ?? 0);
            ?>
            <article class="card quiz-review-card <?= $isCorrect ? 'correct' : 'wrong' ?>">
                <div class="quiz-review-heading">
                    <span><?= $isCorrect ? '✓' : '✕' ?></span>
                    <strong>Questão <?= (int) ($index + 1) ?></strong>
                    <small><?= $isCorrect ? 'ACERTOU' : 'ERROU' ?></small>
                </div>

                <h3><?= e($question['statement']) ?></h3>

                <div class="quiz-review-options">
                    <?php foreach ($question['alternatives'] as $alternative): ?>
                        <?php
                        $selected = (int) $alternative['id'] === $selectedId;
                        $correctAlt = (int) $alternative['is_correct'] === 1;
                        $class = $correctAlt ? 'correct-option' : ($selected ? 'selected-wrong' : '');
                        ?>
                        <div class="quiz-review-option <?= $class ?>">
                            <strong><?= e($alternative['label'] ?: chr(64 + (int) $alternative['position'])) ?></strong>
                            <span><?= e($alternative['text']) ?></span>
                            <?php if ($correctAlt): ?><em>Resposta correta</em><?php endif; ?>
                            <?php if ($selected && !$correctAlt): ?><em>Sua resposta</em><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (!empty($question['explanation'])): ?>
                    <div class="quiz-explanation">
                        <strong>Comentário:</strong>
                        <p><?= nl2br(e($question['explanation'])) ?></p>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="quiz-result-actions">
        <?php if ($passed && !empty($attempt['next_content']['lesson_id'])): ?>
            <a class="primary" href="<?= e(url('/aula/' . $attempt['next_content']['lesson_id'])) ?>">
                Continuar para a próxima fase →
            </a>
        <?php elseif ($passed): ?>
            <a class="primary" href="<?= e(url('/dashboard')) ?>">Voltar ao dashboard →</a>
        <?php else: ?>
            <a class="secondary" href="<?= e(url('/fase/' . $attempt['phase_id'])) ?>">Revisar a fase</a>
            <form action="<?= e(url('/prova/' . $attempt['quiz_id'] . '/iniciar')) ?>" method="post" class="inline">
                <?= \App\Core\Csrf::input() ?>
                <button class="primary" type="submit">Tentar novamente →</button>
            </form>
        <?php endif; ?>
    </div>
</section>
