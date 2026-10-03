<?php
use App\Core\Csrf;
use App\Core\Session;

$error = Session::pullFlash('error');
$success = Session::pullFlash('success');
$history = $history ?? [];
$activeAttempts = $activeAttempts ?? [];
$subjects = $subjects ?? [];
$stats = $stats ?? [];
$chart = $chart ?? [];
?>
<section class="simulation-hub">
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>

    <header class="simulation-hero card">
        <div>
            <span class="simulation-eyebrow">CENTRAL DE SIMULADOS</span>
            <h1>Teste o que você realmente aprendeu</h1>
            <p>Gere provas diferentes a cada tentativa, acompanhe sua evolução e descubra os assuntos que precisam de mais revisão.</p>
            <div class="simulation-hero-actions">
                <a class="primary" href="<?= e(url('/simulados/novo')) ?>">＋ Gerar novo simulado</a>
                <span>Não interfere no desbloqueio das aulas.</span>
            </div>
        </div>
        <div class="simulation-hero-mark" aria-hidden="true">◎</div>
    </header>

    <div class="simulation-stat-grid">
        <article class="simulation-stat card"><span>🎯</span><div><small>Simulados</small><strong><?= (int) ($stats['attempts'] ?? 0) ?></strong><em>finalizados</em></div></article>
        <article class="simulation-stat card"><span>📊</span><div><small>Média</small><strong><?= number_format((float) ($stats['average'] ?? 0), 1, ',', '.') ?>%</strong><em>desempenho</em></div></article>
        <article class="simulation-stat card"><span>🏆</span><div><small>Melhor nota</small><strong><?= number_format((float) ($stats['best'] ?? 0), 1, ',', '.') ?>%</strong><em>recorde pessoal</em></div></article>
        <article class="simulation-stat card"><span>📈</span><div><small>Última evolução</small><strong><?= ((float) ($stats['trend'] ?? 0)) >= 0 ? '+' : '' ?><?= number_format((float) ($stats['trend'] ?? 0), 1, ',', '.') ?></strong><em>pontos percentuais</em></div></article>
    </div>

    <?php if ($activeAttempts): ?>
        <section class="simulation-section">
            <div class="simulation-section-title"><div><span>EM ANDAMENTO</span><h2>Continue de onde parou</h2></div></div>
            <div class="simulation-active-grid">
                <?php foreach ($activeAttempts as $active): ?>
                    <article class="simulation-active card">
                        <div>
                            <span class="simulation-chip">Tentativa <?= (int) $active['id'] ?></span>
                            <h3><?= e($active['module_title'] ?: 'Simulado geral') ?></h3>
                            <p><?= e($active['course_title'] ?? '') ?></p>
                            <div class="simulation-active-meta">
                                <span><?= (int) $active['answered'] ?>/<?= (int) $active['question_limit'] ?> respondidas</span>
                                <span><?= max(0, (int) ceil(((int) $active['remaining_seconds']) / 60)) ?> min restantes</span>
                            </div>
                        </div>
                        <div class="simulation-active-actions">
                            <a class="primary" href="<?= e(url('/simulados/tentativa/' . $active['id'])) ?>">Continuar</a>
                            <form action="<?= e(url('/simulados/tentativa/' . $active['id'] . '/abandonar')) ?>" method="post" onsubmit="return confirm('Abandonar esta tentativa?');">
                                <?= Csrf::input() ?>
                                <button class="secondary" type="submit">Abandonar</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="simulation-dashboard-grid">
        <section class="card simulation-panel">
            <div class="simulation-panel-head"><div><span>EVOLUÇÃO</span><h2>Últimos resultados</h2></div><strong><?= (int) ($stats['questions'] ?? 0) ?> questões treinadas</strong></div>
            <?php if ($chart): ?>
                <div class="simulation-chart" aria-label="Evolução dos resultados">
                    <?php foreach ($chart as $row): ?>
                        <?php $p = max(2, min(100, (float) $row['percentage'])); ?>
                        <div class="simulation-chart-column" title="<?= number_format((float) $row['percentage'], 1, ',', '.') ?>%">
                            <span style="height:<?= $p ?>%"></span>
                            <small><?= number_format((float) $row['percentage'], 0) ?>%</small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="simulation-empty">Faça seu primeiro simulado para começar a formar seu histórico.</div>
            <?php endif; ?>
        </section>

        <section class="card simulation-panel">
            <div class="simulation-panel-head"><div><span>DIAGNÓSTICO</span><h2>Desempenho por conteúdo</h2></div></div>
            <?php if ($subjects): ?>
                <div class="simulation-subject-list">
                    <?php foreach ($subjects as $subject): ?>
                        <?php $p = (float) $subject['percentage']; ?>
                        <div class="simulation-subject-row">
                            <div><strong><?= e($subject['module_title']) ?></strong><small><?= (int) $subject['correct'] ?>/<?= (int) $subject['total'] ?> acertos</small></div>
                            <div class="simulation-subject-progress"><span style="width:<?= max(0, min(100, $p)) ?>%"></span></div>
                            <b><?= number_format($p, 0) ?>%</b>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="simulation-empty">Ainda não há dados suficientes para identificar seus pontos fortes e fracos.</div>
            <?php endif; ?>
        </section>
    </div>

    <section class="simulation-section">
        <div class="simulation-section-title"><div><span>HISTÓRICO</span><h2>Suas tentativas anteriores</h2></div><a class="secondary" href="<?= e(url('/simulados/novo')) ?>">Novo simulado</a></div>
        <?php if ($history): ?>
            <div class="simulation-history-list">
                <?php foreach ($history as $row): ?>
                    <article class="simulation-history card">
                        <div class="simulation-history-score <?= (int) $row['passed'] === 1 ? 'passed' : '' ?>"><?= number_format((float) $row['percentage'], 0) ?>%</div>
                        <div class="simulation-history-main">
                            <strong><?= e($row['module_title'] ?: 'Simulado geral') ?></strong>
                            <small><?= e($row['course_title'] ?? '') ?> • <?= (int) $row['question_limit'] ?> questões • <?= date('d/m/Y H:i', strtotime((string) $row['finished_at'])) ?></small>
                        </div>
                        <div class="simulation-history-result"><strong><?= (int) round((float) $row['score']) ?>/<?= (int) $row['question_limit'] ?></strong><small>acertos</small></div>
                        <div class="simulation-history-actions"><a href="<?= e(url('/simulados/tentativa/' . $row['id'] . '/resultado')) ?>">Resultado</a><a href="<?= e(url('/simulados/tentativa/' . $row['id'] . '/revisao')) ?>">Revisar</a></div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="card simulation-empty">Nenhum simulado finalizado ainda.</div>
        <?php endif; ?>
    </section>
</section>
