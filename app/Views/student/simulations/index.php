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
<section class="simulation-hub simulation-hub-v2">
    <?php if ($error): ?><div class="alert error simulation-hub-alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert success simulation-hub-alert"><?= e($success) ?></div><?php endif; ?>

    <div class="simulation-hub-top">
        <header class="simulation-hero simulation-hero-compact card">
            <div>
                <span class="simulation-eyebrow">CENTRAL DE SIMULADOS</span>
                <h1>Treine. Meça. Evolua.</h1>
                <p>Gere provas diferentes, acompanhe sua evolução e identifique rapidamente os conteúdos que merecem revisão.</p>
                <div class="simulation-hero-actions">
                    <a class="primary" href="<?= e(url('/simulados/novo')) ?>">＋ Novo simulado</a>
                    <span>Treino livre • não altera o desbloqueio das aulas</span>
                </div>
            </div>
            <div class="simulation-hero-mark" aria-hidden="true">◎</div>
        </header>

        <div class="simulation-stat-grid simulation-stat-grid-compact">
            <article class="simulation-stat card">
                <span>🎯</span>
                <div><small>Simulados</small><strong><?= (int) ($stats['attempts'] ?? 0) ?></strong><em>finalizados</em></div>
            </article>
            <article class="simulation-stat card">
                <span>📊</span>
                <div><small>Média</small><strong><?= number_format((float) ($stats['average'] ?? 0), 1, ',', '.') ?>%</strong><em>desempenho</em></div>
            </article>
            <article class="simulation-stat card">
                <span>🏆</span>
                <div><small>Melhor nota</small><strong><?= number_format((float) ($stats['best'] ?? 0), 1, ',', '.') ?>%</strong><em>recorde pessoal</em></div>
            </article>
            <article class="simulation-stat card">
                <span>📈</span>
                <div><small>Evolução</small><strong><?= ((float) ($stats['trend'] ?? 0)) >= 0 ? '+' : '' ?><?= number_format((float) ($stats['trend'] ?? 0), 1, ',', '.') ?></strong><em>p.p. na última comparação</em></div>
            </article>
        </div>
    </div>

    <div class="simulation-hub-workspace <?= $activeAttempts ? 'has-active' : 'no-active' ?>">
        <section class="card simulation-panel simulation-hub-panel simulation-evolution-panel">
            <div class="simulation-panel-head">
                <div><span>EVOLUÇÃO</span><h2>Últimos resultados</h2></div>
                <strong><?= (int) ($stats['questions'] ?? 0) ?> questões treinadas</strong>
            </div>

            <?php if ($chart): ?>
                <div class="simulation-chart simulation-chart-compact" aria-label="Evolução dos resultados">
                    <?php foreach ($chart as $row): ?>
                        <?php $p = max(2, min(100, (float) $row['percentage'])); ?>
                        <div class="simulation-chart-column" title="<?= number_format((float) $row['percentage'], 1, ',', '.') ?>%">
                            <span style="height:<?= $p ?>%"></span>
                            <small><?= number_format((float) $row['percentage'], 0) ?>%</small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="simulation-empty simulation-empty-compact">
                    <span>📈</span>
                    <strong>Seu gráfico começa no primeiro simulado.</strong>
                    <small>Faça uma tentativa para acompanhar sua evolução.</small>
                </div>
            <?php endif; ?>
        </section>

        <section class="card simulation-panel simulation-hub-panel simulation-subject-panel">
            <div class="simulation-panel-head">
                <div><span>DIAGNÓSTICO</span><h2>Desempenho por conteúdo</h2></div>
            </div>

            <?php if ($subjects): ?>
                <div class="simulation-subject-list simulation-subject-list-compact">
                    <?php foreach ($subjects as $subject): ?>
                        <?php $p = (float) $subject['percentage']; ?>
                        <div class="simulation-subject-row">
                            <div>
                                <strong><?= e($subject['module_title']) ?></strong>
                                <small><?= (int) $subject['correct'] ?>/<?= (int) $subject['total'] ?> acertos</small>
                            </div>
                            <div class="simulation-subject-progress"><span style="width:<?= max(0, min(100, $p)) ?>%"></span></div>
                            <b><?= number_format($p, 0) ?>%</b>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="simulation-empty simulation-empty-compact">
                    <span>🧭</span>
                    <strong>Ainda não há diagnóstico.</strong>
                    <small>Ele aparecerá conforme você responder simulados.</small>
                </div>
            <?php endif; ?>
        </section>

        <aside class="simulation-hub-side">
            <?php if ($activeAttempts): ?>
                <section class="card simulation-hub-side-panel simulation-continue-panel">
                    <div class="simulation-panel-head compact-head">
                        <div><span>EM ANDAMENTO</span><h2>Continue de onde parou</h2></div>
                    </div>

                    <div class="simulation-active-list-compact">
                        <?php foreach ($activeAttempts as $active): ?>
                            <article class="simulation-active simulation-active-compact">
                                <div class="simulation-active-compact-copy">
                                    <span class="simulation-chip">Tentativa <?= (int) $active['id'] ?></span>
                                    <h3><?= e($active['module_title'] ?: 'Simulado geral') ?></h3>
                                    <p><?= (int) $active['answered'] ?>/<?= (int) $active['question_limit'] ?> respondidas • <?= max(0, (int) ceil(((int) $active['remaining_seconds']) / 60)) ?> min restantes</p>
                                </div>
                                <div class="simulation-active-actions simulation-active-actions-compact">
                                    <a class="primary" href="<?= e(url('/simulados/tentativa/' . $active['id'])) ?>">Continuar</a>
                                    <form action="<?= e(url('/simulados/tentativa/' . $active['id'] . '/abandonar')) ?>" method="post" onsubmit="return confirm('Abandonar esta tentativa?');">
                                        <?= Csrf::input() ?>
                                        <button class="secondary" type="submit" title="Abandonar tentativa">×</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="card simulation-hub-side-panel simulation-history-panel">
                <div class="simulation-panel-head compact-head">
                    <div><span>HISTÓRICO</span><h2>Tentativas anteriores</h2></div>
                    <a class="simulation-mini-link" href="<?= e(url('/simulados/novo')) ?>">Novo +</a>
                </div>

                <?php if ($history): ?>
                    <div class="simulation-history-list simulation-history-list-compact">
                        <?php foreach ($history as $row): ?>
                            <article class="simulation-history simulation-history-compact">
                                <div class="simulation-history-score <?= (int) $row['passed'] === 1 ? 'passed' : '' ?>"><?= number_format((float) $row['percentage'], 0) ?>%</div>
                                <div class="simulation-history-main">
                                    <strong><?= e($row['module_title'] ?: 'Simulado geral') ?></strong>
                                    <small><?= (int) round((float) $row['score']) ?>/<?= (int) $row['question_limit'] ?> acertos • <?= date('d/m', strtotime((string) $row['finished_at'])) ?></small>
                                </div>
                                <div class="simulation-history-actions">
                                    <a href="<?= e(url('/simulados/tentativa/' . $row['id'] . '/resultado')) ?>">Resultado</a>
                                    <a href="<?= e(url('/simulados/tentativa/' . $row['id'] . '/revisao')) ?>">Revisar</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="simulation-empty simulation-empty-compact">
                        <span>📝</span>
                        <strong>Nenhum simulado finalizado.</strong>
                        <small>Seu histórico aparecerá aqui.</small>
                    </div>
                <?php endif; ?>
            </section>
        </aside>
    </div>
</section>
