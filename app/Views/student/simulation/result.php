<?php
$percentage = (float) $attempt['percentage'];
$passed = (int) $attempt['passed'] === 1;
$minutes = intdiv((int) $attempt['duration_seconds'], 60);
$seconds = (int) $attempt['duration_seconds'] % 60;
?>
<section class="simulation-result-page">
    <header class="simulation-result-hero card <?= $passed ? 'passed' : '' ?>">
        <div class="simulation-result-badge"><?= $passed ? '🏆' : '🎯' ?></div>
        <div>
            <span class="simulation-eyebrow">RESULTADO DO SIMULADO</span>
            <h1><?= $passed ? 'Meta atingida!' : 'Resultado registrado' ?></h1>
            <p>Este resultado não bloqueia nem libera etapas do curso. Use-o como diagnóstico para orientar sua próxima revisão.</p>
        </div>
        <div class="simulation-result-score"><strong><?= number_format($percentage, 0) ?>%</strong><span><?= (int) $attempt['correct_count'] ?>/<?= (int) $attempt['question_limit'] ?> acertos</span></div>
    </header>

    <div class="simulation-result-stat-grid">
        <article class="card"><span>✓</span><div><small>Acertos</small><strong><?= (int) $attempt['correct_count'] ?></strong></div></article>
        <article class="card"><span>✕</span><div><small>Erros</small><strong><?= (int) $attempt['wrong_count'] ?></strong></div></article>
        <article class="card"><span>○</span><div><small>Em branco</small><strong><?= (int) $attempt['unanswered_count'] ?></strong></div></article>
        <article class="card"><span>◷</span><div><small>Tempo</small><strong><?= sprintf('%02d:%02d', $minutes, $seconds) ?></strong></div></article>
    </div>

    <section class="card simulation-result-performance">
        <div class="simulation-panel-head"><div><span>DIAGNÓSTICO</span><h2>Desempenho por conteúdo</h2></div></div>
        <div class="simulation-subject-list">
            <?php foreach ($attempt['performance'] as $row): ?>
                <div class="simulation-subject-row">
                    <div><strong><?= e($row['module_title']) ?></strong><small><?= (int) $row['correct'] ?>/<?= (int) $row['total'] ?> acertos</small></div>
                    <div class="simulation-subject-progress"><span style="width:<?= max(0, min(100, (float) $row['percentage'])) ?>%"></span></div>
                    <b><?= number_format((float) $row['percentage'], 0) ?>%</b>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="simulation-result-actions">
        <a class="primary" href="<?= e(url('/simulados/tentativa/' . $attempt['id'] . '/revisao')) ?>">Revisar respostas</a>
        <a class="secondary" href="<?= e(url('/simulados/novo')) ?>">Gerar outro simulado</a>
        <a class="secondary" href="<?= e(url('/simulados')) ?>">Voltar ao histórico</a>
    </div>
</section>
