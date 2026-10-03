<?php
$selectedCount = (int) ($attempt['answered_count'] ?? 0);
?>
<section class="simulation-review-page">
    <div class="simulation-back"><a href="<?= e(url('/simulados/tentativa/' . $attempt['id'] . '/resultado')) ?>">← Voltar ao resultado</a></div>

    <header class="simulation-review-header card">
        <span class="simulation-eyebrow">REVISÃO COMENTADA</span>
        <h1><?= e($attempt['module_title'] ?: 'Simulado geral') ?></h1>
        <p><?= (int) $attempt['correct_count'] ?> acertos em <?= (int) $attempt['question_limit'] ?> questões. Revise principalmente os erros e as questões deixadas em branco.</p>
    </header>

    <div class="simulation-review-list">
        <?php foreach ($attempt['questions'] as $index => $question): ?>
            <?php
            $selectedId = (int) ($question['selected_alternative_id'] ?? 0);
            $isCorrect = (int) ($question['answer_is_correct'] ?? 0) === 1;
            $unanswered = $selectedId <= 0;
            ?>
            <article class="card simulation-review-card <?= $isCorrect ? 'correct' : 'wrong' ?>">
                <div class="simulation-review-heading">
                    <span><?= $isCorrect ? '✓' : ($unanswered ? '○' : '✕') ?></span>
                    <div><strong>Questão <?= $index + 1 ?></strong><small><?= e($question['module_title']) ?> • <?= $question['difficulty'] === 'hard' ? 'Difícil' : 'Intermediária' ?></small></div>
                    <em><?= $isCorrect ? 'ACERTOU' : ($unanswered ? 'EM BRANCO' : 'ERROU') ?></em>
                </div>

                <h2><?= e($question['statement']) ?></h2>

                <div class="simulation-review-options">
                    <?php foreach ($question['alternatives'] as $alternative): ?>
                        <?php
                        $selected = (int) $alternative['id'] === $selectedId;
                        $correctAlt = (int) $alternative['is_correct'] === 1;
                        $class = $correctAlt ? 'correct-option' : ($selected ? 'selected-wrong' : '');
                        ?>
                        <div class="simulation-review-option <?= $class ?>">
                            <strong><?= e($alternative['label'] ?: chr(64 + (int) $alternative['position'])) ?></strong>
                            <span><?= e($alternative['text']) ?></span>
                            <?php if ($correctAlt): ?><em>Resposta correta</em><?php endif; ?>
                            <?php if ($selected && !$correctAlt): ?><em>Sua resposta</em><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (!empty($question['explanation'])): ?>
                    <div class="simulation-explanation"><strong>Comentário</strong><p><?= nl2br(e($question['explanation'])) ?></p></div>
                <?php endif; ?>

                <?php if (!empty($question['source_label'])): ?>
                    <div class="simulation-source"><strong>Fonte:</strong> <?= e($question['source_label']) ?></div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="simulation-result-actions">
        <a class="primary" href="<?= e(url('/simulados/novo')) ?>">Gerar outro simulado</a>
        <a class="secondary" href="<?= e(url('/simulados')) ?>">Histórico</a>
    </div>
</section>
