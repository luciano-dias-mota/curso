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
    $progressPct = max(0, min(100, (float) ($course['progress_pct'] ?? 0)));

    $continueModule = null;
    foreach ($modules as $candidate) {
        if (
            (string) ($candidate['status'] ?? '') === 'published'
            && (string) ($candidate['user_status'] ?? 'locked') !== 'locked'
        ) {
            $continueModule = $candidate;
            if (($candidate['user_status'] ?? '') === 'in_progress') {
                break;
            }
        }
    }
?>
<section class="pg-course-home">
    <header class="pg-course-hero">
        <div class="pg-course-hero-copy">
            <a class="pg-back-link" href="<?= e(url('/dashboard')) ?>">← Voltar ao portal</a>
            <span class="pg-kicker">SQL / BANCO DE DADOS</span>

            <h1>PostgreSQL <span>— Do Zero ao Intermediário</span></h1>
            <p>
                Aprenda banco de dados na prática, escrevendo SQL e construindo
                um projeto real passo a passo.
            </p>

            <div class="pg-hero-stats">
                <span><strong><?= $modulesTotal ?></strong> módulos</span>
                <span><strong><?= $lessonsTotal ?></strong> aulas</span>
                <span><strong><?= $labsTotal ?></strong> laboratórios</span>
                <span><strong>Iniciante</strong> ao Intermediário</span>
            </div>

            <div class="pg-hero-actions">
                <?php if ($continueModule): ?>
                    <a
                        class="pg-primary"
                        href="<?= e(url('/curso/' . (int) $course['id'] . '/modulo/' . (int) $continueModule['id'])) ?>"
                    >
                        ▶ Continuar curso
                    </a>
                <?php else: ?>
                    <span class="pg-primary is-disabled">Curso em preparação</span>
                <?php endif; ?>

                <a class="pg-secondary" href="#modulos">☷ Ver módulos</a>
            </div>
        </div>

        <div class="pg-tech-scene" aria-hidden="true">
            <div class="pg-sql-window">
                <span>SQL</span>
                <code>SELECT *<br>FROM produtos<br>WHERE estoque &gt; 0;</code>
            </div>

            <svg class="pg-database-art" viewBox="0 0 420 260" role="presentation">
                <defs>
                    <linearGradient id="pgCylinder" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0" stop-color="#ffffff"/>
                        <stop offset=".35" stop-color="#8bd5ff"/>
                        <stop offset="1" stop-color="#1677c8"/>
                    </linearGradient>
                    <linearGradient id="pgCylinderDark" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0" stop-color="#67c8ff"/>
                        <stop offset="1" stop-color="#075da6"/>
                    </linearGradient>
                </defs>
                <g opacity=".18" stroke="#0b72c8" fill="none">
                    <path d="M15 56h90l30 30h84"/>
                    <path d="M24 206h110l34-34h76"/>
                    <path d="M260 22v45l42 34"/>
                    <circle cx="105" cy="56" r="4" fill="#0b72c8"/>
                    <circle cx="219" cy="86" r="4" fill="#0b72c8"/>
                    <circle cx="168" cy="172" r="4" fill="#0b72c8"/>
                </g>
                <g transform="translate(202 42)">
                    <ellipse cx="92" cy="30" rx="74" ry="23" fill="url(#pgCylinder)"/>
                    <path d="M18 30v54c0 13 33 23 74 23s74-10 74-23V30" fill="url(#pgCylinderDark)" opacity=".94"/>
                    <ellipse cx="92" cy="84" rx="74" ry="23" fill="#76cef9" opacity=".72"/>
                    <path d="M18 84v52c0 13 33 23 74 23s74-10 74-23V84" fill="url(#pgCylinderDark)" opacity=".92"/>
                    <ellipse cx="92" cy="136" rx="74" ry="23" fill="#c7efff"/>
                    <path d="M18 136v45c0 13 33 23 74 23s74-10 74-23v-45" fill="url(#pgCylinderDark)"/>
                    <ellipse cx="92" cy="181" rx="74" ry="23" fill="#e6f8ff"/>
                </g>
                <g transform="translate(38 112)">
                    <rect width="112" height="82" rx="16" fill="rgba(255,255,255,.76)" stroke="rgba(20,112,190,.18)"/>
                    <path d="M18 24h76M18 42h48M18 60h64" stroke="#1680d6" stroke-width="7" stroke-linecap="round" opacity=".62"/>
                </g>
            </svg>
        </div>
    </header>

    <section class="pg-course-summary">
        <div class="pg-summary-progress">
            <div>
                <span>Seu progresso no curso</span>
                <strong><?= number_format($progressPct, 0, ',', '.') ?>%</strong>
            </div>
            <div class="pg-progress-track">
                <span style="width:<?= e((string) $progressPct) ?>%"></span>
            </div>
            <small><?= $modulesPublished ?> de <?= $modulesTotal ?> módulos já liberados para teste.</small>
        </div>

        <div class="pg-summary-card">
            <span>▥</span>
            <div><small>Módulos</small><strong><?= $modulesTotal ?></strong></div>
        </div>

        <div class="pg-summary-card">
            <span>▷</span>
            <div><small>Aulas</small><strong><?= $lessonsTotal ?></strong></div>
        </div>

        <div class="pg-summary-card">
            <span>⌨</span>
            <div><small>Laboratórios</small><strong><?= $labsTotal ?></strong></div>
        </div>

        <div class="pg-summary-card">
            <span>↗</span>
            <div><small>Nível</small><strong>Iniciante</strong></div>
        </div>
    </section>

    <section class="pg-course-modules" id="modulos">
        <div class="pg-section-head">
            <div>
                <span class="pg-kicker">TRILHA DE APRENDIZAGEM</span>
                <h2>Módulos do curso</h2>
            </div>
            <p>
                A trilha começa nos fundamentos e avança até um projeto completo
                da LD Informática.
            </p>
        </div>

        <div class="pg-module-grid">
            <?php foreach ($modules as $m): ?>
                <?php
                $isDraft = (string) ($m['status'] ?? '') !== 'published';
                $locked = !$isDraft && (($m['user_status'] ?? 'locked') === 'locked');
                $done = !$isDraft && (($m['user_status'] ?? '') === 'completed');
                $progress = max(0, min(100, (float) ($m['progress_pct'] ?? 0)));
                ?>
                <article class="pg-module-card <?= $isDraft ? 'is-coming' : ($locked ? 'is-locked' : ($done ? 'is-done' : 'is-active')) ?>">
                    <div class="pg-module-card-top">
                        <span class="pg-module-number">
                            <?= e((string) ($m['rail_label'] ?? $m['position'])) ?>
                        </span>

                        <span class="pg-module-status">
                            <?php if ($isDraft): ?>
                                Em breve
                            <?php elseif ($done): ?>
                                Concluído
                            <?php elseif ($locked): ?>
                                Bloqueado
                            <?php else: ?>
                                Disponível
                            <?php endif; ?>
                        </span>
                    </div>

                    <h3><?= e((string) $m['title']) ?></h3>
                    <p><?= e((string) ($m['description'] ?? '')) ?></p>

                    <div class="pg-module-meta">
                        <span>▤ <?= (int) ($m['lesson_count'] ?? 0) ?> aulas</span>
                        <span>⌨ <?= (int) ($m['lab_count'] ?? 0) ?> labs</span>
                    </div>

                    <div class="pg-progress-track is-small">
                        <span style="width:<?= e((string) $progress) ?>%"></span>
                    </div>

                    <div class="pg-module-footer">
                        <small><?= number_format($progress, 0, ',', '.') ?>%</small>

                        <?php if ($isDraft): ?>
                            <span class="pg-module-button is-disabled">Em construção</span>
                        <?php elseif ($locked): ?>
                            <span class="pg-module-button is-disabled">Aguardando liberação</span>
                        <?php else: ?>
                            <a
                                class="pg-module-button"
                                href="<?= e(url('/curso/' . (int) $course['id'] . '/modulo/' . (int) $m['id'])) ?>"
                            >
                                <?= $done ? 'Revisar módulo' : 'Entrar no módulo' ?> →
                            </a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="pg-lab-banner">
        <div>
            <span class="pg-kicker">APRENDA FAZENDO</span>
            <h2>Laboratório PostgreSQL no navegador</h2>
            <p>
                Escreva SQL, execute consultas e receba validação automática sem
                precisar instalar um servidor PostgreSQL no computador.
            </p>
        </div>

        <div class="pg-lab-banner-stats">
            <strong><?= $labsPublished ?></strong>
            <span>laboratórios liberados</span>
            <small><?= max(0, $labsTotal - $labsPublished) ?> em preparação</small>
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
