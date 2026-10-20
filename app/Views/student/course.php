<?php
$moduleAccessMode = (string) ($settings['module_access_mode'] ?? 'free');
$lessonAccessMode = (string) ($settings['lesson_access_mode'] ?? 'sequential');
$isPostgres = (string) ($course['experience_key'] ?? '') === 'postgresql';

if ($isPostgres):
    $modulesTotal = (int) ($courseStats['modules_total'] ?? count($modules));
    $modulesPublished = (int) ($courseStats['modules_published'] ?? 0);
    $lessonsTotal = (int) ($courseStats['lessons_total'] ?? 0);
    $lessonsPublished = (int) ($courseStats['lessons_published'] ?? 0);
    $labsTotal = (int) ($courseStats['labs_total'] ?? 0);
    $labsPublished = (int) ($courseStats['labs_published'] ?? 0);
    $plannedLabs = max($labsTotal, $lessonsTotal);
    $progressPct = max(0, min(100, (float) ($course['progress_pct'] ?? 0)));

    $completedModules = 0;
    $continueModule = null;

    foreach ($modules as $candidate) {
        if (($candidate['user_status'] ?? '') === 'completed') {
            $completedModules++;
        }

        if (
            $continueModule === null
            && (string) ($candidate['status'] ?? '') === 'published'
            && (string) ($candidate['user_status'] ?? 'locked') !== 'locked'
        ) {
            $continueModule = $candidate;
        }

        if (
            (string) ($candidate['status'] ?? '') === 'published'
            && ($candidate['user_status'] ?? '') === 'in_progress'
        ) {
            $continueModule = $candidate;
            break;
        }
    }
