<?php
$moduleAccessMode = (string) ($settings['module_access_mode'] ?? 'free');
$lessonAccessMode = (string) ($settings['lesson_access_mode'] ?? 'sequential');
?>
<section class="trail-page">
    <header class="trail-hero card">
        <div>
            <a class="back" href="<?= e(url('/dashboard#meus-cursos')) ?>">← Meus cursos</a>
            <span class="eyebrow">AMBIENTE DO CURSO</span>
            <h1><?= e($course['title']) ?></h1>
            <p><?= e($course['description'] ?? $course['short_description'] ?? '') ?></p>
        </div>
        <div class="score">
            <strong><?= number_format((float) $course['progress_pct'], 0) ?>%</strong>
            <span>concluído</span>
            <div class="progress"><span style="width:<?= max(0, min(100, (float) $course['progress_pct'])) ?>%"></span></div>
        </div>
    </header>

    <div class="section-head">
        <div>
            <span class="eyebrow">MAPA DE ESTUDO</span>
            <h2>Escolha o módulo de hoje</h2>
        </div>
        <p>
            <?php if ($moduleAccessMode === 'free'): ?>
                Os módulos publicados estão disponíveis livremente.
            <?php else: ?>
                Os módulos são liberados conforme sua progressão.
            <?php endif; ?>
            As aulas internas seguem o modo <?= $lessonAccessMode === 'free' ? 'livre' : 'sequencial' ?> configurado para este curso.
        </p>
    </div>

    <div class="timeline">
        <?php foreach ($modules as $m): ?>
            <?php
            $locked = ($m['user_status'] ?? 'locked') === 'locked';
            $done = ($m['user_status'] ?? '') === 'completed';
            ?>
            <article class="timeline-item <?= $locked ? 'locked' : '' ?> <?= $done ? 'done' : '' ?>">
                <div class="rail"><span><?= e((string) ($m['rail_label'] ?? $m['position'])) ?></span></div>
                <div class="card module-box">
                    <div class="module-head">
                        <div>
                            <span class="eyebrow"><?= e((string) ($m['display_label'] ?? ('MÓDULO ' . (int) $m['position']))) ?></span>
                            <h3><?= e($m['title']) ?></h3>
                        </div>
                        <span class="badge <?= $locked ? 'locked' : ($done ? 'done' : 'active') ?>">
                            <?= $locked ? 'BLOQUEADO' : ($done ? 'CONCLUÍDO' : 'DISPONÍVEL') ?>
                        </span>
                    </div>

                    <p><?= e($m['description'] ?? '') ?></p>

                    <div class="meta">
                        <span><?= (int) $m['required_phase_count'] ?> fases obrigatórias</span>
                        <?php if ((int) $m['library_phase_count']): ?>
                            <span><?= (int) $m['library_phase_count'] ?> biblioteca(s)</span>
                        <?php endif; ?>
                    </div>

                    <div class="progress"><span style="width:<?= max(0, min(100, (float) $m['progress_pct'])) ?>%"></span></div>

                    <?php if ($locked): ?>
                        <span class="primary disabled" aria-disabled="true">Conclua o módulo anterior para liberar</span>
                    <?php else: ?>
                        <a class="primary" href="<?= e(url('/modulo/' . $m['id'])) ?>">
                            <?= $done ? 'Revisar módulo →' : 'Estudar este módulo →' ?>
                        </a>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
