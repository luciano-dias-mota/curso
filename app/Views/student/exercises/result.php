<?php
$session = $session ?? [];
$review = $review ?? [];
$score = (float) ($session['percentage'] ?? 0);
$correct = (int) ($session['correct_count'] ?? 0);
$total = max(1, (int) ($session['question_limit'] ?? count($review)));
$scope = $session['phase_title_snapshot'] ?: ($session['module_title_snapshot'] ?: $session['course_title_snapshot']);
?>
<section class="study-tools-page exercise-result-page">
    <header class="exercise-result-hero card">
        <div class="exercise-result-score"><strong><?= number_format($score, 0, ',', '.') ?>%</strong><span><?= $correct ?> de <?= $total ?> corretas</span></div>
        <div>
            <span class="study-tools-eyebrow">EXERCÍCIO CONCLUÍDO</span>
            <h1><?= $score >= 80 ? 'Base muito bem consolidada' : ($score >= 60 ? 'Bom avanço — revise os erros' : 'Vale reforçar este conteúdo') ?></h1>
            <p><?= e((string) $scope) ?> · nível médio · sem impacto no desbloqueio da trilha.</p>
            <div class="study-result-actions">
                <a class="primary" href="<?= e(url('/exercicios')) ?>">Gerar outro exercício</a>
                <a class="secondary" href="<?= e(url('/dashboard')) ?>">Voltar ao início</a>
            </div>
        </div>
    </header>

    <section class="study-panel card">
        <div class="study-panel-heading"><div><span>REVISÃO</span><h2>Confira o que acertou e errou</h2></div></div>
        <div class="exercise-review-list">
            <?php foreach ($review as $item): ?>
                <article class="exercise-review-item <?= $item['is_correct'] ? 'is-correct' : 'is-wrong' ?>">
                    <div class="exercise-review-heading">
                        <span><?= (int) $item['position'] ?></span>
                        <div><strong><?= $item['is_correct'] ? 'Correta' : 'Revisar' ?></strong><small><?= e((string) $item['statement']) ?></small></div>
                    </div>
                    <div class="exercise-review-answer">
                        <p><strong>Sua resposta:</strong> <?= e((string) (($item['selected']['label'] ?? '—') . ') ' . ($item['selected']['text'] ?? 'Não respondida'))) ?></p>
                        <?php if (!$item['is_correct']): ?>
                            <p><strong>Correta:</strong> <?= e((string) (($item['correct']['label'] ?? '—') . ') ' . ($item['correct']['text'] ?? ''))) ?></p>
                        <?php endif; ?>
                        <?php if ((string) $item['explanation'] !== ''): ?><p class="exercise-review-explanation"><?= e((string) $item['explanation']) ?></p><?php endif; ?>
                        <?php if ((string) $item['source'] !== ''): ?><small><?= e((string) $item['source']) ?></small><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</section>