?>
<section class="pg-course-home">
    <nav class="pg-breadcrumb" aria-label="Navegação estrutural">
        <a href="<?= e(url('/dashboard')) ?>">Meus Cursos</a>
        <span aria-hidden="true">›</span>
        <strong><?= e((string) $course['title']) ?></strong>
    </nav>

    <section class="pg-course-hero">
        <div class="pg-course-hero-copy">
            <h1>PostgreSQL — Do Zero ao Intermediário</h1>
            <p>
                Aprenda banco de dados na prática, escrevendo SQL e construindo
                um projeto real passo a passo.
            </p>

            <div class="pg-hero-stats" aria-label="Informações do curso">
                <span>
                    <i aria-hidden="true">▥</i>
                    <b><strong><?= $modulesTotal ?></strong> Módulos</b>
                </span>
                <span>
                    <i aria-hidden="true">▤</i>
                    <b><strong><?= $lessonsTotal ?></strong> Aulas</b>
                </span>
                <span>
                    <i aria-hidden="true">♜</i>
                    <b><strong><?= $plannedLabs ?></strong> Laboratórios</b>
                </span>
                <span>
                    <i aria-hidden="true">▥</i>
                    <b><strong>Iniciante ao</strong> Intermediário</b>
                </span>
            </div>

            <div class="pg-hero-actions">
                <?php if ($continueModule): ?>
                    <a
                        class="pg-primary"
                        href="<?= e(url('/curso/' . (int) $course['id'] . '/modulo/' . (int) $continueModule['id'])) ?>"
                    >
                        <span aria-hidden="true">▶</span> Continuar curso
                    </a>
                <?php else: ?>
                    <span class="pg-primary is-disabled">Curso em preparação</span>
                <?php endif; ?>

                <a class="pg-secondary" href="#modulos">
                    <span aria-hidden="true">☷</span> Ver módulos
                </a>
            </div>
        </div>

        <div
            class="pg-hero-art"
            role="img"
            aria-label="Ilustração de PostgreSQL, banco de dados, SQL e circuitos"
        ></div>
    </section>

    <section class="pg-progress-panel" aria-label="Resumo do progresso">
        <div class="pg-progress-main">
            <h2>Seu progresso no curso</h2>

            <div class="pg-progress-line">
                <div class="pg-progress-track">
                    <span style="width:<?= e((string) $progressPct) ?>%"></span>
                </div>
                <strong><?= number_format($progressPct, 0, ',', '.') ?>%</strong>
            </div>

            <p>
                <?= $completedModules ?> de <?= $modulesTotal ?>
                módulo<?= $modulesTotal === 1 ? '' : 's' ?> concluído<?= $completedModules === 1 ? '' : 's' ?>
            </p>
        </div>

        <div class="pg-progress-stat">
            <span class="is-blue" aria-hidden="true">▥</span>
            <div>
                <small>Módulos</small>
                <strong><?= $modulesTotal ?></strong>
            </div>
        </div>

        <div class="pg-progress-stat">
            <span class="is-blue" aria-hidden="true">▤</span>
            <div>
                <small>Aulas</small>
                <strong><?= $lessonsTotal ?></strong>
            </div>
        </div>

        <div class="pg-progress-stat">
            <span class="is-blue" aria-hidden="true">♜</span>
            <div>
                <small>Laboratórios</small>
                <strong><?= $plannedLabs ?></strong>
            </div>
        </div>

        <div class="pg-progress-stat">
            <span class="is-green" aria-hidden="true">▥</span>
            <div>
                <small>Seu nível</small>
                <strong>Iniciante</strong>
            </div>
        </div>
    </section>

    <section class="pg-course-modules" id="modulos">
        <header class="pg-learning-head">
            <span class="pg-learning-icon" aria-hidden="true">▭</span>
            <div>
                <h2>Trilha de Aprendizagem</h2>
                <p>Siga a ordem dos módulos para construir seu conhecimento em PostgreSQL.</p>
            </div>
        </header>

        <div class="pg-module-grid">
            <?php foreach ($modules as $m): ?>
                <?php
                $isDraft = (string) ($m['status'] ?? '') !== 'published';
                $userStatus = (string) ($m['user_status'] ?? 'locked');
                $locked = $isDraft || $userStatus === 'locked';
                $done = !$isDraft && $userStatus === 'completed';
                $inProgress = !$isDraft && $userStatus === 'in_progress';
                $progress = max(0, min(100, (float) ($m['progress_pct'] ?? 0)));
                ?>
                <article class="pg-module-card <?= $done ? 'is-done' : ($inProgress ? 'is-current' : ($locked ? 'is-locked' : 'is-available')) ?>">
                    <div class="pg-module-content">
                        <span class="pg-module-number"><?= e((string) ($m['rail_label'] ?? $m['position'])) ?></span>

                        <div class="pg-module-copy">
                            <h3><?= e((string) $m['title']) ?></h3>
                            <p><?= e((string) ($m['description'] ?? '')) ?></p>
                        </div>
                    </div>

                    <div class="pg-module-progress-row">
                        <div class="pg-progress-track is-small">
                            <span style="width:<?= e((string) $progress) ?>%"></span>
                        </div>
                        <strong><?= number_format($progress, 0, ',', '.') ?>%</strong>
                    </div>

                    <footer class="pg-module-footer">
                        <span class="pg-module-state">
                            <?php if ($done): ?>
                                ✓ Concluído
                            <?php elseif ($inProgress): ?>
                                ▣ Em andamento
                            <?php elseif ($locked): ?>
                                ♙ Bloqueado
                            <?php else: ?>
                                Disponível
                            <?php endif; ?>
                        </span>

                        <?php if (!$locked): ?>
                            <a
                                class="pg-module-action <?= $inProgress ? 'is-primary' : '' ?>"
                                href="<?= e(url('/curso/' . (int) $course['id'] . '/modulo/' . (int) $m['id'])) ?>"
                            >
                                <?= $done ? 'Revisar módulo' : ($inProgress ? 'Continuar módulo' : 'Acessar módulo') ?>
                            </a>
                        <?php elseif (!$isDraft): ?>
                            <span class="pg-module-action is-disabled">Aguardando liberação</span>
                        <?php else: ?>
                            <span class="pg-module-action is-disabled">Ver conteúdo</span>
                        <?php endif; ?>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</section>
<?php else: ?>
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
<?php endif; ?>
